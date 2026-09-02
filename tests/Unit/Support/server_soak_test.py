#!/usr/bin/env python3

import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = ROOT / "scripts/perf/server_soak.py"

spec = importlib.util.spec_from_file_location("server_soak", MODULE_PATH)
assert spec is not None and spec.loader is not None
server_soak = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server_soak)


class WorkflowGrowthResultGateTest(unittest.TestCase):
    def test_healthy_shared_runner_contention_meets_completion_floor(self) -> None:
        result, failures = server_soak.evaluate_workflow_growth(
            target_runs=1000,
            minimum_completion_ratio=0.98,
            start_results={
                "requests": 986,
                "successful": 986,
                "available": 986,
                "errors": 0,
            },
            final_workflow_runs=986,
            compose_backed=True,
        )

        self.assertEqual([], failures)
        self.assertEqual(980, result["minimum_successful_starts"])
        self.assertEqual(0.986, result["completion_ratio"])

    def test_genuinely_incomplete_growth_fails_completion_and_cardinality(self) -> None:
        _result, failures = server_soak.evaluate_workflow_growth(
            target_runs=1000,
            minimum_completion_ratio=0.98,
            start_results={
                "requests": 979,
                "successful": 979,
                "available": 979,
                "errors": 0,
            },
            final_workflow_runs=979,
            compose_backed=True,
        )

        self.assertTrue(any("workflow growth target incomplete" in failure for failure in failures))
        self.assertTrue(any("workflow run cardinality below completion floor" in failure for failure in failures))

    def test_request_error_fails_even_when_completion_floor_is_met(self) -> None:
        _result, failures = server_soak.evaluate_workflow_growth(
            target_runs=1000,
            minimum_completion_ratio=0.98,
            start_results={
                "requests": 987,
                "successful": 986,
                "available": 986,
                "errors": 1,
            },
            final_workflow_runs=986,
            compose_backed=True,
        )

        self.assertFalse(any("workflow growth target incomplete" in failure for failure in failures))
        self.assertIn("workflow_start recorded 1 request errors", failures)
        self.assertTrue(any("workflow_start availability fell below 1.0" in failure for failure in failures))


class RuntimeEvidenceConfigurationTest(unittest.TestCase):
    def test_redis_sampling_targets_the_configured_cache_database(self) -> None:
        with patch.dict(os.environ, {"DW_PERF_REDIS_CACHE_DB": "3"}):
            self.assertEqual(3, server_soak.redis_cache_database())

        script = server_soak.redis_sampling_script(3)
        self.assertIn('redis-cli -n "$cache_database" DBSIZE', script)
        self.assertIn('redis-cli -n "$cache_database" --scan', script)

    def test_redis_cache_database_rejects_invalid_values(self) -> None:
        for value in ("-1", "16", "cache"):
            with self.subTest(value=value):
                with patch.dict(os.environ, {"DW_PERF_REDIS_CACHE_DB": value}):
                    with self.assertRaises(ValueError):
                        server_soak.redis_cache_database()

    def test_remote_execution_environment_overrides_github_runner_metadata(self) -> None:
        with patch.dict(
            os.environ,
            {
                "RUNNER_ENVIRONMENT": "github-hosted",
                "DW_PERF_RUNNER_ENVIRONMENT": "self-hosted",
            },
        ):
            self.assertEqual("self-hosted", server_soak.runner_environment())


if __name__ == "__main__":
    unittest.main()
