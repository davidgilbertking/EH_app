"""Return one exact, reported white diagnostic scene to verified Action.

One control write only. Leave DP25 dormant; never restore scenes or retry writes.
"""

import argparse
import importlib
import json
import re
import time
from datetime import datetime, timezone

from configure import (PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline,
                       device_id, quiet_library, read_private, save_private, utc_now)
from lan_responses import LanResponseReader
from read_state import inspect_status
from timed_white_probe import ProbeInterrupted, exclusive_probe, interruptible
from white_profile import load_calibration, validated_settings, white_from_status


def reported_white_scene(directory, name, selected):
    if not isinstance(name, str) or re.fullmatch(r"native-scene-probe-[A-Za-z0-9_-]+\.json", name) is None:
        raise DiagnosticError("invalid_probe_report_name")
    report = read_private(directory / name)
    if report.get("schema_version") != 1 or report.get("device_id") != selected or str(report.get("protocol")) != "3.5":
        raise DiagnosticError("probe_report_device_or_protocol_mismatch")
    command = report.get("command")
    if not isinstance(command, dict) or set(command) != {"21", "25"} or command["21"] != "scene":
        raise DiagnosticError("invalid_probe_scene_command")
    payload = command["25"]
    if not isinstance(payload, str) or re.fullmatch(r"[0-9a-fA-F]+", payload) is None:
        raise DiagnosticError("invalid_probe_scene_payload")
    if not 28 <= len(payload) <= 210 or (len(payload) - 2) % 26 or int(payload[:2], 16) > 7:
        raise DiagnosticError("invalid_probe_scene_payload")
    for start in range(2, len(payload), 26):
        unit = payload[start:start + 26]
        switch, gradient, mode = (int(unit[index:index + 2], 16) for index in (0, 2, 4))
        h, s, v, bright, temperature = (int(unit[index:index + 4], 16) for index in (6, 10, 14, 18, 22))
        if switch > 100 or gradient > 100 or mode not in (0, 2) or (h, s, v) != (0, 0, 0):
            raise DiagnosticError("probe_scene_not_white_only")
        if not 10 <= bright <= 1000 or not 0 <= temperature <= 1000:
            raise DiagnosticError("probe_scene_white_value_out_of_range")
    return payload


def restore(expected_id, probe_report, directory=PRIVATE_DIRECTORY, device_factory=None,
            response_reader_factory=LanResponseReader, clock=time.monotonic, cancelled=lambda: False):
    result = {"ok": False, "commandsAttempted": 0, "sendOutcome": "not_attempted",
              "storedActionConfirmedOnWriter": False, "readback_matched": False,
              "physical_finish_confirmed": False, "visual_verification_required": True,
              "receivedResponses": []}
    settings, started, queried = None, clock(), False
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ")

    def check():
        if cancelled():
            raise ProbeInterrupted()
        if clock() - started >= 20:
            raise DeadlineExceeded()

    def record(message):
        check()
        result["receivedResponses"].append(dict(message, receivedMs=round((clock() - started) * 1000, 3)))
        response = message.get("response")
        if not queried or not isinstance(response, dict):
            return
        dps = response.get("dps")
        if dps is None and isinstance(response.get("data"), dict):
            dps = response["data"].get("dps")
        if not isinstance(dps, dict):
            return
        inspect_status(response, selected)
        if all(key in dps for key in target):
            result["storedActionConfirmedOnWriter"] = all(type(dps[key]) is type(value) and dps[key] == value for key, value in target.items())

    try:
        settings, schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        payload = reported_white_scene(directory, probe_report, selected)
        profiles = load_calibration(directory, selected, schema)["white_profiles"]
        if "action" not in profiles or profiles["action"].get("replay_verified") is not True:
            raise DiagnosticError("action_not_visually_verified")
        target = profiles["action"]["dps"]
        result.update({"profile": "action", "targetWhiteValues": target, "probeReport": probe_report})
        with exclusive_probe(directory), interruptible(), quiet_library(), deadline(20):
            check()
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
                original = device.status()
                inspect_status(original, selected)
                dps = original["dps"] if "dps" in original else original["data"]["dps"]
                if dps.get("20") is not True or dps.get("21") != "scene" or dps.get("25") != payload:
                    raise DiagnosticError("current_scene_does_not_match_probe")
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                active_socket = device.socket
                filename = "restore-probe-before-" + stamp + ".json"
                save_private(directory, filename, {"schema_version": 1, "device_id": selected, "version": "3.5",
                             "captured_at": utc_now(), "state": original})
                result["originalSnapshot"] = filename
                device.set_socketRetryLimit(0)
                device.set_sendWait(None)
                reader = response_reader_factory(device, clock=clock)
                check()
                result.update({"commandsAttempted": 1, "sendOutcome": "unknown"})
                # Prepare stored white values before requesting white mode. This
                # is one packet, not a guarantee of firmware processing order.
                command = {"20": True, "22": target["22"], "23": target["23"], "21": "white"}
                if device.set_multiple_values(command, nowait=True) is not None or device.socket is not active_socket:
                    raise DiagnosticError("write_outcome_unknown")
                result["sendOutcome"] = "sent_without_ack"
                check()
                reader.drain_until(clock() + 1, cancelled=cancelled, on_message=record)
                check()
                if device.socket is not active_socket:
                    raise DiagnosticError("probe_connection_changed")
                queried = True
                if device.status(nowait=True) is not None:
                    raise DiagnosticError("status_query_failed")
                reader.drain_until(clock() + 2, cancelled=cancelled, on_message=record)
                check()
                if not result["storedActionConfirmedOnWriter"]:
                    raise DiagnosticError("stored_action_unconfirmed_on_writer")
            finally:
                device.close()
            check()
            observer = connect(False)
            try:
                result["storedWhiteValues"] = white_from_status(observer.status(), selected, schema)
            finally:
                observer.close()
            check()
        result["readback_matched"] = result["storedWhiteValues"] == target
        result["ok"] = result["readback_matched"]
        if not result["ok"]:
            result["error"] = "stored_action_mismatch"
    except DiagnosticError as error:
        result["error"] = str(error)
        if getattr(error, "metadata", None):
            result["responseError"] = error.metadata
    except (ProbeInterrupted, KeyboardInterrupt):
        result["error"] = "cancelled"
    except DeadlineExceeded:
        result["error"] = "restore_deadline_exceeded"
    except Exception:
        result["error"] = "restore_transport_failed"
    if settings is not None:
        filename = "restore-probe-white-" + stamp + ".json"
        report = dict(result, schema_version=1, captured_at=utc_now(), device_id=settings["device_id"], protocol="3.5")
        try:
            save_private(directory, filename, report)
            result["saved_to"] = "storage/app/private/lighting/" + filename
        except Exception:
            result.update({"ok": False, "report_error": "restore_report_save_failed"})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--expect-device-id", required=True)
    parser.add_argument("--probe-report", required=True)
    args = parser.parse_args(argv)
    result = restore(args.expect_device_id, args.probe_report)
    output = {key: value for key, value in result.items() if key != "receivedResponses"}
    output["responsesReceived"] = len(result["receivedResponses"])
    print(json.dumps(output, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
