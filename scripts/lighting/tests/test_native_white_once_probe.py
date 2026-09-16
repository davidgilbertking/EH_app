"""Offline one-exit probe tests with fake clocks, sockets and isolated files."""

import json
import os
from pathlib import Path
import signal
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import native_white_once_probe as once
import test_native_scene_probe as scene_fixtures
import test_native_white_probe as fixtures


class NativeWhiteOnceTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load
    status = scene_fixtures.NativeSceneProbeTest.status
    send = scene_fixtures.NativeSceneProbeTest.send

    def setUp(self):
        scene_fixtures.NativeSceneProbeTest.setUp(self)
        self.now, self.writes, self.devices = 0.0, [], []
        self.source, self.target = fixtures.ACTION, fixtures.DARK
        self.foreign_guard, self.contradiction = False, False
        self.writer_full, self.fail_white, self.fail_scene = True, None, None
        self.fail_observer, self.signal_on_exit, self.deadline_in_native = False, False, False
        self.source_override = None
        self.factory = Mock(side_effect=self.connect)
        self.reader_factory = Mock(side_effect=self.reader)

    def connect(self, *_args, **options):
        # A second connection must never coexist with the previous one.
        self.assertTrue(all(device.closed for device in self.devices))
        device = Mock()
        device.socket, device.closed = object(), False
        device.role = "initial" if not self.devices else ("guard" if options["persist"] else "observer")
        device.queried = False
        device.close.side_effect = lambda: setattr(device, "closed", True)

        def status(nowait=False):
            if nowait:
                device.queried = True
                return None
            if device.role == "initial":
                dps = dict(self.source_override or self.source, **{"25": self.old_scene})
            elif device.role == "observer":
                if self.fail_observer:
                    raise TimeoutError(fixtures.KEY)
                dps = self.target
            else:
                payload = self.writes[0][1]["25"]
                dps = {"20": True, "21": "scene", "25": ("07" + payload[2:]) if self.foreign_guard else payload}
            return {"devId": fixtures.DEVICE_ID, "dps": dps}

        def send(dps, nowait):
            self.assertTrue(nowait)
            self.writes.append((self.now, dps))
            if "25" in dps:
                failure = self.fail_scene
            else:
                if self.signal_on_exit:
                    os.kill(os.getpid(), signal.SIGTERM)
                failure = self.fail_white
            if isinstance(failure, BaseException):
                raise failure
            return failure

        device.status.side_effect, device.set_multiple_values.side_effect = status, send
        self.devices.append(device)
        return device

    def reader(self, device, clock):
        test = self

        class Reader:
            def drain_until(self, until, cancelled, on_message):
                test.now = max(test.now, until)
                if test.deadline_in_native and device.role == "initial":
                    raise configure.DeadlineExceeded()
                if cancelled():
                    raise configure.DiagnosticError("cancelled")
                on_message({"metadata": {"cmd": 13, "seq": 700, "retcode": 0, "emptyAck": True}, "response": None})
                if device.role == "guard" and test.contradiction and len(test.writes) == 1:
                    on_message({"metadata": {}, "response": {"dps": {"21": "colour"}}})
                if device.role == "guard" and device.queried and test.writer_full:
                    on_message({"metadata": {"cmd": 8, "seq": 1, "retcode": 0, "emptyAck": False},
                                "response": {"devId": fixtures.DEVICE_ID, "dps": test.target}})

        return Reader()

    def probe(self, **overrides):
        arguments = {"from_profile": "action", "to_profile": "dark", "expected_id": fixtures.DEVICE_ID,
                     "directory": self.directory, "device_factory": self.factory,
                     "response_reader_factory": self.reader_factory, "clock": lambda: self.now}
        arguments.update(overrides)
        return once.probe(**arguments)

    def test_normal_exact_source_eight_units_and_one_exit_at_nine_seconds(self):
        result = self.probe()
        self.assertTrue(result["ok"], result)
        self.assertTrue(result["readback_matched"])
        self.assertTrue(result["stoppedConfirmed"])
        self.assertFalse(result["mayStillLoop"])
        self.assertFalse(result["one_shot_verified"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertEqual(2, len(self.writes))
        payload = self.writes[0][1]["25"]
        self.assertEqual(210, len(payload))
        self.assertEqual("00", payload[:2])
        self.assertEqual("646402" + "0000" * 3 + "03e8014c", payload[2:28])
        target_unit = "010102" + "0000" * 3 + "000a0000"
        self.assertEqual(target_unit * 7, payload[28:])
        self.assertEqual((9.0, fixtures.DARK), self.writes[1])
        self.assertEqual(["20", "22", "23", "21"], list(self.writes[1][1]))
        self.assertEqual(["initial", "guard", "observer"], [device.role for device in self.devices])
        self.assertTrue(all(device.closed for device in self.devices))
        self.assertTrue(all(call.kwargs["max_simultaneous_dps"] == 0 for call in self.factory.call_args_list))
        for device in self.devices:
            device.set_value.assert_not_called()
        for path in list(self.directory.glob("native-scene-probe-*.json")) + list(self.directory.glob("native-white-once-before-*.json")):
            self.assertEqual(0o600, path.stat().st_mode & 0o777)
            self.assertNotIn(fixtures.KEY, path.read_text())

    def test_wrong_source_and_cancel_before_activation_never_write(self):
        self.source_override = fixtures.DARK
        result = self.probe()
        self.assertEqual("source_profile_mismatch", result["error"])
        self.assertEqual([], self.writes)
        self.assertFalse(result["mayStillLoop"])
        self.setUp()
        self.assertEqual("cancelled", self.probe(cancelled=lambda: True)["error"])
        self.factory.assert_not_called()

    def test_invalid_timing_pair_stop_or_target_id_fail_before_connection(self):
        for overrides in ({"timing_byte": 0}, {"timing_byte": 101}, {"timing_byte": True},
                          {"stop_after_ms": 6999}, {"stop_after_ms": 20001},
                          {"to_profile": "action"}, {"expected_id": "other_device_123456"}):
            self.assertFalse(self.probe(**overrides)["ok"])
            self.factory.assert_not_called()

    def test_cancel_in_native_wait_runs_one_owned_exit_without_reusing_cancel_callback(self):
        result = self.probe(cancelled=lambda: self.now >= 6)
        self.assertEqual("cancelled", result["error"])
        self.assertFalse(result["ok"])
        self.assertTrue(result["readback_matched"])
        self.assertFalse(result["mayStillLoop"])
        self.assertEqual(2, len(self.writes))
        self.assertEqual((6.0, self.target), self.writes[-1])

    def test_sigterm_during_exit_send_never_resends_and_can_reconcile(self):
        self.signal_on_exit = True
        previous = signal.getsignal(signal.SIGTERM)
        result = self.probe()
        self.assertEqual("cancelled", result["error"])
        self.assertTrue(result["exitAttempted"])
        self.assertTrue(result["stoppedConfirmed"])
        self.assertEqual(2, len(self.writes))
        self.assertEqual(previous, signal.getsignal(signal.SIGTERM))

    def test_foreign_guard_or_partial_contradiction_never_overwrites_or_retries_guard(self):
        for field in ("foreign_guard", "contradiction"):
            with self.subTest(field=field):
                self.setUp()
                setattr(self, field, True)
                result = self.probe()
                self.assertFalse(result["ok"])
                self.assertFalse(result["exitAttempted"])
                self.assertTrue(result["mayStillLoop"])
                self.assertEqual(1, len(self.writes))
                self.assertEqual(2, len(self.devices))

    def test_partial_or_ack_only_writer_can_be_confirmed_by_independent_full_read(self):
        self.writer_full = False
        result = self.probe()
        self.assertTrue(result["ok"])
        self.assertFalse(result["storedTargetConfirmedOnWriter"])
        self.assertTrue(result["readback_matched"])
        self.assertEqual(2, len(self.writes))

    def test_unknown_exit_never_resends_and_only_readback_can_resolve_outcome(self):
        for confirm in (True, False):
            with self.subTest(confirm=confirm):
                self.setUp()
                self.fail_white = TimeoutError(fixtures.KEY)
                self.fail_observer = not confirm
                result = self.probe()
                self.assertEqual(confirm, result["readback_matched"])
                self.assertEqual(confirm, result["ok"])
                self.assertEqual(not confirm, result["mayStillLoop"])
                self.assertEqual(2, len(self.writes))
                self.assertNotIn(fixtures.KEY, json.dumps(result))

    def test_native_deadline_or_unknown_scene_send_can_cleanup_exact_owned_scene_once(self):
        for failure in ("deadline", "send"):
            with self.subTest(failure=failure):
                self.setUp()
                if failure == "deadline":
                    self.deadline_in_native = True
                else:
                    self.fail_scene = {"Err": "914", "Error": fixtures.KEY}
                result = self.probe()
                self.assertFalse(result["ok"])
                self.assertTrue(result["stoppedConfirmed"])
                self.assertEqual(2, len(self.writes))

    def test_no_disk_save_before_exit_and_final_report_failure_cannot_suppress_stop(self):
        original_save = configure.save_private
        failed = False

        def save(directory, filename, report):
            nonlocal failed
            self.assertFalse(report.get("exitPlanned") and not report.get("exitAttempted"))
            if report.get("exitAttempted") and not failed:
                failed = True
                raise OSError("fixture-disk-error")
            original_save(directory, filename, report)

        with patch.object(once, "save_private", side_effect=save):
            result = self.probe()
        self.assertTrue(failed)
        self.assertTrue(result["stoppedConfirmed"])
        self.assertTrue(result["readback_matched"])
        self.assertEqual(2, len(self.writes))


if __name__ == "__main__":
    unittest.main()
