"""Offline DP28 probe tests; isolated fixtures and fake devices only."""

import io
import json
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import native_white_probe
import white_profile

DEVICE_ID = "fixture_device_123456"
KEY = "test-key12345678"
ACTION = {"20": True, "21": "white", "22": 1000, "23": 332}
DARK = {"20": True, "21": "white", "22": 10, "23": 0}


class NativeWhiteProbeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.save("device.json", {"schema_version": 1, "device_id": DEVICE_ID,
                                 "ip": "192.168.1.9", "version": "3.5", "local_key": KEY})
        white_entries = []
        for dp, (code, kind) in white_profile.WHITE_SCHEMA.items():
            values = {"22": self.range(10, 1000), "23": self.range(0, 1000),
                      "21": {"range": ["white", "colour", "scene", "music"]}}.get(dp, {})
            white_entries.append({"dp_id": int(dp), "code": code, "type": kind, "values": json.dumps(values)})
        self.native_values = {"change_mode": {"range": ["direct", "gradient"]},
                              "h": self.range(0, 360), "s": self.range(0, 255), "v": self.range(0, 255),
                              "bright": self.range(0, 1000), "temperature": self.range(0, 1000)}
        native = {"dp_id": 28, "code": "control_data", "type": "Json", "values": json.dumps(self.native_values)}
        self.save("schema.json", {"device_id": DEVICE_ID, "schema": {
            "functions": white_entries + [native], "status": white_entries,
        }})
        profiles = {}
        for name, dps, percentages in (("action", ACTION, (100, 33)), ("dark", DARK, (1, 0))):
            profiles[name] = {"ui": dict(zip(("brightness", "temperature"), percentages)),
                              "dps": dps, "source_snapshot": name + ".json", "captured_at": "test-time",
                              "user_confirmed": True, "replay_verified": True}
        self.save("calibration.json", {"schema_version": 1, "device_id": DEVICE_ID,
                                      "protocol": "3.5", "white_profiles": profiles})

    @staticmethod
    def range(minimum, maximum):
        return {"min": minimum, "max": maximum, "scale": 0, "step": 1}

    def save(self, name, value):
        configure.save_private(self.directory, name, value)

    def load(self, name):
        return json.loads((self.directory / name).read_text())

    def devices(self, before=DARK, stored=DARK):
        writer, reader = Mock(), Mock()
        for device, dps in ((writer, before), (reader, stored)):
            device.socket = object()
            device.status.return_value = {"devId": DEVICE_ID, "dps": dps}
            device.set_multiple_values.return_value = None
        return writer, reader

    def probe(self, factory, expected_id=DEVICE_ID):
        return native_white_probe.probe("dark", "action", expected_id, self.directory, device_factory=factory)

    def test_one_dp28_packet_and_old_stored_values_do_not_imply_physical_verdict(self):
        before = (self.directory / "calibration.json").read_bytes()
        writer, reader = self.devices()
        factory = Mock(side_effect=[writer, reader])
        result = self.probe(factory)
        self.assertTrue(result["ok"])
        self.assertEqual("sent_without_ack", result["sendOutcome"])
        self.assertEqual(DARK, result["storedWhiteValues"])
        self.assertEqual(ACTION, result["targetWhiteValues"])
        self.assertTrue(result["visual_verification_required"])
        self.assertFalse(result["native_transition_verified"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertNotIn("readback_matched", result)
        payload = "1" + "0000" * 3 + "03e8" + "014c"
        self.assertEqual(21, len(payload))
        writer.set_multiple_values.assert_called_once_with({"28": payload}, nowait=True)
        writer.set_socketRetryLimit.assert_called_once_with(0)
        self.assertEqual(["set_retry", "status", "set_socketRetryLimit", "set_multiple_values", "close"],
                         [call[0] for call in writer.method_calls])
        reader.set_multiple_values.assert_not_called()
        writer.close.assert_called_once()
        reader.close.assert_called_once()
        report = next(self.directory.glob("native-white-probe-*.json"))
        self.assertEqual(0o600, stat.S_IMODE(report.stat().st_mode))
        self.assertNotIn(KEY, report.read_text() + json.dumps(result))
        self.assertEqual(before, (self.directory / "calibration.json").read_bytes())

    def test_direct_payload_changes_only_mode_flag_and_gradient_default_is_preserved(self):
        expected_tail = "0000" * 3 + "03e8" + "014c"
        self.assertEqual("0" + expected_tail, native_white_probe.native_payload(
            self.directory, DEVICE_ID, ACTION, change_mode="direct"))
        self.assertEqual("1" + expected_tail, native_white_probe.native_payload(self.directory, DEVICE_ID, ACTION))
        self.assertEqual("1" + expected_tail, native_white_probe.native_payload(
            self.directory, DEVICE_ID, ACTION, change_mode="gradient"))
        with self.assertRaisesRegex(configure.DiagnosticError, "invalid_native_mode"):
            native_white_probe.native_payload(self.directory, DEVICE_ID, ACTION, change_mode="flashing")

    def test_wrong_source_or_mode_is_rejected_without_write(self):
        for current in (ACTION, dict(DARK, **{"21": "scene"}), dict(DARK, **{"20": False})):
            with self.subTest(current=current):
                writer, _reader = self.devices(before=current)
                result = self.probe(Mock(return_value=writer))
                self.assertFalse(result["ok"])
                self.assertFalse(result["oneCommandAttempted"])
                writer.set_multiple_values.assert_not_called()
                writer.close.assert_called_once()

    def test_wrong_id_unverified_profile_and_schema_fail_before_device_creation(self):
        factory = Mock()
        result = self.probe(factory, "other_device_123456")
        self.assertEqual("configured_device_mismatch", result["error"])
        calibration = self.load("calibration.json")
        calibration["white_profiles"]["action"]["replay_verified"] = False
        self.save("calibration.json", calibration)
        self.assertEqual("profile_not_visually_verified", self.probe(factory)["error"])
        calibration["white_profiles"]["action"]["replay_verified"] = True
        self.save("calibration.json", calibration)
        schema = self.load("schema.json")
        schema["schema"]["functions"][-1]["code"] = "wrong_control"
        self.save("schema.json", schema)
        self.assertEqual("invalid_control_data_schema", self.probe(factory)["error"])
        factory.assert_not_called()

    def test_gradient_and_all_components_must_fit_actual_schema(self):
        for field, value in (("change_mode", {"range": ["direct"]}),
                             ("s", self.range(1, 255)), ("bright", self.range(0, 999))):
            with self.subTest(field=field):
                schema = self.load("schema.json")
                altered = dict(self.native_values, **{field: value})
                schema["schema"]["functions"][-1]["values"] = json.dumps(altered)
                self.save("schema.json", schema)
                factory = Mock()
                self.assertFalse(self.probe(factory)["oneCommandAttempted"])
                factory.assert_not_called()

    def test_send_timeout_or_deadline_never_retries_or_restores_white_profile(self):
        for failure in (TimeoutError(KEY), configure.DeadlineExceeded(), {"Err": "914", "Error": KEY}):
            with self.subTest(failure=type(failure).__name__):
                writer, _reader = self.devices()
                if isinstance(failure, BaseException):
                    writer.set_multiple_values.side_effect = failure
                else:
                    writer.set_multiple_values.return_value = failure
                factory = Mock(return_value=writer)
                output = io.StringIO()
                with patch.object(sys, "stdout", output):
                    result = self.probe(factory)
                self.assertFalse(result["ok"])
                self.assertTrue(result["oneCommandAttempted"])
                self.assertEqual("unknown", result["sendOutcome"])
                writer.set_multiple_values.assert_called_once()
                writer.set_value.assert_not_called()
                writer.close.assert_called_once()
                self.assertEqual(1, factory.call_count)
                self.assertNotIn(KEY, output.getvalue() + json.dumps(result))


if __name__ == "__main__":
    unittest.main()
