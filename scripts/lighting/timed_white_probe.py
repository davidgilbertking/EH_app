"""Diagnostic paced white ramp; transport timings never prove physical smoothness.

Establish source once, settle, send prevalidated native DP28 targets, settle,
commit target once, and read stored state through a fresh connection. No retry,
reconnection during writes, fallback write, or automatic restore is permitted.
"""

import argparse
import fcntl
import importlib
import json
import math
import os
import signal
import time
from contextlib import contextmanager
from datetime import datetime, timezone

from configure import (
    PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline, device_id,
    prepare_private_directory, quiet_library, save_private, utc_now,
)
from native_white_probe import native_payload
from lan_responses import LanResponseReader
from white_profile import PROFILES, load_calibration, validated_settings, white_from_status


class ProbeInterrupted(BaseException):
    pass


@contextmanager
def exclusive_probe(directory):
    directory = prepare_private_directory(directory)
    descriptor = os.open(directory / "probe.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        os.fchmod(descriptor, 0o600)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise DiagnosticError("probe_already_running") from None
        yield
    finally:
        os.close(descriptor)


@contextmanager
def interruptible():
    previous = {number: signal.getsignal(number) for number in (signal.SIGINT, signal.SIGTERM)}

    def cancel(_number, _frame):
        raise ProbeInterrupted()

    for number in previous:
        signal.signal(number, cancel)
    try:
        yield
    finally:
        for number, handler in previous.items():
            signal.signal(number, handler)


def prepare_frames(directory, selected, source, target, duration_ms, tick_ms, curve, native_mode="gradient"):
    if type(duration_ms) is not int or not 500 <= duration_ms <= 30000:
        raise DiagnosticError("invalid_duration")
    if type(tick_ms) is not int or not 100 <= tick_ms <= 500 or curve not in ("smoothstep", "linear", "perceptual"):
        raise DiagnosticError("invalid_tick_or_curve")
    count = duration_ms // tick_ms
    if not 5 <= count <= 300:
        raise DiagnosticError("frame_count_out_of_bounds")
    # A remainder lengthens the final slot instead of creating a short catch-up slot.
    offsets = [index * tick_ms for index in range(1, count)] + [duration_ms]
    frames = []
    for offset in offsets:
        fraction = offset / duration_ms
        weight = fraction if curve == "linear" else fraction * fraction * (3 - 2 * fraction)
        if curve == "perceptual":
            weight = fraction ** 2.2  # Experimental easing, not measured lamp gamma.
        white = {"20": True, "21": "white"}
        for dp in ("22", "23"):
            white[dp] = round(source[dp] + (target[dp] - source[dp]) * weight)
        # Check every prospective native payload before opening any device socket.
        frames.append((offset / 1000, native_payload(directory, selected, white, change_mode=native_mode)))
    return frames


def probe(from_profile, to_profile, expected_id, duration_ms=4000, tick_ms=100,
          curve="smoothstep", directory=PRIVATE_DIRECTORY, device_factory=None,
          clock=time.monotonic, sleep=time.sleep, cancelled=lambda: False, native_mode="gradient",
          receive_responses=False, response_reader_factory=LanResponseReader, require_source=False):
    result = {"ok": False, "commandsAttempted": 0, "nativeFramesSent": 0,
              "sendOutcome": "not_attempted", "storedWhiteValues": None,
              "readback_matched": False, "visual_verification_required": True,
              "physical_finish_confirmed": False, "timings": [],
              "receiveResponses": receive_responses, "receivedResponses": [],
              "storedTargetConfirmedOnWriter": False, "requireSource": require_source}
    settings = None
    reader = None
    commit_sent = False
    started, ramp_started = clock(), None

    def check_cancelled():
        if cancelled():
            raise ProbeInterrupted()

    def wait_until(target_time):
        if reader is not None:
            check_cancelled()
            reader.drain_until(target_time, cancelled=cancelled, on_message=record_response)
            check_cancelled()
            return
        while True:
            check_cancelled()
            remaining = target_time - clock()
            if remaining <= 0:
                return
            sleep(min(0.05, remaining))

    def record_response(message):
        entry = dict(message["metadata"], receivedMs=round((clock() - started) * 1000, 3))
        response = message.get("response")
        if isinstance(response, dict):
            dps = response.get("dps")
            if dps is None and isinstance(response.get("data"), dict):
                dps = response["data"].get("dps")
            if isinstance(dps, dict):
                entry["dpsIds"] = sorted(dps)
                if all(key in dps for key in ("20", "21", "22", "23")):
                    white = white_from_status(response, selected, white_schema)
                    entry["storedWhiteValues"] = white
                    if commit_sent and white == target:
                        result["storedTargetConfirmedOnWriter"] = True
        result["receivedResponses"].append(entry)

    def send(device, dps, stage, planned=None):
        check_cancelled()
        begun = clock()
        result["commandsAttempted"] += 1
        result["sendOutcome"] = "unknown"
        record = {"stage": stage, "startedMs": round((begun - started) * 1000, 3),
                  "plannedRampMs": None if planned is None else round(planned * 1000, 3)}
        result["timings"].append(record)
        try:
            response = device.set_multiple_values(dps, nowait=True)
        finally:
            record["sendMs"] = round((clock() - begun) * 1000, 3)
        if response is not None:
            raise DiagnosticError("write_outcome_unknown")
        result["sendOutcome"] = "sent_without_ack"
        check_cancelled()
        if clock() - begun > min(0.250, 2.5 * tick_ms / 1000):
            raise DiagnosticError("send_too_slow")
        return begun

    try:
        if from_profile not in PROFILES or to_profile not in PROFILES or from_profile == to_profile:
            raise DiagnosticError("invalid_profile_pair")
        settings, white_schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        profiles = load_calibration(directory, selected, white_schema)["white_profiles"]
        if any(name not in profiles or profiles[name].get("replay_verified") is not True for name in (from_profile, to_profile)):
            raise DiagnosticError("profile_not_visually_verified")
        source, target = profiles[from_profile]["dps"], profiles[to_profile]["dps"]
        frames = prepare_frames(directory, selected, source, target, duration_ms, tick_ms, curve, native_mode)
        result.update({"fromProfile": from_profile, "toProfile": to_profile, "targetWhiteValues": target,
                       "durationMs": duration_ms, "tickMs": tick_ms, "curve": curve,
                       "nativeMode": native_mode, "plannedFrames": len(frames)})
        with exclusive_probe(directory), interruptible(), quiet_library(), deadline(min(45, duration_ms / 1000 + 25)):
            check_cancelled()
            tinytuya = importlib.import_module("tinytuya")
            if tinytuya.__version__ != "1.20.0":
                raise DiagnosticError("tinytuya_version_mismatch")
            factory = device_factory or tinytuya.Device

            def connect(persistent):
                device = factory(selected, address=settings["ip"], local_key=settings["local_key"],
                                 version=3.5, dev_type="default", persist=persistent, connection_timeout=3,
                                 connection_retry_limit=1, connection_retry_delay=0, max_simultaneous_dps=0)
                device.disabledetect = True
                device.set_retry(False)
                return device

            device = connect(True)
            try:
                actual = white_from_status(device.status(), selected, white_schema)  # Reject colour/scene/off.
                if require_source and actual != source:
                    raise DiagnosticError("source_profile_mismatch")
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                device.set_socketRetryLimit(0)
                device.set_sendWait(None)
                if receive_responses:
                    reader = response_reader_factory(device, clock=clock)
                if not require_source:
                    send(device, dict(source), "source")
                    wait_until(clock() + 1.0)
                ramp_started, previous_send = clock(), None
                for offset, payload in frames:
                    planned = ramp_started + offset
                    wait_until(planned)
                    now = clock()
                    lateness = now - planned
                    if lateness > min(0.250, 2.5 * tick_ms / 1000):
                        raise DiagnosticError("schedule_too_late")
                    # Tolerate ordinary jitter, never emit a rapid catch-up burst.
                    if previous_send is not None and now - previous_send < tick_ms / 1000 * 0.75:
                        raise DiagnosticError("catch_up_burst_prevented")
                    previous_send = send(device, {"28": payload}, "native", offset)
                    result["timings"][-1]["latenessMs"] = round(lateness * 1000, 3)
                    result["nativeFramesSent"] += 1
                result["scheduledRampElapsedMs"] = round((clock() - ramp_started) * 1000, 3)
                wait_until(clock() + 1.0)
                send(device, dict(target), "target_commit")
                if reader is not None:
                    commit_sent = True
                    wait_until(clock() + 0.5)
                    # One read-only request; status(nowait=True) cannot perform
                    # TinyTuya's write fallback, and socket retries remain zero.
                    check_cancelled()
                    result["finalStatusQueryAttempted"] = True
                    if device.status(nowait=True) is not None:
                        raise DiagnosticError("final_status_query_failed")
                    wait_until(clock() + 2.0)
                    # Some lamps return partial updates here. The independent
                    # read below decides the stored outcome without a resend.
            finally:
                device.close()
            check_cancelled()
            observer = connect(False)
            try:
                result["storedWhiteValues"] = white_from_status(observer.status(), selected, white_schema)
            finally:
                observer.close()
            check_cancelled()
        result["readback_matched"] = result["storedWhiteValues"] == target
        result["ok"] = result["readback_matched"]
        if not result["ok"]:
            result["error"] = "stored_target_mismatch"
    except DiagnosticError as error:
        result["error"] = str(error)
        if getattr(error, "metadata", None):
            result["responseError"] = error.metadata
    except (ProbeInterrupted, KeyboardInterrupt):
        result["error"] = "cancelled"
    except DeadlineExceeded:
        result["error"] = "probe_deadline_exceeded"
    except Exception:
        result["error"] = "probe_transport_failed"
    if settings is not None:
        filename = "timed-white-probe-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ") + ".json"
        report = dict(result, schema_version=1, captured_at=utc_now(), device_id=settings["device_id"], protocol="3.5")
        try:
            save_private(directory, filename, report)
            result["saved_to"] = "storage/app/private/lighting/" + filename
        except Exception:
            result.update({"ok": False, "report_error": "probe_report_save_failed"})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--from-profile", required=True, choices=tuple(PROFILES))
    parser.add_argument("--to-profile", required=True, choices=tuple(PROFILES))
    parser.add_argument("--expect-device-id", required=True)
    parser.add_argument("--duration-ms", type=int, default=4000)
    parser.add_argument("--tick-ms", type=int, default=100)
    parser.add_argument("--curve", choices=("smoothstep", "linear", "perceptual"), default="smoothstep")
    parser.add_argument("--native-mode", choices=("direct", "gradient"), default="gradient")
    parser.add_argument("--receive-responses", action="store_true",
                        help="Read authenticated replies between frames and confirm final stored target")
    parser.add_argument("--require-source", action="store_true",
                        help="Require the current calibrated source and skip the initial source write")
    args = parser.parse_args(argv)
    result = probe(args.from_profile, args.to_profile, args.expect_device_id,
                   args.duration_ms, args.tick_ms, args.curve, native_mode=args.native_mode,
                   receive_responses=args.receive_responses, require_source=args.require_source)
    # Full per-command timings remain in the private report.
    output = {key: value for key, value in result.items() if key not in ("timings", "receivedResponses")}
    output["responsesReceived"] = len(result["receivedResponses"])
    print(json.dumps(output, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
