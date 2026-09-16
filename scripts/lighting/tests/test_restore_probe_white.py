"""Offline cleanup tests; fake credentials, responses, clocks and connections."""

import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import restore_probe_white
import test_native_white_probe as fixtures
from timed_white_probe import exclusive_probe

REPORT = "native-scene-probe-20260916T160409_344634Z.json"
SCENE = "00" + "282802" + "0000" * 3 + "03e8014c"


class RestoreProbeWhiteTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load

    def setUp(self):
        fixtures.NativeWhiteProbeTest.setUp(self)
        self.save(REPORT, {"schema_version": 1, "device_id": fixtures.DEVICE_ID, "protocol": "3.5",
                           "command": {"25": SCENE, "21": "scene"}})
        self.now, self.queries, self.writes = 0.0, 0, []
        self.original = {"devId": fixtures.DEVICE_ID, "dps": dict(fixtures.DARK, **{"21": "scene", "25": SCENE})}
        self.writer, self.observer = Mock(), Mock()
        self.writer.socket = object()
        self.writer.status.side_effect = self.status
        self.writer.set_multiple_values.side_effect = self.send
        self.observer.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": dict(fixtures.ACTION, **{"25": SCENE})}
        self.factory = Mock(side_effect=[self.writer, self.observer])
        self.confirmed = True
        self.reader_factory = Mock(side_effect=self.reader)

    def status(self, nowait=False):
        if nowait:
            self.queries += 1
            return None
        return self.original

    def send(self, dps, nowait):
        self.assertTrue(nowait)
        backups = list(self.directory.glob("restore-probe-before-*.json"))
        self.assertEqual(1, len(backups))
        self.assertEqual(self.original, json.loads(backups[0].read_text())["state"])
        self.writes.append(dps)

    def reader(self, device, clock):
        test = self
        self.assertIs(device, self.writer)

        class Reader:
            def drain_until(self, until, cancelled, on_message):
                test.now = until
                if cancelled():
                    raise configure.DiagnosticError("cancelled")
                on_message({"metadata": {"cmd": 13, "seq": 4000, "retcode": 0, "emptyAck": True}, "response": None})
                # A complete but queued report before the status query must not
                # count if the only later reply is an ACK.
                if test.queries == 0 or test.confirmed:
                    on_message({"metadata": {"cmd": 8, "seq": 1, "retcode": 0, "emptyAck": False},
                                "response": {"devId": fixtures.DEVICE_ID, "dps": fixtures.ACTION}})

        return Reader()

    def restore(self, **overrides):
        arguments = {"expected_id": fixtures.DEVICE_ID, "probe_report": REPORT, "directory": self.directory,
                     "device_factory": self.factory, "response_reader_factory": self.reader_factory,
                     "clock": lambda: self.now}
        arguments.update(overrides)
        return restore_probe_white.restore(**arguments)

    def test_one_ordered_action_write_confirmed_on_writer_and_fresh_read_no_scene_restore(self):
        calibration = (self.directory / "calibration.json").read_bytes()
        result = self.restore()
        self.assertTrue(result["ok"], result.get("error"))
        self.assertEqual([fixtures.ACTION], self.writes)
        self.assertEqual(["20", "22", "23", "21"], list(self.writes[0]))
        self.assertEqual(1, result["commandsAttempted"])
        self.assertTrue(result["storedActionConfirmedOnWriter"])
        self.assertTrue(result["readback_matched"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertEqual(1, self.queries)
        self.assertEqual(3.0, self.now)
        self.writer.set_socketRetryLimit.assert_called_once_with(0)
        self.writer.set_sendWait.assert_called_once_with(None)
        self.writer.set_value.assert_not_called()
        self.observer.set_multiple_values.assert_not_called()
        self.writer.close.assert_called_once()
        self.observer.close.assert_called_once()
        self.assertEqual([True, False], [call.kwargs["persist"] for call in self.factory.call_args_list])
        self.assertTrue(all(call.kwargs["max_simultaneous_dps"] == 0 for call in self.factory.call_args_list))
        self.assertEqual(calibration, (self.directory / "calibration.json").read_bytes())
        for path in self.directory.glob("restore-probe-*.json"):
            self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertNotIn(fixtures.KEY, path.read_text())

    def test_report_identity_protocol_or_path_and_unverified_action_reject_before_connection(self):
        for name in ("../" + REPORT, "status.json", "/tmp/" + REPORT):
            self.assertEqual("invalid_probe_report_name", self.restore(probe_report=name)["error"])
        self.assertEqual("configured_device_mismatch", self.restore(expected_id="other_device_123456")["error"])
        original = self.load(REPORT)
        for field, value in (("device_id", "other_device_123456"), ("protocol", "3.3"), ("schema_version", 2)):
            self.save(REPORT, dict(original, **{field: value}))
            self.assertEqual("probe_report_device_or_protocol_mismatch", self.restore()["error"])
        self.save(REPORT, original)
        calibration = self.load("calibration.json")
        calibration["white_profiles"]["action"]["replay_verified"] = False
        self.save("calibration.json", calibration)
        self.assertEqual("action_not_visually_verified", self.restore()["error"])
        self.factory.assert_not_called()

    def test_all_report_units_must_be_white_static_or_gradient_with_bounded_values(self):
        original = self.load(REPORT)
        bad_units = ["282801" + "0000" * 3 + "03e8014c",  # Jump is outside this cleanup scope.
                     "282802000103e803e803e8014c", "282802" + "0000" * 3 + "0000014c",
                     "282802" + "0000" * 3 + "03e903e9", "ff2802" + "0000" * 3 + "03e8014c"]
        for unit in bad_units:
            self.save(REPORT, dict(original, command={"25": SCENE + unit, "21": "scene"}))
            self.assertFalse(self.restore()["ok"])
            self.factory.assert_not_called()
        # Multiple white units, including a static terminal unit, are valid.
        valid = SCENE + "282800" + "0000" * 3 + "03e8014c"
        self.save(REPORT, dict(original, command={"25": valid, "21": "scene"}))
        self.assertEqual(valid, restore_probe_white.reported_white_scene(self.directory, REPORT, fixtures.DEVICE_ID))

    def test_live_off_white_or_different_scene_never_writes(self):
        for override in ({"20": False}, {"21": "white"}, {"25": "01" + SCENE[2:]}):
            with self.subTest(override=override):
                self.setUp()
                self.original["dps"].update(override)
                self.assertEqual("current_scene_does_not_match_probe", self.restore()["error"])
                self.writer.set_multiple_values.assert_not_called()
                self.writer.close.assert_called_once()

    def test_stale_action_and_empty_ack_are_not_confirmation_and_prevent_fresh_connection(self):
        self.confirmed = False
        result = self.restore()
        self.assertEqual("stored_action_unconfirmed_on_writer", result["error"])
        self.assertEqual(1, len(self.writes))
        self.assertEqual(1, self.factory.call_count)
        self.assertFalse(result["readback_matched"])

    def test_timeout_or_uncertain_write_never_retries_or_restores(self):
        for failure in (TimeoutError(fixtures.KEY), {"Err": "914", "Error": fixtures.KEY}):
            with self.subTest(failure=type(failure).__name__):
                self.setUp()
                def send(dps, nowait):
                    self.send(dps, nowait)
                    if isinstance(failure, BaseException):
                        raise failure
                    return failure
                self.writer.set_multiple_values.side_effect = send
                result = self.restore()
                self.assertFalse(result["ok"])
                self.assertEqual(1, len(self.writes))
                self.assertEqual(0, self.queries)
                self.assertEqual(1, self.factory.call_count)
                self.assertNotIn(fixtures.KEY, json.dumps(result))

    def test_cancel_deadline_and_fresh_read_mismatch_do_not_issue_another_write(self):
        self.assertEqual("cancelled", self.restore(cancelled=lambda: self.now >= 1)["error"])
        self.assertEqual(1, len(self.writes))
        self.assertEqual(0, self.queries)
        self.setUp()
        reader = Mock()
        reader.drain_until.side_effect = configure.DeadlineExceeded()
        self.reader_factory.side_effect = None
        self.reader_factory.return_value = reader
        self.assertEqual("restore_deadline_exceeded", self.restore()["error"])
        self.assertEqual(1, len(self.writes))
        self.setUp()
        self.observer.status.return_value = {"dps": fixtures.DARK}
        self.assertEqual("stored_action_mismatch", self.restore()["error"])
        self.assertEqual(1, len(self.writes))

    def test_backup_failure_or_lock_prevents_control_write(self):
        with patch.object(restore_probe_white, "save_private", side_effect=OSError("fixture")):
            self.assertFalse(self.restore()["ok"])
        self.writer.set_multiple_values.assert_not_called()
        self.setUp()
        with exclusive_probe(self.directory):
            self.assertEqual("probe_already_running", self.restore()["error"])
        self.factory.assert_not_called()


if __name__ == "__main__":
    unittest.main()
