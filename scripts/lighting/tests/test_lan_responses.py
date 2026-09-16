"""Offline authenticated-frame tests; byte fixtures use a fake session key."""

import io
import json
from pathlib import Path
import socket
import struct
import sys
import time
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from configure import DiagnosticError
from lan_responses import LanResponseError, LanResponseReader
from tinytuya import Device
from tinytuya.core import header as H
from tinytuya.core.message_helper import TuyaMessage, pack_message

KEY = b"test-key12345678"
DEVICE_ID = "fixture_device_123456"


class Clock:
    def __init__(self):
        self.now = 0.0

    def time(self):
        return self.now


class FakeSocket:
    def __init__(self, chunks=None):
        self.chunks = list(chunks or [])
        self.receives = []
        self.timeout = 3.0

    def gettimeout(self):
        return self.timeout

    def setblocking(self, blocking):
        self.timeout = None if blocking else 0.0

    def settimeout(self, timeout):
        self.timeout = timeout

    def recv(self, size, flags):
        self.receives.append((size, flags))
        if not self.chunks:
            raise BlockingIOError()
        data = self.chunks.pop(0)
        if isinstance(data, BaseException):
            raise data
        if len(data) > size:
            self.chunks.insert(0, data[size:])
        return data[:size]


def frame(payload=b"", sequence=37, command=8, retcode=0, key=KEY):
    if isinstance(payload, dict):
        payload = json.dumps(payload).encode()
    return pack_message(TuyaMessage(sequence, command, retcode, payload, 0, True,
                                   H.PREFIX_6699_VALUE, b"fixture-iv12"), hmac_key=key)


class LanResponsesTest(unittest.TestCase):
    def setUp(self):
        self.clock = Clock()
        self.sock = FakeSocket()
        self.decoder = Mock(side_effect=lambda payload: json.loads(payload))
        self.device = types.SimpleNamespace(id=DEVICE_ID, version=3.5, socket=self.sock,
                                            local_key=KEY, parent=None, _decode_payload=self.decoder)
        def readiness(readable, writable, exceptional, timeout):
            if self.sock.chunks:
                return readable, [], []
            self.clock.now += timeout
            return [], [], []
        self.ready = Mock(side_effect=readiness)
        self.reader = LanResponseReader(self.device, clock=self.clock.time, select_fn=self.ready)

    def test_partial_header_and_ciphertext_survive_separate_100ms_windows(self):
        packet = frame({"devId": DEVICE_ID, "dps": {"20": True}})
        self.sock.chunks = [packet[:3]]
        self.assertEqual([], self.reader.drain_until(0.1))
        self.assertEqual(3, self.reader.pending_bytes)
        self.sock.chunks = [packet[3:21]]
        self.assertEqual([], self.reader.drain_until(0.2))
        self.assertEqual(21, self.reader.pending_bytes)
        self.sock.chunks = [packet[21:]]
        responses = self.reader.drain_until(0.3)
        self.assertEqual({"devId": DEVICE_ID, "dps": {"20": True}}, responses[0]["response"])
        self.assertEqual(0, self.reader.pending_bytes)
        self.assertTrue(all(flags == socket.MSG_DONTWAIT for _size, flags in self.sock.receives))
        self.assertLessEqual(max(call.args[3] for call in self.ready.call_args_list), 0.05)

    def test_multiple_authenticated_frames_empty_ack_and_independent_sequences(self):
        self.sock.chunks = [frame(sequence=4000) + frame({}, sequence=2) + frame({"dps": {"22": 1000}}, sequence=900)]
        callback = Mock()
        messages = self.reader.drain_until(0.1, on_message=callback)
        self.assertEqual([4000, 2, 900], [message["metadata"]["seq"] for message in messages])
        self.assertTrue(messages[0]["metadata"]["emptyAck"])
        self.assertIsNone(messages[0]["response"])
        self.assertEqual({}, messages[1]["response"])
        self.assertFalse(messages[2]["metadata"]["emptyAck"])
        self.assertEqual(3, callback.call_count)
        self.assertEqual(2, self.decoder.call_count)

    def test_installed_decoder_handles_version_header_and_nested_dps_without_network(self):
        device = Device(DEVICE_ID, address="192.168.1.9", local_key=KEY.decode(), version=3.5)
        device.disabledetect = True
        device.socket = self.sock
        try:
            reader = LanResponseReader(device, clock=self.clock.time, select_fn=self.ready)
            payload = H.PROTOCOL_35_HEADER + json.dumps({"data": {"devId": DEVICE_ID, "dps": {"23": 332}}}).encode()
            self.sock.chunks = [frame(payload)]
            self.assertEqual({"23": 332}, reader.drain_until(0.1)[0]["response"]["dps"])
        finally:
            device.socket = None

    def test_nonfinite_deadline_cannot_create_an_unbounded_drain(self):
        for invalid in (float("inf"), float("nan"), None):
            with self.assertRaisesRegex(LanResponseError, "lan_invalid_deadline"):
                self.reader.drain_until(invalid)
        self.ready.assert_not_called()

    def test_gcm_tamper_and_wrong_key_never_reach_decoder(self):
        good = frame({"dps": {"20": True}})
        tampered = bytearray(good)
        tampered[-5] ^= 0x01
        for packet in (bytes(tampered), frame({"dps": {"20": True}}, key=b"otherkey12345678")):
            with self.subTest(packet_length=len(packet)):
                self.setUp()
                self.sock.chunks = [packet]
                with self.assertRaises(LanResponseError):
                    self.reader.drain_until(0.1)
                self.decoder.assert_not_called()
                with self.assertRaisesRegex(LanResponseError, "lan_reader_failed"):
                    self.reader.drain_until(0.2)

    def test_invalid_prefix_suffix_oversize_and_short_length_are_rejected(self):
        packet = frame({"dps": {"20": True}})
        corruptions = (
            b"BAD!" + packet[4:], packet[:-4] + b"BAD!",
            struct.pack(H.MESSAGE_HEADER_FMT_6699, H.PREFIX_6699_VALUE, 0, 1, 8, 70000),
            struct.pack(H.MESSAGE_HEADER_FMT_6699, H.PREFIX_6699_VALUE, 0, 1, 8, 20),
        )
        for raw in corruptions:
            with self.subTest(length=len(raw)):
                self.setUp()
                self.sock.chunks = [raw]
                with self.assertRaises(LanResponseError):
                    self.reader.drain_until(0.1)
                self.decoder.assert_not_called()
                self.assertLessEqual(self.reader.pending_bytes, self.reader.MAX_BUFFER)

    def test_retcode_error_is_authenticated_and_metadata_contains_no_payload(self):
        self.sock.chunks = [frame({"secret": "do-not-print"}, retcode=4)]
        with self.assertRaisesRegex(LanResponseError, "lan_retcode_error") as caught:
            self.reader.drain_until(0.1)
        self.assertEqual(4, caught.exception.metadata["retcode"])
        self.assertNotIn("do-not-print", str(caught.exception) + json.dumps(caught.exception.metadata))
        self.decoder.assert_not_called()

    def test_wrong_identity_malformed_json_and_device_error_are_rejected(self):
        payloads = ({"gwId": "other_device"}, {"data": {"devId": "other_device", "dps": {"20": True}}},
                    b"not-json", {"Err": "914", "Error": "secret"}, {"dps": {"secret": True}})
        for payload in payloads:
            with self.subTest(payload_type=type(payload).__name__):
                self.setUp()
                self.sock.chunks = [frame(payload)]
                output = io.StringIO()
                with patch.object(sys, "stdout", output), self.assertRaises(LanResponseError) as caught:
                    self.reader.drain_until(0.1)
                self.assertEqual("", output.getvalue())
                self.assertNotIn("secret", str(caught.exception))

    def test_cancel_deadline_and_nonblocking_race_preserve_partial_frame(self):
        packet = frame({"dps": {"20": True}})
        self.sock.chunks = [packet[:10], BlockingIOError()]
        self.reader.drain_until(0.1)
        self.assertEqual(10, self.reader.pending_bytes)
        with self.assertRaisesRegex(DiagnosticError, "cancelled"):
            self.reader.drain_until(0.2, cancelled=lambda: True)
        self.assertEqual(10, self.reader.pending_bytes)
        self.sock.chunks = [packet[10:]]
        self.assertEqual(1, len(self.reader.drain_until(0.2)))
        self.assertEqual(3.0, self.sock.gettimeout())

    def test_real_socket_readiness_race_does_not_wait_for_python_socket_timeout(self):
        receiving, peer = socket.socketpair()
        try:
            receiving.settimeout(0.3)
            self.device.socket = receiving
            # Simulate select readiness becoming stale before recv, with no data.
            reader = LanResponseReader(self.device, select_fn=lambda read, _w, _e, _t: (read, [], []))
            started = time.monotonic()
            self.assertEqual([], reader.drain_until(started + 0.03))
            self.assertLess(time.monotonic() - started, 0.15)
            self.assertEqual(0.3, receiving.gettimeout())
        finally:
            receiving.close()
            peer.close()

    def test_peer_close_connection_change_and_flood_stop_without_writes(self):
        self.sock.chunks = [b""]
        with self.assertRaisesRegex(LanResponseError, "lan_peer_closed"):
            self.reader.drain_until(0.1)
        self.setUp()
        self.device.socket = FakeSocket()
        with self.assertRaisesRegex(LanResponseError, "lan_connection_changed"):
            self.reader.drain_until(0.1)
        self.setUp()
        self.sock.chunks = [frame() * 513]
        with self.assertRaisesRegex(LanResponseError, "lan_message_limit"):
            self.reader.drain_until(0.1)
        self.assertLessEqual(self.reader.pending_bytes, self.reader.MAX_BUFFER)
        self.assertFalse(hasattr(self.sock, "sendall"))


if __name__ == "__main__":
    unittest.main()
