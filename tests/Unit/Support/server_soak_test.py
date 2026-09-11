#!/usr/bin/env python3

import importlib.util
import json
import os
import subprocess
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
    def test_mysql_sampling_rejects_failed_or_malformed_output_without_crashing(self):
        for code, output in (
            (1, "Unavailable: backend could not be reached"),
            (0, "Unavailable: backend could not be reached"),
            (1, "1 2 3 4"), (0, "1 2 3"), (0, "1 2 3 4 extra"),
            (0, "1 2 -3 4"), (0, "1 2 3.0 4"), (0, ""),
        ):
            with self.subTest(code=code, output=output):
                result = subprocess.CompletedProcess([], code, stdout=output)
                with patch.object(server_soak, "run_command", return_value=result):
                    observation = server_soak.mysql_counts("fixture")
                self.assertEqual(0, observation["mysql_sample_ok"])
                self.assertNotIn("mysql_ready_tasks", observation)

    def test_mysql_sampling_preserves_zero_and_valid_counts(self):
        result = subprocess.CompletedProcess([], 0, stdout="10\t25\t49\t0\n")
        with patch.object(server_soak, "run_command", return_value=result):
            self.assertEqual({
                "mysql_sample_ok": 1, "mysql_namespaces": 10,
                "mysql_worker_registrations": 25, "mysql_workflow_runs": 49,
                "mysql_ready_tasks": 0,
            }, server_soak.mysql_counts("fixture"))

    def test_failed_sampler_does_not_prevent_other_samples_or_later_recovery(self):
        samplers = {"docker_stats": "docker_stats_ok", "redis_info": "redis_sample_ok", "mysql_counts": "mysql_sample_ok"}
        for failing, field in samplers.items():
            for error in (OSError("fixture"), subprocess.TimeoutExpired(["fixture"], 30)):
                with self.subTest(sampler=failing, error=type(error).__name__):
                    with patch.object(server_soak, "docker_stats", return_value={"docker_stats_ok": 1, "server_container_healthy": 1}), \
                         patch.object(server_soak, "redis_info", return_value={"redis_sample_ok": 1}), \
                         patch.object(server_soak, "mysql_counts", return_value={"mysql_sample_ok": 1}):
                        with patch.object(server_soak, failing, side_effect=error):
                            failed = server_soak.sample("fixture")
                        recovered = server_soak.sample("fixture")
                    self.assertEqual(0, failed[field])
                    for health in samplers.values():
                        self.assertEqual(1, recovered[health])
                        if health != field:
                            self.assertEqual(1, failed[health])
                    health = server_soak.sample_health([failed, recovered], "fixture")
                    self.assertEqual(1, health["unhealthy_samples"])
                    self.assertEqual(1, health["unhealthy_field_counts"][field])
                    self.assertFalse(health["unhealthy_final_sample"])

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


class EnduranceCoverageTest(unittest.TestCase):
    def test_startup_only_cache_activity_does_not_qualify_a_long_soak(self):
        rows = [{"redis_polling_keys": 10}] * 7 + [{"redis_polling_keys": 0}] * 1433
        self.assertFalse(server_soak.polling_activity_summary(rows, 0.8)["sustained"])
        self.assertTrue(server_soak.polling_activity_summary(rows[:7], 0.8)["sustained"])

    def test_http_success_with_stale_registration_is_not_a_valid_poll(self):
        for body in ({"poll_status": "stale_worker_registration"}, {"reason": "worker_not_registered"}, {"poll_status": "no_workflow_capability"}, None):
            self.assertFalse(server_soak.poll_response_valid(body))
        self.assertTrue(server_soak.poll_response_valid({"task": None, "poll_status": "empty"}))

    def test_each_polling_thread_gets_a_registration_across_the_configured_dimensions(self):
        with patch.object(server_soak, "http_json", return_value=(201, {})) as register:
            workers = server_soak.register_workers("http://fixture", "fixture", [f"ns-{i}" for i in range(8)], [f"queue-{i}" for i in range(16)], 24)
        self.assertEqual(24, register.call_count)
        self.assertEqual(24, len({worker[2] for worker in workers}))
        self.assertEqual(8, len({worker[0] for worker in workers}))
        self.assertEqual(16, len({worker[1] for worker in workers}))

    def standard_output(self, completed=True, elapsed=60):
        return "\n".join(json.dumps(row) for row in [
            {"phase": "started", "sdk": "test", "php": "test"},
            {"phase": "workflow", "completed": completed, "latency_seconds": 0.7},
            {"phase": "finished", "elapsed_seconds": elapsed},
        ])

    def test_validated_completions_are_distinct_from_poll_requests(self):
        result = server_soak.evaluate_standard_workflows(self.standard_output(), 0, 60)
        self.assertEqual([], result["failures"])
        self.assertEqual(1, result["completed"])
        self.assertEqual(0.7, result["latency_seconds"]["p99"])

    def test_partial_failed_empty_and_malformed_runs_do_not_pass(self):
        for output, code in [
            (self.standard_output(False), 0),
            (self.standard_output(), 1),
            (self.standard_output(elapsed=20), 0),
            ("", 0), ("not json", 0), ("[]", 0),
        ]:
            with self.subTest(output=output, code=code):
                self.assertTrue(server_soak.evaluate_standard_workflows(output, code, 60)["failures"])

    def test_health_failures_are_detected_without_workflow_growth(self):
        metrics = server_soak.EndpointMetrics()
        metrics.record("health", 200, 0.1, valid=False)
        metrics.record("ready", 503, 0.1)
        failures = server_soak.evaluate_availability(metrics.snapshot(), ["health", "ready", "cluster_info"], 3, 5)
        self.assertIn("health returned request or payload errors", failures)
        self.assertIn("ready availability fell below 1.0", failures)
        self.assertIn("cluster_info availability was not sampled", failures)

    def test_all_backpressure_is_not_a_healthy_poll_experiment(self):
        for status in (200, 429):
            metrics = server_soak.EndpointMetrics()
            metrics.record("worker_poll", status, 0.1, backpressured=status == 200)
            self.assertTrue(server_soak.evaluate_availability(metrics.snapshot(), ["worker_poll"], 3, 5))
            metrics.record("worker_poll", 200, 0.1)
            self.assertEqual([], server_soak.evaluate_availability(metrics.snapshot(), ["worker_poll"], 3, 5))

    def test_resource_summary_does_not_substitute_zero_for_missing_cpu(self):
        result = server_soak.resource_summary([{"server_memory_bytes": 1048576}])
        self.assertEqual(1, result["server"]["peak_memory_mib"])
        self.assertIsNone(result["server"]["cpu_mean_percent"])
        result = server_soak.resource_summary([{"server_memory_bytes": 1048576, "server_cpu_percent": 0}])
        self.assertEqual(0, result["server"]["cpu_mean_percent"])

    def test_idle_drain_does_not_dilute_load_memory_slope_or_cpu(self):
        rows = [{"timestamp": i * 60, "server_memory_bytes": (100 + i) * 1048576,
                 "server_cpu_percent": 50} for i in range(10)]
        rows.append({"phase": "final", "timestamp": 900, "server_memory_bytes": 1048576,
                     "server_cpu_percent": 0})
        self.assertAlmostEqual(60, server_soak.memory_slope_mb_hour(rows))
        result = server_soak.resource_summary(rows)["server"]
        self.assertEqual(50, result["cpu_mean_percent"])
        self.assertEqual(1, result["final_memory_mib"])

    def test_summary_exposes_missing_standard_workflow_coverage(self):
        report = server_soak.render_summary({"failures": ["partial"]})
        self.assertIn("FAIL: partial", report)
        self.assertIn("Not exercised", report)
        self.assertIn("not a maximum-capacity benchmark", report)


if __name__ == "__main__":
    unittest.main()
