#!/usr/bin/env python3

import importlib.util
import os
from pathlib import Path
import subprocess
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

    def test_native_host_uses_loopback_for_every_default_probe(self) -> None:
        endpoints = runner.build_probe_endpoints("127.0.0.1", runner.DEFAULT_PORTS)

        self.assertEqual("http://127.0.0.1:18084", endpoints["server_a"])
        self.assertEqual("http://127.0.0.1:18085", endpoints["server_b"])
        self.assertEqual("http://127.0.0.1:18086", endpoints["load_balancer"])

    def test_containerized_orchestrator_uses_one_docker_gateway_host(self) -> None:
        endpoints = runner.build_probe_endpoints("172.24.0.1", runner.DEFAULT_PORTS)

        self.assertEqual("http://172.24.0.1:18084", endpoints["server_a"])
        self.assertEqual("http://172.24.0.1:18085", endpoints["server_b"])
        self.assertEqual("http://172.24.0.1:18086", endpoints["load_balancer"])

    def test_probe_urls_support_dns_and_ipv6_hosts(self) -> None:
        dns = runner.build_probe_endpoints("host.docker.internal", runner.DEFAULT_PORTS)
        ipv6 = runner.build_probe_endpoints("2001:db8::10", runner.DEFAULT_PORTS)

        self.assertEqual("http://host.docker.internal:18084", dns["server_a"])
        self.assertEqual("http://[2001:db8::10]:18086", ipv6["load_balancer"])

    def test_connect_host_rejects_arbitrary_url_values(self) -> None:
        invalid_values = (
            "http://172.24.0.1",
            "host.docker.internal:18084",
            "host.docker.internal/api",
            "user@host.docker.internal",
        )

        for value in invalid_values:
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, "DW_FAILOVER_CONNECT_HOST"):
                runner.build_probe_endpoints(value, runner.DEFAULT_PORTS)

    def test_connect_host_and_published_ports_remain_separate(self) -> None:
        endpoints = runner.build_probe_endpoints(
            "docker-host-gateway",
            {"server_a": 28084, "server_b": 28085, "load_balancer": 28086},
        )

        self.assertEqual("http://docker-host-gateway:28084", endpoints["server_a"])
        self.assertEqual("http://docker-host-gateway:28085", endpoints["server_b"])
        self.assertEqual("http://docker-host-gateway:28086", endpoints["load_balancer"])

    def test_topology_readiness_observations_are_bounded(self) -> None:
        original_request = runner.request
        original_diagnostics = runner.TOPOLOGY_DIAGNOSTICS

        try:
            runner.TOPOLOGY_DIAGNOSTICS = runner.initial_topology_diagnostics()
            runner.request = lambda *_args, **_kwargs: (0, {"transport_error": "unreachable"}, 1)
            for _ in range(runner.READINESS_OBSERVATION_LIMIT + 3):
                runner.observe_topology_readiness("server_a", runner.SERVER_A, 1)

            evidence = runner.TOPOLOGY_DIAGNOSTICS["readiness_observations"]["server_a"]
            self.assertEqual(runner.READINESS_OBSERVATION_LIMIT + 3, evidence["attempt_count"])
            self.assertEqual(runner.READINESS_OBSERVATION_LIMIT, len(evidence["observations"]))
            self.assertEqual(3, evidence["observations_truncated"])
            self.assertFalse(evidence["ready"])
        finally:
            runner.request = original_request
            runner.TOPOLOGY_DIAGNOSTICS = original_diagnostics

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

    def test_compose_up_failure_runs_bounded_probes_and_keeps_topology_diagnostics(self) -> None:
        original_compose = runner.compose
        original_request = runner.request
        original_failure_timeout = runner.TOPOLOGY_START_FAILURE_READINESS_TIMEOUT
        original_diagnostics = runner.TOPOLOGY_DIAGNOSTICS

        def failing_compose(*args, **_kwargs):
            if args and args[0] == "up":
                raise subprocess.CalledProcessError(
                    1,
                    ["docker", "compose", *args],
                    output="server-a is healthy\nserver-b is healthy\n",
                    stderr="dependency failed to start: load-balancer\n",
                )
            if args[:2] == ("ps", "--all"):
                return subprocess.CompletedProcess(args, 0, "server-a healthy\nserver-b healthy\n", "")
            if args and args[0] == "port":
                ports = {
                    "server-a": 18084,
                    "server-b": 18085,
                    "load-balancer": 18086,
                }
                return subprocess.CompletedProcess(
                    args,
                    0,
                    f"0.0.0.0:{ports[args[1]]}\n",
                    "",
                )
            raise AssertionError(f"unexpected Compose call: {args!r}")

        try:
            runner.TOPOLOGY_DIAGNOSTICS = runner.initial_topology_diagnostics()
            runner.compose = failing_compose
            runner.request = lambda *_args, **_kwargs: (
                0,
                {"transport_error": "published endpoint unreachable"},
                1,
            )
            runner.TOPOLOGY_START_FAILURE_READINESS_TIMEOUT = 0.01

            with self.assertRaises(subprocess.CalledProcessError):
                runner.start_topology()

            failure = runner.TOPOLOGY_DIAGNOSTICS
            self.assertEqual(1, failure["compose_up"]["exit_code"])
            self.assertEqual(
                f"{runner.SERVER_A}/api/ready",
                failure["resolved_probe_endpoints"]["server_a"]["readiness_url"],
            )
            self.assertIn("server-a healthy", failure["compose_ps"]["stdout"])
            self.assertEqual(
                ["0.0.0.0:18084"],
                failure["published_port_mappings"]["server_a"]["published"],
            )
            for name, evidence in failure["readiness_observations"].items():
                with self.subTest(endpoint=name):
                    self.assertGreaterEqual(evidence["attempt_count"], 1)
                    self.assertGreaterEqual(len(evidence["observations"]), 1)
                    self.assertEqual(0, evidence["observations"][-1]["http_status"])
        finally:
            runner.compose = original_compose
            runner.request = original_request
            runner.TOPOLOGY_START_FAILURE_READINESS_TIMEOUT = original_failure_timeout
            runner.TOPOLOGY_DIAGNOSTICS = original_diagnostics


if __name__ == "__main__":
    unittest.main()
