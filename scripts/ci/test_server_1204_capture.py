"""Offline controls only. No Docker, SDK, database or network execution."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("capture1204", Path(__file__).with_name("server_1204_capture.py"))
capture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capture)


class CaptureControls(unittest.TestCase):
    def setUp(self):
        self.selected = capture.selected_tests(capture.SELECTION.read_bytes())

    def test_selected_bytes_and_target_are_fixed(self):
        self.assertEqual(len(self.selected), 405)
        self.assertIn("worker_poll_fence::held_activity_polls_end_when_the_registration_changes", self.selected)
        with self.assertRaisesRegex(RuntimeError, "digest"):
            capture.selected_tests(capture.SELECTION.read_bytes() + b"\n")

    def test_inventory_rejects_drift_and_duplicate(self):
        names = [f"case_{n:04}" for n in range(810)]
        capture.inventory("\n".join(x + ": test" for x in names), names[1::2])
        with self.assertRaisesRegex(RuntimeError, "inventory"):
            capture.inventory("\n".join(x + ": test" for x in names + names[:1]), names[1::2])
        with self.assertRaisesRegex(RuntimeError, "membership"):
            capture.inventory("\n".join(x + ": test" for x in names), names[::2])

    def test_full_pass_and_cutoff_remain_distinct(self):
        log = "\n".join("2026-11-14T00:00:00Z test " + name + " ... " +
                        ("ignored, profile child is selected only by its parent" if i == 0 else "ok")
                        for i, name in enumerate(self.selected))
        self.assertEqual(capture.observations(log, 0, self.selected)["outcome"], "runner-blocked")
        target = "worker_poll_fence::held_activity_polls_end_when_the_registration_changes"
        for phase in ("database_create", "activity_reregistration_start", "activity_reregistration_returned"):
            log += "\n2026-11-14T00:00:00Z server1204_context " + json.dumps({"test": target, "phase": phase})
        self.assertEqual(capture.observations(log, 0, self.selected)["outcome"], "baseline-not-reproduced")
        self.assertEqual(capture.observations(log, 124, self.selected)["outcome"], "runner-blocked")
        self.assertEqual(capture.observations(log.splitlines()[0], 0, self.selected)["outcome"], "runner-blocked")

    def test_duplicate_and_foreign_completion_refuse(self):
        line = "2026-11-14T00:00:00Z test " + self.selected[0] + " ... ok"
        with self.assertRaisesRegex(RuntimeError, "foreign/duplicate"):
            capture.observations(line + "\n" + line, 0, self.selected)
        with self.assertRaisesRegex(RuntimeError, "foreign/duplicate"):
            capture.observations("2026-11-14T00:00:00Z test foreign ... ok", 0, self.selected)

    def test_false_pool_timeout_is_not_a_positive(self):
        line = "2026-11-14T00:00:00Z server1204_acquire_failure phase=mysql_ownership_lock pool_timeout=false"
        self.assertFalse(capture.observations(line, 0, self.selected)["pool_timeout_observed"])
        self.assertTrue(capture.observations(line.replace("false", "true"), 101, self.selected)["pool_timeout_observed"])

    def test_local_run_refuses_before_any_command(self):
        with patch.dict(capture.os.environ, {}, clear=True), patch.object(capture, "run") as command:
            with patch("sys.argv", ["capture", "run", "--source", "/unused", "--evidence", "/unused"]):
                with self.assertRaisesRegex(RuntimeError, "unselected Actions context"):
                    capture.main()
            command.assert_not_called()

    def test_lost_create_output_is_reconciled_and_foreign_nonce_refuses(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.dict(capture.os.environ, {"GITHUB_RUN_ID": "123", "GITHUB_RUN_ATTEMPT": "1", "GITHUB_JOB": "capture"}):
                effect = capture.Capture(root, root / "evidence")
            effect.evidence.mkdir()
            effect.private.mkdir()
            effect.nonce = "own-nonce"
            identifier = "a" * 64
            exists = False
            foreign = False

            def wire(*args, **_options):
                nonlocal exists
                if args[0] == "create":
                    exists = True
                    raise subprocess.TimeoutExpired("fixed create", 30)
                if args[0] == "ps":
                    return subprocess.CompletedProcess(args, 0, identifier if exists else "", "")
                if args[0] == "inspect":
                    labels = effect.labels("inventory")
                    if foreign:
                        labels[capture.OWNER + ".nonce"] = "foreign"
                    value = {"Id": identifier, "Config": {"Labels": labels}, "State": {"Running": False}, "Mounts": []}
                    return subprocess.CompletedProcess(args, 0 if exists else 1, json.dumps([value] if exists else []), "")
                if args[0] == "rm":
                    exists = False
                    return subprocess.CompletedProcess(args, 0, "", "")
                if args[0] == "logs":
                    _options["output"].write_text("")
                    return subprocess.CompletedProcess(args, 0, "", "")
                if args[:2] == ("network", "ls"):
                    return subprocess.CompletedProcess(args, 0, "", "")
                self.fail(str(args))

            effect.docker = wire
            with self.assertRaises(subprocess.TimeoutExpired):
                effect.create("inventory", "fixed-image", ["fixed-marker"], 1, 1, 64)
            self.assertEqual(effect.containers, {})
            effect.cleanup()
            self.assertFalse(exists)
            self.assertEqual(effect.containers["inventory"], identifier)
            effect.private.mkdir()
            exists = True
            foreign = True
            with self.assertRaisesRegex(RuntimeError, "container scope remains"):
                effect.cleanup()
            self.assertTrue(exists)


if __name__ == "__main__":
    unittest.main()
