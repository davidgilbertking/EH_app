"""Read selected lamp state using only generic Device.status; never set or probe DPS."""

import argparse
import importlib
import json
import re
from datetime import datetime, timezone

from configure import (
    PRIVATE_DIRECTORY, READ_ONLY_VERSIONS, DeadlineExceeded, DiagnosticError,
    deadline, device_id, local_ip, local_key, protocol, quiet_library,
    read_private, save_private, utc_now,
)


def value_type(value):
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, (int, float)):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    raise DiagnosticError("invalid_status_type")


def inspect_status(response, selected_id):
    if not isinstance(response, dict):
        raise DiagnosticError("empty_or_invalid_status")
    if "Err" in response or "Error" in response:
        code = str(response.get("Err", ""))
        raise DiagnosticError("device_error_" + code if re.fullmatch(r"[0-9]{1,8}", code) else "device_error")
    for container in (response, response.get("data")):
        if isinstance(container, dict):
            for field in ("devId", "gwId"):
                if field in container and container[field] != selected_id:
                    raise DiagnosticError("status_device_mismatch")
    dps = response.get("dps")
    if dps is None and isinstance(response.get("data"), dict):
        dps = response["data"].get("dps")
    if not isinstance(dps, dict) or not dps:
        raise DiagnosticError("status_missing_dps")
    if any(not isinstance(key, str) or not re.fullmatch(r"[0-9]{1,5}", key) for key in dps):
        raise DiagnosticError("invalid_dps_identifier")
    return [{"id": key, "type": value_type(dps[key])} for key in sorted(dps, key=int)]


def read_state(directory=PRIVATE_DIRECTORY, ip=None, version=None, expected_id=None,
               timeout=3, attempts=2, max_seconds=20, device_factory=None):
    configuration = read_private(directory / "device.json")
    if configuration.get("schema_version") != 1:
        raise DiagnosticError("unsupported_configuration_schema")
    selected = device_id(configuration.get("device_id"))
    if expected_id is not None and device_id(expected_id) != selected:
        raise DiagnosticError("configured_device_mismatch")
    address = local_ip(ip or configuration.get("ip"))
    version = protocol(version if version is not None else configuration.get("version"))
    key = local_key(configuration.get("local_key"))
    if not 1 <= timeout <= 10 or not 1 <= attempts <= 3 or not 1 <= max_seconds <= 60:
        raise DiagnosticError("invalid_timeout_or_attempts")
    response = None
    last_error = DiagnosticError("empty_or_invalid_status")
    with quiet_library(), deadline(max_seconds):
        tinytuya = importlib.import_module("tinytuya")
        if tinytuya.__version__ != "1.20.0":
            raise DiagnosticError("tinytuya_version_mismatch")
        factory = device_factory or tinytuya.Device
        for _attempt in range(attempts):
            device = None
            try:
                device = factory(selected, address=address, local_key=key, version=float(version),
                                 dev_type="default", persist=False, connection_timeout=timeout,
                                 connection_retry_limit=1, connection_retry_delay=0)
                # These settings adjust only local library behavior; they send nothing.
                device.disabledetect = True
                device.set_retry(False)
                response = device.status()
                summary = inspect_status(response, selected)
                break
            except DiagnosticError as error:
                last_error = error
                if str(error) in ("status_device_mismatch", "invalid_dps_identifier"):
                    raise
            except Exception:
                last_error = DiagnosticError("status_transport_failed")
            finally:
                if device is not None:
                    device.close()
        else:
            raise last_error
    filename = "status-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S_%fZ") + ".json"
    save_private(directory, filename, {
        "captured_at": utc_now(), "device_id": selected, "ip": address, "version": version,
        "tinytuya_version": "1.20.0", "state": response, "lighting_changed": False,
    })
    return {"ok": True, "dps_count": len(summary), "dps": summary,
            "saved_to": "storage/app/private/lighting/" + filename,
            "device_state_read": True, "lighting_changed": False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ip", help="Override only the selected lamp's current LAN IPv4")
    parser.add_argument("--version", choices=READ_ONLY_VERSIONS, help="Confirmed protocol override; no autodetection")
    parser.add_argument("--expect-device-id", help="Fail before connection unless private config selects this exact ID")
    parser.add_argument("--timeout", type=int, default=3, choices=range(1, 11), metavar="1..10")
    parser.add_argument("--attempts", type=int, default=2, choices=range(1, 4), metavar="1..3")
    parser.add_argument("--deadline", type=int, default=20, choices=range(1, 61), metavar="1..60")
    args = parser.parse_args(argv)
    try:
        result = read_state(ip=args.ip, version=args.version, expected_id=args.expect_device_id,
                            timeout=args.timeout, attempts=args.attempts, max_seconds=args.deadline)
    except DiagnosticError as error:
        result = {"ok": False, "error": str(error), "lighting_changed": False}
    except DeadlineExceeded:
        result = {"ok": False, "error": "status_deadline_exceeded", "lighting_changed": False}
    except KeyboardInterrupt:
        result = {"ok": False, "error": "cancelled", "lighting_changed": False}
    except Exception:
        result = {"ok": False, "error": "status_read_failed", "lighting_changed": False}
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
