"""Local-only scene capture tests; isolated files and fake device credentials."""

import hashlib
import io
import json
import os
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import sys
from threading import Barrier
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import scene_profile
import test_native_white_probe as fixtures

UNIT = "0B0a02" + "00F0" + "03E8" + "03e8" + "0000" + "0000"
PAYLOAD = "A7" + UNIT


class SceneProfilesTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load

    def setUp(self):
        fixtures.NativeWhiteProbeTest.setUp(self)
        self.scene_values = {"scene_num": self.range(1, 8), "scene_units": {
            "unit_change_mode": {"range": ["static", "jump", "gradient"]},
            "unit_switch_duration": self.range(0, 100), "unit_gradient_duration": self.range(0, 100),
            "h": self.range(0, 360), "s": self.range(0, 1000), "v": self.range(0, 1000),
            "bright": self.range(0, 1000), "temperature": self.range(0, 1000)}}
        self.set_scene_schema(self.scene_values)
        self.snapshot("blue.json", PAYLOAD)

    def set_scene_schema(self, values):
        schema = self.load("schema.json")
        for group in ("functions", "status"):
            entries = [entry for entry in schema["schema"][group] if entry["dp_id"] != 25]
            schema["schema"][group] = entries + [{"dp_id": 25, "code": "scene_data_v2", "type": "Json", "values": json.dumps(values)}]
        self.save("schema.json", schema)

    def snapshot(self, name, payload=PAYLOAD, **dps):
        self.save(name, {"device_id": fixtures.DEVICE_ID, "version": "3.5", "captured_at": "fixture",
                         "state": {"devId": fixtures.DEVICE_ID, "dps": {"20": True, "21": "scene", "25": payload, **dps}}})

    def capture(self, alias="blue", snapshot="blue.json", confirmed=True, expected=fixtures.DEVICE_ID, replace_sha256=None):
        return scene_profile.capture(alias, snapshot, expected, confirmed, self.directory, replace_sha256=replace_sha256)

    def replacement(self):
        self.capture()
        self.snapshot("changed.json", "07" + UNIT * 8)
        return self.load("scene-presets.json")["presets"]["blue"]["sha256"]

    def test_capture_preserves_exact_case_header_checksum_and_never_marks_replay_verified(self):
        with patch("socket.socket", side_effect=AssertionError("Network is forbidden")):
            result = self.capture()
            listed = scene_profile.list_presets(fixtures.DEVICE_ID, self.directory)
            validated = scene_profile.validate(fixtures.DEVICE_ID, "blue.json", self.directory)
        preset = self.load("scene-presets.json")["presets"]["blue"]
        self.assertEqual({"21": "scene", "25": PAYLOAD}, preset["dps"])
        self.assertEqual(hashlib.sha256(PAYLOAD.encode("utf-8")).hexdigest(), preset["sha256"])
        self.assertEqual("Blue", preset["name"])
        self.assertTrue(preset["user_confirmed"])
        self.assertFalse(preset["replay_verified"])
        self.assertEqual("blue.json", preset["source_snapshot"])
        self.assertEqual((1, 167), (result["unit_count"], result["header_number"]))
        self.assertEqual(167, validated["header_number"])
        self.assertEqual("blue", listed["presets"][0]["alias"])
        self.assertNotIn(PAYLOAD, json.dumps(result) + json.dumps(listed))
        self.assertNotIn(fixtures.KEY, (self.directory / "scene-presets.json").read_text() + json.dumps(result))
        self.assertEqual(0o600, (self.directory / "scene-presets.json").stat().st_mode & 0o777)

    def test_wire_unit_count_and_opaque_header_are_independent_of_cloud_scene_number(self):
        for header in ("00", "07", "08", "FF"):
            for count in (1, 8):
                summary = scene_profile.validate_payload(header + UNIT * count, self.scene_values)
                self.assertEqual(count, summary["unit_count"])
                self.assertEqual(int(header, 16), summary["header_number"])

    def test_unconfirmed_off_white_or_missing_scene_are_not_capturable(self):
        with self.assertRaisesRegex(configure.DiagnosticError, "user_confirmation_required"):
            self.capture(confirmed=False)
        for dps in ({"20": False}, {"21": "white"}, {"25": None}):
            self.snapshot("blue.json", **dps)
            with self.assertRaises(configure.DiagnosticError):
                self.capture()
        self.assertFalse((self.directory / "scene-presets.json").exists())

    def test_malformed_truncated_oversized_unknown_mode_or_out_of_range_rejected(self):
        bad = (None, "", "00", PAYLOAD[:-1], PAYLOAD + "00", PAYLOAD + " ", "00" + UNIT * 9,
               "G7" + UNIT, "00" + UNIT[:4] + "03" + UNIT[6:],
               "00" + "65" + UNIT[2:], "00" + UNIT[:6] + "0169" + UNIT[10:],
               "00" + UNIT[:10] + "03E9" + UNIT[14:], "00" + UNIT[:18] + "03E9" + UNIT[22:])
        for payload in bad:
            with self.subTest(payload=payload):
                self.snapshot("blue.json", payload)
                with self.assertRaises(configure.DiagnosticError):
                    self.capture()
        self.assertFalse((self.directory / "scene-presets.json").exists())

    def test_schema_conflict_missing_dp_mode_or_actual_step_rejected(self):
        original = self.load("schema.json")
        for kind in ("missing", "wrong_type", "conflict", "mode", "step"):
            with self.subTest(kind=kind):
                schema = json.loads(json.dumps(original))
                if kind == "missing":
                    schema["schema"]["status"].pop()
                elif kind == "wrong_type":
                    schema["schema"]["status"][-1]["type"] = "String"
                elif kind == "conflict":
                    changed = json.loads(schema["schema"]["status"][-1]["values"])
                    changed["scene_units"]["h"]["max"] = 359
                    schema["schema"]["status"][-1]["values"] = json.dumps(changed)
                else:
                    changed = json.loads(json.dumps(self.scene_values))
                    if kind == "mode":
                        changed["scene_units"]["unit_change_mode"]["range"] = ["static"]
                    else:
                        changed["scene_units"]["unit_switch_duration"]["step"] = 2
                    for group in ("functions", "status"):
                        schema["schema"][group][-1]["values"] = json.dumps(changed)
                self.save("schema.json", schema)
                with self.assertRaises(configure.DiagnosticError):
                    self.capture()

    def test_wrong_device_protocol_response_identity_or_path_never_creates_store(self):
        with self.assertRaisesRegex(configure.DiagnosticError, "configured_device_mismatch"):
            self.capture(expected="other_device_123456")
        with self.assertRaisesRegex(configure.DiagnosticError, "invalid_snapshot_name"):
            self.capture(snapshot="../blue.json")
        original = self.load("blue.json")
        for field, value in (("device_id", "other_device_123456"), ("version", "3.3")):
            self.save("blue.json", dict(original, **{field: value}))
            with self.assertRaises(configure.DiagnosticError):
                self.capture()
        original["state"]["devId"] = "other_device_123456"
        self.save("blue.json", original)
        with self.assertRaisesRegex(configure.DiagnosticError, "status_device_mismatch"):
            self.capture()
        self.assertFalse((self.directory / "scene-presets.json").exists())

    def test_different_existing_alias_rejected_same_capture_preserves_verified_record_and_other_alias(self):
        self.capture()
        self.snapshot("green.json", "07" + UNIT)
        self.capture(alias="green", snapshot="green.json")
        saved = self.load("scene-presets.json")
        saved["presets"]["blue"]["replay_verified"] = True
        self.save("scene-presets.json", saved)
        original = (self.directory / "scene-presets.json").read_bytes()
        result = self.capture()
        self.assertTrue(result["idempotent"])
        self.assertTrue(result["replay_verified"])
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())
        self.snapshot("different.json", PAYLOAD.lower())
        with self.assertRaisesRegex(configure.DiagnosticError, "scene_alias_already_configured"):
            self.capture(snapshot="different.json")
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())

    def test_checksum_tamper_rejected_by_list_validate_and_further_capture(self):
        self.capture()
        saved = self.load("scene-presets.json")
        saved["presets"]["blue"]["dps"]["25"] = PAYLOAD.lower()
        self.save("scene-presets.json", saved)
        for operation in (lambda: scene_profile.list_presets(fixtures.DEVICE_ID, self.directory),
                          lambda: scene_profile.validate(fixtures.DEVICE_ID, directory=self.directory), self.capture):
            with self.assertRaisesRegex(configure.DiagnosticError, "scene_checksum_mismatch"):
                operation()

    def test_explicit_replacement_backs_up_full_verified_collection_and_resets_only_changed_alias(self):
        previous_sha = self.replacement()
        self.snapshot("green.json", "07" + UNIT)
        self.capture(alias="green", snapshot="green.json")
        before = self.load("scene-presets.json")
        for preset in before["presets"].values():
            preset.update(replay_verified=True, visual_confirmation={"note": "fixture confirmation"})
        self.save("scene-presets.json", before)
        with patch("socket.socket", side_effect=AssertionError("Network is forbidden")):
            result = self.capture(snapshot="changed.json", replace_sha256=previous_sha.upper())
        after = self.load("scene-presets.json")
        self.assertEqual(before, self.load(result["backup"]))
        self.assertEqual(before["presets"]["green"], after["presets"]["green"])
        blue = after["presets"]["blue"]
        self.assertEqual("07" + UNIT * 8, blue["dps"]["25"])
        self.assertEqual("changed.json", blue["source_snapshot"])
        self.assertEqual(previous_sha, blue["previous_sha256"])
        self.assertEqual(result["backup"], blue["previous_presets_backup"])
        self.assertFalse(blue["replay_verified"])
        self.assertNotIn("visual_confirmation", blue)
        self.assertTrue(result["replaced"])
        self.assertTrue(result["captured"])
        self.assertFalse(result["idempotent"])
        self.assertFalse(result["lighting_changed"])
        for filename in ("scene-presets.json", result["backup"], ".scene-presets.lock"):
            self.assertEqual(0o600, (self.directory / filename).stat().st_mode & 0o777)
        self.assertNotIn(fixtures.KEY, (self.directory / result["backup"]).read_text())
        self.assertTrue(scene_profile.validate(fixtures.DEVICE_ID, directory=self.directory)["ok"])

    def test_replacement_requires_existing_alias_current_checksum_and_confirmation(self):
        previous_sha = self.replacement()
        original = (self.directory / "scene-presets.json").read_bytes()
        attempts = (
            ({"replace_sha256": "0" * 64}, "scene_previous_checksum_mismatch"),
            ({"replace_sha256": "bad"}, "invalid_replace_sha256"),
            ({"replace_sha256": 42}, "invalid_replace_sha256"),
            ({"replace_sha256": previous_sha, "confirmed": False}, "user_confirmation_required"),
            ({"replace_sha256": previous_sha, "alias": "green"}, "scene_alias_not_configured"),
        )
        for options, error in attempts:
            with self.subTest(error=error), self.assertRaisesRegex(configure.DiagnosticError, error):
                self.capture(snapshot="changed.json", **options)
            self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())
            self.assertEqual([], list(self.directory.glob("scene-presets-backup-*.json")))

    def test_identical_replacement_is_idempotent_and_preserves_verified_record_without_backup(self):
        previous_sha = self.replacement()
        saved = self.load("scene-presets.json")
        saved["presets"]["blue"].update(replay_verified=True, visual_confirmed_at="fixture")
        self.save("scene-presets.json", saved)
        original = (self.directory / "scene-presets.json").read_bytes()
        result = self.capture(replace_sha256=previous_sha)
        self.assertTrue(result["idempotent"])
        self.assertTrue(result["replay_verified"])
        self.assertFalse(result["captured"])
        self.assertFalse(result["replaced"])
        self.assertIsNone(result["backup"])
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())
        self.assertEqual([], list(self.directory.glob("scene-presets-backup-*.json")))
        with self.assertRaisesRegex(configure.DiagnosticError, "scene_previous_checksum_mismatch"):
            self.capture(replace_sha256="0" * 64)

    def test_replacement_still_validates_snapshot_before_backing_up_or_writing(self):
        previous_sha = self.replacement()
        original = (self.directory / "scene-presets.json").read_bytes()
        for dps in ({"21": "white"}, {"20": False}, {"25": "00"}, {"25": "07" + "65" + UNIT[2:]}):
            self.snapshot("changed.json", **dps)
            with self.assertRaises(configure.DiagnosticError):
                self.capture(snapshot="changed.json", replace_sha256=previous_sha)
            self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())
            self.assertEqual([], list(self.directory.glob("scene-presets-backup-*.json")))

    def test_failed_backup_prevents_any_attempt_to_replace_original(self):
        previous_sha = self.replacement()
        original = (self.directory / "scene-presets.json").read_bytes()
        with patch.object(scene_profile, "save_private", side_effect=OSError("fixture full disk")) as save:
            with self.assertRaises(OSError):
                self.capture(snapshot="changed.json", replace_sha256=previous_sha)
        self.assertEqual(1, save.call_count)
        self.assertTrue(save.call_args.args[1].startswith("scene-presets-backup-"))
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())

    def test_failed_backup_verification_keeps_original(self):
        previous_sha = self.replacement()
        original = (self.directory / "scene-presets.json").read_bytes()
        original_read = scene_profile.read_private

        def corrupt_backup(path):
            if path.name.startswith("scene-presets-backup-"):
                return {"presets": {}}
            return original_read(path)

        with patch.object(scene_profile, "read_private", side_effect=corrupt_backup):
            with self.assertRaisesRegex(configure.DiagnosticError, "scene_backup_mismatch"):
                self.capture(snapshot="changed.json", replace_sha256=previous_sha)
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())

    def test_failed_final_atomic_replace_keeps_original_and_complete_backup(self):
        previous_sha = self.replacement()
        before = self.load("scene-presets.json")
        original = (self.directory / "scene-presets.json").read_bytes()
        original_replace = os.replace

        def fail_current(source, destination):
            if Path(destination).name == "scene-presets.json":
                raise OSError("fixture final rename failure")
            return original_replace(source, destination)

        with patch.object(configure.os, "replace", side_effect=fail_current):
            with self.assertRaises(OSError):
                self.capture(snapshot="changed.json", replace_sha256=previous_sha)
        backups = list(self.directory.glob("scene-presets-backup-*.json"))
        self.assertEqual(1, len(backups))
        self.assertEqual(before, self.load(backups[0].name))
        self.assertEqual(original, (self.directory / "scene-presets.json").read_bytes())
        self.assertTrue(all(path.suffix == ".json" or path.name == ".scene-presets.lock"
                            for path in self.directory.iterdir()))

    def test_concurrent_replacements_with_same_expected_checksum_have_only_one_winner(self):
        previous_sha = self.replacement()
        before = self.load("scene-presets.json")
        self.snapshot("other.json", "08" + UNIT * 2)
        barrier = Barrier(2)

        def replace(snapshot):
            barrier.wait(timeout=5)
            try:
                return self.capture(snapshot=snapshot, replace_sha256=previous_sha)
            except configure.DiagnosticError as error:
                return {"error": str(error)}

        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(replace, ("changed.json", "other.json")))
        self.assertEqual(1, sum(result.get("ok", False) for result in results))
        self.assertEqual(["scene_previous_checksum_mismatch"], [result["error"] for result in results if "error" in result])
        backups = list(self.directory.glob("scene-presets-backup-*.json"))
        self.assertEqual(1, len(backups))
        self.assertEqual(before, self.load(backups[0].name))
        winner = next(result for result in results if result.get("ok"))
        self.assertEqual(winner["sha256"], self.load("scene-presets.json")["presets"]["blue"]["sha256"])

    def test_cli_replacement_requires_explicit_checksum_and_reports_backup_without_secrets(self):
        previous_sha = self.replacement()
        output = io.StringIO()
        original_capture = scene_profile.capture

        def local_capture(*args, **kwargs):
            return original_capture(*args, directory=self.directory, **kwargs)

        with patch.object(scene_profile, "capture", side_effect=local_capture), patch.object(sys, "stdout", output):
            code = scene_profile.main(["capture", "--alias", "blue", "--snapshot", "changed.json",
                                       "--expect-device-id", fixtures.DEVICE_ID, "--user-confirmed",
                                       "--replace-sha256", previous_sha])
        result = json.loads(output.getvalue())
        self.assertEqual(0, code)
        self.assertTrue(result["replaced"])
        self.assertEqual(PAYLOAD, self.load(result["backup"])["presets"]["blue"]["dps"]["25"])
        self.assertNotIn(fixtures.KEY, output.getvalue())
        self.assertNotIn("07" + UNIT * 8, output.getvalue())

    def test_cli_errors_suppress_secret_exceptions_and_empty_list_does_not_create_store(self):
        self.assertEqual([], scene_profile.list_presets(fixtures.DEVICE_ID, self.directory)["presets"])
        self.assertFalse((self.directory / "scene-presets.json").exists())
        output = io.StringIO()
        with patch.object(scene_profile, "capture", side_effect=RuntimeError(fixtures.KEY)), patch.object(sys, "stdout", output):
            code = scene_profile.main(["capture", "--alias", "blue", "--snapshot", "blue.json",
                                       "--expect-device-id", fixtures.DEVICE_ID, "--user-confirmed"])
        self.assertEqual(1, code)
        self.assertEqual("scene_profile_failed", json.loads(output.getvalue())["error"])
        self.assertNotIn(fixtures.KEY, output.getvalue())


if __name__ == "__main__":
    unittest.main()
