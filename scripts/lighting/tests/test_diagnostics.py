"""Offline tests: mocked TinyTuya/HTTP only, no cloud, LAN, or private real config."""

import io
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import read_state

DEVICE_ID = "test_device_123456"
KEY = "test-key12345678"
SECRET = "cloud-secret-must-never-print"


class DiagnosticsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)

    def save_config(self, **changes):
        payload = {"schema_version": 1, "device_id": DEVICE_ID, "ip": "192.168.1.8",
                   "version": "3.5", "local_key": KEY}
        payload.update(changes)
        configure.save_private(self.directory, "device.json", payload)

    def test_configuration_saves_only_selected_connection_owner_only(self):
        fetch = Mock(return_value=KEY)
        result = configure.configure_connection("eu", "access", SECRET, DEVICE_ID,
                                                "192.168.1.8", "3.5", self.directory, fetch)
        fetch.assert_called_once_with("eu", "access", SECRET, DEVICE_ID)
        saved = (self.directory / "device.json").read_text()
        self.assertIn(KEY, saved)
        self.assertNotIn(SECRET, saved)
        self.assertNotIn(SECRET, json.dumps(result))
        self.assertNotIn(KEY, json.dumps(result))
        self.assertEqual(0o600, stat.S_IMODE((self.directory / "device.json").stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(self.directory.stat().st_mode))

    def test_invalid_key_does_not_overwrite_existing_configuration(self):
        self.save_config()
        before = (self.directory / "device.json").read_bytes()
        with self.assertRaisesRegex(configure.DiagnosticError, "invalid_local_key"):
            configure.configure_connection("eu", "access", SECRET, DEVICE_ID,
                                           "192.168.1.8", "3.5", self.directory, Mock(return_value="short"))
        self.assertEqual(before, (self.directory / "device.json").read_bytes())

    def test_cloud_retrieves_exact_device_only_and_rejects_wrong_target(self):
        cloud = Mock(token="test-token")
        cloud.cloudrequest.return_value = {"success": True, "result": {"id": "different_device", "local_key": KEY}}
        module = types.SimpleNamespace(Cloud=Mock(return_value=cloud), requests=object())
        session = Mock()
        session.__enter__ = Mock(return_value=session)
        session.__exit__ = Mock(return_value=False)
        requests = types.SimpleNamespace(Session=Mock(return_value=session))
        modules = {"tinytuya.Cloud": module, "tinytuya": types.SimpleNamespace(__version__="1.20.0"), "requests": requests}
        with patch.object(configure.importlib, "import_module", side_effect=modules.__getitem__):
            with self.assertRaisesRegex(configure.DiagnosticError, "cloud_device_mismatch"):
                configure.fetch_local_key("eu", "access", SECRET, DEVICE_ID)
        cloud.cloudrequest.assert_called_once_with(f"/v1.0/devices/{DEVICE_ID}", action="GET")
        cloud.getdevices.assert_not_called()
        self.assertEqual("", cloud.apiSecret)
        self.assertIsNone(cloud.token)

    def test_cloud_transport_limits_endpoints_timeouts_redirects_and_writes(self):
        session = Mock()
        session.get.return_value.status_code = 200
        transport = configure.SelectedDeviceTransport(session, "eu", DEVICE_ID)
        url = f"https://openapi.tuyaeu.com/v1.0/devices/{DEVICE_ID}"
        transport.get(url, headers={"test": "value"})
        session.get.assert_called_once_with(url, timeout=(5, 10), allow_redirects=False, headers={"test": "value"})
        for unsafe in ("https://openapi.tuyaeu.com/v1.0/devices", "https://wrong.example/v1.0/token?grant_type=1"):
            with self.assertRaises(configure.DiagnosticError):
                transport.get(unsafe)
        with self.assertRaisesRegex(configure.DiagnosticError, "cloud_write_forbidden"):
            transport.request("POST", url)

    def test_cloud_business_error_prints_only_short_ascii_numeric_code(self):
        for code, expected in ((1106, "_1106"), ("1106", "_1106"),
                               (SECRET, ""), ("123456789", ""), ("١١٠٦", ""),
                               ({"secret": SECRET}, "")):
            with self.subTest(code=code):
                cloud = Mock(token="test-token")
                cloud.cloudrequest.return_value = {
                    "success": False, "code": code, "msg": SECRET, "result": {"local_key": KEY},
                }
                module = types.SimpleNamespace(Cloud=Mock(return_value=cloud), requests=object())
                session = Mock()
                session.__enter__ = Mock(return_value=session)
                session.__exit__ = Mock(return_value=False)
                modules = {
                    "tinytuya.Cloud": module,
                    "tinytuya": types.SimpleNamespace(__version__="1.20.0"),
                    "requests": types.SimpleNamespace(Session=Mock(return_value=session)),
                }
                output = io.StringIO()
                with patch.object(configure.importlib, "import_module", side_effect=modules.__getitem__), \
                     patch.object(configure.sys.stdin, "isatty", return_value=True), \
                     patch.object(configure.getpass, "getpass", side_effect=["access", SECRET]), \
                     patch.object(sys, "stdout", output):
                    exit_code = configure.main([
                        "--device-id", DEVICE_ID, "--ip", "192.168.1.8", "--version", "3.5", "--region", "eu",
                    ])
                self.assertEqual(1, exit_code)
                self.assertEqual("cloud_device_details_failed" + expected, json.loads(output.getvalue())["error"])
                self.assertNotIn(SECRET, output.getvalue())
                self.assertNotIn(KEY, output.getvalue())
                cloud.getdevices.assert_not_called()

    def test_status_only_saved_privately_and_stdout_summary_contains_no_values(self):
        self.save_config()
        device = Mock()
        device.status.return_value = {"devId": DEVICE_ID, "dps": {"20": True, "25": SECRET, "23": 17}}
        factory = Mock(return_value=device)
        result = read_state.read_state(self.directory, device_factory=factory)
        self.assertEqual(3, result["dps_count"])
        self.assertEqual(["20", "23", "25"], [item["id"] for item in result["dps"]])
        self.assertNotIn(SECRET, json.dumps(result))
        self.assertNotIn(KEY, json.dumps(result))
        self.assertTrue(device.disabledetect)
        device.status.assert_called_once_with()
        device.close.assert_called_once_with()
        self.assertEqual(["set_retry", "status", "close"], [call[0] for call in device.method_calls])
        snapshot = next(self.directory.glob("status-*.json"))
        self.assertEqual(0o600, stat.S_IMODE(snapshot.stat().st_mode))
        self.assertIn(SECRET, snapshot.read_text())  # Raw values stay only in private snapshot.

    def test_read_fails_closed_before_network_on_bad_key_wrong_target_or_autodetect_version(self):
        cases = [({"local_key": "short"}, None, "invalid_local_key"),
                 ({}, "other_device", "configured_device_mismatch"),
                 ({"version": "3.2"}, None, "unsupported_read_only_protocol")]
        for changes, expected, error in cases:
            self.save_config(**changes)
            factory = Mock()
            with self.assertRaisesRegex(configure.DiagnosticError, error):
                read_state.read_state(self.directory, expected_id=expected, device_factory=factory)
            factory.assert_not_called()

    def test_wrong_status_target_fails_closed_and_socket_is_closed(self):
        self.save_config()
        device = Mock()
        device.status.return_value = {"devId": "other_device", "dps": {"20": True}}
        with self.assertRaisesRegex(configure.DiagnosticError, "status_device_mismatch"):
            read_state.read_state(self.directory, device_factory=Mock(return_value=device))
        device.close.assert_called_once()
        self.assertEqual([], list(self.directory.glob("status-*.json")))

    def test_retries_bounded_and_library_output_exception_secrets_never_print(self):
        self.save_config()
        device = Mock()
        def failure():
            print(SECRET)
            raise RuntimeError(KEY)
        device.status.side_effect = failure
        factory = Mock(return_value=device)
        output = io.StringIO()
        with patch("sys.stdout", output):
            with self.assertRaisesRegex(configure.DiagnosticError, "status_transport_failed"):
                read_state.read_state(self.directory, attempts=2, device_factory=factory)
        self.assertEqual(2, device.status.call_count)
        self.assertEqual(2, device.close.call_count)
        self.assertEqual("", output.getvalue())
        with patch.object(read_state, "read_state", side_effect=RuntimeError(SECRET)), patch("sys.stdout", output):
            self.assertEqual(1, read_state.main([]))
        self.assertNotIn(SECRET, output.getvalue())
        self.assertNotIn(KEY, output.getvalue())

    def test_cli_configuration_never_echoes_secret_bearing_exception(self):
        output = io.StringIO()
        with patch.object(configure.sys.stdin, "isatty", return_value=True), \
             patch.object(configure.getpass, "getpass", side_effect=["access", SECRET]), \
             patch.object(configure, "configure_connection", side_effect=RuntimeError(SECRET)), \
             patch("sys.stdout", output):
            self.assertEqual(1, configure.main(["--device-id", DEVICE_ID, "--ip", "192.168.1.8", "--version", "3.5", "--region", "eu"]))
        self.assertNotIn(SECRET, output.getvalue())
        self.assertEqual("configuration_failed", json.loads(output.getvalue())["error"])

    def test_device_error_code_only_not_message_or_payload(self):
        with self.assertRaisesRegex(configure.DiagnosticError, "^device_error_914$"):
            read_state.inspect_status({"Err": "914", "Error": SECRET, "Payload": KEY}, DEVICE_ID)
        with self.assertRaisesRegex(configure.DiagnosticError, "^device_error$"):
            read_state.inspect_status({"Err": SECRET, "Error": KEY}, DEVICE_ID)


if __name__ == "__main__":
    unittest.main()
