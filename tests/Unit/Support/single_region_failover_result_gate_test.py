#!/usr/bin/env python3

import importlib.util
import os
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = ROOT / "scripts/conformance/single-region-failover-published-artifacts.py"

for name, value in {
    "DW_FAILOVER_COMPOSE_FILE": str(ROOT / "docker-compose.failover-rehearsal.yml"),
    "DW_FAILOVER_RESULT_DIR": str(ROOT / "build/failover-rehearsal"),
    "DW_FAILOVER_PROJECT": "failover-result-gate-test",
    "DW_FAILOVER_RUNNER_VERSION": "1",
    "DW_FAILOVER_SERVER_IMAGE_REQUESTED": "durableworkflow/server:1.2.3",
    "DW_FAILOVER_SERVER_IMAGE": "durableworkflow/server@sha256:" + "a" * 64,
    "DW_FAILOVER_MYSQL_IMAGE_REQUESTED": "mysql:8.4.5",
    "DW_FAILOVER_MYSQL_IMAGE": "mysql@sha256:" + "b" * 64,
    "DW_FAILOVER_REDIS_IMAGE_REQUESTED": "redis:7.4.2-alpine",
    "DW_FAILOVER_REDIS_IMAGE": "redis@sha256:" + "c" * 64,
    "DW_FAILOVER_NGINX_IMAGE_REQUESTED": "nginx:1.27.4-alpine",
    "DW_FAILOVER_NGINX_IMAGE": "nginx@sha256:" + "d" * 64,
    "DW_FAILOVER_DOCKER_VERSION": "test",
    "DW_FAILOVER_COMPOSE_VERSION": "test",
    "DW_FAILOVER_BASH_VERSION": "test",
}.items():
    os.environ.setdefault(name, value)

spec = importlib.util.spec_from_file_location("single_region_failover", MODULE_PATH)
assert spec is not None and spec.loader is not None
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ResultGateTest(unittest.TestCase):
    def setUp(self) -> None:
        runner.RESULT["phase_outcomes"] = {
            name: {"status": "pass"} for name in runner.REQUIRED_PHASES
        }
        runner.RESULT["recovery_bounds"] = {
            name: {"seconds": seconds, "passed": True}
            for name, seconds in runner.BOUNDS.items()
        }

    def test_phase_rejects_false_or_unset_bound(self) -> None:
        for phase, bounds in runner.PHASE_RECOVERY_BOUNDS.items():
            for bound in bounds:
                for passed in (False, None):
                    with self.subTest(phase=phase, bound=bound, passed=passed):
                        runner.RESULT["recovery_bounds"][bound]["passed"] = passed
                        with self.assertRaisesRegex(AssertionError, bound):
                            runner.run_phase(phase, lambda: {})
                        runner.RESULT["recovery_bounds"][bound]["passed"] = True

    def test_cache_readiness_requires_the_cache_check_to_recover(self) -> None:
        original_ready = runner.ready

        try:
            for status, expected in (("warning", None), ("unavailable", None), ("ok", "ok")):
                with self.subTest(status=status):
                    runner.ready = lambda _base, status=status: {
                        "http_status": 200,
                        "body": {"checks": {"cache": {"status": status}}},
                    }
                    observation = runner.cache_ready("http://server-a")
                    if expected is None:
                        self.assertIsNone(observation)
                    else:
                        self.assertEqual(expected, observation["body"]["checks"]["cache"]["status"])
        finally:
            runner.ready = original_ready

    def test_final_result_rejects_false_or_unset_bound(self) -> None:
        bound = "scheduler_fire_after_restart_seconds"

        for passed in (False, None):
            with self.subTest(passed=passed):
                runner.RESULT["recovery_bounds"][bound]["passed"] = passed
                with self.assertRaisesRegex(AssertionError, bound):
                    runner.require_passing_result()

    def test_final_result_rejects_missing_bound_result(self) -> None:
        bound = "database_ready_after_return_seconds"
        del runner.RESULT["recovery_bounds"][bound]

        with self.assertRaisesRegex(AssertionError, bound):
            runner.require_passing_result()

    def test_final_result_accepts_only_complete_passing_bounds(self) -> None:
        runner.require_passing_result()

    def test_main_emits_failure_when_final_bound_gate_rejects(self) -> None:
        bound = "database_ready_after_return_seconds"
        runner.RESULT["recovery_bounds"][bound]["passed"] = False
        original_run_phase = runner.run_phase
        original_write_result = runner.write_result
        original_keep_stack = runner.KEEP_STACK

        def record_passing_phase(name, _callback):
            runner.RESULT["phase_outcomes"][name] = {"status": "pass"}

        try:
            runner.run_phase = record_passing_phase
            runner.write_result = lambda: None
            runner.KEEP_STACK = True

            self.assertEqual(1, runner.main())
            self.assertEqual("fail", runner.RESULT["outcome"])
            self.assertIn(
                bound,
                runner.RESULT["phase_outcomes"]["singleton_scheduler_restart"]["reason"],
            )
        finally:
            runner.run_phase = original_run_phase
            runner.write_result = original_write_result
            runner.KEEP_STACK = original_keep_stack


if __name__ == "__main__":
    unittest.main()
