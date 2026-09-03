#!/usr/bin/env python3

import importlib.util
import os
from pathlib import Path
import subprocess
import unittest
from unittest import mock


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
        runner.RESULT["readiness_transitions"] = []
        runner.RESULT["recovery_timings_ms"] = {}
        runner.RESULT["identities"] = {}
        runner.RESULT["duplicate_assertions"] = []
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

    def test_released_contract_requires_the_canonical_suite_schema(self) -> None:
        canonical = {
            "scenario_manifest": {
                "suite_schema": runner.PLATFORM_CONFORMANCE_SUITE_SCHEMA,
            },
        }
        self.assertEqual(
            runner.PLATFORM_CONFORMANCE_SUITE_SCHEMA,
            runner.parse_public_suite_schema(canonical),
        )

        for value in (None, {}, "durable-workflow.v2.platform-conformance-suite"):
            with self.subTest(suite_schema=value):
                contract = {"scenario_manifest": {"suite_schema": value}}
                with self.assertRaisesRegex(AssertionError, "non-canonical"):
                    runner.parse_public_suite_schema(contract)

    def test_released_lease_discovery_must_match_the_runner_configuration(self) -> None:
        contract = {
            "recovery_bounds": {
                "workflow_task_lease_seconds": 8,
            },
        }
        cluster = {
            "topology": {
                "matching_role": {
                    "discovery_limits": {
                        "workflow_task_lease_seconds": 8,
                    },
                },
            },
        }

        self.assertEqual(8, runner.require_effective_workflow_task_lease(cluster, contract, 8))

        with self.assertRaisesRegex(AssertionError, "failover contract disagrees"):
            runner.require_effective_workflow_task_lease(
                cluster,
                {"recovery_bounds": {"workflow_task_lease_seconds": 300}},
                8,
            )

        with self.assertRaisesRegex(AssertionError, "cluster discovery disagrees"):
            runner.require_effective_workflow_task_lease(
                {"topology": {"matching_role": {"discovery_limits": {}}}},
                contract,
                8,
            )

    def test_cache_readiness_requires_the_cache_check_to_recover(self) -> None:
        original_ready = runner.ready

        try:
            for status, expected in (("warning", False), ("unavailable", False), ("ok", True)):
                with self.subTest(status=status):
                    runner.ready = lambda _base, status=status: {
                        "http_status": 200,
                        "body": {"checks": {"cache": {"status": status}}},
                    }
                    observation = runner.cache_ready("http://server-a")
                    self.assertIs(expected, bool(observation))
                    self.assertEqual(status, observation["body"]["checks"]["cache"]["status"])
                    self.assertEqual(None if expected else "cache_not_ready", observation["rejection_reason"])
        finally:
            runner.ready = original_ready

    def test_readiness_rejection_preserves_http_and_transport_observations(self) -> None:
        original_request = runner.request

        try:
            for status, body, reason in (
                (503, {"status": "not_ready"}, "unexpected_http_status"),
                (0, {"transport_error": "timed out"}, "transport_error"),
            ):
                with self.subTest(status=status):
                    runner.request = lambda *_args, status=status, body=body, **_kwargs: (status, body, 3000)
                    observation = runner.ready("http://server-a")

                    self.assertFalse(observation)
                    self.assertEqual(status, observation["http_status"])
                    self.assertEqual(body, observation["body"])
                    self.assertEqual(reason, observation["rejection_reason"])
        finally:
            runner.request = original_request

    def test_readiness_timeout_reports_the_last_transport_observation(self) -> None:
        observation = runner.ProbeObservation({
            "http_status": 0,
            "body": {"transport_error": "timed out"},
            "accepted": False,
            "rejection_reason": "transport_error",
        })

        with self.assertRaisesRegex(AssertionError, "transport_error.*timed out"):
            runner.wait_for("warning readiness", lambda: observation, 0.01, interval=0.001)

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

    def test_restarted_node_refreshes_its_published_endpoint_and_evidence(self) -> None:
        original_compose = runner.compose
        original_ports = runner.PUBLISHED_PORTS.copy()
        original_endpoints = runner.PROBE_ENDPOINTS.copy()
        original_lb = runner.LB
        original_server_a = runner.SERVER_A
        original_server_b = runner.SERVER_B
        original_topology_ports = runner.RESULT["topology"]["published_ports"]
        original_diagnostics = runner.TOPOLOGY_DIAGNOSTICS

        try:
            runner.PUBLISHED_PORTS.clear()
            runner.PUBLISHED_PORTS.update({
                "server_a": 28084,
                "server_b": 28085,
                "load_balancer": 28086,
            })
            runner.PROBE_ENDPOINTS.clear()
            runner.PROBE_ENDPOINTS.update(
                runner.build_probe_endpoints(runner.CONNECT_HOST, runner.PUBLISHED_PORTS)
            )
            runner.LB = runner.PROBE_ENDPOINTS["load_balancer"]
            runner.SERVER_A = runner.PROBE_ENDPOINTS["server_a"]
            runner.SERVER_B = runner.PROBE_ENDPOINTS["server_b"]
            runner.RESULT["topology"]["published_ports"] = runner.PUBLISHED_PORTS.copy()
            runner.TOPOLOGY_DIAGNOSTICS = runner.initial_topology_diagnostics()
            runner.compose = lambda *_args, **_kwargs: subprocess.CompletedProcess(
                [], 0, "0.0.0.0:29084\n", ""
            )

            evidence = runner.refresh_published_endpoint("server_a", "server-a")

            self.assertEqual(29084, runner.PUBLISHED_PORTS["server_a"])
            self.assertEqual(f"http://{runner.CONNECT_HOST}:29084", runner.SERVER_A)
            self.assertEqual(runner.SERVER_A, evidence["base_url"])
            self.assertTrue(evidence["endpoint_changed"])
            self.assertEqual(
                runner.SERVER_A,
                runner.TOPOLOGY_DIAGNOSTICS["resolved_probe_endpoints"]["server_a"]["base_url"],
            )
            self.assertEqual(
                f"{runner.SERVER_A}/api/ready",
                runner.TOPOLOGY_DIAGNOSTICS["readiness_observations"]["server_a"]["endpoint"],
            )
            self.assertEqual(29084, runner.RESULT["topology"]["published_ports"]["server_a"])
        finally:
            runner.compose = original_compose
            runner.PUBLISHED_PORTS.clear()
            runner.PUBLISHED_PORTS.update(original_ports)
            runner.PROBE_ENDPOINTS.clear()
            runner.PROBE_ENDPOINTS.update(original_endpoints)
            runner.LB = original_lb
            runner.SERVER_A = original_server_a
            runner.SERVER_B = original_server_b
            runner.RESULT["topology"]["published_ports"] = original_topology_ports
            runner.TOPOLOGY_DIAGNOSTICS = original_diagnostics

    def test_restarted_node_refresh_keeps_a_fixed_endpoint_unchanged(self) -> None:
        original_compose = runner.compose
        original_diagnostics = runner.TOPOLOGY_DIAGNOSTICS
        original_port = runner.PUBLISHED_PORTS["server_a"]
        original_endpoint = runner.PROBE_ENDPOINTS["server_a"]

        try:
            runner.TOPOLOGY_DIAGNOSTICS = runner.initial_topology_diagnostics()
            runner.compose = lambda *_args, **_kwargs: subprocess.CompletedProcess(
                [], 0, f"0.0.0.0:{original_port}\n", ""
            )

            evidence = runner.refresh_published_endpoint("server_a", "server-a")

            self.assertEqual(original_endpoint, evidence["base_url"])
            self.assertFalse(evidence["endpoint_changed"])
        finally:
            runner.compose = original_compose
            runner.TOPOLOGY_DIAGNOSTICS = original_diagnostics

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

    def test_nonterminal_observation_accepts_every_public_running_raw_status(self) -> None:
        for raw_status in ("pending", "running", "waiting"):
            with self.subTest(raw_status=raw_status):
                observation = runner.nonterminal_run_observation(
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

        observation = runner.nonterminal_run_observation(
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

    def test_nonterminal_observation_rejects_terminal_run_descriptions(self) -> None:
        for raw_status, status_bucket in (
            ("completed", "completed"),
            ("cancelled", "failed"),
            ("terminated", "failed"),
            ("failed", "failed"),
        ):
            with self.subTest(raw_status=raw_status):
                observation = runner.nonterminal_run_observation(
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

    def test_nonterminal_observation_rejects_identity_mismatches(self) -> None:
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
                observation = runner.nonterminal_run_observation(
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

    def test_nonterminal_observation_fails_closed_for_missing_or_inconsistent_status_contract(self) -> None:
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
                observation = runner.nonterminal_run_observation(
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
        lease_expires_at = (
            runner.utc_now() + runner.dt.timedelta(seconds=runner.BOUNDS["workflow_task_lease_seconds"])
        ).isoformat().replace("+00:00", "Z")

        def fake_compose(*args, **_kwargs):
            compose_calls.append(args)
            if args[:3] == ("ps", "--status", "running"):
                stdout = "server-b\nload-balancer\n"
            elif args[:2] == ("port", "server-a"):
                stdout = f"0.0.0.0:{runner.PUBLISHED_PORTS['server_a']}\n"
            else:
                stdout = ""
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

        def fake_complete(task, base, **_kwargs):
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
                "lease_expires_at": lease_expires_at,
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
            self.assertIn(("kill", "server-a"), compose_calls)
            self.assertNotIn(("stop", "server-a"), compose_calls)
            self.assertIn(("start", "server-a"), compose_calls)
            self.assertTrue(result["lost_node_stopped"])
            self.assertEqual("sigkill", result["node_loss_mode"])
            self.assertTrue(result["shared_endpoint_reached_surviving_node"])
            self.assertEqual(lease_expires_at, result["lease_expires_at"])
            self.assertTrue(result["completion_before_lease_expiry"])
            self.assertEqual(8, result["lease_timing"]["advertised_lease_seconds"])
            self.assertGreater(
                result["lease_timing"]["lease_remaining_ms"]["completion_finished"],
                0,
            )
            self.assertEqual(
                [
                    "completion_finished",
                    "completion_started",
                    "node_loss_completed",
                    "node_loss_started",
                    "shared_traffic_confirmed",
                    "shared_traffic_started",
                    "survivor_readiness_confirmed",
                    "survivor_readiness_started",
                    "task_claim_observed",
                ],
                sorted(result["lease_timing"]["milestones_ms"]),
            )
            self.assertEqual("pending", result["survivor_response"]["response_summary"]["raw_status"])
            self.assertEqual("completed", result["final_description"]["response_summary"]["raw_status"])
            self.assertEqual("workflow-1", result["final_description"]["response_summary"]["workflow_id"])
            self.assertEqual("run-1", result["final_description"]["response_summary"]["run_id"])
        finally:
            for name, value in originals.items():
                setattr(runner, name, value)

    def test_api_node_loss_fails_as_harness_timing_when_node_loss_consumes_the_eight_second_lease(self) -> None:
        claimed_at = runner.dt.datetime(2026, 7, 15, 20, 0, tzinfo=runner.dt.timezone.utc)
        lease_expires_at = (
            claimed_at + runner.dt.timedelta(seconds=runner.BOUNDS["workflow_task_lease_seconds"])
        ).isoformat().replace("+00:00", "Z")
        compose_calls = []
        completion = mock.Mock(side_effect=AssertionError("completion must not be attempted"))

        class Clock:
            value = 100.0

            def monotonic(self) -> float:
                return self.value

        clock = Clock()

        def fake_compose(*args, **_kwargs):
            compose_calls.append(args)
            if args == ("kill", "server-a"):
                clock.value += 9
            return subprocess.CompletedProcess(args, 0, "", "")

        with (
            mock.patch.object(runner.time, "monotonic", clock.monotonic),
            mock.patch.multiple(
                runner,
                utc_now=lambda: claimed_at,
                register_worker=lambda *_args, **_kwargs: {},
                start_workflow=lambda *_args, **_kwargs: {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": 201,
                    "ack_ms": 1,
                },
                poll_task=lambda *_args, **_kwargs: {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "task_id": "task-1",
                    "lease_owner": "worker-1",
                    "workflow_task_attempt": 1,
                    "lease_expires_at": lease_expires_at,
                },
                compose=fake_compose,
                complete_task=completion,
            ),
            self.assertRaisesRegex(
                AssertionError,
                "api_node_loss harness_timing: lease budget exhausted before node_loss_completed",
            ),
        ):
            runner.api_node_loss_phase()

        timing = runner.RESULT["phase_evidence"]["api_node_loss"]["lease_timing"]
        self.assertEqual("harness_timing", timing["failure_classification"])
        self.assertEqual(8, timing["advertised_lease_seconds"])
        self.assertEqual(9000, timing["milestones_ms"]["node_loss_completed"])
        self.assertLess(timing["lease_remaining_ms"]["node_loss_completed"], 0)
        self.assertEqual([("kill", "server-a")], compose_calls)
        completion.assert_not_called()

    def test_database_interruption_completes_within_live_lease_for_every_public_running_status(self) -> None:
        for raw_status in ("pending", "running", "waiting"):
            with self.subTest(raw_status=raw_status):
                result, trace = self.run_database_interruption({
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": raw_status,
                    "status_bucket": "running",
                    "is_terminal": False,
                    "input": {"secret": "must not enter evidence"},
                })

                post_recovery = result["post_recovery_description"]
                self.assertTrue(post_recovery["accepted"])
                self.assertEqual(raw_status, post_recovery["response_summary"]["raw_status"])
                self.assertNotIn("input", post_recovery["response_summary"])
                self.assertEqual([("stop", "mysql"), ("start", "mysql")], trace["compose_calls"])
                self.assertEqual([runner.SERVER_B, runner.SERVER_B], trace["completion_bases"])
                self.assertEqual(["task-1", "task-1"], trace["completion_task_ids"])
                self.assertEqual(503, result["failed_write_status"])
                self.assertEqual(2, len(result["readiness_down"]))
                self.assertEqual(2, len(result["readiness_recovered"]))
                self.assertTrue(result["duplicate_completion_refused"])
                self.assertEqual("completed", result["final_status"])
                self.assertTrue(result["final_description"]["response_summary"]["is_terminal"])
                self.assertNotIn("output", result["final_description"]["response_summary"])
                self.assertTrue(result["completion_before_lease_expiry"])
                self.assertEqual("original_claimant_live_lease", result["completion"]["strategy"])
                self.assertFalse(result["replacement_reclaim"]["required"])
                self.assertGreater(
                    result["lease_timing"]["lease_remaining_ms"]["original_completion_finished"],
                    0,
                )
                self.assertFalse(
                    result["lease_timing"]["bound_classification"]["worker_lease_loss"]["consumed"],
                )
                self.assertEqual(
                    post_recovery,
                    runner.RESULT["phase_evidence"]["database_interruption"]["post_recovery_description"],
                )

    def test_database_interruption_reclaims_after_outage_crosses_lease_expiry(self) -> None:
        result, trace = self.run_database_interruption(
            {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "status": "waiting",
                "status_bucket": "running",
                "is_terminal": False,
            },
            cross_lease_expiry=True,
        )

        self.assertFalse(result["completion_before_lease_expiry"])
        self.assertEqual("replacement_worker_after_lease_expiry", result["completion"]["strategy"])
        self.assertEqual("task-2", result["completion"]["task_id"])
        self.assertEqual([runner.SERVER_B, runner.SERVER_A, runner.SERVER_B], trace["completion_bases"])
        self.assertEqual(["task-1", "task-2", "task-2"], trace["completion_task_ids"])
        self.assertEqual([409, 202, 409], trace["completion_statuses"])
        self.assertTrue(result["stale_owner_fence"]["observed"])
        self.assertEqual("lease_expired", result["stale_owner_fence"]["reason"])
        self.assertTrue(result["replacement_reclaim"]["observed"])
        self.assertEqual("task-2", result["replacement_reclaim"]["task_id"])
        self.assertEqual(
            "database_recovered_after_lease_expiry",
            result["lease_timing"]["outcome_classification"],
        )
        self.assertEqual(8000, result["lease_timing"]["lease_expiry_deadline_ms"])
        self.assertEqual(9000, result["lease_timing"]["outage_duration_ms"])
        self.assertTrue({
            "outage_started",
            "server-a_not_ready",
            "server-b_not_ready",
            "database_return_started",
            "server-a_ready",
            "server-b_ready",
            "durable_state_verified",
            "stale_owner_fence_finished",
            "replacement_reclaim_finished",
            "replacement_completion_finished",
        }.issubset(result["lease_timing"]["milestones_ms"]))
        self.assertLess(
            result["lease_timing"]["lease_remaining_ms"]["stale_owner_fence_finished"],
            0,
        )
        self.assertTrue(
            result["lease_timing"]["bound_classification"]["database_reclaim"]["consumed"],
        )
        self.assertFalse(
            result["lease_timing"]["bound_classification"]["worker_lease_loss"]["consumed"],
        )
        self.assertTrue(
            runner.RESULT["recovery_bounds"]["database_reclaim_after_lease_seconds"]["passed"],
        )
        self.assertEqual(1, runner.RESULT["duplicate_assertions"][-1]["logical_completion_count"])

    def test_database_interruption_fails_closed_for_invalid_post_recovery_descriptions(self) -> None:
        valid = {
            "workflow_id": "workflow-1",
            "run_id": "run-1",
            "status": "waiting",
            "status_bucket": "running",
            "is_terminal": False,
        }
        cases = (
            ("missing state", 200, {key: value for key, value in valid.items() if key != "status"}, "missing_or_unknown_raw_status"),
            ("terminal state", 200, {**valid, "status": "completed", "status_bucket": "completed", "is_terminal": True}, "terminal_run"),
            ("workflow mismatch", 200, {**valid, "workflow_id": "wrong-workflow"}, "workflow_identity_mismatch"),
            ("run mismatch", 200, {**valid, "run_id": "wrong-run"}, "run_identity_mismatch"),
            ("contradictory bucket", 200, {**valid, "status_bucket": "completed"}, "status_bucket_contract_mismatch"),
            ("contradictory terminal flag", 200, {**valid, "is_terminal": True}, "terminal_flag_contract_mismatch"),
            ("non-200 response", 503, valid, "http_status_not_ok"),
        )

        for label, http_status, body, reason in cases:
            with self.subTest(case=label), self.assertRaisesRegex(AssertionError, reason):
                self.run_database_interruption(body, http_status=http_status)

    def run_database_interruption(
        self,
        post_recovery_body: dict,
        *,
        http_status: int = 200,
        cross_lease_expiry: bool = False,
    ) -> tuple[dict, dict]:
        trace = {
            "compose_calls": [],
            "completion_bases": [],
            "completion_task_ids": [],
            "completion_statuses": [],
            "poll_workers": [],
        }
        completion_recorded = False
        claimed_at = runner.dt.datetime(2026, 7, 15, 20, 0, tzinfo=runner.dt.timezone.utc)
        lease_expires_at = (
            claimed_at + runner.dt.timedelta(seconds=runner.BOUNDS["workflow_task_lease_seconds"])
        ).isoformat().replace("+00:00", "Z")

        class Clock:
            value = 100.0

            def monotonic(self) -> float:
                return self.value

        clock = Clock()

        def fake_compose(*args, **_kwargs):
            trace["compose_calls"].append(args)
            if cross_lease_expiry and args == ("start", "mysql"):
                clock.value += runner.BOUNDS["workflow_task_lease_seconds"] + 1
            return subprocess.CompletedProcess(args, 0, "", "")

        def fake_ready(base, expected_status=200):
            database_status = "unavailable" if expected_status == 503 else "ok"
            return {
                "http_status": expected_status,
                "body": {
                    "status": "not_ready" if expected_status == 503 else "ready",
                    "node": base,
                    "checks": {"database": {"status": database_status}},
                },
                "request_ms": 1,
            }

        def fake_describe(workflow_id, run_id, _base=runner.LB):
            if not completion_recorded:
                return http_status, post_recovery_body, 3
            return 200, {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "status": "completed",
                "status_bucket": "completed",
                "is_terminal": True,
                "output": {"secret": "must not enter evidence"},
            }, 4

        def fake_poll(worker_id, *_args, **_kwargs):
            trace["poll_workers"].append(worker_id)
            if len(trace["poll_workers"]) == 1:
                return {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "task_id": "task-1",
                    "lease_owner": worker_id,
                    "workflow_task_attempt": 1,
                    "lease_expires_at": lease_expires_at,
                }
            return {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "task_id": "task-2",
                "lease_owner": worker_id,
                "workflow_task_attempt": 2,
                "lease_expires_at": (
                    claimed_at + runner.dt.timedelta(seconds=30)
                ).isoformat().replace("+00:00", "Z"),
            }

        def fake_complete(task, base, **_kwargs):
            nonlocal completion_recorded
            trace["completion_bases"].append(base)
            trace["completion_task_ids"].append(task["task_id"])
            if cross_lease_expiry and task["task_id"] == "task-1":
                trace["completion_statuses"].append(409)
                return 409, {"recorded": False, "reason": "lease_expired"}, 2
            if completion_recorded:
                trace["completion_statuses"].append(409)
                return 409, {"recorded": False, "reason": "already_completed"}, 2
            completion_recorded = True
            trace["completion_statuses"].append(202)
            return 202, {"recorded": True, "run_id": task["run_id"]}, 2

        runner.RESULT["phase_evidence"] = {}
        runner.RESULT["readiness_transitions"] = []
        runner.RESULT["recovery_timings_ms"] = {}
        runner.RESULT["identities"] = {}
        runner.RESULT["duplicate_assertions"] = []
        runner.RESULT["loss_assertions"] = []
        runner.RESULT["recovery_bounds"] = {
            name: {"seconds": seconds, "passed": None}
            for name, seconds in runner.BOUNDS.items()
        }
        with (
            mock.patch.object(runner.time, "monotonic", clock.monotonic),
            mock.patch.multiple(
                runner,
                utc_now=lambda: claimed_at,
                register_worker=lambda *_args, **_kwargs: {},
                start_workflow=lambda *_args, **_kwargs: {
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": 201,
                    "ack_ms": 1,
                },
                poll_task=fake_poll,
                compose=fake_compose,
                ready=fake_ready,
                request=lambda *_args, **_kwargs: (503, {"error": "database unavailable"}, 1),
                describe=fake_describe,
                complete_task=fake_complete,
                wait_for=lambda _label, callback, *_args, **_kwargs: callback(),
            ),
        ):
            return runner.database_interruption_phase(), trace

    def test_worker_lease_loss_accepts_every_public_running_raw_status(self) -> None:
        for raw_status in ("pending", "running", "waiting"):
            with self.subTest(raw_status=raw_status):
                result, trace = self.run_worker_lease_loss({
                    "workflow_id": "workflow-1",
                    "run_id": "run-1",
                    "status": raw_status,
                    "status_bucket": "running",
                    "is_terminal": False,
                    "input": {"secret": "must not enter evidence"},
                })

                pre_recovery = result["pre_recovery_description"]
                self.assertTrue(pre_recovery["accepted"])
                self.assertEqual(raw_status, pre_recovery["response_summary"]["raw_status"])
                self.assertNotIn("input", pre_recovery["response_summary"])
                self.assertEqual(
                    pre_recovery,
                    runner.RESULT["phase_evidence"]["worker_lease_loss"]["pre_recovery_description"],
                )
                self.assertEqual(8500, result["recovery_ms"])
                self.assertNotEqual(result["initial_lease_owner"], result["recovery_lease_owner"])
                self.assertEqual([runner.SERVER_A, runner.SERVER_B], trace["completion_bases"])
                self.assertEqual([202, 409], trace["completion_statuses"])
                self.assertTrue(result["duplicate_completion_refused"])
                self.assertEqual("completed", result["final_status"])
                self.assertTrue(result["final_description"]["response_summary"]["is_terminal"])
                self.assertEqual(
                    409,
                    runner.RESULT["duplicate_assertions"][-1]["duplicate_completion_http_status"],
                )
                self.assertTrue(
                    runner.RESULT["loss_assertions"][-1]["run_state_preserved_while_leased"],
                )
                self.assertTrue(
                    runner.RESULT["recovery_bounds"]["workflow_task_lease_seconds"]["passed"],
                )
                self.assertTrue(
                    runner.RESULT["recovery_bounds"]["worker_repair_after_lease_seconds"]["passed"],
                )

    def test_worker_lease_loss_fails_closed_for_invalid_pre_recovery_descriptions(self) -> None:
        valid = {
            "workflow_id": "workflow-1",
            "run_id": "run-1",
            "status": "pending",
            "status_bucket": "running",
            "is_terminal": False,
        }
        cases = (
            (
                "missing state",
                200,
                {key: value for key, value in valid.items() if key != "status"},
                "missing_or_unknown_raw_status",
                "status",
            ),
            (
                "terminal state",
                200,
                {
                    **valid,
                    "status": "completed",
                    "status_bucket": "completed",
                    "is_terminal": True,
                },
                "terminal_run",
                "is_terminal",
            ),
            (
                "workflow mismatch",
                200,
                {**valid, "workflow_id": "wrong-workflow"},
                "workflow_identity_mismatch",
                "workflow_id",
            ),
            (
                "run mismatch",
                200,
                {**valid, "run_id": "wrong-run"},
                "run_identity_mismatch",
                "run_id",
            ),
            (
                "contradictory bucket",
                200,
                {**valid, "status_bucket": "completed"},
                "status_bucket_contract_mismatch",
                "status_bucket",
            ),
            (
                "contradictory terminal flag",
                200,
                {**valid, "is_terminal": True},
                "terminal_flag_contract_mismatch",
                "is_terminal",
            ),
            ("non-200 response", 503, valid, "http_status_not_ok", "http_status"),
        )

        for label, http_status, body, reason, field in cases:
            with self.subTest(case=label), self.assertRaisesRegex(AssertionError, reason):
                self.run_worker_lease_loss(body, http_status=http_status)

            evidence = runner.RESULT["phase_evidence"]["worker_lease_loss"]["pre_recovery_description"]
            self.assertFalse(evidence["accepted"])
            self.assertEqual(reason, evidence["rejection_reason"])
            self.assertEqual(field, evidence["rejection_field"])

    def test_worker_lease_loss_failure_output_retains_the_last_canonical_observation(self) -> None:
        observation = {
            "http_status": 200,
            "response_summary": {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "raw_status": "pending",
                "status_bucket": "completed",
                "is_terminal": False,
            },
            "accepted": False,
            "rejection_reason": "status_bucket_contract_mismatch",
            "rejection_field": "status_bucket",
        }

        def run_phase(name, _callback):
            if name == "worker_lease_loss":
                runner.RESULT["phase_evidence"][name] = {
                    "pre_recovery_description": observation,
                }
                raise AssertionError("status_bucket_contract_mismatch")
            runner.RESULT["phase_outcomes"][name] = {"status": "pass"}

        runner.RESULT["phase_outcomes"] = {}
        runner.RESULT["phase_evidence"] = {}
        with mock.patch.multiple(
            runner,
            run_phase=run_phase,
            write_result=lambda: None,
            KEEP_STACK=True,
        ):
            self.assertEqual(1, runner.main())

        failure = runner.RESULT["phase_outcomes"]["worker_lease_loss"]
        self.assertIn("status_bucket_contract_mismatch", failure["reason"])
        self.assertEqual(
            observation,
            failure["phase_evidence"]["pre_recovery_description"],
        )

    def run_worker_lease_loss(
        self,
        pre_recovery_body: dict,
        *,
        http_status: int = 200,
    ) -> tuple[dict, dict]:
        trace = {
            "completion_bases": [],
            "completion_statuses": [],
            "poll_workers": [],
        }
        completion_recorded = False

        def fake_poll(worker_id, _base, _timeout=15, _poll_request_id=None):
            trace["poll_workers"].append(worker_id)
            return {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "task_id": "task-1",
                "lease_owner": worker_id,
                "workflow_task_attempt": len(trace["poll_workers"]),
                "lease_expires_at": "2026-07-14T00:00:08Z",
            }

        def fake_describe(workflow_id, run_id, _base=runner.LB):
            if not completion_recorded:
                return http_status, pre_recovery_body, 3
            return 200, {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "status": "completed",
                "status_bucket": "completed",
                "is_terminal": True,
                "output": {"secret": "must not enter evidence"},
            }, 4

        def fake_complete(_task, base):
            nonlocal completion_recorded
            trace["completion_bases"].append(base)
            if completion_recorded:
                trace["completion_statuses"].append(409)
                return 409, {"recorded": False}, 2
            completion_recorded = True
            trace["completion_statuses"].append(202)
            return 202, {"recorded": True}, 2

        runner.RESULT["phase_evidence"] = {}
        runner.RESULT["recovery_timings_ms"] = {}
        runner.RESULT["identities"] = {}
        runner.RESULT["duplicate_assertions"] = []
        runner.RESULT["loss_assertions"] = []
        runner.RESULT["recovery_bounds"] = {
            name: {"seconds": seconds, "passed": None}
            for name, seconds in runner.BOUNDS.items()
        }
        with mock.patch.multiple(
            runner,
            register_worker=lambda *_args, **_kwargs: {},
            start_workflow=lambda *_args, **_kwargs: {
                "workflow_id": "workflow-1",
                "run_id": "run-1",
                "status": 201,
                "ack_ms": 1,
            },
            poll_task=fake_poll,
            describe=fake_describe,
            complete_task=fake_complete,
            monotonic_ms=lambda _started: 8500,
        ):
            return runner.worker_lease_loss_phase(), trace

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
