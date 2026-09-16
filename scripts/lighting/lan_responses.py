"""Read authenticated Tuya 3.5 replies from one existing socket, without sending.

Use one reader on the sending thread for the lifetime of the negotiated socket.
Partial frames survive drain windows. Sequence numbers describe incoming frames;
they are never matched against outgoing commands. Empty ACKs do not prove state.
"""

import importlib
import math
import select
import socket
import struct
import threading
import time

from configure import DiagnosticError, device_id, quiet_library
from read_state import inspect_status


class LanResponseError(DiagnosticError):
    def __init__(self, code, metadata=None):
        super().__init__(code)
        self.metadata = metadata or {}


class LanResponseReader:
    MAX_BUFFER = 65536
    MAX_MESSAGES = 512

    def __init__(self, device, clock=time.monotonic, select_fn=select.select, decoder=None):
        tinytuya = importlib.import_module("tinytuya")
        if tinytuya.__version__ != "1.20.0":
            raise LanResponseError("tinytuya_version_mismatch")
        self.headers = importlib.import_module("tinytuya.core.header")
        self.messages = importlib.import_module("tinytuya.core.message_helper")
        self.device, self.sock = device, device.socket
        self.selected_id = device_id(device.id)
        self.session_key = device.local_key
        if device.version != 3.5 or self.sock is None or getattr(device, "parent", None) is not None:
            raise LanResponseError("lan_existing_connection_required")
        if not isinstance(self.session_key, bytes) or len(self.session_key) != 16:
            raise LanResponseError("lan_invalid_session_key")
        if not hasattr(socket, "MSG_DONTWAIT"):
            raise LanResponseError("lan_nonblocking_receive_unavailable")
        self.clock, self.select_fn = clock, select_fn
        self.decode = decoder or device._decode_payload
        self.header_size = struct.calcsize(self.headers.MESSAGE_HEADER_FMT_6699)
        self.buffer = bytearray()
        self.created_at = clock()
        self.thread_id = threading.get_ident()
        self.failed = False

    @property
    def pending_bytes(self):
        return len(self.buffer)

    def _fail(self, code, metadata=None):
        self.failed = True
        raise LanResponseError(code, metadata)

    def _check_connection(self):
        if self.failed:
            raise LanResponseError("lan_reader_failed")
        if threading.get_ident() != self.thread_id:
            self._fail("lan_wrong_thread")
        if self.device.socket is not self.sock or self.device.local_key != self.session_key or self.device.version != 3.5:
            self._fail("lan_connection_changed")

    def _extract(self):
        if len(self.buffer) < 4:
            return None
        if self.buffer[:4] != self.headers.PREFIX_6699_BIN:
            self._fail("lan_invalid_prefix")
        if len(self.buffer) < self.header_size:
            return None
        try:
            header = self.messages.parse_header(bytes(self.buffer[:self.header_size]))
        except Exception:
            self._fail("lan_malformed_header")
        # nonce12 + retcode4 + GCM tag16, plus header and suffix outside length.
        if header.prefix != self.headers.PREFIX_6699_VALUE or header.length < 32:
            self._fail("lan_malformed_frame")
        if header.total_length > self.MAX_BUFFER:
            self._fail("lan_frame_too_large")
        if len(self.buffer) < header.total_length:
            return None
        raw = bytes(self.buffer[:header.total_length])
        del self.buffer[:header.total_length]
        if raw[-4:] != self.headers.SUFFIX_6699_BIN:
            self._fail("lan_invalid_suffix")
        try:
            with quiet_library():
                message = self.messages.unpack_message(raw, header=header, hmac_key=self.session_key, no_retcode=False)
        except Exception:
            self._fail("lan_malformed_or_unauthenticated_frame")
        if message.crc_good is not True:
            self._fail("lan_authentication_failed")
        metadata = {"cmd": message.cmd, "seq": message.seqno, "retcode": message.retcode,
                    "elapsedMs": round((self.clock() - self.created_at) * 1000, 3),
                    "emptyAck": len(message.payload) == 0}
        if message.retcode != 0:
            self._fail("lan_retcode_error", metadata)
        response = None
        if message.payload:
            try:
                with quiet_library():
                    response = self.decode(message.payload)
            except Exception:
                self._fail("lan_payload_invalid", metadata)
            if not isinstance(response, dict) or any(field in response for field in ("Err", "Error", "invalid_json")):
                self._fail("lan_payload_invalid", metadata)
            for container in (response, response.get("data")):
                if isinstance(container, dict):
                    for field in ("devId", "gwId"):
                        if field in container and container[field] != self.selected_id:
                            self._fail("lan_response_device_mismatch", metadata)
            if "dps" in response or isinstance(response.get("data"), dict) and "dps" in response["data"]:
                try:
                    inspect_status(response, self.selected_id)
                except DiagnosticError:
                    self._fail("lan_status_invalid", metadata)
        return {"metadata": metadata, "response": response}

    def drain_until(self, deadline, cancelled=lambda: False, on_message=None):
        """Return authenticated messages received before an absolute monotonic deadline.

        Select waits at most 50ms to keep cancellation responsive. Each recv
        temporarily disables Python's socket timeout, restoring it before the
        sender resumes. Deadline expiry preserves incomplete data.
        """
        if type(deadline) not in (int, float) or not math.isfinite(deadline):
            raise LanResponseError("lan_invalid_deadline")
        collected = []
        while True:
            if cancelled():
                raise DiagnosticError("cancelled")
            self._check_connection()
            remaining = deadline - self.clock()
            if remaining <= 0:
                return collected
            message = self._extract()
            if message is not None:
                collected.append(message)
                if on_message is not None:
                    on_message(message)
                if len(collected) >= self.MAX_MESSAGES:
                    self._fail("lan_message_limit")
                continue
            try:
                ready, _writable, exceptional = self.select_fn([self.sock], [], [self.sock], min(0.05, remaining))
            except InterruptedError:
                continue
            except Exception:
                self._fail("lan_select_failed")
            if exceptional:
                self._fail("lan_socket_error")
            if not ready:
                continue
            if cancelled():
                raise DiagnosticError("cancelled")
            # A ready socket can lose readability before recv; never block beyond
            # the current scheduling window, and keep previously received bytes.
            try:
                previous_timeout = self.sock.gettimeout()
                try:
                    self.sock.setblocking(False)
                    data = self.sock.recv(min(4096, self.MAX_BUFFER - len(self.buffer)), socket.MSG_DONTWAIT)
                finally:
                    self.sock.settimeout(previous_timeout)
            except (BlockingIOError, InterruptedError, socket.timeout):
                continue
            except Exception:
                self._fail("lan_receive_failed")
            if not data:
                self._fail("lan_peer_closed")
            self.buffer.extend(data)
            if len(self.buffer) > self.MAX_BUFFER:
                self._fail("lan_buffer_limit")
