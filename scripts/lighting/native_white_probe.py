"""Send one bounded DP28 gradient probe between visually verified white profiles.

The native format has no duration field. Stored white parameters after the probe
are diagnostic data, not proof of target output, fade completion, or smoothness.
Format source: https://developer.tuya.com/en/docs/iot/product-function-definition?id=K9s9rhj576ypf
"""

import argparse
import importlib
import json
from datetime import datetime, timezone

from configure import (
    PRIVATE_DIRECTORY, DeadlineExceeded, DiagnosticError, deadline, device_id,
    quiet_library, read_private, save_private, utc_now,
)
from white_profile import PROFILES, load_calibration, validated_settings, white_from_status


def native_payload(directory, selected, target, change_mode="gradient"):
    if change_mode not in ("direct", "gradient"):
        raise DiagnosticError("invalid_native_mode")
    document = read_private(directory / "schema.json")
    if document.get("device_id") != selected:
        raise DiagnosticError("schema_device_mismatch")
    functions = document.get("schema", {}).get("functions")
    if not isinstance(functions, list):
        raise DiagnosticError("invalid_control_data_schema")
    entries = [entry for entry in functions if isinstance(entry, dict) and str(entry.get("dp_id")) == "28"]
    if len(entries) != 1 or entries[0].get("code") != "control_data" or entries[0].get("type") != "Json":
        raise DiagnosticError("invalid_control_data_schema")
    try:
        values = json.loads(entries[0]["values"])
    except (KeyError, TypeError, ValueError):
        raise DiagnosticError("invalid_control_data_schema") from None
    if not isinstance(values, dict) or not isinstance(values.get("change_mode"), dict):
        raise DiagnosticError("invalid_control_data_schema")
    modes = values["change_mode"].get("range")
    if not isinstance(modes, list) or change_mode not in modes:
        raise DiagnosticError("native_mode_not_in_schema")
    components = {"h": 0, "s": 0, "v": 0, "bright": target["22"], "temperature": target["23"]}
    for name, value in components.items():
        limits = values.get(name)
        if not isinstance(limits, dict) or any(type(limits.get(field)) is not int for field in ("min", "max", "scale", "step")):
            raise DiagnosticError("invalid_control_data_schema")
        if limits["scale"] != 0 or limits["step"] <= 0 or type(value) is not int:
            raise DiagnosticError("invalid_control_data_schema")
        if not 0 <= value <= 0xFFFF or not limits["min"] <= value <= limits["max"]:
            raise DiagnosticError("control_data_value_out_of_schema")
        if (value - limits["min"]) % limits["step"] != 0:
            raise DiagnosticError("control_data_value_out_of_schema")
    # One mode flag (0 direct, 1 gradient) + H4/S4/V4/white4/temp4 = 21 hex characters.
    return ("1" if change_mode == "gradient" else "0") + "".join(f"{value:04x}" for value in components.values())


def probe(from_profile, to_profile, expected_id, directory=PRIVATE_DIRECTORY,
          timeout=3, max_seconds=20, device_factory=None):
    result = {"ok": False, "oneCommandAttempted": False, "sendOutcome": "not_attempted",
              "storedWhiteValues": None, "visual_verification_required": True,
              "physical_finish_confirmed": False, "native_transition_verified": False}
    settings, payload = None, None
    try:
        if from_profile not in PROFILES or to_profile not in PROFILES or from_profile == to_profile:
            raise DiagnosticError("invalid_profile_pair")
        if not 1 <= timeout <= 10 or not 1 <= max_seconds <= 60:
            raise DiagnosticError("invalid_timeout")
        settings, white_schema = validated_settings(directory)
        selected = settings["device_id"]
        if device_id(expected_id) != selected:
            raise DiagnosticError("configured_device_mismatch")
        profiles = load_calibration(directory, selected, white_schema)["white_profiles"]
        for name in (from_profile, to_profile):
            if name not in profiles or profiles[name].get("replay_verified") is not True:
                raise DiagnosticError("profile_not_visually_verified")
        source, target = profiles[from_profile]["dps"], profiles[to_profile]["dps"]
        payload = native_payload(directory, selected, target)
        result.update({"fromProfile": from_profile, "toProfile": to_profile, "targetWhiteValues": target})
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
                actual = white_from_status(device.status(), selected, white_schema)
                if actual != source:
                    raise DiagnosticError("source_profile_mismatch")
                if device.socket is None:
                    raise DiagnosticError("preflight_socket_closed")
                # Disable TinyTuya transport resend after the established read;
                # nowait also bypasses its individual-DP fallback writes.
                device.set_socketRetryLimit(0)
                result.update({"oneCommandAttempted": True, "sendOutcome": "unknown"})
                response = device.set_multiple_values({"28": payload}, nowait=True)
                if response is not None:
                    raise DiagnosticError("write_outcome_unknown")
                result["sendOutcome"] = "sent_without_ack"
            finally:
                device.close()
            observer = connect(False)
            try:
                result["storedWhiteValues"] = white_from_status(observer.status(), selected, white_schema)
            finally:
                observer.close()
        # Only the diagnostic procedure succeeded. DP22/23 may remain unchanged
        # because DP28 is transient; do not compare them to the target as a verdict.
        result["ok"] = True
    except DiagnosticError as error:
        result["error"] = str(error)
    except DeadlineExceeded:
        result["error"] = "probe_deadline_exceeded"
    except (KeyboardInterrupt, EOFError):
        result["error"] = "cancelled"
    except Exception:
        result["error"] = "probe_transport_failed"
    if settings is not None:
        filename = "native-white-probe-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ") + ".json"
        report = dict(result, schema_version=1, captured_at=utc_now(), device_id=settings["device_id"], protocol="3.5")
        if payload is not None:
            report["command"] = {"28": payload}
        try:
            save_private(directory, filename, report)
            result["saved_to"] = "storage/app/private/lighting/" + filename
        except Exception:
            result["ok"] = False
            result["report_error"] = "probe_report_save_failed"
    return result


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--from-profile", required=True, choices=tuple(PROFILES))
    parser.add_argument("--to-profile", required=True, choices=tuple(PROFILES))
    parser.add_argument("--expect-device-id", required=True)
    parser.add_argument("--timeout", type=int, default=3, choices=range(1, 11), metavar="1..10")
    parser.add_argument("--deadline", type=int, default=20, choices=range(1, 61), metavar="1..60")
    args = parser.parse_args(argv)
    result = probe(args.from_profile, args.to_profile, args.expect_device_id,
                   timeout=args.timeout, max_seconds=args.deadline)
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
