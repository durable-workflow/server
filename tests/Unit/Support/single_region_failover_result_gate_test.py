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
        runner.PUBLIC_RUN_STATUS_CONTRACT.clear()
        runner.PUBLIC_RUN_STATUS_CONTRACT.update(runner.parse_public_run_status_contract({
            "pending": {"status_bucket": "running", "is_terminal": False},
            "running": {"status_bucket": "running", "is_terminal": False},
            "waiting": {"status_bucket": "running", "is_terminal": False},
            "cancelled": {"status_bucket": "failed", "is_terminal": True},
            "terminated": {"status_bucket": "failed", "is_terminal": True},
            "completed": {"status_bucket": "completed", "is_terminal": True},
            "failed": {"status_bucket": "failed", "is_terminal": True},
        }))
        runner.RESULT["phase_outcomes"] = {
            name: {"status": "pass"} for name in runner.REQUIRED_PHASES
        }
        runner.RESULT["recovery_bounds"] = {
            name: {"seconds": seconds, "passed": True}
            for name, seconds in runner.BOUNDS.items()
        }
        runner.RESULT["phase_evidence"] = {}
        runner.RESULT["recovery_timings_ms"] = {}
        runner.RESULT["identities"] = {}
        runner.RESULT["loss_assertions"] = []

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

    def test_survivor_traffic_accepts_every_public_running_raw_status(self) -> None:
        for raw_status in ("pending", "running", "waiting"):
            with self.subTest(raw_status=raw_status):
                observation = runner.survivor_run_observation(
                    200,
                    {
                        "workflow_id": "workflow-1",
                        "run_id": "run-1",
                        "status": raw_status,
                        "status_bucket": "running",
                        "is_terminal": False,
                        "input": {"secret": "must not enter evidence"},
                    },
                    "workflow-1",
                    "run-1",
                )

                self.assertTrue(observation["accepted"])
                self.assertIsNone(observation["rejection_reason"])
                self.assertEqual(raw_status, observation["response_summary"]["raw_status"])
                self.assertNotIn("input", observation["response_summary"])

    def test_running_status_acceptance_is_derived_from_the_public_contract(self) -> None:
        runner.PUBLIC_RUN_STATUS_CONTRACT["paused"] = {
            "status_bucket": "running",
            "is_terminal": False,
        }

        observation = runner.survivor_run_observation(
            200,
            {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "status": "paused",
                "status_bucket": "running",
                "is_terminal": False,
            },
            "workflow-1",
            "run-1",
        )

        self.assertTrue(observation["accepted"])

    def test_survivor_traffic_rejects_terminal_run_descriptions(self) -> None:
        for raw_status, status_bucket in (
            ("completed", "completed"),
            ("cancelled", "failed"),
            ("terminated", "failed"),
            ("failed", "failed"),
        ):
            with self.subTest(raw_status=raw_status):
                observation = runner.survivor_run_observation(
                    200,
                    {
                        "workflow_id": "workflow-1",
                        "run_id": "run-1",
                        "status": raw_status,
                        "status_bucket": status_bucket,
                        "is_terminal": True,
                    },
                    "workflow-1",
                    "run-1",
                )

                self.assertFalse(observation["accepted"])
                self.assertEqual("terminal_run", observation["rejection_reason"])

    def test_survivor_traffic_rejects_identity_mismatches(self) -> None:
        for field in ("workflow_id", "run_id"):
            with self.subTest(field=field):
                body = {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": "waiting",
                    "status_bucket": "running",
                    "is_terminal": False,
                }
                body[field] = "wrong-identity"
                observation = runner.survivor_run_observation(
                    200,
                    body,
                    "workflow-1",
                    "run-1",
                )

                self.assertFalse(observation["accepted"])
                self.assertEqual(
                    f"{field.removesuffix('_id')}_identity_mismatch",
                    observation["rejection_reason"],
                )

    def test_survivor_traffic_fails_closed_for_missing_or_inconsistent_status_contract(self) -> None:
        invalid_bodies = (
            {"status_bucket": "running", "is_terminal": False},
            {"status": "waiting", "is_terminal": False},
            {"status": "waiting", "status_bucket": "running"},
            {"status": "waiting", "status_bucket": "completed", "is_terminal": False},
            {"status": "waiting", "status_bucket": "running", "is_terminal": True},
            {"status": "invented", "status_bucket": "running", "is_terminal": False},
        )

        for invalid in invalid_bodies:
            with self.subTest(invalid=invalid):
                observation = runner.survivor_run_observation(
                    200,
                    {"workflow_id": "workflow-1", "run_id": "run-1", **invalid},
                    "workflow-1",
                    "run-1",
                )

                self.assertFalse(observation["accepted"])
                self.assertIsNotNone(observation["rejection_reason"])

    def test_bounded_survivor_wait_retains_last_redacted_response(self) -> None:
        original_describe = runner.describe
        original_lb = runner.LB
        evidence = {}

        try:
            runner.LB = "http://shared-endpoint"
            runner.describe = lambda *_args, **_kwargs: (
                200,
                {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": "completed",
                    "status_bucket": "completed",
                    "is_terminal": True,
                    "output": {"secret": "must not enter evidence"},
                },
                7,
            )

            with self.assertRaisesRegex(AssertionError, "'http_status': 200"):
                runner.wait_for_survivor_traffic(
                    "workflow-1",
                    "run-1",
                    0.01,
                    evidence,
                    interval=0,
                )

            self.assertEqual(200, evidence["http_status"])
            self.assertEqual("completed", evidence["response_summary"]["raw_status"])
            self.assertEqual("completed", evidence["response_summary"]["status_bucket"])
            self.assertTrue(evidence["response_summary"]["is_terminal"])
            self.assertNotIn("output", evidence["response_summary"])
        finally:
            runner.describe = original_describe
            runner.LB = original_lb

    def test_api_node_loss_completes_the_claimed_run_through_the_survivor(self) -> None:
        originals = {
            name: getattr(runner, name)
            for name in (
                "register_worker",
                "start_workflow",
                "poll_task",
                "compose",
                "ready",
                "describe",
                "complete_task",
                "wait_for",
            )
        }
        compose_calls = []
        completion_bases = []

        def fake_compose(*args, **_kwargs):
            compose_calls.append(args)
            stdout = "server-b\nload-balancer\n" if args[:3] == ("ps", "--status", "running") else ""
            return subprocess.CompletedProcess(args, 0, stdout, "")

        def fake_describe(workflow_id, run_id, base=runner.LB):
            common = {"workflow_id": workflow_id, "run_id": run_id}
            if base == runner.LB:
                return 200, {
                    **common,
                    "status": "pending",
                    "status_bucket": "running",
                    "is_terminal": False,
                }, 3
            return 200, {
                **common,
                "status": "completed",
                "status_bucket": "completed",
                "is_terminal": True,
            }, 4

        def fake_complete(task, base):
            completion_bases.append(base)
            return 202, {"recorded": True, "run_id": task["run_id"]}, 2

        try:
            runner.register_worker = lambda *_args, **_kwargs: {}
            runner.start_workflow = lambda *_args, **_kwargs: {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "status": 201,
                "ack_ms": 1,
            }
            runner.poll_task = lambda *_args, **_kwargs: {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "task_id": "task-1",
                "lease_owner": "worker-1",
                "workflow_task_attempt": 1,
            }
            runner.compose = fake_compose
            runner.ready = lambda base, *_args, **_kwargs: {
                "http_status": 200,
                "body": {"status": "ready", "base": base},
            }
            runner.describe = fake_describe
            runner.complete_task = fake_complete
            runner.wait_for = lambda _label, callback, *_args, **_kwargs: callback()

            result = runner.api_node_loss_phase()

            self.assertEqual([runner.SERVER_B], completion_bases)
            self.assertIn(("stop", "server-a"), compose_calls)
            self.assertIn(("start", "server-a"), compose_calls)
            self.assertTrue(result["lost_node_stopped"])
            self.assertTrue(result["shared_endpoint_reached_surviving_node"])
            self.assertEqual("pending", result["survivor_response"]["response_summary"]["raw_status"])
            self.assertEqual("completed", result["final_description"]["response_summary"]["raw_status"])
            self.assertEqual("workflow-1", result["final_description"]["response_summary"]["workflow_id"])
            self.assertEqual("run-1", result["final_description"]["response_summary"]["run_id"])
        finally:
            for name, value in originals.items():
                setattr(runner, name, value)

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
