"""Observe one native white DP25 scene, from calibrated dark to Action.

Two control writes only: establish dark, then activate a white scene.
Authenticated stored scene data never proves physical duration or one-shot hold.
Timing source: https://github.com/tuya/tuya-lighting-ios-sdk/blob/main/README-zh.md
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


def scene_unit(directory, selected, target, duration_ms, mode="gradient"):
    if type(duration_ms) is not int or not 500 <= duration_ms <= 8000 or duration_ms % 100:
        raise DiagnosticError("invalid_scene_duration")
    document = read_private(directory / "schema.json")
    if document.get("device_id") != selected:
        raise DiagnosticError("schema_device_mismatch")
    groups = []
    for group in ("functions", "status"):
        entries = document.get("schema", {}).get(group)
        if not isinstance(entries, list):
            raise DiagnosticError("invalid_scene_schema")
        matches = [entry for entry in entries if isinstance(entry, dict) and str(entry.get("dp_id")) == "25"]
        if len(matches) != 1 or (matches[0].get("code"), matches[0].get("type")) != ("scene_data_v2", "Json"):
            raise DiagnosticError("invalid_scene_schema")
        try:
            values = json.loads(matches[0]["values"])
        except (KeyError, TypeError, ValueError):
            raise DiagnosticError("invalid_scene_schema") from None
        if not isinstance(values, dict):
            raise DiagnosticError("invalid_scene_schema")
        groups.append(values)
    if groups[0] != groups[1]:
        raise DiagnosticError("scene_schema_conflict")
    schema = groups[0]
    # Cloud labels the eight slots 1..8. Preserve the observed LAN 0..7 byte;
    # do not invent a slot or assume cloud and LAN IDs are interchangeable.
    if schema.get("scene_num") != {"min": 1, "max": 8, "scale": 0, "step": 1}:
        raise DiagnosticError("unsupported_scene_number_schema")
    units = schema.get("scene_units")
    if not isinstance(units, dict) or not isinstance(units.get("unit_change_mode"), dict):
        raise DiagnosticError("invalid_scene_schema")
    modes = units["unit_change_mode"].get("range")
    if mode not in ("gradient", "static"):
        raise DiagnosticError("unsupported_scene_unit_mode")
    if not isinstance(modes, list) or mode not in modes:
        raise DiagnosticError(mode + "_not_in_scene_schema")
    values = {"unit_switch_duration": duration_ms // 100, "unit_gradient_duration": duration_ms // 100,
              "h": 0, "s": 0, "v": 0, "bright": target["22"], "temperature": target["23"]}
    for name, value in values.items():
        limits = units.get(name)
        if not isinstance(limits, dict) or any(type(limits.get(key)) is not int for key in ("min", "max", "scale", "step")):
            raise DiagnosticError("invalid_scene_schema")
        if limits["scale"] != 0 or limits["step"] <= 0 or type(value) is not int:
            raise DiagnosticError("invalid_scene_schema")
        if not limits["min"] <= value <= limits["max"] or (value - limits["min"]) % limits["step"]:
            raise DiagnosticError("scene_value_out_of_schema")
        if not 0 <= value <= (100 if name.startswith("unit_") else 0xFFFF):
            raise DiagnosticError("scene_value_out_of_schema")
    units100 = duration_ms // 100
    mode_byte = 2 if mode == "gradient" else 0
    return f"{units100:02x}{units100:02x}{mode_byte:02x}" + "0000" * 3 + f"{target['22']:04x}{target['23']:04x}"


def preserve_scene_id(current, unit):
    if not isinstance(current, str) or re.fullmatch(r"[0-9a-fA-F]+", current) is None:
        raise DiagnosticError("invalid_current_scene")
    if not 28 <= len(current) <= 210 or (len(current) - 2) % 26 or int(current[:2], 16) > 7:
        raise DiagnosticError("invalid_current_scene")
    return current[:2].lower() + unit


def state_dps(response, selected):
    inspect_status(response, selected)
    return response["dps"] if "dps" in response else response["data"]["dps"]


def probe(expected_id, duration_ms=4000, observe_seconds=20, directory=PRIVATE_DIRECTORY,
          device_factory=None, response_reader_factory=LanResponseReader,
          clock=time.monotonic, cancelled=lambda: False, program="single"):
    result = {"ok": False, "commandsAttempted": 0, "statusQueriesAttempted": 0,
              "sendOutcome": "not_attempted", "storedSourceConfirmed": False,
              "storedSceneConfirmed": False, "physical_finish_confirmed": False,
              "one_shot_verified": False, "visual_verification_required": True,
              "receivedResponses": [], "timings": []}
    settings, source, payload = None, None, None
    started, phase = clock(), "preflight"
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ")

    def check():
        if cancelled():
            raise ProbeInterrupted()
        if clock() - started >= 45:
            raise DeadlineExceeded()

    def record(message):
        check()
        entry = dict(message, phase=phase, receivedMs=round((clock() - started) * 1000, 3))
        result["receivedResponses"].append(entry)
        response = message.get("response")
        if not isinstance(response, dict):
            return  # An authenticated ACK is not state evidence.
        if "dps" not in response and not (isinstance(response.get("data"), dict) and "dps" in response["data"]):
            return
        dps = state_dps(response, selected)
        if phase == "source_query" and all(key in dps for key in source):
            result["storedSourceConfirmed"] = all(type(dps[key]) is type(value) and dps[key] == value for key, value in source.items())
        if phase == "final_query" and all(key in dps for key in ("20", "21", "25")):
            result["storedSceneConfirmed"] = dps["20"] is True and dps["21"] == "scene" and dps["25"] == payload

    try:
        if program not in ("single", "anchored"):
            raise DiagnosticError("invalid_scene_program")
        if type(observe_seconds) is not int or not 10 <= observe_seconds <= 30:
            raise DiagnosticError("invalid_observation_duration")
        settings, white_schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        if "scene" not in white_schema["21"]["range"]:
            raise DiagnosticError("scene_mode_not_in_schema")
        profiles = load_calibration(directory, selected, white_schema)["white_profiles"]
        if any(name not in profiles or profiles[name].get("replay_verified") is not True for name in ("dark", "action")):
            raise DiagnosticError("profile_not_visually_verified")
        source, target = profiles["dark"]["dps"], profiles["action"]["dps"]
        unit = scene_unit(directory, selected, target, duration_ms)
        if program == "anchored":
            # An explicit starting unit tests the transition INSIDE the scene,
            # independently of the firmware's white-to-scene entry behavior.
            # The final static unit has the same target and is intended to hold;
            # neither its stopping behavior nor the fade duration is assumed.
            unit = (scene_unit(directory, selected, source, 1000) + unit
                    + scene_unit(directory, selected, target, 1000, mode="static"))
        result.update({"fromProfile": "dark", "toProfile": "action", "targetWhiteValues": target,
                       "durationMs": duration_ms, "observeSeconds": observe_seconds,
                       "program": program, "sceneUnitCount": len(unit) // 26})
        with exclusive_probe(directory), interruptible(), quiet_library(), deadline(45):
            check()
            tinytuya = importlib.import_module("tinytuya")
            if tinytuya.__version__ != "1.20.0":
                raise DiagnosticError("tinytuya_version_mismatch")
            device = (device_factory or tinytuya.Device)(selected, address=settings["ip"], local_key=settings["local_key"],
                version=3.5, dev_type="default", persist=True, connection_timeout=3,
                connection_retry_limit=1, connection_retry_delay=0, max_simultaneous_dps=0)
            try:
                device.disabledetect = True
                device.set_retry(False)
                original = device.status()
                white_from_status(original, selected, white_schema)
                payload = preserve_scene_id(state_dps(original, selected).get("25"), unit)
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                active_socket = device.socket
                backup = "native-scene-before-" + stamp + ".json"
                save_private(directory, backup, {"schema_version": 1, "device_id": selected, "version": "3.5",
                             "captured_at": utc_now(), "state": original})
                result["originalSnapshot"] = backup
                device.set_socketRetryLimit(0)
                device.set_sendWait(None)
                reader = response_reader_factory(device, clock=clock)

                def drain(until):
                    check()
                    reader.drain_until(until, cancelled=cancelled, on_message=record)
                    check()

                def send(dps, stage):
                    check()
                    if device.socket is not active_socket:
                        raise DiagnosticError("probe_connection_changed")
                    begun = clock()
                    result["commandsAttempted"] += 1
                    result["sendOutcome"] = "unknown"
                    result["timings"].append({"stage": stage, "startedMs": round((begun - started) * 1000, 3)})
                    response = device.set_multiple_values(dps, nowait=True)
                    result["timings"][-1]["sendMs"] = round((clock() - begun) * 1000, 3)
                    if response is not None or device.socket is not active_socket:
                        raise DiagnosticError("write_outcome_unknown")
                    result["sendOutcome"] = "sent_without_ack"
                    check()

                def query():
                    check()
                    if device.socket is not active_socket:
                        raise DiagnosticError("probe_connection_changed")
                    result["statusQueriesAttempted"] += 1
                    if device.status(nowait=True) is not None:
                        raise DiagnosticError("status_query_failed")
                    check()

                send(dict(source), "source")
                phase = "source_settle"
                drain(clock() + 1)
                phase = "source_query"
                query()
                drain(clock() + 2)
                if not result["storedSourceConfirmed"]:
                    raise DiagnosticError("stored_source_unconfirmed")
                phase = "scene"
                # Put the new program before mode activation in the single
                # packet; firmware processing order/atomicity is not guaranteed.
                send({"25": payload, "21": "scene"}, "native_scene")
                observation_started = clock()
                query()
                drain(observation_started + observe_seconds - 2)
                phase = "final_query"
                query()
                drain(observation_started + observe_seconds)
                result["observationElapsedMs"] = round((clock() - observation_started) * 1000, 3)
                if not result["storedSceneConfirmed"]:
                    raise DiagnosticError("stored_scene_unconfirmed")
            finally:
                device.close()
        result["ok"] = True
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
        filename = "native-scene-probe-" + stamp + ".json"
        report = dict(result, schema_version=1, captured_at=utc_now(), device_id=settings["device_id"], protocol="3.5")
        if payload is not None:
            report["command"] = {"25": payload, "21": "scene"}
        try:
            save_private(directory, filename, report)
            result["saved_to"] = "storage/app/private/lighting/" + filename
        except Exception:
            result.update({"ok": False, "report_error": "probe_report_save_failed"})
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--expect-device-id", required=True)
    parser.add_argument("--duration-ms", type=int, default=4000)
    parser.add_argument("--observe-seconds", type=int, default=20)
    parser.add_argument("--program", choices=("single", "anchored"), default="single")
    parser.add_argument("--leave-scene-active", action="store_true",
                        help="Historical scene experiment: the lamp can keep looping after this process exits")
    args = parser.parse_args(argv)
    if not args.leave_scene_active:
        parser.error("Use native_white_once_probe.py for a bounded test. This historical probe requires --leave-scene-active.")
    result = probe(args.expect_device_id, args.duration_ms, args.observe_seconds, program=args.program)
    output = {key: value for key, value in result.items() if key not in ("receivedResponses", "timings")}
    output["responsesReceived"] = len(result["receivedResponses"])
    print(json.dumps(output, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
