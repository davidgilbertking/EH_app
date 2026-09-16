"""Bounded native white-scene experiment with one explicit white exit.

Timing bytes are experimental firmware parameters, not promised durations.
Repeated target units are not proof of one-shot playback or a physical hold.
"""

import argparse
import importlib
import json
import time
from contextlib import contextmanager
from datetime import datetime, timezone

from configure import (PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline,
                       device_id, quiet_library, read_private, save_private, utc_now)
from lan_responses import LanResponseReader
from native_scene_probe import preserve_scene_id, scene_unit, state_dps
from timed_white_probe import ProbeInterrupted, exclusive_probe, interruptible
from white_profile import load_calibration, validated_settings, white_from_status


def prepare_units(directory, selected, source, target, timing_byte):
    if type(timing_byte) is not int or not 1 <= timing_byte <= 100:
        raise DiagnosticError("invalid_timing_byte")
    # Reuse the existing complete schema/white-component checks. The helper's
    # millisecond label is not used to interpret these raw experimental bytes.
    source_unit = scene_unit(directory, selected, source, 500)
    target_unit = scene_unit(directory, selected, target, 500)
    entries = read_private(directory / "schema.json")["schema"]["functions"]
    schema = json.loads(next(entry["values"] for entry in entries if str(entry.get("dp_id")) == "25"))["scene_units"]
    for byte in (100, timing_byte):
        for field in ("unit_switch_duration", "unit_gradient_duration"):
            limits = schema[field]
            if not limits["min"] <= byte <= limits["max"] or (byte - limits["min"]) % limits["step"]:
                raise DiagnosticError("timing_byte_out_of_schema")
    return "6464" + source_unit[4:] + (f"{timing_byte:02x}{timing_byte:02x}" + target_unit[4:]) * 7


def probe(from_profile, to_profile, expected_id, timing_byte=1, stop_after_ms=9000,
          directory=PRIVATE_DIRECTORY, device_factory=None, response_reader_factory=LanResponseReader,
          clock=time.monotonic, cancelled=lambda: False):
    result = {"ok": False, "commandsAttempted": 0, "sceneAttempted": False, "exitAttempted": False,
              "sceneSendOutcome": "not_attempted", "exitSendOutcome": "not_attempted",
              "storedTargetConfirmedOnWriter": False, "readback_matched": False,
              "stoppedConfirmed": False, "mayStillLoop": False,
              "one_shot_verified": False, "physical_finish_confirmed": False,
              "visual_verification_required": True, "receivedResponses": []}
    settings, payload, scene_started, owned = None, None, None, False
    query_started, phase, reconcile_attempted = False, "preflight", False
    exit_flow_started, native_completed = False, False
    started = clock()
    hard_stop = started + 45
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ")
    # Keep the existing narrow restore tool usable after an interrupted process.
    filename = "native-scene-probe-" + stamp + ".json"

    def check_cancelled(ignore=False):
        if not ignore and cancelled():
            raise ProbeInterrupted()
        if clock() >= hard_stop:
            raise DeadlineExceeded()

    @contextmanager
    def bounded(seconds):
        remaining = hard_stop - clock()
        if remaining <= 0:
            raise DeadlineExceeded()
        with deadline(min(seconds, remaining)):
            yield

    def save_report():
        result["mayStillLoop"] = result["sceneAttempted"] and not result["stoppedConfirmed"]
        report = dict(result, schema_version=1, captured_at=utc_now(), device_id=settings["device_id"],
                      protocol="3.5", experiment="native_white_once")
        if payload is not None:
            report["command"] = {"25": payload, "21": "scene"}
        save_private(directory, filename, report)
        result["saved_to"] = "storage/app/private/lighting/" + filename

    def record(message):
        nonlocal owned
        result["receivedResponses"].append(dict(message, phase=phase, receivedMs=round((clock() - started) * 1000, 3)))
        response = message.get("response")
        if not isinstance(response, dict):
            return
        nested = response.get("data")
        if "dps" not in response and not (isinstance(nested, dict) and isinstance(nested.get("dps"), dict)):
            return
        dps = state_dps(response, selected)
        if phase == "owned_wait":
            expected = {"20": True, "21": "scene", "25": payload}
            if any(key in dps and (type(dps[key]) is not type(value) or dps[key] != value) for key, value in expected.items()):
                owned = False
        if phase == "exit_read" and query_started and all(key in dps for key in target):
            result["storedTargetConfirmedOnWriter"] = all(type(dps[key]) is type(value) and dps[key] == value for key, value in target.items())

    def error_code(error):
        if isinstance(error, DiagnosticError):
            return str(error)
        if isinstance(error, (ProbeInterrupted, KeyboardInterrupt)):
            return "cancelled"
        if isinstance(error, DeadlineExceeded):
            return "probe_deadline_exceeded"
        return "probe_transport_failed"

    def remember_error(error, field="error"):
        if field not in result:
            result[field] = error_code(error)

    try:
        if {from_profile, to_profile} != {"action", "dark"}:
            raise DiagnosticError("invalid_profile_pair")
        if type(stop_after_ms) is not int or not 7000 <= stop_after_ms <= 20000:
            raise DiagnosticError("invalid_stop_after")
        settings, white_schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        if "scene" not in white_schema["21"]["range"]:
            raise DiagnosticError("scene_mode_not_in_schema")
        profiles = load_calibration(directory, selected, white_schema)["white_profiles"]
        if any(name not in profiles or profiles[name].get("replay_verified") is not True for name in (from_profile, to_profile)):
            raise DiagnosticError("profile_not_visually_verified")
        source, target = profiles[from_profile]["dps"], profiles[to_profile]["dps"]
        units = prepare_units(directory, selected, source, target, timing_byte)
        result.update({"fromProfile": from_profile, "toProfile": to_profile, "targetWhiteValues": target,
                       "timingByte": timing_byte, "stopAfterMs": stop_after_ms, "sceneUnitCount": 8})
        with exclusive_probe(directory), interruptible(), quiet_library():
            tinytuya = importlib.import_module("tinytuya")
            if tinytuya.__version__ != "1.20.0":
                raise DiagnosticError("tinytuya_version_mismatch")
            factory = device_factory or tinytuya.Device

            def connect(persistent=True):
                device = factory(selected, address=settings["ip"], local_key=settings["local_key"],
                    version=3.5, dev_type="default", persist=persistent, connection_timeout=3,
                    connection_retry_limit=1, connection_retry_delay=0, max_simultaneous_dps=0)
                device.disabledetect = True
                device.set_retry(False)
                return device

            def arm(device):
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                device.set_socketRetryLimit(0)
                device.set_sendWait(None)
                return response_reader_factory(device, clock=clock), device.socket

            def run_native():
                nonlocal payload, scene_started, phase
                check_cancelled()
                device = connect()
                try:
                    with bounded(5):
                        original = device.status()
                        if white_from_status(original, selected, white_schema) != source:
                            raise DiagnosticError("source_profile_mismatch")
                        payload = preserve_scene_id(state_dps(original, selected).get("25"), units)
                        backup = "native-white-once-before-" + stamp + ".json"
                        save_private(directory, backup, {"schema_version": 1, "device_id": selected, "version": "3.5",
                                     "captured_at": utc_now(), "state": original})
                        result["originalSnapshot"] = backup
                        reader, active_socket = arm(device)
                    with bounded(stop_after_ms / 1000 + 3):
                        check_cancelled()
                        result.update({"sceneAttempted": True, "commandsAttempted": 1, "sceneSendOutcome": "unknown"})
                        save_report()  # Durable command and ownership evidence before sending.
                        scene_started = clock()
                        result["sceneSentMs"] = round((scene_started - started) * 1000, 3)
                        phase = "native"
                        if device.set_multiple_values({"25": payload, "21": "scene"}, nowait=True) is not None or device.socket is not active_socket:
                            raise DiagnosticError("scene_write_outcome_unknown")
                        result["sceneSendOutcome"] = "sent_without_ack"
                        check_cancelled()
                        reader.drain_until(scene_started + stop_after_ms / 1000 - 3,
                                           cancelled=cancelled, on_message=record)
                        check_cancelled()
                finally:
                    device.close()

            def exit_once(cleanup=False):
                nonlocal owned, phase, query_started, exit_flow_started
                if result["exitAttempted"]:
                    return
                exit_flow_started = True
                check_cancelled(cleanup)
                device = connect()
                try:
                    with bounded(5):
                        # The old connection is already closed: never compete
                        # with another LAN client while obtaining fresh state.
                        actual = device.status()
                        dps = state_dps(actual, selected)
                        if all(key in dps and type(dps[key]) is type(value) and dps[key] == value for key, value in target.items()):
                            result["alreadyAtTarget"] = True
                            return
                        if dps.get("20") is not True or dps.get("21") != "scene" or dps.get("25") != payload:
                            raise DiagnosticError("owned_scene_not_confirmed")
                        owned = True
                        reader, active_socket = arm(device)
                    with bounded(10):
                        phase = "owned_wait"
                        if not cleanup:
                            reader.drain_until(scene_started + stop_after_ms / 1000,
                                               cancelled=cancelled, on_message=record)
                        check_cancelled(cleanup)
                        if not owned or device.socket is not active_socket:
                            raise DiagnosticError("owned_scene_changed")
                        result["exitPlanned"] = True
                        # The durable scene report already exists. Keep disk
                        # I/O out of the stop path, including a blocking save
                        # that could consume the reserved cleanup deadline.
                        result["exitSentMs"] = round((clock() - started) * 1000, 3)
                        result["exitLatenessMs"] = max(0, round((clock() - scene_started) * 1000 - stop_after_ms, 3))
                        command = {"20": True, "22": target["22"], "23": target["23"], "21": "white"}
                        result.update({"exitAttempted": True, "commandsAttempted": 2, "exitSendOutcome": "unknown"})
                        if device.set_multiple_values(command, nowait=True) is not None or device.socket is not active_socket:
                            raise DiagnosticError("exit_write_outcome_unknown")
                        result["exitSendOutcome"] = "sent_without_ack"
                        phase = "exit_read"
                        callback = (lambda: False) if cleanup else cancelled
                        reader.drain_until(clock() + 1, cancelled=callback, on_message=record)
                        check_cancelled(cleanup)
                        if device.socket is not active_socket:
                            raise DiagnosticError("exit_connection_changed")
                        query_started = True
                        if device.status(nowait=True) is not None:
                            raise DiagnosticError("exit_status_query_failed")
                        reader.drain_until(clock() + 4, cancelled=callback, on_message=record)
                finally:
                    device.close()

            def reconcile():
                nonlocal reconcile_attempted
                if reconcile_attempted:
                    return
                reconcile_attempted = True
                with bounded(5):
                    observer = connect(False)
                    try:
                        actual = observer.status()
                        result["storedWhiteValues"] = white_from_status(actual, selected, white_schema)
                        result["readback_matched"] = result["storedWhiteValues"] == target
                        result["stoppedConfirmed"] = True
                    finally:
                        observer.close()

            try:
                run_native()
                native_completed = True
                exit_once()
            except (Exception, ProbeInterrupted, KeyboardInterrupt, DeadlineExceeded) as error:
                remember_error(error)
                if result["sceneAttempted"] and not result["exitAttempted"] and (not exit_flow_started or error_code(error) == "cancelled"):
                    try:
                        exit_once(cleanup=True)
                    except (Exception, ProbeInterrupted, KeyboardInterrupt, DeadlineExceeded) as cleanup_error:
                        remember_error(cleanup_error, "cleanupError")
            # Independent read may resolve an uncertain exit without resending.
            if result["exitAttempted"] or result.get("alreadyAtTarget"):
                try:
                    reconcile()
                except (Exception, ProbeInterrupted, KeyboardInterrupt, DeadlineExceeded) as error:
                    remember_error(error, "reconciliationError")
            if native_completed and result["exitAttempted"] and result["readback_matched"] and result.get("error") not in (None, "cancelled"):
                result["exitWarning"] = result.pop("error")
                result["exitOutcomeReconciled"] = True
            result["ok"] = result["readback_matched"] and "error" not in result
            if not result["ok"] and "error" not in result:
                result["error"] = "stored_target_unconfirmed"
    except (Exception, ProbeInterrupted, KeyboardInterrupt, DeadlineExceeded) as error:
        remember_error(error)
    if settings is not None:
        try:
            save_report()
        except Exception:
            result.update({"ok": False, "report_error": "probe_report_save_failed"})
    result["mayStillLoop"] = result["sceneAttempted"] and not result["stoppedConfirmed"]
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--from-profile", required=True, choices=("action", "dark"))
    parser.add_argument("--to-profile", required=True, choices=("action", "dark"))
    parser.add_argument("--expect-device-id", required=True)
    parser.add_argument("--timing-byte", type=int, default=1)
    parser.add_argument("--stop-after-ms", type=int, default=9000)
    args = parser.parse_args(argv)
    result = probe(args.from_profile, args.to_profile, args.expect_device_id, args.timing_byte, args.stop_after_ms)
    output = {key: value for key, value in result.items() if key != "receivedResponses"}
    output["responsesReceived"] = len(result["receivedResponses"])
    print(json.dumps(output, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
