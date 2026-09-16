"""Offline scene replay checks with temporary files, fake sockets and clocks."""

import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import scene_profile
import scene_replay_probe
import test_native_white_probe as fixtures
import test_scene_profiles as scenes
from timed_white_probe import ProbeInterrupted, exclusive_probe


class SceneReplayProbeTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load
    set_scene_schema = scenes.SceneProfilesTest.set_scene_schema
    snapshot = scenes.SceneProfilesTest.snapshot

    def setUp(self):
        scenes.SceneProfilesTest.setUp(self)
        scene_profile.capture("blue", "blue.json", fixtures.DEVICE_ID, True, self.directory)
        self.now, self.queries, self.writes = 0.0, 0, []
        self.original = {"devId": fixtures.DEVICE_ID, "dps": dict(fixtures.DARK, **{"25": "old-program"})}
        self.target = {"20": True, "21": "scene", "25": scenes.PAYLOAD}
        self.writer, self.observer = Mock(), Mock()
        self.writer.socket = object()
        self.writer.status.side_effect = self.status
        self.writer.set_multiple_values.side_effect = self.send
        self.observer.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": self.target}
        self.factory = Mock(side_effect=self.connect)
        self.reader_factory = Mock(side_effect=self.reader)
        self.writer_reply = "full"

    def connect(self, *args, **kwargs):
        if kwargs["persist"]:
            return self.writer
        self.writer.close.assert_called_once()  # No overlapping LAN clients.
        return self.observer

    def status(self, nowait=False):
        if nowait:
            self.queries += 1
            return None
        return self.original

    def send(self, dps, nowait):
        self.assertTrue(nowait)
        self.writer.set_socketRetryLimit.assert_called_once_with(0)
        self.writer.set_sendWait.assert_called_once_with(None)
        backup = next(self.directory.glob("scene-replay-before-*.json"))
        report = next(self.directory.glob("scene-replay-probe-*.json"))
        self.assertEqual(self.original, json.loads(backup.read_text())["state"])
        self.assertEqual(dps, json.loads(report.read_text())["command"])
        self.assertTrue(json.loads(report.read_text())["scene_may_be_active"])
        self.writes.append(dict(dps))

    def reader(self, device, clock):
        self.assertIs(device, self.writer)
        test = self

        class Reader:
            def drain_until(self, until, cancelled, on_message):
                test.now = until
                if cancelled():
                    raise configure.DiagnosticError("cancelled")
                on_message({"metadata": {"cmd": 13, "seq": 901, "retcode": 0, "emptyAck": True}, "response": None})
                # An old full report received before the explicit query must
                # not turn later empty ACKs or partial fields into state proof.
                dps = test.target if test.queries == 0 else {
                    "full": test.target, "partial": {"21": "scene"},
                    "mismatch": fixtures.DARK, "ack": None,
                }[test.writer_reply]
                if dps is not None:
                    on_message({"metadata": {"cmd": 8, "seq": 2, "retcode": 0, "emptyAck": False},
                                "response": {"devId": fixtures.DEVICE_ID, "dps": dps}})

        return Reader()

    def replay(self, **overrides):
        arguments = {"alias": "blue", "expected_id": fixtures.DEVICE_ID, "directory": self.directory,
                     "device_factory": self.factory, "response_reader_factory": self.reader_factory,
                     "clock": lambda: self.now}
        arguments.update(overrides)
        with patch("socket.socket", side_effect=AssertionError("Live network is forbidden")):
            return scene_replay_probe.replay(**arguments)

    def test_one_exact_scene_write_and_independent_confirmation_preserve_saved_presets(self):
        saved = (self.directory / "scene-presets.json").read_bytes()
        result = self.replay()
        self.assertTrue(result["ok"], result.get("error"))
        self.assertEqual([{"25": scenes.PAYLOAD, "21": "scene"}], self.writes)
        self.assertEqual(["25", "21"], list(self.writes[0]))
        self.assertEqual(1, result["commandsAttempted"])
        self.assertEqual(1, self.queries)
        self.assertTrue(result["storedTargetConfirmedOnWriter"])
        self.assertTrue(result["readback_matched"])
        self.assertTrue(result["scene_left_active"])
        self.assertFalse(result["replay_verified"])
        self.assertTrue(result["visual_verification_required"])
        self.assertEqual(3.0, self.now)
        self.writer.set_socketNODELAY.assert_called_once_with(True)
        self.writer.set_retry.assert_called_once_with(False)
        self.assertTrue(self.writer.disabledetect)
        self.writer.set_value.assert_not_called()
        self.observer.set_multiple_values.assert_not_called()
        self.writer.close.assert_called_once()
        self.observer.close.assert_called_once()
        self.assertEqual([True, False], [call.kwargs["persist"] for call in self.factory.call_args_list])
        self.assertTrue(all(call.kwargs["max_simultaneous_dps"] == 0 for call in self.factory.call_args_list))
        self.assertEqual(saved, (self.directory / "scene-presets.json").read_bytes())
        for path in self.directory.glob("scene-replay-*.json"):
            self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertNotIn(fixtures.KEY, path.read_text())
        self.assertNotIn(fixtures.KEY, json.dumps(result))
        self.assertNotIn(scenes.PAYLOAD, json.dumps(result))

    def test_wrong_alias_id_missing_unconfirmed_checksum_or_schema_never_connects(self):
        self.assertEqual("invalid_scene_alias", self.replay(alias="red")["error"])
        self.assertEqual("configured_device_mismatch", self.replay(expected_id="other_device_123456")["error"])
        self.assertEqual("scene_preset_not_captured", self.replay(alias="green")["error"])
        original = self.load("scene-presets.json")
        for field, value in (("user_confirmed", False), ("sha256", "0" * 64)):
            changed = json.loads(json.dumps(original))
            changed["presets"]["blue"][field] = value
            self.save("scene-presets.json", changed)
            self.assertFalse(self.replay()["ok"])
        self.save("scene-presets.json", original)
        schema = self.load("schema.json")
        schema["schema"]["status"][-1]["type"] = "String"
        self.save("schema.json", schema)
        self.assertFalse(self.replay()["ok"])
        self.factory.assert_not_called()

    def test_dark_must_be_user_confirmed_replay_verified_and_exact_10_zero(self):
        original = self.load("calibration.json")
        for field, value in (("user_confirmed", False), ("replay_verified", False),
                             ("dps", dict(fixtures.DARK, **{"22": 11}))):
            changed = json.loads(json.dumps(original))
            changed["white_profiles"]["dark"][field] = value
            self.save("calibration.json", changed)
            self.assertFalse(self.replay()["ok"])
        self.factory.assert_not_called()

    def test_live_off_scene_colour_non_dark_or_wrong_device_never_writes(self):
        for changes in ({"20": False}, {"21": "scene"}, {"21": "colour"},
                        {"22": 11}, {"23": 1}, {"22": 10.0}):
            with self.subTest(changes=changes):
                self.setUp()
                self.original["dps"].update(changes)
                result = self.replay()
                self.assertFalse(result["ok"])
                self.assertEqual(0, result["commandsAttempted"])
                if changes.get("21") == "scene":
                    self.assertTrue(result["scene_left_active"])
                self.writer.set_multiple_values.assert_not_called()
                self.writer.close.assert_called_once()
        self.setUp()
        self.original["devId"] = "other_device_123456"
        self.assertEqual("status_device_mismatch", self.replay()["error"])
        self.writer.set_multiple_values.assert_not_called()

    def test_stale_full_writer_ack_or_partial_is_not_proof_but_fresh_full_can_confirm(self):
        for reply in ("ack", "partial", "mismatch"):
            with self.subTest(reply=reply):
                self.setUp()
                self.writer_reply = reply
                result = self.replay()
                self.assertTrue(result["ok"], result.get("error"))
                self.assertFalse(result["storedTargetConfirmedOnWriter"])
                self.assertTrue(result["readback_matched"])
                self.assertEqual(1, len(self.writes))

    def test_writer_success_does_not_replace_full_fresh_readback(self):
        for fresh in ({"21": "scene"}, dict(self.target, **{"25": scenes.PAYLOAD.lower()}), fixtures.DARK):
            with self.subTest(fresh=fresh):
                self.setUp()
                self.observer.status.return_value = {"dps": fresh}
                result = self.replay()
                self.assertEqual("stored_scene_mismatch", result["error"])
                self.assertTrue(result["storedTargetConfirmedOnWriter"])
                self.assertFalse(result["readback_matched"])
                self.assertEqual(1, len(self.writes))
                self.observer.close.assert_called_once()

    def test_timeout_uncertain_result_socket_loss_or_signal_never_retries_or_exits(self):
        for failure in (TimeoutError(fixtures.KEY), {"Err": "914", "Error": fixtures.KEY},
                        ProbeInterrupted(), "socket_lost"):
            with self.subTest(failure=type(failure).__name__):
                self.setUp()

                def send(dps, nowait):
                    self.send(dps, nowait)
                    if isinstance(failure, BaseException):
                        raise failure
                    if failure == "socket_lost":
                        self.writer.socket = None
                        return None
                    return failure

                self.writer.set_multiple_values.side_effect = send
                result = self.replay()
                self.assertFalse(result["ok"])
                self.assertEqual(1, result["commandsAttempted"])
                self.assertEqual(1, len(self.writes))
                self.assertEqual("unknown", result["sendOutcome"])
                self.assertIsNone(result["scene_left_active"])
                self.assertTrue(result["scene_may_be_active"])
                self.assertEqual(0, self.queries)
                self.assertEqual(1, self.factory.call_count)
                self.writer.close.assert_called_once()
                self.assertNotIn(fixtures.KEY, json.dumps(result))

    def test_cancellation_and_deadline_have_no_cleanup_writes(self):
        self.assertEqual("cancelled", self.replay(cancelled=lambda: True)["error"])
        self.factory.assert_not_called()
        self.assertFalse(self.writes)
        self.setUp()
        result = self.replay(cancelled=lambda: self.now >= 1)
        self.assertEqual("cancelled", result["error"])
        self.assertEqual(1, len(self.writes))
        self.assertIsNone(result["scene_left_active"])
        self.assertTrue(result["scene_may_be_active"])
        self.assertEqual(0, self.queries)
        self.setUp()
        reader = Mock()
        reader.drain_until.side_effect = configure.DeadlineExceeded()
        self.reader_factory.side_effect = None
        self.reader_factory.return_value = reader
        self.assertEqual("replay_deadline_exceeded", self.replay()["error"])
        self.assertEqual(1, len(self.writes))

    def test_prewrite_report_failure_or_lock_prevents_write_and_cli_omits_response_data(self):
        real_save, calls = scene_replay_probe.save_private, []

        def fail_report(directory, filename, data):
            calls.append(filename)
            if len(calls) == 2:
                raise OSError(fixtures.KEY)
            return real_save(directory, filename, data)

        with patch.object(scene_replay_probe, "save_private", side_effect=fail_report):
            result = self.replay()
        self.assertFalse(result["ok"])
        self.assertFalse(result["scene_may_be_active"])
        self.writer.set_multiple_values.assert_not_called()
        self.setUp()
        with exclusive_probe(self.directory):
            self.assertEqual("probe_already_running", self.replay()["error"])
        self.factory.assert_not_called()
        output = io.StringIO()
        with patch.object(scene_replay_probe, "replay", return_value=dict(result, receivedResponses=[fixtures.KEY])), patch.object(sys, "stdout", output):
            code = scene_replay_probe.main(["--alias", "blue", "--expect-device-id", fixtures.DEVICE_ID])
        self.assertEqual(1, code)
        self.assertNotIn(fixtures.KEY, output.getvalue())
        self.assertEqual(1, json.loads(output.getvalue())["responsesReceived"])


if __name__ == "__main__":
    unittest.main()
