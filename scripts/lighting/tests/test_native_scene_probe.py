"""Offline native scene tests: all state, keys, clocks and devices are fixtures."""

import json
import contextlib
import io
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import native_scene_probe
import test_native_white_probe as fixtures
from timed_white_probe import exclusive_probe


class NativeSceneProbeTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load

    def setUp(self):
        fixtures.NativeWhiteProbeTest.setUp(self)
        scene = {"scene_num": self.range(1, 8), "scene_units": {
            "unit_change_mode": {"range": ["static", "jump", "gradient"]},
            "unit_switch_duration": self.range(0, 100), "unit_gradient_duration": self.range(0, 100),
            "h": self.range(0, 360), "s": self.range(0, 1000), "v": self.range(0, 1000),
            "bright": self.range(0, 1000), "temperature": self.range(0, 1000)}}
        schema = self.load("schema.json")
        for group in ("functions", "status"):
            schema["schema"][group].append({"dp_id": 25, "code": "scene_data_v2", "type": "Json", "values": json.dumps(scene)})
        self.save("schema.json", schema)
        self.now, self.queries, self.writes = 0.0, 0, []
        self.old_scene = "00" + "282802" + "0000" * 3 + "000a0000"
        self.original = {"devId": fixtures.DEVICE_ID, "dps": dict(fixtures.ACTION, **{"25": self.old_scene, "26": 0})}
        self.writer = Mock()
        self.writer.socket = object()
        self.writer.status.side_effect = self.status
        self.writer.set_multiple_values.side_effect = self.send
        self.factory = Mock(return_value=self.writer)
        self.source_replies, self.final_replies = True, True
        self.stale_source, self.stale_scene = False, False
        self.reader_factory = Mock(side_effect=self.reader)

    def status(self, nowait=False):
        if not nowait:
            return self.original
        self.queries += 1
        return None

    def send(self, dps, nowait):
        self.assertTrue(nowait)
        backups = list(self.directory.glob("native-scene-before-*.json"))
        self.assertEqual(1, len(backups))  # Backup must precede even the source write.
        self.assertEqual(self.original, json.loads(backups[0].read_text())["state"])
        self.writes.append((self.now, dps))
        return None

    def reader(self, device, clock):
        test = self
        self.assertIs(device, self.writer)

        class Reader:
            def drain_until(self, until, cancelled, on_message):
                test.now = until
                if cancelled():
                    raise configure.DiagnosticError("cancelled")
                on_message({"metadata": {"seq": 900, "cmd": 13, "retcode": 0, "emptyAck": True}, "response": None})
                dps = None
                if test.queries == 0 and test.stale_source or test.queries == 1 and test.source_replies:
                    dps = fixtures.DARK
                elif test.queries == 2 and test.stale_scene or test.queries == 3 and test.final_replies:
                    dps = {"20": True, "21": "scene", "25": test.writes[-1][1]["25"]}
                if dps is not None:
                    on_message({"metadata": {"seq": 2, "cmd": 8, "retcode": 0, "emptyAck": False},
                                "response": {"devId": fixtures.DEVICE_ID, "dps": dps}})

        return Reader()

    def probe(self, **overrides):
        arguments = {"expected_id": fixtures.DEVICE_ID, "directory": self.directory,
                     "device_factory": self.factory, "response_reader_factory": self.reader_factory,
                     "clock": lambda: self.now}
        arguments.update(overrides)
        return native_scene_probe.probe(**arguments)

    def test_two_writes_exact_4_second_encoding_full_backup_and_no_endpoint_commit(self):
        calibration = (self.directory / "calibration.json").read_bytes()
        result = self.probe()
        self.assertTrue(result["ok"], result.get("error"))
        self.assertEqual((0.0, fixtures.DARK), self.writes[0])
        expected = "00" + "282802" + "0000" * 3 + "03e8014c"
        self.assertEqual((3.0, {"21": "scene", "25": expected}), self.writes[1])
        self.assertEqual(["25", "21"], list(self.writes[1][1]))
        self.assertEqual(2, len(self.writes))
        self.assertEqual(28, len(expected))
        self.assertEqual(23.0, self.now)
        self.assertEqual(20000, result["observationElapsedMs"])
        self.assertTrue(result["storedSourceConfirmed"])
        self.assertTrue(result["storedSceneConfirmed"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertFalse(result["one_shot_verified"])
        self.assertTrue(result["visual_verification_required"])
        self.assertEqual(3, result["statusQueriesAttempted"])
        self.writer.set_socketRetryLimit.assert_called_once_with(0)
        self.writer.set_sendWait.assert_called_once_with(None)
        self.writer.set_value.assert_not_called()
        self.writer.close.assert_called_once()
        self.assertEqual(1, self.factory.call_count)
        self.assertEqual(0, self.factory.call_args.kwargs["max_simultaneous_dps"])
        self.assertEqual(calibration, (self.directory / "calibration.json").read_bytes())
        for report in self.directory.glob("native-scene-*.json"):
            self.assertEqual(0o600, report.stat().st_mode & 0o777)
            self.assertNotIn(fixtures.KEY, report.read_text())

    def test_duration_boundaries_and_observed_slot_are_preserved_without_cloud_reindexing(self):
        for duration, encoded in ((500, "050502"), (4000, "282802"), (8000, "505002")):
            unit = native_scene_probe.scene_unit(self.directory, fixtures.DEVICE_ID, fixtures.ACTION, duration)
            self.assertEqual(encoded, unit[:6])
            for slot in ("00", "07"):
                current = slot + self.old_scene[2:] * 8
                self.assertEqual(slot + unit, native_scene_probe.preserve_scene_id(current, unit))

    def test_anchored_program_contains_source_target_and_static_hold_without_more_writes(self):
        result = self.probe(program="anchored")
        self.assertTrue(result["ok"], result.get("error"))
        self.assertEqual(2, len(self.writes))
        payload = self.writes[1][1]["25"]
        self.assertEqual(80, len(payload))
        self.assertEqual("00", payload[:2])
        units = [payload[i:i+26] for i in range(2, len(payload), 26)]
        # Decode independently as byte time/speed/mode, HSV and white words.
        decoded = [(int(u[:2],16), int(u[2:4],16), int(u[4:6],16),
                    tuple(int(u[i:i+4],16) for i in range(6,26,4))) for u in units]
        self.assertEqual([(10,10,2,(0,0,0,10,0)),
                          (40,40,2,(0,0,0,1000,332)),
                          (10,10,0,(0,0,0,1000,332))], decoded)
        self.assertEqual(3, result["sceneUnitCount"])
        self.assertEqual(23.0, self.now)
        self.assertFalse(result["one_shot_verified"])
        self.assertFalse(result["physical_finish_confirmed"])

    def test_anchored_requires_static_support_before_any_connection(self):
        schema = self.load("schema.json")
        for group in ("functions", "status"):
            entry = schema["schema"][group][-1]
            values = json.loads(entry["values"])
            values["scene_units"]["unit_change_mode"]["range"] = ["gradient"]
            entry["values"] = json.dumps(values)
        self.save("schema.json", schema)
        self.assertEqual("static_not_in_scene_schema", self.probe(program="anchored")["error"])
        self.factory.assert_not_called()

    def test_stale_source_and_empty_ack_do_not_authorize_scene_write(self):
        self.stale_source, self.source_replies = True, False
        result = self.probe()
        self.assertEqual("stored_source_unconfirmed", result["error"])
        self.assertFalse(result["storedSourceConfirmed"])
        self.assertEqual([fixtures.DARK], [dps for _when, dps in self.writes])

    def test_old_scene_report_before_final_query_and_ack_do_not_confirm_final_state(self):
        self.stale_scene, self.final_replies = True, False
        result = self.probe()
        self.assertEqual("stored_scene_unconfirmed", result["error"])
        self.assertEqual(2, len(self.writes))
        self.assertFalse(result["storedSceneConfirmed"])
        self.assertEqual(23.0, self.now)

    def test_bad_id_duration_and_observation_fail_before_connection(self):
        for overrides in ({"expected_id": "other_device_123456"}, {"duration_ms": 499},
                          {"duration_ms": 8001}, {"duration_ms": 4050}, {"duration_ms": True},
                          {"observe_seconds": 31}, {"observe_seconds": 9}, {"program": "loop"}):
            with self.subTest(overrides=overrides):
                self.assertFalse(self.probe(**overrides)["ok"])
                self.factory.assert_not_called()
        self.assertEqual([], self.writes)

    def test_schema_mode_range_conflict_and_unverified_profile_reject_before_connection(self):
        original = self.load("schema.json")
        for change in ("type", "gradient", "range", "conflict"):
            with self.subTest(change=change):
                schema = json.loads(json.dumps(original))
                for group in ("functions", "status"):
                    entry = schema["schema"][group][-1]
                    values = json.loads(entry["values"])
                    if change == "type":
                        entry["type"] = "String"
                    elif change == "gradient":
                        values["scene_units"]["unit_change_mode"]["range"] = ["static"]
                    elif change == "range":
                        values["scene_units"]["bright"]["max"] = 999
                    elif group == "status":
                        values["scene_num"]["max"] = 9
                    entry["values"] = json.dumps(values)
                self.save("schema.json", schema)
                self.assertFalse(self.probe()["ok"])
                self.factory.assert_not_called()
        self.save("schema.json", original)
        calibration = self.load("calibration.json")
        calibration["white_profiles"]["dark"]["replay_verified"] = False
        self.save("calibration.json", calibration)
        self.assertEqual("profile_not_visually_verified", self.probe()["error"])
        self.factory.assert_not_called()

    def test_invalid_current_scene_or_lamp_mode_fails_before_source_write(self):
        for value in (None, "", "XX" + self.old_scene[2:], "08" + self.old_scene[2:],
                      self.old_scene + "00", "00" + self.old_scene[2:] * 9):
            with self.subTest(value=value):
                self.original["dps"]["25"] = value
                self.assertEqual("invalid_current_scene", self.probe()["error"])
                self.writer.set_multiple_values.assert_not_called()
        self.original["dps"]["25"] = self.old_scene
        self.original["dps"]["21"] = "colour"
        self.assertEqual("lamp_must_be_on_and_white", self.probe()["error"])
        self.writer.set_multiple_values.assert_not_called()

    def test_write_error_stops_without_retry_restore_or_final_commit(self):
        for failure in (TimeoutError(fixtures.KEY), {"Err": "914", "Error": fixtures.KEY}):
            with self.subTest(failure=type(failure).__name__):
                self.setUp()
                def send(dps, nowait):
                    self.send(dps, nowait)
                    if "25" in dps:
                        if isinstance(failure, BaseException):
                            raise failure
                        return failure
                self.writer.set_multiple_values.side_effect = send
                result = self.probe()
                self.assertFalse(result["ok"])
                self.assertEqual(2, len(self.writes))
                self.assertEqual(1, self.factory.call_count)
                self.assertEqual(1, self.queries)
                self.assertNotIn(fixtures.KEY, json.dumps(result))
                self.writer.close.assert_called_once()

    def test_cancel_or_deadline_during_source_drain_prevents_scene_and_restore(self):
        result = self.probe(cancelled=lambda: self.now >= 1)
        self.assertEqual("cancelled", result["error"])
        self.assertEqual(1, len(self.writes))
        self.assertEqual(0, self.queries)
        self.setUp()
        reader = Mock()
        reader.drain_until.side_effect = configure.DeadlineExceeded()
        self.reader_factory.side_effect = None
        self.reader_factory.return_value = reader
        result = self.probe()
        self.assertEqual("probe_deadline_exceeded", result["error"])
        self.assertEqual(1, len(self.writes))

    def test_backup_failure_or_existing_probe_lock_prevents_writes(self):
        with patch.object(native_scene_probe, "save_private", side_effect=OSError("fixture")):
            result = self.probe()
        self.assertFalse(result["ok"])
        self.writer.set_multiple_values.assert_not_called()
        self.setUp()
        with exclusive_probe(self.directory):
            self.assertEqual("probe_already_running", self.probe()["error"])
        self.factory.assert_not_called()

    def test_cli_cannot_accidentally_leave_a_persistent_scene(self):
        with patch.object(native_scene_probe, "probe") as probe, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit) as stopped:
                native_scene_probe.main(["--expect-device-id", fixtures.DEVICE_ID])
        self.assertEqual(2, stopped.exception.code)
        probe.assert_not_called()


if __name__ == "__main__":
    unittest.main()
