"""Replay one exact captured DIY scene from verified Dark white, without retries.

The scene is deliberately left active. No dimming, colour exit, restoration or
visual verification is performed; cancellation cannot undo an uncertain write.
"""

import argparse
import importlib
import json
import time
from datetime import datetime, timezone

from configure import (PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline,
                       quiet_library, save_private, utc_now)
from lan_responses import LanResponseReader
from read_state import inspect_status
from scene_profile import NAMES, context, load_presets
from timed_white_probe import ProbeInterrupted, exclusive_probe, interruptible
from white_profile import load_calibration, validated_settings, white_from_status

DARK = {"20": True, "21": "white", "22": 10, "23": 0}


def replay(alias, expected_id, directory=PRIVATE_DIRECTORY, device_factory=None,
           response_reader_factory=LanResponseReader, clock=time.monotonic,
           cancelled=lambda: False):
    result = {"ok": False, "commandsAttempted": 0, "sendOutcome": "not_attempted",
              "storedTargetConfirmedOnWriter": False, "readback_matched": False,
              "scene_left_active": None, "scene_may_be_active": False,
              "replay_verified": False, "visual_verification_required": True,
              "receivedResponses": []}
    selected, command, original, observed = None, None, None, None
    preflight_scene = None
    started, queried = clock(), False
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ")
    filename = "scene-replay-probe-" + stamp + ".json"

    def check():
        if cancelled():
            raise ProbeInterrupted()
        if clock() - started >= 20:
            raise DeadlineExceeded()

    def dps_from(response):
        inspect_status(response, selected)
        return response["dps"] if "dps" in response else response["data"]["dps"]

    def target_matches(dps):
        return dps.get("20") is True and dps.get("21") == "scene" and dps.get("25") == command["25"]

    def record(message):
        check()
        entry = dict(message["metadata"], receivedMs=round((clock() - started) * 1000, 3))
        response = message.get("response")
        if isinstance(response, dict) and ("dps" in response or
                isinstance(response.get("data"), dict) and "dps" in response["data"]):
            dps = dps_from(response)
            entry["dpsIds"] = sorted(dps)
            if queried and all(key in dps for key in ("20", "21", "25")):
                result["storedTargetConfirmedOnWriter"] = target_matches(dps)
                entry["targetMatched"] = result["storedTargetConfirmedOnWriter"]
        result["receivedResponses"].append(entry)

    def save_report():
        report = dict(result, schema_version=1, device_id=selected, protocol="3.5",
                      captured_at=utc_now(), command=command, observed=observed)
        save_private(directory, filename, report)

    try:
        if alias not in NAMES:
            raise DiagnosticError("invalid_scene_alias")
        selected, scene_schema = context(directory, expected_id)
        settings, white_schema = validated_settings(directory)
        presets = load_presets(directory, selected, scene_schema)["presets"]
        if alias not in presets:
            raise DiagnosticError("scene_preset_not_captured")
        preset = presets[alias]
        profiles = load_calibration(directory, selected, white_schema)["white_profiles"]
        if "dark" not in profiles or profiles["dark"].get("replay_verified") is not True:
            raise DiagnosticError("dark_not_visually_verified")
        if profiles["dark"]["dps"] != DARK:
            raise DiagnosticError("unsupported_dark_profile")
        # Preserve the exact captured string, including case and scene header.
        # Program first, then mode in one packet; firmware atomicity is unknown.
        command = {"25": preset["dps"]["25"], "21": "scene"}
        result.update({"alias": alias, "name": NAMES[alias], "sha256": preset["sha256"]})
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
                device.set_socketNODELAY(True)
                return device

            device = connect(True)
            try:
                original = device.status()
                current = dps_from(original)
                if "20" in current and "21" in current:
                    preflight_scene = current["20"] is True and current["21"] == "scene"
                    result["scene_left_active"] = preflight_scene
                if white_from_status(original, selected, white_schema) != DARK:
                    raise DiagnosticError("current_white_does_not_match_dark")
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                active_socket = device.socket
                backup = "scene-replay-before-" + stamp + ".json"
                save_private(directory, backup, {"schema_version": 1, "device_id": selected,
                             "version": "3.5", "captured_at": utc_now(), "state": original})
                result["originalSnapshot"] = backup
                # This durable pre-write record survives interruption after send.
                # It records uncertainty conservatively, not proof of activation.
                result.update({"scene_left_active": None, "scene_may_be_active": True})
                save_report()
                device.set_socketRetryLimit(0)
                device.set_sendWait(None)
                reader = response_reader_factory(device, clock=clock)
                check()
                if device.socket is not active_socket:
                    raise DiagnosticError("probe_connection_changed")
                result.update({"commandsAttempted": 1, "sendOutcome": "unknown"})
                # TinyTuya 1.20 Device.set_multiple_values(nowait=True) uses one
                # CONTROL with max_simultaneous_dps=0 and skips per-DP fallback.
                response = device.set_multiple_values(command, nowait=True)
                if response is not None or device.socket is not active_socket:
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
            finally:
                device.close()
            # Never overlap LAN clients. A queued ACK or partial writer reply
            # cannot replace the independent full status required for success.
            check()
            observer = connect(False)
            try:
                observed = observer.status()
                dps = dps_from(observed)
                result["readback_matched"] = target_matches(dps)
                result["scene_left_active"] = (dps["20"] is True and dps["21"] == "scene"
                                               if "20" in dps and "21" in dps else None)
            finally:
                observer.close()
            check()
            result["ok"] = result["readback_matched"]
            if not result["ok"]:
                result["error"] = "stored_scene_mismatch"
    except DiagnosticError as error:
        result["error"] = str(error)
    except (ProbeInterrupted, KeyboardInterrupt):
        result["error"] = "cancelled"
    except DeadlineExceeded:
        result["error"] = "replay_deadline_exceeded"
    except Exception:
        result["error"] = "replay_transport_failed"
    if not result["commandsAttempted"]:
        # This flag concerns uncertainty introduced by OUR attempted replay.
        # An unrelated pre-existing scene may still be active after rejection.
        result.update({"scene_left_active": preflight_scene, "scene_may_be_active": False})
    else:
        result["scene_may_be_active"] = result["scene_left_active"] is not False
    if selected is not None:
        try:
            save_report()
            result["saved_to"] = "storage/app/private/lighting/" + filename
        except Exception:
            result.update({"ok": False, "report_error": "replay_report_save_failed"})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--alias", required=True, choices=tuple(NAMES))
    parser.add_argument("--expect-device-id", required=True)
    args = parser.parse_args(argv)
    result = replay(args.alias, args.expect_device_id)
    output = {key: value for key, value in result.items() if key != "receivedResponses"}
    output["responsesReceived"] = len(result["receivedResponses"])
    print(json.dumps(output, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
