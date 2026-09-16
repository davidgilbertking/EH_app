"""Local-only capture, validation and listing of confirmed DIY scene snapshots.

No device commands, scene reconstruction or replay verification are performed.
The original DP25 string, including its case and opaque header, is preserved.
"""

import argparse
import hashlib
import json
import os
import re
import stat
from contextlib import contextmanager
from uuid import uuid4

from configure import (PRIVATE_DIRECTORY, DiagnosticError, device_id, prepare_private_directory,
                       read_private, save_private, utc_now)
from read_state import inspect_status
from white_profile import source_name, validated_settings

NAMES = {"green": "Green", "yellow": "Yellow", "blue": "Blue"}
MODES = {0: "static", 1: "jump", 2: "gradient"}
FIELDS = ("unit_switch_duration", "unit_gradient_duration", "h", "s", "v", "bright", "temperature")
FILENAME = "scene-presets.json"


def checked_range(value, limits):
    if not isinstance(limits, dict) or any(type(limits.get(key)) is not int for key in ("min", "max", "scale", "step")):
        raise DiagnosticError("invalid_scene_schema")
    if limits["min"] > limits["max"] or limits["scale"] != 0 or limits["step"] <= 0:
        raise DiagnosticError("invalid_scene_schema")
    if not limits["min"] <= value <= limits["max"] or (value - limits["min"]) % limits["step"]:
        raise DiagnosticError("scene_value_out_of_schema")


def scene_schema(directory, selected):
    document = read_private(directory / "schema.json")
    if document.get("device_id") != selected:
        raise DiagnosticError("schema_device_mismatch")
    schema = document.get("schema")
    if not isinstance(schema, dict):
        raise DiagnosticError("invalid_scene_schema")
    groups = []
    for group in ("functions", "status"):
        entries = schema.get(group)
        if not isinstance(entries, list):
            raise DiagnosticError("invalid_scene_schema")
        matches = [entry for entry in entries if isinstance(entry, dict) and str(entry.get("dp_id")) == "25"]
        if len(matches) != 1 or (matches[0].get("code"), matches[0].get("type")) != ("scene_data_v2", "Json"):
            raise DiagnosticError("invalid_scene_schema")
        try:
            values = json.loads(matches[0]["values"])
        except (KeyError, TypeError, ValueError):
            raise DiagnosticError("invalid_scene_schema") from None
        if not isinstance(values, dict) or not isinstance(values.get("scene_units"), dict):
            raise DiagnosticError("invalid_scene_schema")
        numbering = values.get("scene_num")
        checked_range(1, numbering)
        if tuple(numbering[key] for key in ("min", "max", "scale", "step")) != (1, 8, 0, 1):
            raise DiagnosticError("unsupported_scene_number_schema")
        modes = values["scene_units"].get("unit_change_mode")
        if not isinstance(modes, dict) or not isinstance(modes.get("range"), list) or not modes["range"]:
            raise DiagnosticError("invalid_scene_schema")
        if any(mode not in MODES.values() for mode in modes["range"]):
            raise DiagnosticError("invalid_scene_schema")
        groups.append(values)
    if groups[0] != groups[1]:
        raise DiagnosticError("scene_schema_conflict")
    return groups[0]


def validate_payload(payload, schema):
    if not isinstance(payload, str) or re.fullmatch(r"[0-9a-fA-F]+", payload) is None:
        raise DiagnosticError("invalid_scene_payload")
    if not 28 <= len(payload) <= 210 or (len(payload) - 2) % 26:
        raise DiagnosticError("invalid_scene_payload_length")
    count = (len(payload) - 2) // 26
    # The header is an opaque scene identifier. The wire format independently
    # permits 1..8 units; do not infer a cloud/LAN scene-number mapping.
    units, modes = schema["scene_units"], []
    for offset in range(2, len(payload), 26):
        unit = payload[offset:offset + 26]
        mode = MODES.get(int(unit[4:6], 16))
        if mode is None or mode not in units["unit_change_mode"]["range"]:
            raise DiagnosticError("scene_mode_out_of_schema")
        modes.append(mode)
        values = [int(unit[:2], 16), int(unit[2:4], 16)]
        values.extend(int(unit[start:start + 4], 16) for start in (6, 10, 14, 18, 22))
        for field, value in zip(FIELDS, values):
            checked_range(value, units.get(field))
    return {"unit_count": count, "header_number": int(payload[:2], 16), "modes": modes,
            "sha256": hashlib.sha256(payload.encode("utf-8")).hexdigest()}


def context(directory, expected_id):
    settings, white_schema = validated_settings(directory)
    selected = settings["device_id"]
    if device_id(expected_id) != selected:
        raise DiagnosticError("configured_device_mismatch")
    if "scene" not in white_schema["21"]["range"]:
        raise DiagnosticError("scene_mode_not_in_schema")
    return selected, scene_schema(directory, selected)


def snapshot_payload(directory, snapshot, selected, schema):
    saved = read_private(directory / source_name(snapshot))
    if saved.get("device_id") != selected or str(saved.get("version")) != "3.5":
        raise DiagnosticError("snapshot_device_or_protocol_mismatch")
    response = saved.get("state")
    inspect_status(response, selected)
    dps = response["dps"] if "dps" in response else response["data"]["dps"]
    if dps.get("20") is not True or dps.get("21") != "scene":
        raise DiagnosticError("snapshot_must_be_on_and_scene")
    payload = dps.get("25")
    return payload, validate_payload(payload, schema)


def load_presets(directory, selected, schema, allow_missing=False):
    path = directory / FILENAME
    if allow_missing and not path.exists() and not path.is_symlink():
        return {"schema_version": 1, "device_id": selected, "protocol": "3.5", "presets": {}}
    saved = read_private(path)
    if saved.get("schema_version") != 1 or saved.get("device_id") != selected or str(saved.get("protocol")) != "3.5":
        raise DiagnosticError("preset_device_or_protocol_mismatch")
    presets = saved.get("presets")
    if not isinstance(presets, dict):
        raise DiagnosticError("invalid_scene_presets")
    for alias, preset in presets.items():
        if alias not in NAMES or not isinstance(preset, dict) or preset.get("name") != NAMES[alias]:
            raise DiagnosticError("invalid_scene_preset")
        dps = preset.get("dps")
        if not isinstance(dps, dict) or set(dps) != {"21", "25"} or dps["21"] != "scene":
            raise DiagnosticError("invalid_scene_preset")
        summary = validate_payload(dps["25"], schema)
        if preset.get("sha256") != summary["sha256"]:
            raise DiagnosticError("scene_checksum_mismatch")
        if preset.get("user_confirmed") is not True or type(preset.get("replay_verified")) is not bool:
            raise DiagnosticError("invalid_scene_confirmation")
        if not isinstance(preset.get("captured_at"), str):
            raise DiagnosticError("invalid_scene_preset")
        source_name(preset.get("source_snapshot"))
    return saved


@contextmanager
def capture_lock(directory):
    # Compare-and-replace must cover the complete read/backup/write sequence.
    # Lock a separate inode because save_private atomically replaces the JSON.
    try:
        import fcntl
    except ImportError:
        raise DiagnosticError("scene_capture_lock_unavailable") from None
    directory = prepare_private_directory(directory)
    flags = os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    path = directory / ".scene-presets.lock"
    if path.is_symlink():
        raise DiagnosticError("invalid_scene_capture_lock")
    descriptor = os.open(path, flags, 0o600)
    try:
        metadata = os.fstat(descriptor)
        if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.getuid()
                or stat.S_IMODE(metadata.st_mode) & 0o077):
            raise DiagnosticError("invalid_scene_capture_lock")
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield
    finally:
        os.close(descriptor)


def capture(alias, snapshot, expected_id, user_confirmed=False, directory=PRIVATE_DIRECTORY, *, replace_sha256=None):
    if alias not in NAMES:
        raise DiagnosticError("invalid_scene_alias")
    if user_confirmed is not True:
        raise DiagnosticError("user_confirmation_required")
    if replace_sha256 is not None:
        if not isinstance(replace_sha256, str) or re.fullmatch(r"[0-9a-fA-F]{64}", replace_sha256) is None:
            raise DiagnosticError("invalid_replace_sha256")
        replace_sha256 = replace_sha256.lower()
    backup = None
    with capture_lock(directory):
        selected, schema = context(directory, expected_id)
        payload, summary = snapshot_payload(directory, snapshot, selected, schema)
        saved = load_presets(directory, selected, schema, allow_missing=True)
        existing = saved["presets"].get(alias)
        if replace_sha256 is not None:
            if existing is None:
                raise DiagnosticError("scene_alias_not_configured")
            if existing["sha256"] != replace_sha256:
                raise DiagnosticError("scene_previous_checksum_mismatch")
        changed = existing is not None and existing["dps"]["25"] != payload
        if changed and replace_sha256 is None:
            raise DiagnosticError("scene_alias_already_configured")
        if changed:
            backup = "scene-presets-backup-" + uuid4().hex + ".json"
            save_private(directory, backup, saved)
            if read_private(directory / backup) != saved:
                raise DiagnosticError("scene_backup_mismatch")
        if existing is None or changed:
            preset = {"name": NAMES[alias], "dps": {"21": "scene", "25": payload},
                "sha256": summary["sha256"], "source_snapshot": snapshot, "captured_at": utc_now(),
                "user_confirmed": True, "replay_verified": False}
            if changed:
                preset.update(previous_sha256=existing["sha256"], previous_presets_backup=backup)
            saved["presets"][alias] = preset
            save_private(directory, FILENAME, saved)
    return {"ok": True, "alias": alias, "name": NAMES[alias], **summary,
            "captured": existing is None or changed, "idempotent": existing is not None and not changed,
            "replaced": changed, "backup": backup,
            "replay_verified": saved["presets"][alias]["replay_verified"], "lighting_changed": False,
            "saved_to": "storage/app/private/lighting/" + FILENAME}


def list_presets(expected_id, directory=PRIVATE_DIRECTORY, allow_missing=True):
    selected, schema = context(directory, expected_id)
    saved = load_presets(directory, selected, schema, allow_missing=allow_missing)
    summaries = [{"alias": alias, "name": preset["name"], **validate_payload(preset["dps"]["25"], schema),
                  "source_snapshot": preset["source_snapshot"], "replay_verified": preset["replay_verified"]}
                 for alias, preset in sorted(saved["presets"].items())]
    return {"ok": True, "presets": summaries, "lighting_changed": False}


def validate(expected_id, snapshot=None, directory=PRIVATE_DIRECTORY):
    if snapshot is None:
        return list_presets(expected_id, directory, allow_missing=False)
    selected, schema = context(directory, expected_id)
    _payload, summary = snapshot_payload(directory, snapshot, selected, schema)
    return {"ok": True, "snapshot": snapshot, **summary, "lighting_changed": False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    capture_parser = commands.add_parser("capture", help="Save an exact, user-confirmed active scene snapshot")
    capture_parser.add_argument("--alias", required=True, choices=tuple(NAMES))
    capture_parser.add_argument("--snapshot", required=True)
    capture_parser.add_argument("--user-confirmed", action="store_true", required=True)
    capture_parser.add_argument("--replace-sha256", help="Replace only this existing SHA-256; back up the old collection first")
    validate_parser = commands.add_parser("validate", help="Validate a snapshot, or the saved preset collection")
    validate_parser.add_argument("--snapshot")
    list_parser = commands.add_parser("list", help="List validated saved presets")
    for command in (capture_parser, validate_parser, list_parser):
        command.add_argument("--expect-device-id", required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == "capture":
            result = capture(args.alias, args.snapshot, args.expect_device_id, args.user_confirmed,
                             replace_sha256=args.replace_sha256)
        elif args.command == "validate":
            result = validate(args.expect_device_id, args.snapshot)
        else:
            result = list_presets(args.expect_device_id)
    except DiagnosticError as error:
        result = {"ok": False, "error": str(error), "lighting_changed": False}
    except Exception:
        result = {"ok": False, "error": "scene_profile_failed", "lighting_changed": False}
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
