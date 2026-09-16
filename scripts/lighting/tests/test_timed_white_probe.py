"""Deterministic timing/cancellation tests, with no network or real private files."""

import fcntl
import io
import json
import os
from pathlib import Path
import signal
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import configure
import timed_white_probe
import test_native_white_probe as fixtures


class Clock:
    def __init__(self, jump_at=None, jump=0):
        self.now = 0.0
        self.jump_at, self.jump = jump_at, jump

    def time(self):
        return self.now

    def sleep(self, seconds):
        extra = 0.0
        if self.jump_at is not None and self.now >= self.jump_at - 0.000001:
            extra, self.jump_at = self.jump, None
        self.now += seconds + extra


class TimedWhiteProbeTest(unittest.TestCase):
    range = staticmethod(fixtures.NativeWhiteProbeTest.range)
    save = fixtures.NativeWhiteProbeTest.save
    load = fixtures.NativeWhiteProbeTest.load

    def setUp(self):
        fixtures.NativeWhiteProbeTest.setUp(self)
        self.clock = Clock()
        self.writes = []
        self.writer, self.reader = Mock(), Mock()
        for device, state in ((self.writer, fixtures.DARK), (self.reader, fixtures.ACTION)):
            device.socket = object()
            device.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": state}
        self.writer.set_multiple_values.side_effect = self.record_write
        self.factory = Mock(side_effect=[self.writer, self.reader])

    def record_write(self, payload, nowait):
        self.assertTrue(nowait)
        self.writes.append((self.clock.time(), payload))
        return None

    def probe(self, **overrides):
        arguments = {"from_profile": "dark", "to_profile": "action", "expected_id": fixtures.DEVICE_ID,
                     "directory": self.directory, "device_factory": self.factory,
                     "duration_ms": 3000, "tick_ms": 100, "curve": "smoothstep",
                     "clock": self.clock.time, "sleep": self.clock.sleep}
        arguments.update(overrides)
        return timed_white_probe.probe(**arguments)

    def test_absolute_schedule_source_settle_commit_and_fresh_readback(self):
        original = (self.directory / "calibration.json").read_bytes()
        result = self.probe()
        self.assertTrue(result["ok"])
        self.assertTrue(result["readback_matched"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertTrue(result["visual_verification_required"])
        self.assertEqual(32, len(self.writes))
        self.assertEqual((0.0, fixtures.DARK), self.writes[0])
        native = self.writes[1:-1]
        for index, (instant, payload) in enumerate(native, 1):
            self.assertAlmostEqual(1 + index / 10, instant)
            self.assertEqual({"28"}, set(payload))
            self.assertEqual("1" + "0000" * 3, payload["28"][:13])
        self.assertAlmostEqual(5.0, self.writes[-1][0])
        self.assertEqual(fixtures.ACTION, self.writes[-1][1])
        self.assertAlmostEqual(3000, result["scheduledRampElapsedMs"])
        self.writer.set_socketRetryLimit.assert_called_once_with(0)
        self.writer.set_sendWait.assert_called_once_with(None)
        self.writer.status.assert_called_once()
        self.writer.close.assert_called_once()
        self.reader.status.assert_called_once()
        self.reader.set_multiple_values.assert_not_called()
        self.assertEqual(original, (self.directory / "calibration.json").read_bytes())
        report = next(self.directory.glob("timed-white-probe-*.json"))
        self.assertNotIn(fixtures.KEY, report.read_text() + json.dumps(result))
        self.assertEqual(0o600, report.stat().st_mode & 0o777)
        self.assertEqual(0o600, (self.directory / "probe.lock").stat().st_mode & 0o777)

    def test_send_cost_does_not_accumulate_into_requested_duration(self):
        def send(payload, nowait):
            self.record_write(payload, nowait)
            self.clock.now += 0.015
        self.writer.set_multiple_values.side_effect = send
        result = self.probe(curve="linear")
        self.assertTrue(result["ok"])
        self.assertAlmostEqual(3.0, self.writes[-2][0] - self.writes[0][0] - 1.015)
        self.assertEqual(3015.0, result["scheduledRampElapsedMs"])
        self.assertTrue(all(entry["sendMs"] == 15 for entry in result["timings"]))
        values = [int(payload["28"][13:17], 16) for _instant, payload in self.writes[1:-1]]
        self.assertEqual(sorted(values), values)
        self.assertEqual(1000, values[-1])

    def test_direct_mode_propagates_to_every_frame_without_changing_schedule(self):
        result = self.probe(duration_ms=4000, native_mode="direct")
        self.assertTrue(result["ok"])
        self.assertEqual("direct", result["nativeMode"])
        self.assertEqual((4000, 100, "smoothstep"), (result["durationMs"], result["tickMs"], result["curve"]))
        self.assertEqual(40, result["nativeFramesSent"])
        for index, (instant, payload) in enumerate(self.writes[1:-1], 1):
            self.assertAlmostEqual(1 + index / 10, instant)
            self.assertEqual("0" + "0000" * 3, payload["28"][:13])
        self.assertEqual("0" + "0000" * 3 + "03e8" + "014c", self.writes[-2][1]["28"])

    def test_absent_or_invalid_native_mode_rejects_before_any_write(self):
        schema = self.load("schema.json")
        values = json.loads(schema["schema"]["functions"][-1]["values"])
        values["change_mode"]["range"] = ["gradient"]
        schema["schema"]["functions"][-1]["values"] = json.dumps(values)
        self.save("schema.json", schema)
        self.assertEqual("native_mode_not_in_schema", self.probe(native_mode="direct")["error"])
        self.assertEqual("invalid_native_mode", self.probe(native_mode="flashing")["error"])
        self.factory.assert_not_called()
        self.assertEqual([], self.writes)

    def test_clock_skip_aborts_instead_of_sending_catch_up_frames(self):
        self.clock = Clock(jump_at=1.05, jump=0.4)
        result = self.probe()
        self.assertEqual("schedule_too_late", result["error"])
        self.assertEqual([fixtures.DARK], [payload for _instant, payload in self.writes])
        self.assertEqual(1, self.factory.call_count)
        self.writer.close.assert_called_once()

    def test_smaller_jitter_cannot_trigger_a_burst_on_the_next_slot(self):
        self.clock = Clock(jump_at=1.05, jump=0.09)
        result = self.probe()
        self.assertEqual("catch_up_burst_prevented", result["error"])
        self.assertEqual(2, len(self.writes))
        self.assertEqual({"28"}, set(self.writes[-1][1]))

    def test_clock_advancing_between_reads_never_produces_negative_sleep(self):
        class AdvancingClock(Clock):
            def time(self):
                current = self.now
                self.now += 0.0007
                return current

            def sleep(self, seconds):
                if seconds < 0:
                    raise AssertionError("Negative sleep must never be requested")
                super().sleep(seconds)

        self.clock = AdvancingClock()
        result = self.probe()
        self.assertTrue(result["ok"], result.get("error"))
        self.assertEqual(32, len(self.writes))

    def test_timeout_slow_send_and_deadline_abort_without_commit_or_restore(self):
        for problem in ("timeout", "slow", "deadline"):
            with self.subTest(problem=problem):
                self.setUp()
                def send(payload, nowait):
                    self.record_write(payload, nowait)
                    if "28" in payload:
                        if problem == "timeout":
                            raise TimeoutError(fixtures.KEY)
                        if problem == "deadline":
                            raise configure.DeadlineExceeded()
                        self.clock.now += 0.3
                self.writer.set_multiple_values.side_effect = send
                result = self.probe()
                self.assertFalse(result["ok"])
                self.assertEqual(2, len(self.writes))
                self.assertEqual(1, self.factory.call_count)
                self.writer.close.assert_called_once()
                self.writer.set_value.assert_not_called()
                self.assertNotIn(fixtures.KEY, json.dumps(result))

    def test_cancel_during_post_ramp_settle_prevents_target_commit(self):
        result = self.probe(duration_ms=500, cancelled=lambda: self.clock.time() >= 1.75)
        self.assertEqual("cancelled", result["error"])
        self.assertEqual(6, len(self.writes))  # Source + five native frames, no commit.
        self.assertEqual(1, self.factory.call_count)
        self.writer.close.assert_called_once()

    def test_sigterm_during_send_stops_all_later_writes_and_restores_handler(self):
        previous = signal.getsignal(signal.SIGTERM)
        def send(payload, nowait):
            self.record_write(payload, nowait)
            if "28" in payload:
                os.kill(os.getpid(), signal.SIGTERM)
        self.writer.set_multiple_values.side_effect = send
        result = self.probe()
        self.assertEqual("cancelled", result["error"])
        self.assertEqual(2, len(self.writes))
        self.assertEqual(previous, signal.getsignal(signal.SIGTERM))
        self.writer.close.assert_called_once()

    def test_every_frame_is_validated_before_source_write(self):
        schema = self.load("schema.json")
        native = json.loads(schema["schema"]["functions"][-1]["values"])
        native["bright"]["step"] = 10  # Intermediate rounded values fail this range.
        schema["schema"]["functions"][-1]["values"] = json.dumps(native)
        self.save("schema.json", schema)
        result = self.probe()
        self.assertEqual("control_data_value_out_of_schema", result["error"])
        self.factory.assert_not_called()
        self.assertEqual([], self.writes)

    def test_boundaries_and_colour_mode_fail_without_writes(self):
        for arguments in ({"duration_ms": 499}, {"duration_ms": 30001}, {"tick_ms": 99},
                          {"tick_ms": 501}, {"duration_ms": 500, "tick_ms": 500},
                          {"expected_id": "other_device_123456"}):
            with self.subTest(arguments=arguments):
                self.assertFalse(self.probe(**arguments)["ok"])
                self.factory.assert_not_called()
        self.writer.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": dict(fixtures.DARK, **{"21": "scene"})}
        self.assertEqual("lamp_must_be_on_and_white", self.probe()["error"])
        self.writer.set_multiple_values.assert_not_called()

    def test_maximum_frame_budget_and_unverified_profile(self):
        frames = timed_white_probe.prepare_frames(self.directory, fixtures.DEVICE_ID,
                                                 fixtures.DARK, fixtures.ACTION, 15000, 100, "linear")
        self.assertEqual(150, len(frames))
        self.assertEqual(15.0, frames[-1][0])
        frames = timed_white_probe.prepare_frames(self.directory, fixtures.DEVICE_ID,
                                                 fixtures.DARK, fixtures.ACTION, 30000, 100, "linear")
        self.assertEqual(300, len(frames))
        self.assertEqual(30.0, frames[-1][0])
        calibration = self.load("calibration.json")
        calibration["white_profiles"]["dark"]["replay_verified"] = False
        self.save("calibration.json", calibration)
        self.assertEqual("profile_not_visually_verified", self.probe()["error"])
        self.factory.assert_not_called()

    def test_competing_probe_fails_before_device_connection(self):
        with timed_white_probe.exclusive_probe(self.directory):
            result = self.probe()
        self.assertEqual("probe_already_running", result["error"])
        self.factory.assert_not_called()

    def response_collector(self, final_state):
        test = self

        class Collector:
            def __init__(self, device, clock):
                test.assertIs(device, test.writer)

            def drain_until(self, instant, cancelled, on_message):
                test.clock.now = instant
                if cancelled():
                    raise configure.DiagnosticError("cancelled")
                # A queued target report BEFORE the final write must not count
                # as confirmation. After it, an empty ACK still is not state.
                final_written = bool(test.writes) and test.writes[-1][1] == fixtures.ACTION
                value = final_state if final_written else fixtures.ACTION
                on_message({"metadata": {"cmd": 13, "seq": 99999, "retcode": 0, "emptyAck": True},
                            "response": None})
                on_message({"metadata": {"cmd": 8, "seq": 5, "retcode": 0, "emptyAck": False},
                            "response": {"devId": fixtures.DEVICE_ID, "dps": value}})

        return Collector

    def test_receiving_replies_preserves_schedule_and_confirms_stored_target_before_closing(self):
        self.writer.status.side_effect = [{"devId": fixtures.DEVICE_ID, "dps": fixtures.DARK}, None]
        result = self.probe(duration_ms=4000, receive_responses=True,
                            response_reader_factory=self.response_collector(fixtures.ACTION))
        self.assertTrue(result["ok"])
        self.assertTrue(result["storedTargetConfirmedOnWriter"])
        self.assertFalse(result["physical_finish_confirmed"])
        self.assertEqual(42, len(self.writes))
        self.assertEqual(4000, result["scheduledRampElapsedMs"])
        self.assertEqual([(), ()], [call.args for call in self.writer.status.call_args_list])
        self.assertEqual({"nowait": True}, self.writer.status.call_args_list[-1].kwargs)
        self.reader.status.assert_called_once()
        self.writer.close.assert_called_once()

    def test_queued_old_target_and_ack_do_not_confirm_final_commit_or_trigger_retry(self):
        self.writer.status.side_effect = [{"devId": fixtures.DEVICE_ID, "dps": fixtures.DARK}, None]
        self.reader.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": fixtures.DARK}
        result = self.probe(receive_responses=True,
                            response_reader_factory=self.response_collector(fixtures.DARK))
        self.assertFalse(result["ok"])
        self.assertFalse(result["storedTargetConfirmedOnWriter"])
        self.assertEqual("stored_target_mismatch", result["error"])
        self.assertEqual(32, len(self.writes))
        self.assertEqual(1, sum(payload == fixtures.ACTION for _, payload in self.writes))
        self.assertEqual(2, self.factory.call_count)
        self.writer.close.assert_called_once()

    def test_perceptual_curve_uses_quantized_monotonic_values_and_exact_endpoint(self):
        frames = timed_white_probe.prepare_frames(self.directory, fixtures.DEVICE_ID,
                                                 fixtures.DARK, fixtures.ACTION, 20000, 200, "perceptual")
        brightness = [int(payload[13:17], 16) for _offset, payload in frames]
        temperature = [int(payload[17:21], 16) for _offset, payload in frames]
        self.assertEqual(100, len(frames))
        self.assertEqual(sorted(brightness), brightness)
        self.assertEqual(sorted(temperature), temperature)
        self.assertEqual((57, 16), (brightness[24], temperature[24]))  # At 25% of elapsed time.
        self.assertLess(brightness[24], 10 + round(990 * 0.25))
        self.assertEqual((1000, 332), (brightness[-1], temperature[-1]))
        self.assertEqual(20.0, frames[-1][0])
        self.assertTrue(all(payload.startswith("1" + "0000" * 3) for _, payload in frames))

    def test_twenty_second_strict_source_candidate_has_66_frames_and_no_source_write(self):
        self.writer.status.side_effect = [{"devId": fixtures.DEVICE_ID, "dps": fixtures.DARK}, None]
        result = self.probe(duration_ms=20000, tick_ms=300, curve="perceptual", require_source=True,
                            receive_responses=True, response_reader_factory=self.response_collector({"21": "white"}))
        self.assertTrue(result["ok"], result.get("error"))
        self.assertTrue(result["requireSource"])
        self.assertFalse(result["storedTargetConfirmedOnWriter"])
        self.assertTrue(result["readback_matched"])
        self.assertEqual(66, result["nativeFramesSent"])
        self.assertEqual(67, len(self.writes))
        self.assertAlmostEqual(0.3, self.writes[0][0])
        self.assertEqual({"28"}, set(self.writes[0][1]))
        self.assertEqual((21.0, fixtures.ACTION), self.writes[-1])
        self.assertEqual(1, sum(dps == fixtures.ACTION for _when, dps in self.writes))
        self.assertFalse(any(dps == fixtures.DARK for _when, dps in self.writes))
        self.assertEqual(20000, result["scheduledRampElapsedMs"])
        self.reader.status.assert_called_once()

    def test_strict_source_mismatch_fails_before_any_native_or_white_write(self):
        self.writer.status.return_value = {"devId": fixtures.DEVICE_ID, "dps": fixtures.ACTION}
        result = self.probe(require_source=True)
        self.assertEqual("source_profile_mismatch", result["error"])
        self.assertEqual([], self.writes)
        self.writer.set_multiple_values.assert_not_called()
        self.assertEqual(1, self.factory.call_count)
        self.writer.close.assert_called_once()

    def test_partial_writer_then_fresh_read_error_never_resends_target(self):
        self.writer.status.side_effect = [{"devId": fixtures.DEVICE_ID, "dps": fixtures.DARK}, None]
        self.reader.status.side_effect = TimeoutError(fixtures.KEY)
        result = self.probe(receive_responses=True,
                            response_reader_factory=self.response_collector({"21": "white"}))
        self.assertFalse(result["ok"])
        self.assertFalse(result["readback_matched"])
        self.assertEqual(1, sum(dps == fixtures.ACTION for _when, dps in self.writes))
        self.assertEqual(2, self.factory.call_count)
        self.assertNotIn(fixtures.KEY, json.dumps(result))
        self.reader.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
