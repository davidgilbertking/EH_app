"""Offline white-profile capture/replay tests; all devices and credentials are fake."""

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
import white_profile

DEVICE_ID = "fixture_device_123456"
KEY = "test-key12345678"
ACTION = {"20": True, "21": "white", "22": 1000, "23": 332}
ENCOUNTERS = {"20": True, "21": "white", "22": 903, "23": 204}


class WhiteProfilesTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.save("device.json", {"schema_version": 1, "device_id": DEVICE_ID,
                                 "ip": "192.168.1.9", "version": "3.5", "local_key": KEY})
        entries = []
        for dp, (code, kind) in white_profile.WHITE_SCHEMA.items():
            values = {"22": {"min": 10, "max": 1000, "scale": 0, "step": 1},
                      "23": {"min": 0, "max": 1000, "scale": 0, "step": 1},
                      "21": {"range": ["white", "colour", "scene", "music"]}}.get(dp, {})
            entries.append({"dp_id": int(dp), "code": code, "type": kind, "values": json.dumps(values)})
        self.save("schema.json", {"device_id": DEVICE_ID, "schema": {"functions": entries, "status": entries}})
        self.snapshot("action.json", ACTION)

    def save(self, name, data):
        configure.save_private(self.directory, name, data)

    def load(self, name):
        return json.loads((self.directory / name).read_text())

    def snapshot(self, name, dps, selected=DEVICE_ID, version="3.5"):
        self.save(name, {"device_id": selected, "version": version, "state": {"devId": selected, "dps": dps}})

    def capture_action(self):
        return white_profile.capture_profile("action", "action.json", 100, 33, True, self.directory)

    def devices(self, before=ACTION, after=ACTION):
        devices = [Mock(), Mock()]
        for device, dps in zip(devices, (before, after)):
            device.socket = object()
            device.status.return_value = {"devId": DEVICE_ID, "dps": dps}
            device.set_multiple_values.return_value = None
        return devices

    def replay(self, factory, selected=DEVICE_ID):
        return white_profile.replay_profile("action", selected, self.directory, device_factory=factory)

    def test_capture_preserves_other_profiles_and_exact_values_without_interpolation(self):
        self.capture_action()
        action = self.load("calibration.json")["white_profiles"]["action"]
        self.snapshot("encounters.json", ENCOUNTERS)
        result = white_profile.capture_profile("encounters", "encounters.json", 90, 20, True, self.directory)
        calibration = self.load("calibration.json")
        self.assertEqual(action, calibration["white_profiles"]["action"])
        self.assertEqual(ENCOUNTERS, calibration["white_profiles"]["encounters"]["dps"])
        self.assertFalse(calibration["white_profiles"]["encounters"]["replay_verified"])
        self.assertEqual(0o600, stat.S_IMODE((self.directory / "calibration.json").stat().st_mode))
        self.assertNotIn(KEY, json.dumps(calibration))
        self.assertNotIn(KEY, json.dumps(result))

    def test_capture_rejects_unconfirmed_wrong_target_protocol_mode_or_out_of_range(self):
        for name, dps, selected, version, brightness, temperature, confirmed in (
            ("unconfirmed.json", ACTION, DEVICE_ID, "3.5", 100, 33, False),
            ("wrong-device.json", ACTION, "other_device_123456", "3.5", 100, 33, True),
            ("wrong-protocol.json", ACTION, DEVICE_ID, "3.3", 100, 33, True),
            ("scene.json", dict(ACTION, **{"21": "scene"}), DEVICE_ID, "3.5", 100, 33, True),
            ("off.json", dict(ACTION, **{"20": False}), DEVICE_ID, "3.5", 100, 33, True),
            ("overflow.json", dict(ACTION, **{"22": 1001}), DEVICE_ID, "3.5", 100, 33, True),
            ("wrong-ui.json", ACTION, DEVICE_ID, "3.5", 100, 34, True),
        ):
            with self.subTest(snapshot=name):
                self.snapshot(name, dps, selected, version)
                with self.assertRaises(configure.DiagnosticError):
                    white_profile.capture_profile("action", name, brightness, temperature, confirmed, self.directory)
                self.assertFalse((self.directory / "calibration.json").exists())

    def test_replay_one_atomic_write_with_exact_payload_and_independent_readback(self):
        self.capture_action()
        before = (self.directory / "calibration.json").read_bytes()
        writer, reader = self.devices()
        factory = Mock(side_effect=[writer, reader])
        result = self.replay(factory)
        self.assertTrue(result["ok"])
        self.assertTrue(result["readback_matched"])
        self.assertFalse(result["replay_verified"])
        self.assertFalse(result["visual_verified"])
        writer.set_multiple_values.assert_called_once_with(ACTION, nowait=True)
        writer.set_value.assert_not_called()
        reader.set_multiple_values.assert_not_called()
        writer.close.assert_called_once()
        reader.close.assert_called_once()
        self.assertEqual(["set_retry", "status", "set_socketRetryLimit", "set_multiple_values", "close"],
                         [call[0] for call in writer.method_calls])
        writer.set_socketRetryLimit.assert_called_once_with(0)
        self.assertEqual(0, factory.call_args_list[0].kwargs["max_simultaneous_dps"])
        self.assertTrue(factory.call_args_list[0].kwargs["persist"])
        self.assertFalse(factory.call_args_list[1].kwargs["persist"])
        self.assertEqual(before, (self.directory / "calibration.json").read_bytes())

    def test_replay_rejects_wrong_target_and_schema_before_creating_device(self):
        self.capture_action()
        factory = Mock()
        self.assertEqual("configured_device_mismatch", self.replay(factory, "other_device_123456")["error"])
        factory.assert_not_called()
        schema = self.load("schema.json")
        schema["schema"]["functions"][0]["code"] = "unverified_switch"
        self.save("schema.json", schema)
        self.assertEqual("invalid_white_schema", self.replay(factory)["error"])
        factory.assert_not_called()

    def test_replay_rejects_arbitrary_extra_dps_in_calibration_before_any_connection(self):
        self.capture_action()
        calibration = self.load("calibration.json")
        calibration["white_profiles"]["action"]["dps"]["25"] = "unverified-scene"
        self.save("calibration.json", calibration)
        factory = Mock()
        self.assertEqual("invalid_white_profile_dps", self.replay(factory)["error"])
        factory.assert_not_called()

    def test_replay_rejects_colour_scene_or_off_before_writing(self):
        self.capture_action()
        for before in (dict(ACTION, **{"21": "colour"}), dict(ACTION, **{"21": "scene"}), dict(ACTION, **{"20": False})):
            with self.subTest(before=before):
                writer, _reader = self.devices(before=before)
                factory = Mock(return_value=writer)
                result = self.replay(factory)
                self.assertFalse(result["write_attempted"])
                self.assertEqual("lamp_must_be_on_and_white", result["error"])
                writer.set_multiple_values.assert_not_called()
                writer.close.assert_called_once()

    def test_timeout_or_error_after_write_never_retries_unknown_command(self):
        self.capture_action()
        for failure in (TimeoutError(KEY), {"Err": "914", "Error": KEY}, configure.DeadlineExceeded()):
            with self.subTest(failure=type(failure).__name__):
                writer, _reader = self.devices()
                if isinstance(failure, BaseException):
                    writer.set_multiple_values.side_effect = failure
                else:
                    writer.set_multiple_values.return_value = failure
                factory = Mock(return_value=writer)
                output = io.StringIO()
                with patch.object(sys, "stdout", output):
                    result = self.replay(factory)
                self.assertFalse(result["ok"])
                self.assertTrue(result["outcome_unknown"])
                self.assertTrue(result["write_attempted"])
                writer.set_multiple_values.assert_called_once_with(ACTION, nowait=True)
                writer.set_value.assert_not_called()
                writer.close.assert_called_once()
                self.assertEqual(1, factory.call_count)
                self.assertNotIn(KEY, json.dumps(result) + output.getvalue())

    def test_readback_mismatch_does_not_resend_or_claim_physical_verification(self):
        self.capture_action()
        writer, reader = self.devices(after=ENCOUNTERS)
        result = self.replay(Mock(side_effect=[writer, reader]))
        self.assertEqual("readback_mismatch", result["error"])
        self.assertFalse(result["readback_matched"])
        self.assertFalse(result["replay_verified"])
        self.assertFalse(result["visual_verified"])
        writer.set_multiple_values.assert_called_once()
        reader.set_multiple_values.assert_not_called()

    def test_closed_preflight_socket_prevents_a_write_or_reconnect(self):
        self.capture_action()
        writer, _reader = self.devices()
        writer.socket = None
        result = self.replay(Mock(return_value=writer))
        self.assertEqual("preflight_socket_closed", result["error"])
        self.assertFalse(result["write_attempted"])
        writer.set_multiple_values.assert_not_called()
        writer.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
