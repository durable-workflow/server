#!/usr/bin/env python3
"""Temporal comparison race (Server #1081): pins, durability, headline and controller guards.

Runs with plain python3 or `uv run --project scripts/perf python -m unittest`.
The controller tests run the script in dry-run mode, which makes no Vultr call.
"""

from __future__ import annotations

from argparse import Namespace
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import re
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "scripts" / "perf"))

import temporal_compare as tc  # noqa: E402

CONTROLLER = ROOT / "scripts/perf/run-vultr-temporal-compare.sh"
WORKFLOW = ROOT / ".github/workflows/temporal-compare.yml"


def rep(seconds: float, ok: bool = True) -> dict:
    return {"ok": ok, "race_seconds": seconds if ok else None, "workflow_seconds": {"p50": seconds / 2, "p95": seconds * 0.9}}


def slot(race: str, index: int, system: str, seconds: list[float], ok: bool = True) -> dict:
    record = {"race": race, "slot": index, "system": system, "reps": [rep(value, ok) for value in seconds], "warmup": None}
    record["summary"] = tc.system_summary(record["reps"])
    return record


class PinsTest(unittest.TestCase):
    def test_every_locked_image_is_a_digest(self):
        images = tc.load_images_lock()
        for name in ("temporal", "roadrunner", "php", "mysql", "postgres", "rust-builder", "uv"):
            self.assertIn(name, images)
            self.assertRegex(images[name]["ref"], r"@sha256:[0-9a-f]{64}$")

    def test_harness_dockerfiles_use_only_locked_digests(self):
        images = tc.load_images_lock()
        for dockerfile in tc.HARNESS_DOCKERFILES:
            self.assertEqual([], tc.dockerfile_pin_errors(ROOT / dockerfile, images), dockerfile)

    def test_unpinned_or_unlisted_base_is_refused(self):
        images = tc.load_images_lock()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "Dockerfile"
            path.write_text(
                "FROM temporalio/temporal:latest AS a\n"
                "FROM php@sha256:" + "0" * 64 + "\n"
                "FROM a\n"
            )
            errors = tc.dockerfile_pin_errors(path, images)
        self.assertEqual(2, len(errors), errors)
        self.assertIn("not pinned by digest", errors[0])
        self.assertIn("not in images.lock.json", errors[1])

    def test_compose_service_must_run_a_locked_or_built_image(self):
        images = tc.load_images_lock()
        config = {"services": {
            "temporal": {"image": images["temporal"]["ref"]},
            "native-sqlite": {"image": "dw-race/native:abc"},
            "mysql": {"image": "mysql:8.0"},
        }}
        errors = tc.compose_image_errors(config, images, {"dw-race/native:abc"})
        self.assertEqual(["service mysql image mysql:8.0 is neither locked nor built by the harness"], errors)

    def test_worker_lock_files_pin_both_sdks(self):
        sdk = tc.composer_versions(tc.HARNESS / "workers/sdk-php/composer.lock")
        temporal = tc.composer_versions(tc.HARNESS / "workers/roadrunner/composer.lock")
        self.assertIn("durable-workflow/sdk", sdk)
        self.assertIn("temporal/sdk", temporal)
        self.assertIsNotNone(tc.uv_lock_version(ROOT / "scripts/perf/uv.lock", "temporalio"))


class DurabilityTest(unittest.TestCase):
    def volume(self, target: str) -> dict:
        return {"type": "volume", "source": "data", "target": target}

    def mysql_config(self, **overrides) -> dict:
        config = {"services": {
            "mysql": {"command": ["--innodb-flush-log-at-trx-commit=1", "--sync-binlog=1", "--require-secure-transport=ON"],
                      "volumes": [self.volume("/var/lib/mysql")]},
            "native-mysql": {"environment": {"DB_SSLMODE": "require"}},
        }}
        for service, values in overrides.items():
            config["services"][service].update(values)
        return config

    def test_default_durability_passes(self):
        self.assertEqual([], tc.durability_errors(self.mysql_config(), "native-mysql"))

    def test_relaxed_commit_flush_is_refused(self):
        config = self.mysql_config(mysql={"command": ["--innodb-flush-log-at-trx-commit=2", "--require-secure-transport=ON"]})
        errors = tc.durability_errors(config, "native-mysql")
        self.assertTrue(any("non-default durability" in error for error in errors), errors)

    def test_plaintext_mysql_is_refused(self):
        config = self.mysql_config(**{"native-mysql": {"environment": {"DB_SSLMODE": "prefer"}}})
        self.assertIn("native-mysql must connect with DB_SSLMODE=require", tc.durability_errors(config, "native-mysql"))
        config = self.mysql_config(mysql={"command": ["--sync-binlog=1"], "volumes": [self.volume("/var/lib/mysql")]})
        self.assertTrue(any("require-secure-transport" in error for error in tc.durability_errors(config, "native-mysql")))

    def test_sqlite_pragmas_memory_and_tmpfs_are_refused(self):
        for service in (
            {"command": ["server", "start-dev", "--sqlite-pragma", "synchronous=OFF"], "volumes": [self.volume("/home/temporal")]},
            {"command": ["server", "start-dev"], "volumes": [self.volume("/home/temporal")], "environment": {"X": "mode=memory"}},
            {"command": ["server", "start-dev"], "tmpfs": ["/home/temporal"], "volumes": [self.volume("/home/temporal")]},
            {"command": ["server", "start-dev"]},
        ):
            self.assertNotEqual([], tc.durability_errors({"services": {"temporal": service}}, "temporal"), service)

    def test_postgres_settings_are_refused_when_relaxed(self):
        for value in ("fsync=off", "synchronous_commit=off", "full_page_writes=off"):
            config = {"services": {"postgres": {"command": ["postgres", "-c", value], "volumes": [self.volume("/var/lib/postgresql/data")]}}}
            self.assertNotEqual([], tc.durability_errors(config, "native-pgsql"), value)

    def test_runtime_answers(self):
        self.assertEqual([], tc.mysql_durability_errors("1\t1\t1"))
        self.assertEqual(["MySQL innodb_flush_log_at_trx_commit is 2, expected 1"], tc.mysql_durability_errors("2\t1\t1"))
        self.assertEqual([], tc.postgres_durability_errors("on|on|on"))
        self.assertEqual(["PostgreSQL synchronous_commit is off, expected on"], tc.postgres_durability_errors("on|off|on"))

    def test_busy_host_is_refused(self):
        self.assertEqual([], tc.busy_host_errors({"role": "target", "cpu_busy_fraction": 0.02, "running_containers": 0}, 0.1))
        errors = tc.busy_host_errors({"role": "target", "cpu_busy_fraction": 0.4, "running_containers": 2}, 0.1)
        self.assertEqual(2, len(errors))
        self.assertTrue(tc.busy_host_errors({"role": "driver", "cpu_busy_fraction": None}, 0.1))


class IdlePreflightTest(unittest.TestCase):
    def setUp(self):
        self.probes = [(tc.DockerHost(role, None), "locked-image", f"race-idle-{role}")
                       for role in ("target", "driver")]
        self.config = Namespace(idle_seconds=5, idle_wait_seconds=20, idle_cpu_threshold=0.1, allow_busy_hosts=False)
        self.provenance = {"hosts": {"target": {}, "driver": {}}, "preflight": []}

    def test_both_hosts_must_pass_the_same_attempt(self):
        loads = {"target": iter([0.2, 0.01, 0.03]), "driver": iter([0.03, 0.4, 0.02])}

        def measure(host, *_):
            return {"role": host.role, "cpu_busy_fraction": next(loads[host.role]), "cpu_model": "fixture CPU"}

        with patch.object(tc, "preflight", side_effect=measure), patch.object(tc.time, "monotonic", side_effect=[0, 0, 5, 10]):
            tc.wait_for_idle_hosts(self.probes, self.config, "batch-0-native", self.provenance)

        attempts = self.provenance["preflight"]
        self.assertEqual([1, 1, 2, 2, 3, 3], [sample["attempt"] for sample in attempts])
        self.assertEqual(["target", "driver"] * 3, [sample["role"] for sample in attempts])
        self.assertTrue(all(sample["slot"] == "batch-0-native" for sample in attempts))
        self.assertEqual({"cpu_model": "fixture CPU"}, self.provenance["hosts"]["target"])

    def test_expired_wait_refuses_a_busy_or_unmeasurable_pair(self):
        def measure(host, *_):
            if host.role == "driver":
                raise subprocess.TimeoutExpired(["docker", "run"], 5)
            return {"role": host.role, "cpu_busy_fraction": 0.3}

        with patch.object(tc, "preflight", side_effect=measure), patch.object(tc.time, "monotonic", side_effect=[0, 0, 18]):
            with self.assertRaisesRegex(RuntimeError, "did not become idle in 20s.*driver host idle check did not measure CPU"):
                tc.wait_for_idle_hosts(self.probes, self.config, "batch-0-native", self.provenance)
        self.assertEqual("idle sample timed out", self.provenance["preflight"][1]["error"])

    def test_a_previous_slots_container_still_counts_as_busy(self):
        host = Mock(role="target")
        host.run.side_effect = [
            Mock(stdout="cpu 10 0 10 80 0 0 0 0\ncpu 11 0 10 89 0 0 0 0\n0.00 0.00\nmodel name: test\n"),
            Mock(stdout="race-idle-driver\nrace-previous-server\n"),
        ]
        with patch.object(tc.time, "monotonic", return_value=0):
            check = tc.preflight(host, "locked-image", "race-idle-target", 5, 20, {"race-idle-target", "race-idle-driver"})
        self.assertEqual(0.1, check["cpu_busy_fraction"])
        self.assertEqual(1, check["running_containers"])
        self.assertIn("target host runs 1 other containers", tc.busy_host_errors(check, 0.1))
        self.assertIn("never", host.run.call_args_list[0].args)

    def test_a_timed_out_sampler_gets_a_scoped_cleanup_within_the_deadline(self):
        host = Mock(role="target")
        host.run.side_effect = [subprocess.TimeoutExpired(["docker", "run"], 17), Mock()]
        with patch.object(tc.time, "monotonic", side_effect=[0, 17]):
            with self.assertRaises(subprocess.TimeoutExpired):
                tc.preflight(host, "locked-image", "race-idle-target", 5, 20, {"race-idle-target"})
        self.assertEqual(("rm", "-f", "race-idle-target"), host.run.call_args_list[1].args)
        self.assertEqual({"check": False, "timeout": 3}, host.run.call_args_list[1].kwargs)


class HeadlineTest(unittest.TestCase):
    def slots(self, temporal=(10.0, 10.2), sqlite=8.0, mysql=11.0, pgsql=9.0):
        result = [slot("five-steps", 0, "temporal", [temporal[0]] * 3)]
        index = 1
        for backend, seconds in (("sqlite", sqlite), ("mysql", mysql), ("pgsql", pgsql)):
            if seconds is not None:
                result.append(slot("five-steps", index, f"native-{backend}", [seconds, seconds + 0.2, seconds - 0.2]))
                index += 1
        result.append(slot("five-steps", index, "temporal", [temporal[1]] * 3))
        return result

    def test_system_order_brackets_native_with_temporal(self):
        self.assertEqual(["temporal", "native-sqlite", "native-mysql", "native-pgsql", "temporal"], tc.system_order(list(tc.BACKENDS)))

    def test_headline_is_the_slowest_backend(self):
        headline = tc.race_headline(self.slots(), 0.10)
        self.assertTrue(headline["usable"], headline["reasons"])
        self.assertEqual("mysql", headline["slowest_native_backend"])
        self.assertAlmostEqual(11.0 / 10.1, headline["ratio_slowest_native_to_temporal"], places=3)
        self.assertAlmostEqual(0.02, headline["temporal_drift_fraction"], places=4)

    def test_sqlite_is_never_the_headline_alone(self):
        headline = tc.race_headline(self.slots(mysql=None, pgsql=None), 0.10)
        self.assertFalse(headline["usable"])
        self.assertIsNone(headline["ratio_slowest_native_to_temporal"])
        self.assertTrue(any("needs SQLite, MySQL and PostgreSQL" in reason for reason in headline["reasons"]))

    def test_temporal_drift_marks_the_run_unusable(self):
        headline = tc.race_headline(self.slots(temporal=(10.0, 11.5)), 0.10)
        self.assertFalse(headline["usable"])
        self.assertTrue(any("drifted" in reason for reason in headline["reasons"]))

    def test_a_failed_rep_marks_the_run_unusable(self):
        slots = self.slots()
        slots[2] = slot("five-steps", 2, "native-mysql", [11.0, 11.0], ok=False)
        self.assertFalse(tc.race_headline(slots, 0.10)["usable"])

    def test_summary_and_markdown(self):
        config = {
            "races": ["five-steps"], "backends": list(tc.BACKENDS), "system_order": tc.system_order(list(tc.BACKENDS)),
            "workflows": 200, "reps": 3, "workers": 6, "step_milliseconds": 20, "warmup_workflows": 20,
            "native_poll_interval_ms": 50, "drift_threshold": 0.10, "local_stand_in": True, "idle_preflight_enforced": False,
            "target_plan": "vhp-2c-4gb-amd", "driver_plan": "vhp-4c-8gb-amd", "region": "ewr",
        }
        slots = [{key: value for key, value in record.items() if key != "summary"} for record in self.slots()]
        summary = tc.build_summary(config, {"hosts": {}}, slots, [])
        self.assertTrue(summary["usable"])
        self.assertFalse(summary["publishable"], "a local stand-in is never publishable")
        markdown = tc.render_summary(summary)
        self.assertIn("| five-steps | mysql |", markdown)
        self.assertIn("not publishable", markdown)
        json.dumps(summary)

    def test_race_result_times_from_first_start_to_last_close(self):
        workflows = [
            {"id": "a", "start_sent": 100.0, "start_acked": 100.01, "closed": 101.0, "status": "completed"},
            {"id": "b", "start_sent": 100.1, "start_acked": 100.12, "closed": 102.5, "status": "completed"},
        ]
        result = tc.race_result("native", "five-steps", workflows, [], "list")
        self.assertTrue(result["ok"])
        self.assertAlmostEqual(2.5, result["race_seconds"])
        self.assertEqual(2, result["workflow_seconds"]["count"])
        workflows[1]["status"] = "failed"
        result = tc.race_result("native", "five-steps", workflows, [], "list")
        self.assertFalse(result["ok"])
        self.assertIsNone(result["race_seconds"])


@unittest.skipUnless(shutil.which("jq") and shutil.which("ssh-keygen") and shutil.which("bash"), "needs bash, jq and ssh-keygen")
class ControllerTest(unittest.TestCase):
    def run_controller(self, mode: str = "race", **env) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory() as directory:
            base = {
                key: value for key, value in os.environ.items()
                if key in ("PATH", "HOME", "LANG", "TMPDIR")
            }
            return subprocess.run(
                ["bash", str(CONTROLLER), mode],
                env={
                    **base,
                    "DW_RACE_DRY_RUN": "1",
                    # Unroutable: a dry run must never reach it.
                    "VULTR_API_BASE": "http://127.0.0.1:9/unused",
                    "GITHUB_SHA": "a" * 40,
                    "GITHUB_RUN_ID": "42",
                    "GITHUB_RUN_ATTEMPT": "1",
                    "DW_RACE_ARTIFACT_DIR": directory,
                    **env,
                },
                text=True, capture_output=True, timeout=60,
            )

    def test_a_refused_call_logs_vultr_error_body_without_the_key(self):
        import http.server
        import threading

        key = "VULTRKEY" + "x" * 28
        served = []

        class Refuse(http.server.BaseHTTPRequestHandler):
            def do_GET(self):  # noqa: N802 - http.server naming
                served.append(self.path)
                body = json.dumps({"error": f"Unauthorized IP address: 203.0.113.9 for {key}", "status": 403}).encode()
                self.send_response(403)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Refuse)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            result = self.run_controller(
                "cleanup",
                DW_RACE_DRY_RUN="0",
                VULTR_API_KEY=key,
                VULTR_API_BASE=f"http://127.0.0.1:{server.server_address[1]}/v2",
            )
        finally:
            server.shutdown()
        output = result.stdout + result.stderr
        self.assertNotEqual(result.returncode, 0, output)
        self.assertTrue(served and served[0].startswith("/v2/instances?"), served)
        self.assertIn("Vultr API GET /instances answered HTTP 403", output)
        self.assertIn("Unauthorized IP address: 203.0.113.9", output)
        self.assertIn("<redacted>", output)
        self.assertNotIn(key, output)

    def test_dry_run_prints_create_delete_and_verify_calls(self):
        result = self.run_controller()
        output = result.stdout + result.stderr
        self.assertEqual(0, result.returncode, output)
        self.assertIn("worst case: VM lifetime cap 180 min -> 3 billed h -> $0.321 (limit $0.35)", output)
        self.assertIn("deletion is verified by the cap and at least 10 min before the 210-min job timeout", output)
        self.assertIn("Deadlines: race by", output)
        self.assertLess(output.index("Deadlines: race by"), output.index("POST /vpcs"))
        self.assertIn("DRY-RUN Vultr API: POST /vpcs", output)
        self.assertEqual(2, output.count("DRY-RUN Vultr API: POST /instances "))
        self.assertIn('"plan":"vhp-2c-4gb-amd"', output)
        self.assertIn('"plan":"vhp-4c-8gb-amd"', output)
        self.assertIn('"tags":["dw-temporal-compare","dw-temporal-compare-42-1"]', output)
        for role in ("target", "driver"):
            self.assertIn(f"DRY-RUN Vultr API: DELETE /instances/dry-run-{role}", output)
            self.assertIn(f"DRY-RUN Vultr API: GET /instances?label=dw-temporal-compare-42-1-{role}", output)
        self.assertIn("DRY-RUN Vultr API: DELETE /vpcs/dry-run-vpc", output)
        self.assertLess(output.index("POST /instances"), output.index("DELETE /instances"))
        self.assertLess(output.index("DELETE /instances/dry-run-driver"), output.index("DELETE /vpcs/dry-run-vpc"))

    def test_refuses_when_worst_case_exceeds_the_spend_cap(self):
        result = self.run_controller(DW_RACE_MAX_SPEND_USD="0.30")
        self.assertEqual(2, result.returncode)
        self.assertIn("Refusing to start: worst-case cost $0.321 exceeds", result.stderr)
        self.assertNotIn("POST /vpcs", result.stderr)

    def test_one_vcpu_target_is_cheaper(self):
        result = self.run_controller(DW_RACE_TARGET_PLAN="vhp-1c-2gb-amd")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("-> 3 billed h -> $0.267", result.stdout)

    def test_refuses_unexpected_plans_and_inputs_before_any_call(self):
        cases = {
            "DW_RACE_TARGET_PLAN": ("vhp-8c-16gb-amd", "Refusing unexpected target plan"),
            "DW_RACE_REPS": ("50", "DW_RACE_REPS must be between 1 and 9"),
            "DW_RACE_SERVER_REF": ("main", "full lowercase commit SHA"),
            "DW_RACE_BACKENDS": ("sqlite;rm", "subset of sqlite,mysql,pgsql"),
            "DW_RACE_MAX_VM_MINUTES": ("600", "between 60 and 240"),
        }
        for name, (value, message) in cases.items():
            result = self.run_controller(**{name: value})
            self.assertEqual(2, result.returncode, name)
            self.assertIn(message, result.stderr, name)
            self.assertNotIn("DRY-RUN Vultr API", result.stderr, name)

    def test_refuses_duplicate_races_or_backends(self):
        for name, value in (("DW_RACE_RACES", "five-steps,five-steps"), ("DW_RACE_BACKENDS", "sqlite,mysql,sqlite")):
            result = self.run_controller(**{name: value})
            self.assertEqual(2, result.returncode, name)
            self.assertIn("Refusing duplicate entries", result.stderr)
            self.assertNotIn("DRY-RUN Vultr API", result.stderr)

    def test_refuses_a_job_timeout_that_would_cut_off_the_exit_trap(self):
        result = self.run_controller(DW_RACE_JOB_TIMEOUT_MINUTES="185")
        self.assertEqual(2, result.returncode)
        self.assertIn("DW_RACE_JOB_TIMEOUT_MINUTES must be at least", result.stderr)

    def test_a_failed_listing_fails_cleanup_loudly(self):
        for failing in ("GET /instances?", "GET /vpcs?"):
            with self.subTest(failing):
                result = self.run_controller("cleanup", DW_RACE_CLEANUP_RUN="42-1", DW_RACE_DRY_RUN_FAIL=failing)
                self.assertNotEqual(0, result.returncode, result.stderr)
                self.assertIn("simulated failure", result.stderr)
                self.assertIn("ERROR: Could not list Vultr instances and VPCs", result.stderr)
                result = self.run_controller("cleanup", DW_RACE_DRY_RUN_FAIL=failing)
                self.assertNotEqual(0, result.returncode, result.stderr)

    def test_a_failed_listing_stops_a_race_before_any_vm_is_created(self):
        result = self.run_controller(DW_RACE_DRY_RUN_FAIL="GET /instances?")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("not creating new VMs", result.stderr)
        self.assertNotIn("POST", result.stderr)

    def test_every_ssh_and_scp_call_is_bounded_by_a_deadline(self):
        lines = CONTROLLER.read_text().splitlines()
        unbounded = [
            line for line in lines
            if re.search(r"(^|[;&|(]\s*|\s)(ssh|scp)\s", line)
            and not line.lstrip().startswith("#")
            and "timeout" not in line
            and "ssh-keygen" not in line
        ]
        self.assertEqual([], unbounded)

    def test_refuses_a_race_longer_than_the_lifetime_cap(self):
        result = self.run_controller(DW_RACE_WORKFLOWS="1000", DW_RACE_REPS="9")
        self.assertEqual(2, result.returncode)
        self.assertIn("exceeds DW_RACE_MAX_VM_MINUTES", result.stderr)

    def test_cleanup_lists_race_resources_only(self):
        result = self.run_controller("cleanup", DW_RACE_CLEANUP_RUN="42-1")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("DRY-RUN Vultr API: GET /instances?per_page=500", result.stderr)
        self.assertIn("DRY-RUN Vultr API: GET /vpcs?per_page=500", result.stderr)
        self.assertNotIn("POST", result.stderr)

    def test_requires_an_api_key_outside_a_dry_run(self):
        result = self.run_controller(DW_RACE_DRY_RUN="0")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("VULTR_API_KEY is required", result.stderr)


class WorkflowTest(unittest.TestCase):
    def test_dispatch_only_with_guards(self):
        text = WORKFLOW.read_text()
        on_block = text.split("\non:\n", 1)[1].split("\npermissions:", 1)[0]
        self.assertIn("  workflow_dispatch:", on_block)
        for trigger in ("schedule", "pull_request", "pull_request_target", "push:", "workflow_run"):
            self.assertNotIn(trigger, on_block)
        self.assertIn("permissions:\n  contents: read\n", text)
        self.assertIn("concurrency:\n  group: temporal-compare\n  cancel-in-progress: false\n", text)
        self.assertIn("if: github.repository == 'durable-workflow/server' && github.ref == 'refs/heads/main'", text)
        self.assertIn("environment: perf-soak", text)
        self.assertIn("secrets.VULTR_PERF_API_KEY", text)
        self.assertIn("retention-days: 90", text)

    def test_job_timeout_outlasts_the_vm_lifetime_cap(self):
        text = WORKFLOW.read_text()
        timeout = int(text.split("timeout-minutes: ", 1)[1].split()[0])
        lifetime = int(text.split('DW_RACE_MAX_VM_MINUTES: "', 1)[1].split('"')[0])
        self.assertGreaterEqual(timeout - lifetime, 20)
        self.assertIn(f'DW_RACE_JOB_TIMEOUT_MINUTES: "{timeout}"', text)


if __name__ == "__main__":
    unittest.main()
