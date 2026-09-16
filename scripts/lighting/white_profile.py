"""Capture user-confirmed white profiles locally; explicitly replay one exact capture.

No interpolation, scene operation, or application-driver activation is performed.
Replay requires an already-on white lamp and a confirmed selected device ID.
"""

import argparse
import importlib
import json
import re
from pathlib import Path

from configure import (
    PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline, device_id,
    local_ip, local_key, quiet_library, read_private, save_private, utc_now,
)
from read_state import inspect_status

PROFILES = {"action": (100, 33), "encounters": (90, 20), "dark": (1, 0)}
WHITE_SCHEMA = {
    "20": ("switch_led", "Boolean"), "21": ("work_mode", "Enum"),
    "22": ("bright_value_v2", "Integer"), "23": ("temp_value_v2", "Integer"),
}


def source_name(value):
    if not isinstance(value, str) or Path(value).name != value or not re.fullmatch(r"[A-Za-z0-9_.-]+\.json", value):
        raise DiagnosticError("invalid_snapshot_name")
    return value


def validated_settings(directory):
    settings = read_private(directory / "device.json")
    if settings.get("schema_version") != 1 or str(settings.get("version")) != "3.5":
        raise DiagnosticError("unsupported_device_configuration")
    selected = device_id(settings.get("device_id"))
    local_ip(settings.get("ip"))
    local_key(settings.get("local_key"))
    document = read_private(directory / "schema.json")
    if document.get("device_id") != selected:
        raise DiagnosticError("schema_device_mismatch")
    schema = document.get("schema")
    if not isinstance(schema, dict):
        raise DiagnosticError("invalid_white_schema")
    groups = []
    for name in ("functions", "status"):
        entries = schema.get(name)
        if not isinstance(entries, list):
            raise DiagnosticError("invalid_white_schema")
        mapped = {}
        for entry in entries:
            if not isinstance(entry, dict):
                raise DiagnosticError("invalid_white_schema")
            dp = str(entry.get("dp_id"))
            if dp not in WHITE_SCHEMA:
                continue
            if dp in mapped or (entry.get("code"), entry.get("type")) != WHITE_SCHEMA[dp]:
                raise DiagnosticError("invalid_white_schema")
            try:
                values = json.loads(entry["values"])
            except (KeyError, TypeError, ValueError):
                raise DiagnosticError("invalid_white_schema") from None
            if not isinstance(values, dict):
                raise DiagnosticError("invalid_white_schema")
            if dp == "21" and (not isinstance(values.get("range"), list) or "white" not in values["range"]):
                raise DiagnosticError("invalid_white_schema")
            if dp in ("22", "23"):
                if any(type(values.get(field)) is not int for field in ("min", "max", "scale", "step")):
                    raise DiagnosticError("invalid_white_schema")
                if values["min"] > values["max"] or values["scale"] != 0 or values["step"] <= 0:
                    raise DiagnosticError("invalid_white_schema")
            mapped[dp] = values
        if set(mapped) != set(WHITE_SCHEMA):
            raise DiagnosticError("invalid_white_schema")
        groups.append(mapped)
    if groups[0] != groups[1]:
        raise DiagnosticError("white_schema_conflict")
    return settings, groups[0]


def validate_white(dps, schema):
    if not isinstance(dps, dict) or set(dps) != set(WHITE_SCHEMA):
        raise DiagnosticError("invalid_white_profile_dps")
    if dps["20"] is not True or dps["21"] != "white":
        raise DiagnosticError("lamp_must_be_on_and_white")
    for dp in ("22", "23"):
        value, limits = dps[dp], schema[dp]
        if type(value) is not int or not limits["min"] <= value <= limits["max"]:
            raise DiagnosticError("white_value_out_of_schema")
        if (value - limits["min"]) % limits["step"] != 0:
            raise DiagnosticError("white_value_out_of_schema")
    return dict(dps)


def white_from_status(response, selected, schema):
    inspect_status(response, selected)
    dps = response.get("dps")
    if dps is None:
        dps = response["data"]["dps"]
    return validate_white({key: dps[key] for key in WHITE_SCHEMA if key in dps}, schema)


def validate_profile(name, profile, schema):
    if name not in PROFILES or not isinstance(profile, dict):
        raise DiagnosticError("invalid_white_profile")
    brightness, temperature = PROFILES[name]
    if profile.get("ui") != {"brightness": brightness, "temperature": temperature} or profile.get("user_confirmed") is not True:
        raise DiagnosticError("white_profile_not_confirmed")
    source_name(profile.get("source_snapshot"))
    if type(profile.get("replay_verified")) is not bool or not isinstance(profile.get("captured_at"), str):
        raise DiagnosticError("invalid_white_profile")
    return validate_white(profile.get("dps"), schema)


def load_calibration(directory, selected, schema, allow_missing=False):
    path = directory / "calibration.json"
    if allow_missing and not path.exists():
        return {"schema_version": 1, "device_id": selected, "protocol": "3.5", "white_profiles": {}}
    calibration = read_private(path)
    if calibration.get("schema_version") != 1 or calibration.get("device_id") != selected or str(calibration.get("protocol")) != "3.5":
        raise DiagnosticError("calibration_device_or_protocol_mismatch")
    profiles = calibration.get("white_profiles")
    if not isinstance(profiles, dict):
        raise DiagnosticError("invalid_calibration")
    for name, profile in profiles.items():
        validate_profile(name, profile, schema)
    return calibration


def capture_profile(profile, snapshot, brightness, temperature, user_confirmed,
                    directory=PRIVATE_DIRECTORY):
    if profile not in PROFILES or (brightness, temperature) != PROFILES[profile]:
        raise DiagnosticError("profile_ui_mismatch")
    if user_confirmed is not True:
        raise DiagnosticError("user_confirmation_required")
    snapshot = source_name(snapshot)
    settings, schema = validated_settings(directory)
    selected = settings["device_id"]
    saved = read_private(directory / snapshot)
    if saved.get("device_id") != selected or str(saved.get("version")) != "3.5":
        raise DiagnosticError("snapshot_device_or_protocol_mismatch")
    dps = white_from_status(saved.get("state"), selected, schema)
    calibration = load_calibration(directory, selected, schema, allow_missing=True)
    calibration["white_profiles"][profile] = {
        "ui": {"brightness": brightness, "temperature": temperature}, "dps": dps,
        "source_snapshot": snapshot, "user_confirmed": True, "captured_at": utc_now(),
        "replay_verified": False,
    }
    save_private(directory, "calibration.json", calibration)
    return {"ok": True, "profile": profile, "white_values": dps, "captured": True,
            "replay_verified": False, "lighting_changed": False}


def replay_profile(profile, expected_id, directory=PRIVATE_DIRECTORY, timeout=3,
                   max_seconds=20, device_factory=None):
    write_attempted = False
    try:
        if profile not in PROFILES:
            raise DiagnosticError("invalid_white_profile")
        if not 1 <= timeout <= 10 or not 1 <= max_seconds <= 60:
            raise DiagnosticError("invalid_timeout")
        settings, schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        calibration = load_calibration(directory, selected, schema)
        if profile not in calibration["white_profiles"]:
            raise DiagnosticError("white_profile_not_captured")
        target = validate_profile(profile, calibration["white_profiles"][profile], schema)
        with quiet_library(), deadline(max_seconds):
            tinytuya = importlib.import_module("tinytuya")
            if tinytuya.__version__ != "1.20.0":
                raise DiagnosticError("tinytuya_version_mismatch")
            factory = device_factory or tinytuya.Device

            def connect(persistent):
                device = factory(selected, address=settings["ip"], local_key=settings["local_key"],
                                 version=3.5, dev_type="default", persist=persistent,
                                 connection_timeout=timeout, connection_retry_limit=1,
                                 connection_retry_delay=0, max_simultaneous_dps=0)
                device.disabledetect = True
                device.set_retry(False)
                return device

            device = connect(True)
            try:
                # Establish the socket and reject scene/colour/off before any write.
                white_from_status(device.status(), selected, schema)
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                # TinyTuya's default set_multiple_values retries failed multi-DP
                # writes as separate set_value calls. nowait=True disables that
                # fallback; zero socket retries prevents ambiguous resend.
                device.set_socketRetryLimit(0)
                write_attempted = True
                response = device.set_multiple_values(dict(target), nowait=True)
                if response is not None:
                    raise DiagnosticError("write_outcome_unknown")
            finally:
                device.close()
            observer = connect(False)
            try:
                observed = white_from_status(observer.status(), selected, schema)
            finally:
                observer.close()
        matched = observed == target
        return {"ok": matched, "profile": profile, "white_values": observed,
                "write_attempted": True, "readback_matched": matched,
                "replay_verified": False, "visual_verified": False,
                **({} if matched else {"error": "readback_mismatch"})}
    except DiagnosticError as error:
        code = str(error)
    except DeadlineExceeded:
        code = "replay_deadline_exceeded"
    except (KeyboardInterrupt, EOFError):
        code = "cancelled"
    except Exception:
        code = "replay_transport_failed"
    return {"ok": False, "error": code, "write_attempted": write_attempted,
            "readback_matched": False, "outcome_unknown": write_attempted,
            "replay_verified": False, "visual_verified": False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    capture = commands.add_parser("capture", help="Local-only capture of a user-verified saved status")
    capture.add_argument("--profile", required=True, choices=tuple(PROFILES))
    capture.add_argument("--snapshot", required=True, help="Basename inside private lighting directory")
    capture.add_argument("--brightness-percent", required=True, type=int)
    capture.add_argument("--temperature-percent", required=True, type=int)
    capture.add_argument("--user-confirmed", required=True, action="store_true")
    replay = commands.add_parser("replay", help="One exact saved white-profile write, only from white/on")
    replay.add_argument("--profile", required=True, choices=tuple(PROFILES))
    replay.add_argument("--expect-device-id", required=True)
    replay.add_argument("--timeout", type=int, default=3, choices=range(1, 11), metavar="1..10")
    replay.add_argument("--deadline", type=int, default=20, choices=range(1, 61), metavar="1..60")
    args = parser.parse_args(argv)
    try:
        if args.command == "capture":
            result = capture_profile(args.profile, args.snapshot, args.brightness_percent,
                                     args.temperature_percent, args.user_confirmed)
        else:
            result = replay_profile(args.profile, args.expect_device_id, timeout=args.timeout,
                                    max_seconds=args.deadline)
    except DiagnosticError as error:
        result = {"ok": False, "error": str(error), "lighting_changed": False}
    except Exception:
        result = {"ok": False, "error": "profile_capture_failed", "lighting_changed": False}
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
