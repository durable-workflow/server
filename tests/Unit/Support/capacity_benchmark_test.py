#!/usr/bin/env python3

import copy
import importlib.util
import io
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.error
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = ROOT / "scripts/benchmark/capacity_suite.py"
spec = importlib.util.spec_from_file_location("capacity_suite", MODULE_PATH)
assert spec is not None and spec.loader is not None
capacity_suite = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capacity_suite)

PUBLICATION_MODULE_PATH = (
    ROOT / "scripts/benchmark/capacity_schema_publication.py"
)
publication_spec = importlib.util.spec_from_file_location(
    "capacity_schema_publication", PUBLICATION_MODULE_PATH
)
assert publication_spec is not None and publication_spec.loader is not None
sys.modules["capacity_suite"] = capacity_suite
capacity_schema_publication = importlib.util.module_from_spec(publication_spec)
publication_spec.loader.exec_module(capacity_schema_publication)

MATRIX_MODULE_PATH = ROOT / "scripts/benchmark/capacity_matrix.py"
matrix_spec = importlib.util.spec_from_file_location(
    "capacity_matrix", MATRIX_MODULE_PATH
)
assert matrix_spec is not None and matrix_spec.loader is not None
sys.modules["capacity_suite"] = capacity_suite
capacity_matrix = importlib.util.module_from_spec(matrix_spec)
sys.modules["capacity_matrix"] = capacity_matrix
matrix_spec.loader.exec_module(capacity_matrix)

LOCAL_DOCKER_MODULE_PATH = ROOT / "scripts/benchmark/capacity_local_docker.py"
local_docker_spec = importlib.util.spec_from_file_location(
    "capacity_local_docker", LOCAL_DOCKER_MODULE_PATH
)
assert local_docker_spec is not None and local_docker_spec.loader is not None
capacity_local_docker = importlib.util.module_from_spec(local_docker_spec)
sys.modules["capacity_local_docker"] = capacity_local_docker
local_docker_spec.loader.exec_module(capacity_local_docker)

COLLECTOR_MODULE_PATH = (
    ROOT / "benchmarks/capacity/v1/collectors/local-docker/local_docker_collector.py"
)
collector_spec = importlib.util.spec_from_file_location(
    "local_docker_collector", COLLECTOR_MODULE_PATH
)
assert collector_spec is not None and collector_spec.loader is not None
local_docker_collector = importlib.util.module_from_spec(collector_spec)
collector_spec.loader.exec_module(local_docker_collector)


class CapacityBenchmarkContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.suite = capacity_suite.load_json(capacity_suite.DEFAULT_SUITE)
        self.profile = capacity_suite.load_json(capacity_suite.DEFAULT_PROFILE)

    @staticmethod
    def empty_demand() -> dict[str, dict[str, int]]:
        return capacity_matrix.MetricBuffer._empty_demand()

    @staticmethod
    def empty_workflow_cohorts() -> dict[str, dict[str, int]]:
        return capacity_matrix.MetricBuffer._empty_workflow_cohorts()

    def offered_load(
        self, suite: dict, cell: dict, load_step: int
    ) -> dict[str, float | int]:
        return capacity_suite._offered_load_contract(
            cell, load_step, suite["operating_point_rule"]
        )

    def capacity_control(
        self, suite: dict, cell: dict, load_step: int
    ) -> dict[str, object]:
        execution = cell["execution"]
        return {
            "suite_version": suite["suite_version"],
            "deterministic_seed": execution["deterministic_seed"],
            "concurrent_open_workflows": execution["concurrent_open_workflows"],
            "client_concurrency": execution["client_concurrency"],
            "worker_concurrency": execution["worker_concurrency"],
            "warmup_seconds": execution["warmup_seconds"],
            "duration_seconds": execution["duration_seconds"],
            "offered_load": self.offered_load(suite, cell, load_step),
            "termination": execution["termination"],
        }

    def compliant_query_cell_observations(
        self, cell_id: str, binding: str
    ) -> tuple[dict, list[dict]]:
        suite = copy.deepcopy(self.suite)
        cell = next(cell for cell in suite["cells"] if cell["id"] == cell_id)
        cell["execution"]["load_steps"] = [4]
        cell["execution"]["duration_seconds"] = 4
        query_cohort = capacity_suite.long_lived_query_target(cell)
        rows = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        for row in rows:
            row["cell_id"] = cell_id
            row["binding"] = binding
            row["control"] = self.capacity_control(suite, cell, 4)
            row["concurrent_open_workflows"] = query_cohort
            row["concurrent_long_lived_query_workflows"] = query_cohort
            row["demand"]["query_operations"] = {
                "attempted": 20,
                "accepted": 20,
                "completed": 20,
                "rejected": 0,
                "throttled": 0,
            }
            row["latencies_ms"]["query"] = [10.0] * 20
            if cell_id == "query-inspection":
                row["counters"]["workflow_starts"] = 0
                row["counters"]["workflow_completions"] = 0
                row["workflow_cohorts"] = self.empty_workflow_cohorts()
                row["demand"]["workflow_starts"] = self.empty_demand()[
                    "workflow_starts"
                ]
                row["latencies_ms"]["schedule_to_start"] = []
            else:
                row["counters"]["activity_dispatches"] = 1
                row["latencies_ms"]["replay"] = [10.0]

        drain = copy.deepcopy(rows[-1])
        drain["sample_index"] = len(rows)
        drain["phase"] = "drain"
        drain["interval_seconds"] = 1.0
        drain["counters"] = {
            "workflow_starts": 0,
            "workflow_completions": query_cohort,
            "activity_dispatches": 5_000,
            "errors": 0,
            "throttles": 0,
        }
        drain["workflow_cohorts"] = self.empty_workflow_cohorts()
        drain["workflow_cohorts"]["long_lived_query"]["completions"] = query_cohort
        drain["demand"] = self.empty_demand()
        drain["demand"]["workflow_starts"]["completed"] = query_cohort
        drain["latencies_ms"] = {
            "schedule_to_start": [100_000.0] * query_cohort,
            "replay": [100_000.0],
            "query": [],
        }
        drain["concurrent_open_workflows"] = 0
        drain["concurrent_long_lived_query_workflows"] = 0
        drain["infrastructure"]["queue_backlog"] = 0
        rows.append(drain)
        return suite, rows

    def test_repository_suite_and_profile_are_complete(self) -> None:
        capacity_suite.validate_suite(self.suite)
        capacity_suite.validate_profile(self.profile)

        self.assertEqual(
            capacity_suite.REQUIRED_CELL_IDS,
            {cell["id"] for cell in self.suite["cells"]},
        )
        self.assertEqual(
            ["php", "python", "rust"],
            self.suite["driver_contract"]["required_bindings"],
        )
        for cell in self.suite["cells"]:
            self.assertEqual(self.suite["artifacts"], cell["artifacts"])
            self.assertEqual(
                capacity_suite.REQUIRED_BINDINGS,
                {binding["language"] for binding in cell["bindings"]},
            )

    def test_dependency_free_adapter_descriptions_match_checked_in_contracts(
        self,
    ) -> None:
        bindings = capacity_suite.SUITE_ROOT / "bindings"
        commands = {
            "php": ["php", str(bindings / "php/capacity_adapter.php"), "describe"],
            "python": [
                sys.executable,
                str(bindings / "python/capacity_adapter.py"),
                "describe",
            ],
        }
        for binding, command in commands.items():
            with self.subTest(binding=binding):
                completed = subprocess.run(
                    command,
                    check=True,
                    capture_output=True,
                    text=True,
                )
                self.assertEqual(
                    capacity_suite.load_json(bindings / binding / "adapter.json"),
                    json.loads(completed.stdout),
                )

        collector = capacity_suite.SUITE_ROOT / "collectors/local-docker"
        completed = subprocess.run(
            [sys.executable, str(collector / "local_docker_collector.py"), "describe"],
            check=True,
            capture_output=True,
            text=True,
        )
        self.assertEqual(
            capacity_suite.load_json(collector / "collector.json"),
            json.loads(completed.stdout),
        )

    def test_cross_binding_payload_and_history_conformance_fixtures(self) -> None:
        fixture_path = capacity_suite.SUITE_ROOT / "workload-conformance-fixtures.json"
        fixtures = capacity_suite.load_json(fixture_path)
        cells = {cell["id"]: cell for cell in self.suite["cells"]}
        for fixture in fixtures["payload_cases"]:
            with self.subTest(payload_fixture=fixture["id"]):
                workload = capacity_suite.workload_contract(
                    self.suite,
                    cells[fixture["cell_id"]],
                    fixture["shape"],
                )
                self.assertEqual(workload["payload"], fixture["payload"])

        bindings = capacity_suite.SUITE_ROOT / "bindings"
        commands = {
            "php": ["php", "capacity_adapter.php", "conformance", str(fixture_path)],
            "python": [
                sys.executable,
                "capacity_adapter.py",
                "conformance",
                str(fixture_path),
            ],
            "rust": [
                "cargo",
                "run",
                "--quiet",
                "--locked",
                "--manifest-path",
                "Cargo.toml",
                "--",
                "conformance",
                str(fixture_path),
            ],
        }
        for binding in fixtures["bindings"]:
            with self.subTest(binding=binding):
                completed = subprocess.run(
                    commands[binding],
                    check=True,
                    capture_output=True,
                    text=True,
                    cwd=bindings / binding,
                )
                self.assertEqual(
                    fixtures["expected_payload_evidence"],
                    json.loads(completed.stdout),
                )

        for binding in fixtures["bindings"]:
            for fixture in fixtures["history_cases"]:
                with self.subTest(binding=binding, history_fixture=fixture["id"]):
                    workload = capacity_suite.workload_contract(
                        self.suite,
                        cells[fixture["cell_id"]],
                        fixture["shape"],
                    )
                    root = self.history_bundle(fixture["root"])
                    children = [
                        self.history_bundle(child) for child in fixture["children"]
                    ]
                    evidence = capacity_suite.validate_history_evidence(
                        workload["history"], root, children
                    )
                    self.assertTrue(evidence["matches"])
                    if "expected_evidence" in fixture:
                        self.assertEqual(fixture["expected_evidence"], evidence)

    @staticmethod
    def history_bundle(fixture: dict) -> dict:
        return {
            "history_complete": fixture["history_complete"],
            "history_events": [
                {"type": event_type} for event_type in fixture["history_event_types"]
            ],
            "tasks": [{"type": task_type} for task_type in fixture["task_types"]],
            "signals": fixture["signals"],
        }

    def test_mixed_generation_resolves_the_selected_component_contract(self) -> None:
        mixed = next(cell for cell in self.suite["cells"] if cell["id"] == "mixed")
        runner = capacity_matrix.CellRunner.__new__(capacity_matrix.CellRunner)
        runner.suite = self.suite
        runner.cell = mixed
        runner.execution = mixed["execution"]
        for shape in ("one-activity", "multiple-activities"):
            with self.subTest(shape=shape):
                payload = runner._payload(shape, 1)
                component = capacity_suite.workload_contract(self.suite, mixed, shape)[
                    "payload"
                ]
                self.assertEqual(component, payload["payload_contract"])
                self.assertEqual(
                    component["workflow_input_bytes"],
                    len(payload["blob"].encode("utf-8")),
                )

    def test_payload_and_history_drift_fail_closed(self) -> None:
        multiple = next(
            cell for cell in self.suite["cells"] if cell["id"] == "multiple-activities"
        )
        history = multiple["workload"]["history"]
        events = [
            "WorkflowStarted",
            *(
                event
                for _ in range(5)
                for event in (
                    "ActivityScheduled",
                    "ActivityStarted",
                    "ActivityCompleted",
                )
            ),
            "WorkflowCompleted",
        ]
        bundle = {
            "history_complete": True,
            "history_events": [{"type": event} for event in events],
            "tasks": [
                *({"type": "workflow"} for _ in range(6)),
                *({"type": "activity"} for _ in range(5)),
            ],
            "signals": [],
        }
        capacity_suite.validate_history_evidence(history, bundle)
        bundle["history_events"][2]["type"] = "ActivityFailed"
        with self.assertRaisesRegex(capacity_suite.ContractError, "evidence.*drifted"):
            capacity_suite.validate_history_evidence(history, bundle)

        fixtures = capacity_suite.load_json(
            capacity_suite.SUITE_ROOT / "workload-conformance-fixtures.json"
        )
        fixtures["payload_cases"][1]["payload"]["activity_result_bytes"] = 65
        with tempfile.TemporaryDirectory() as temporary:
            drifted_fixture = Path(temporary) / "drifted.json"
            drifted_fixture.write_text(json.dumps(fixtures))
            completed = subprocess.run(
                [
                    sys.executable,
                    str(
                        capacity_suite.SUITE_ROOT
                        / "bindings/python/capacity_adapter.py"
                    ),
                    "conformance",
                    str(drifted_fixture),
                ],
                capture_output=True,
                text=True,
            )
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("activity result", completed.stderr)

    def test_matrix_controller_plans_every_cell_binding_with_exact_controls(
        self,
    ) -> None:
        completed = subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts/benchmark/capacity_matrix.py"),
                "dry-run",
            ],
            check=True,
            capture_output=True,
            text=True,
            cwd=ROOT,
        )
        plan = json.loads(completed.stdout)
        self.assertEqual(27, len(plan["matrix"]))
        self.assertEqual(
            {
                (cell["id"], binding)
                for cell in self.suite["cells"]
                for binding in ("php", "python", "rust")
            },
            {(entry["cell_id"], entry["binding"]) for entry in plan["matrix"]},
        )
        for entry in plan["matrix"]:
            cell = next(
                cell for cell in self.suite["cells"] if cell["id"] == entry["cell_id"]
            )
            self.assertEqual(cell["execution"], entry["execution"])

    def test_local_docker_topology_matches_the_profile_and_rejects_drift(
        self,
    ) -> None:
        context = capacity_local_docker.validate_topology()
        topology = context["topology"]
        compose = context["compose"]
        self.assertEqual(
            set(self.profile["components"]), set(topology["component_services"])
        )
        for component, service_name in topology["component_services"].items():
            service = compose["services"][service_name]
            expected = self.profile["components"][component]
            self.assertEqual(expected["image"], service["image"])
            self.assertEqual(expected["cpu_cores"], service["cpus"])
            self.assertEqual(expected["memory_bytes"], service["mem_limit"])

        drifted = copy.deepcopy(compose)
        drifted["services"]["redis"]["image"] = "docker.io/library/redis:latest"
        original_load = capacity_local_docker._load

        def load_with_drift(path: Path) -> dict:
            if path == capacity_local_docker.COMPOSE_PATH:
                return drifted
            return original_load(path)

        with mock.patch.object(
            capacity_local_docker, "_load", side_effect=load_with_drift
        ):
            with self.assertRaisesRegex(
                capacity_local_docker.TopologyError, "redis image differs"
            ):
                capacity_local_docker.validate_topology()

    def test_matrix_routes_adapter_roles_to_resolved_language_containers(
        self,
    ) -> None:
        adapter = capacity_suite.load_json(
            capacity_suite.SUITE_ROOT / "bindings/python/adapter.json"
        )
        runner = capacity_matrix.CellRunner.__new__(capacity_matrix.CellRunner)
        environment = {
            "DURABLE_WORKFLOW_RUNTIME_URL": "http://server:8080",
            "DURABLE_WORKFLOW_NAMESPACE": "capacity",
            "DURABLE_WORKFLOW_TASK_QUEUE": "capacity-smoke",
            "UNRELATED_SECRET": "do-not-forward",
        }
        with mock.patch.dict(
            capacity_matrix.os.environ,
            {
                "CAPACITY_ADAPTER_CONTAINER_PYTHON": "resolved-python-container",
                "CAPACITY_ADAPTER_WORKDIR_PYTHON": "/capacity",
            },
            clear=False,
        ):
            command = runner._entrypoint(adapter, "worker", environment)
        self.assertEqual(
            ["docker", "exec", "--interactive", "--workdir", "/capacity"],
            command[:5],
        )
        self.assertIn("resolved-python-container", command)
        self.assertEqual(["python3", "capacity_adapter.py", "worker"], command[-3:])
        self.assertTrue(
            any(
                value == "DURABLE_WORKFLOW_TASK_QUEUE=capacity-smoke"
                for value in command
            )
        )
        self.assertFalse(any("UNRELATED_SECRET" in value for value in command))

    def test_local_launcher_passes_every_resolved_identity_automatically(
        self,
    ) -> None:
        context = capacity_local_docker.validate_topology()
        resolved = {
            service: f"container-{index}"
            for index, service in enumerate(
                context["topology"]["component_services"].values(), start=1
            )
        }
        with mock.patch.object(
            capacity_local_docker,
            "_container_id",
            side_effect=lambda topology, service: resolved[service],
        ):
            environment = capacity_local_docker._execution_environment(context)
        collector_mapping = context["collector"]["component_containers"]
        for component, variable in collector_mapping.items():
            service = context["topology"]["component_services"][component]
            self.assertEqual(resolved[service], environment[variable])
        for binding, service in context["topology"]["adapter_services"].items():
            self.assertEqual(
                resolved[service],
                environment[f"CAPACITY_ADAPTER_CONTAINER_{binding.upper()}"],
            )

    def test_local_launcher_scopes_compose_resources_to_the_actions_job(self) -> None:
        context = capacity_local_docker.validate_topology()
        topology = context["topology"]
        with mock.patch.dict(
            capacity_local_docker.os.environ,
            {
                "GITHUB_RUN_ID": "12345",
                "GITHUB_RUN_ATTEMPT": "2",
                "GITHUB_JOB": "capacity_contract",
            },
            clear=True,
        ):
            command = capacity_local_docker._compose_command(topology, "up")
            self.assertEqual(
                "durable-workflow-capacity-v1-12345-2-capacity_contract",
                command[command.index("--project-name") + 1],
            )

        with mock.patch.dict(
            capacity_local_docker.os.environ,
            {"GITHUB_RUN_ID": "12345"},
            clear=True,
        ):
            with self.assertRaisesRegex(
                capacity_local_docker.TopologyError,
                "requires run id, run attempt, and job identity",
            ):
                capacity_local_docker._compose_command(topology, "up")

    def test_local_launcher_uses_one_validated_project_for_compose_and_collector(
        self,
    ) -> None:
        context = capacity_local_docker.validate_topology()
        topology = context["topology"]
        resolved = {
            service: f"container-{index}"
            for index, service in enumerate(
                topology["component_services"].values(), start=1
            )
        }
        with (
            mock.patch.dict(
                capacity_local_docker.os.environ,
                {"CAPACITY_DOCKER_PROJECT": "capacity-custom"},
                clear=True,
            ),
            mock.patch.object(
                capacity_local_docker,
                "_container_id",
                side_effect=lambda topology, service: resolved[service],
            ),
        ):
            command = capacity_local_docker._compose_command(topology, "up")
            environment = capacity_local_docker._execution_environment(context)
        self.assertEqual(
            "capacity-custom", command[command.index("--project-name") + 1]
        )
        self.assertEqual("capacity-custom", environment["CAPACITY_DOCKER_PROJECT"])
        self.assertEqual(
            "capacity-custom_capacity", environment["CAPACITY_DOCKER_NETWORK"]
        )

        with mock.patch.dict(
            capacity_local_docker.os.environ,
            {"CAPACITY_DOCKER_PROJECT": "shared/unsafe"},
            clear=True,
        ):
            with self.assertRaisesRegex(
                capacity_local_docker.TopologyError,
                "must be a lowercase Compose project name",
            ):
                capacity_local_docker._compose_command(topology, "up")

    def test_local_launcher_fails_closed_on_engine_identity_drift(self) -> None:
        outputs = {
            ("docker", "version"): json.dumps(
                {"Version": "27.5.2", "Os": "linux", "Arch": "amd64"}
            ),
            ("docker", "info"): json.dumps(
                {
                    "DefaultRuntime": "runc",
                    "CgroupVersion": "2",
                    "CgroupDriver": "systemd",
                    "Driver": "overlay2",
                    "KernelVersion": "6.8.0-101-generic",
                    "DockerRootDir": "/var/lib/docker",
                }
            ),
        }

        def fake_run(command: list[str], **kwargs: object) -> str:
            return outputs[(command[0], command[1])]

        with (
            mock.patch.object(
                capacity_local_docker.shutil, "which", return_value="/usr/bin/docker"
            ),
            mock.patch.object(capacity_local_docker, "_run", side_effect=fake_run),
        ):
            with self.assertRaisesRegex(
                capacity_local_docker.TopologyError, "Docker version"
            ):
                capacity_local_docker.verify_host(self.profile)

    def test_local_launcher_derives_the_complete_supported_host_identity(self) -> None:
        def fake_run(command: list[str], **kwargs: object) -> str:
            if command[:2] == ["docker", "version"]:
                return json.dumps(
                    {
                        "Version": "27.5.1",
                        "Os": "linux",
                        "Arch": "amd64",
                        "Components": [
                            {"Name": "containerd", "Version": "1.7.25"},
                            {"Name": "runc", "Version": "1.2.3"},
                            {"Name": "docker-init", "Version": "0.19.0"},
                        ],
                    }
                )
            if command[:2] == ["docker", "info"]:
                return json.dumps(
                    {
                        "DefaultRuntime": "runc",
                        "CgroupVersion": "2",
                        "CgroupDriver": "systemd",
                        "Driver": "overlay2",
                        "KernelVersion": "6.8.0-101-generic",
                        "DockerRootDir": "/var/lib/docker",
                    }
                )
            if command[:3] == ["docker", "compose", "version"]:
                return "2.32.4\n"
            if command[0] == "findmnt":
                return json.dumps(
                    {
                        "filesystems": [
                            {
                                "source": "/dev/mapper/system-docker",
                                "fstype": "ext4",
                                "options": "rw,relatime,errors=remount-ro",
                            }
                        ]
                    }
                )
            if command[0] == "lsblk":
                return json.dumps(
                    {
                        "blockdevices": [
                            {
                                "name": "system-docker",
                                "rota": False,
                                "tran": None,
                                "type": "lvm",
                                "children": [
                                    {
                                        "name": "nvme0n1",
                                        "rota": False,
                                        "tran": "nvme",
                                        "type": "disk",
                                    }
                                ],
                            }
                        ]
                    }
                )
            self.fail(f"unexpected command: {command}")

        disk_usage = type(
            "DiskUsage",
            (),
            {"total": self.profile["durable_storage"]["capacity_bytes"]},
        )()
        with (
            mock.patch.object(
                capacity_local_docker.shutil, "which", return_value="/usr/bin/docker"
            ),
            mock.patch.object(
                capacity_local_docker.shutil, "disk_usage", return_value=disk_usage
            ),
            mock.patch.object(capacity_local_docker, "_run", side_effect=fake_run),
        ):
            capacity_local_docker.verify_host(self.profile)

    def test_live_smoke_result_is_qualified_but_never_publishable(self) -> None:
        collector_path = (
            capacity_suite.SUITE_ROOT / "collectors/local-docker/collector.json"
        )
        collector = capacity_suite.load_json(collector_path)
        arguments = capacity_matrix.parse_args(
            [
                "smoke",
                "--source-revision",
                "383b14e389ac7ec4873c74034d6087ce9db0bea0",
                "--run-timestamp",
                "2026-08-11T00:00:00Z",
            ]
        )
        qualified_step = {
            "operating_point_eligible": True,
            "drain": {"converged": True},
        }
        captured: dict[str, object] = {}

        def capture_result(result: dict, path: Path) -> None:
            captured["result"] = result
            captured["path"] = path

        with (
            mock.patch.dict(
                capacity_matrix.os.environ,
                {
                    "DURABLE_WORKFLOW_RUNTIME_URL": "http://server:8080",
                    "DURABLE_WORKFLOW_NAMESPACE": "capacity",
                },
                clear=False,
            ),
            mock.patch.object(
                capacity_matrix.CellRunner,
                "run_load_step",
                return_value=[{"live": True}],
            ),
            mock.patch.object(
                capacity_matrix.capacity_suite,
                "reduce_step",
                return_value=qualified_step,
            ),
            mock.patch.object(capacity_matrix, "_write_jsonl"),
            mock.patch.object(
                capacity_matrix.capacity_suite,
                "_write_result",
                side_effect=capture_result,
            ),
        ):
            result = capacity_matrix._run_smoke(
                arguments, self.suite, self.profile, collector
            )
        self.assertTrue(result["qualified"])
        self.assertFalse(result["publishable"])
        self.assertEqual("local_topology_smoke", result["evidence_class"])
        self.assertEqual(result, captured["result"])
        self.assertEqual(
            arguments.output_dir / "local-docker-smoke.result.json",
            captured["path"],
        )

    def test_local_docker_collector_matches_normal_stats_rows_by_id(self) -> None:
        full_id = "0123456789ab" + ("c" * 52)
        collector = local_docker_collector.LocalDockerCollector.__new__(
            local_docker_collector.LocalDockerCollector
        )
        collector.containers = {"server": full_id}
        collector.profile = {
            "components": {"server": {"cpu_cores": 2, "memory_bytes": 2_147_483_648}}
        }
        stats = json.dumps(
            {
                "BlockIO": "0B / 0B",
                "CPUPerc": "125.50%",
                "Container": full_id[:12],
                "ID": full_id[:12],
                "MemPerc": "12.50%",
                "MemUsage": "256MiB / 2GiB",
                "Name": "capacity-server-1",
                "NetIO": "1kB / 2kB",
                "PIDs": "4",
            }
        )

        with mock.patch.object(local_docker_collector, "_run", return_value=stats):
            result = collector._docker_stats()

        self.assertEqual(
            {
                "assigned_cpu_cores": 2.0,
                "consumed_cpu_cores": 1.255,
                "assigned_memory_bytes": 2_147_483_648,
                "consumed_memory_bytes": 268_435_456,
            },
            result["server"],
        )

    def test_local_docker_collector_rejects_non_id_stats_identity(self) -> None:
        full_id = "0123456789ab" + ("c" * 52)
        collector = local_docker_collector.LocalDockerCollector.__new__(
            local_docker_collector.LocalDockerCollector
        )
        collector.containers = {"server": full_id}
        collector.profile = {
            "components": {"server": {"cpu_cores": 2, "memory_bytes": 2_147_483_648}}
        }
        stats = json.dumps(
            {
                "CPUPerc": "1.00%",
                "MemUsage": "1MiB / 2GiB",
                "Name": full_id,
            }
        )

        with (
            mock.patch.object(local_docker_collector, "_run", return_value=stats),
            self.assertRaisesRegex(
                local_docker_collector.CollectorError,
                "omitted a valid container ID",
            ),
        ):
            collector._docker_stats()

    def test_adapter_descriptor_missing_a_cell_fails_suite_validation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            copied_root = Path(directory) / "v1"
            shutil.copytree(
                capacity_suite.SUITE_ROOT,
                copied_root,
                ignore=shutil.ignore_patterns("vendor", ".deps", "target"),
            )
            descriptor_path = copied_root / "bindings/php/adapter.json"
            descriptor = capacity_suite.load_json(descriptor_path)
            del descriptor["workloads"]["mixed"]
            descriptor_path.write_text(json.dumps(descriptor))

            copied_suite = capacity_suite.load_json(copied_root / "suite.json")
            with self.assertRaisesRegex(
                capacity_suite.ContractError, "must implement every suite cell"
            ):
                capacity_suite.validate_suite(copied_suite, copied_root / "suite.json")

    def test_schema_publication_rejects_unpublished_or_divergent_sources(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            copied_root = Path(directory) / "v1"
            shutil.copytree(
                capacity_suite.SUITE_ROOT,
                copied_root,
                ignore=shutil.ignore_patterns("vendor", ".deps", "target"),
            )
            copied_suite = capacity_suite.load_json(copied_root / "suite.json")
            future_schema = copied_root / "schemas/future.schema.json"
            future_schema.write_text(
                json.dumps(
                    {
                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                        "$id": (
                            "https://durable-workflow.github.io/schemas/"
                            "capacity-benchmark-future/v1.json"
                        ),
                        "type": "object",
                    }
                )
            )

            with self.assertRaisesRegex(
                capacity_suite.ContractError,
                "cover every capacity schema source exactly once",
            ):
                capacity_suite.validate_suite(copied_suite, copied_root / "suite.json")

            future_schema.unlink()
            suite_schema = copied_root / "schemas/suite.schema.json"
            suite_schema.write_text(suite_schema.read_text() + "\n")
            with self.assertRaisesRegex(
                capacity_suite.ContractError,
                "digest for suite does not match",
            ):
                capacity_suite.validate_suite(copied_suite, copied_root / "suite.json")

    def test_public_schema_qualification_follows_https_redirects_and_checks_json(
        self,
    ) -> None:
        publication = capacity_suite.load_json(
            capacity_suite.SUITE_ROOT / capacity_suite.SCHEMA_PUBLICATION
        )
        bodies = {
            publication["canonical_url"]: (
                capacity_suite.SUITE_ROOT / capacity_suite.SCHEMA_PUBLICATION
            ).read_bytes()
        }
        for name, relative in capacity_suite.REQUIRED_SCHEMAS.items():
            bodies[publication["schemas"][name]["$id"]] = (
                capacity_suite.SUITE_ROOT / relative
            ).read_bytes()

        class Response:
            status = 200

            def __init__(
                self, url: str, body: bytes, content_type: str = "application/json"
            ) -> None:
                self.url = url.replace(
                    "https://durable-workflow.github.io",
                    "https://durable-workflow.com",
                )
                self.body = body
                self.headers = {"Content-Type": content_type}

            def __enter__(self):
                return self

            def __exit__(self, *_args) -> None:
                return None

            def geturl(self) -> str:
                return self.url

            def read(self) -> bytes:
                return self.body

        requested = []

        def opener(request, timeout):
            self.assertEqual(15, timeout)
            requested.append(request.full_url)
            return Response(request.full_url, bodies[request.full_url])

        capacity_suite.verify_schema_publication(opener=opener)
        self.assertEqual(set(bodies), set(requested))

        def html_opener(request, timeout):
            return Response(request.full_url, b"{}", "text/html; charset=utf-8")

        with self.assertRaisesRegex(
            capacity_suite.ContractError, "non-JSON content type"
        ):
            capacity_suite.verify_schema_publication(opener=html_opener)

    def test_http_503_does_not_affect_source_validation_but_fails_publication_audit(
        self,
    ) -> None:
        def unavailable_opener(request, timeout):
            self.assertEqual(15, timeout)
            raise urllib.error.HTTPError(
                request.full_url,
                503,
                "Service Unavailable",
                {},
                None,
            )

        with (
            mock.patch.object(
                capacity_suite.urllib.request,
                "urlopen",
                side_effect=unavailable_opener,
            ),
            mock.patch("sys.stdout", new=io.StringIO()),
            mock.patch("sys.stderr", new_callable=io.StringIO) as errors,
        ):
            self.assertEqual(0, capacity_suite.main(["validate"]))
            self.assertEqual(1, capacity_schema_publication.main())

        self.assertIn(
            "capacity schema publication audit error:",
            errors.getvalue(),
        )
        self.assertRegex(
            errors.getvalue(),
            "public schema route .* returned HTTP 503",
        )

    def test_bounded_reference_is_deterministic_and_rejects_transient_peak(
        self,
    ) -> None:
        first = capacity_suite._reference_observations(self.suite, self.profile)
        second = capacity_suite._reference_observations(self.suite, self.profile)
        self.assertEqual(first, second)

        result = capacity_suite.reduce_result(
            self.suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            first,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
            evidence_class="harness_reference",
            publishable=False,
        )

        self.assertFalse(result["publishable"])
        self.assertEqual("deterministic-model", result["identity"]["binding"])
        self.assertEqual(8, result["maximum_sustained_operating_point"]["load_step"])
        steps = {step["load_step"]: step for step in result["load_steps"]}
        self.assertGreater(
            steps[12]["rates"]["workflow_starts_per_second"],
            steps[8]["rates"]["workflow_starts_per_second"],
        )
        self.assertTrue(steps[12]["saturation"]["sustained"])
        self.assertIn("schedule_to_start_p99_ms", steps[12]["saturation"]["violations"])
        self.assertFalse(steps[8]["saturation"]["sustained"])
        self.assertIn("components", steps[8]["infrastructure"])
        self.assertIn("database", steps[8]["infrastructure"])
        self.assertIn("redis", steps[8]["infrastructure"])
        self.assertIn("storage", steps[8])

    def test_exact_artifact_and_workload_shape_drift_fail_validation(self) -> None:
        rolling = copy.deepcopy(self.suite)
        rolling["artifacts"]["server"]["version"] = "latest"
        rolling["artifacts"]["server"]["reference"] = (
            "docker.io/durableworkflow/server:latest"
        )
        rolling["cells"] = [
            {**cell, "artifacts": rolling["artifacts"]} for cell in rolling["cells"]
        ]
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "exact artifact version"
        ):
            capacity_suite.validate_suite(rolling)

        incomplete = copy.deepcopy(self.suite)
        del incomplete["cells"][0]["workload"]["payload"]["signal_bytes"]
        with self.assertRaisesRegex(capacity_suite.ContractError, "signal_bytes"):
            capacity_suite.validate_suite(incomplete)

        fractional = copy.deepcopy(self.suite)
        fractional["cells"][0]["execution"]["worker_concurrency"] = 1.5
        with self.assertRaisesRegex(capacity_suite.ContractError, "must be an integer"):
            capacity_suite.validate_suite(fractional)

        unordered = copy.deepcopy(self.suite)
        unordered["cells"][0]["execution"]["load_steps"] = [25, 100, 50]
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "unique and strictly increasing"
        ):
            capacity_suite.validate_suite(unordered)

        query_drift = copy.deepcopy(self.suite)
        query_cell = next(
            cell for cell in query_drift["cells"] if cell["id"] == "query-inspection"
        )
        query_cell["workload"]["measurement_eligibility"]["long_lived_query_cohort"][
            "concurrent_open_workflow_share"
        ] = 0.99
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "must match its declared query workload"
        ):
            capacity_suite.validate_suite(query_drift)

        mixed_drift = copy.deepcopy(self.suite)
        mixed_cell = next(
            cell for cell in mixed_drift["cells"] if cell["id"] == "mixed"
        )
        mixed_cell["workload"]["mix"]["simple-start-complete"] = 0.24
        with self.assertRaisesRegex(capacity_suite.ContractError, "weights must sum"):
            capacity_suite.validate_suite(mixed_drift)

    def test_incomplete_measurement_window_cannot_be_an_operating_point(self) -> None:
        observations = capacity_suite._reference_observations(self.suite, self.profile)
        one_sample = [row for row in observations if row["load_step"] == 4][:1]
        step = capacity_suite.reduce_step(
            one_sample,
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="simple-start-complete",
        )
        self.assertFalse(step["operating_point_eligible"])
        self.assertIn("complete_measurement_window", step["saturation"]["violations"])

    def test_open_slot_saturation_cannot_qualify_an_underdelivered_step(self) -> None:
        observations = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        for row in observations:
            row["counters"]["workflow_starts"] = 1
            row["counters"]["workflow_completions"] = 1
            row["workflow_cohorts"]["completion_required"] = {
                "starts": 1,
                "completions": 1,
            }
            row["demand"]["workflow_starts"] = {
                "attempted": 1,
                "accepted": 1,
                "completed": 1,
                "rejected": 0,
                "throttled": 0,
            }
            row["latencies_ms"]["schedule_to_start"] = [10.0]
            row["concurrent_open_workflows"] = 0

        step = capacity_suite.reduce_step(
            observations,
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="simple-start-complete",
        )

        self.assertEqual(16.0, step["offered_load"]["workflow_starts"]["target_count"])
        self.assertEqual(4, step["offered_load"]["workflow_starts"]["attempted"])
        self.assertEqual(12, step["offered_load"]["workflow_starts"]["unoffered"])
        self.assertNotIn(
            "complete_measurement_window", step["saturation"]["violations"]
        )
        self.assertIn("workflow_offer_underdelivery", step["saturation"]["violations"])
        self.assertIn("workflow_start_underdelivery", step["saturation"]["violations"])
        self.assertFalse(step["operating_point_eligible"])

    def test_forged_offered_load_and_unresolved_demand_fail_closed(self) -> None:
        observations = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        forged_contract = copy.deepcopy(observations)
        for row in forged_contract:
            row["control"]["offered_load"]["workflow_starts_per_second"] = 3.0
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "must equal the declared load step"
        ):
            capacity_suite.reduce_step(
                forged_contract,
                self.suite["operating_point_rule"],
                required_measurement_seconds=4,
                cell_id="simple-start-complete",
            )

        unresolved = copy.deepcopy(observations)
        unresolved[0]["demand"]["workflow_starts"]["attempted"] += 1
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "must resolve as accepted"
        ):
            capacity_suite.reduce_step(
                unresolved,
                self.suite["operating_point_rule"],
                required_measurement_seconds=4,
                cell_id="simple-start-complete",
            )

    def test_slow_collection_cannot_hide_admission_loss(self) -> None:
        observations = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        for index, row in enumerate(observations):
            delivered = 4 if index == 0 else 0
            row["counters"]["workflow_starts"] = delivered
            row["counters"]["workflow_completions"] = delivered
            row["workflow_cohorts"]["completion_required"] = {
                "starts": delivered,
                "completions": delivered,
            }
            row["demand"]["workflow_starts"] = {
                "attempted": delivered,
                "accepted": delivered,
                "completed": delivered,
                "rejected": 0,
                "throttled": 0,
            }
            row["latencies_ms"]["schedule_to_start"] = [10.0] * delivered
            row["concurrent_open_workflows"] = 0

        step = capacity_suite.reduce_step(
            observations,
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="simple-start-complete",
        )

        self.assertEqual(4, step["offered_load"]["workflow_starts"]["attempted"])
        self.assertEqual(4.0, step["measurement_seconds"])
        self.assertIn("workflow_offer_underdelivery", step["saturation"]["violations"])
        self.assertFalse(step["operating_point_eligible"])

    def test_serial_query_bottleneck_cannot_qualify_query_underdelivery(
        self,
    ) -> None:
        observations = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        query_cell = next(
            cell for cell in self.suite["cells"] if cell["id"] == "query-inspection"
        )
        for row in observations:
            row["control"]["offered_load"] = self.offered_load(
                self.suite, query_cell, 4
            )
            row["concurrent_open_workflows"] += 500
            row["concurrent_long_lived_query_workflows"] = 500
            row["demand"]["query_operations"] = {
                "attempted": 1,
                "accepted": 1,
                "completed": 1,
                "rejected": 0,
                "throttled": 0,
            }
            row["latencies_ms"]["query"] = [10.0]

        step = capacity_suite.reduce_step(
            observations,
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="query-inspection",
        )

        query_delivery = step["offered_load"]["query_operations"]
        self.assertEqual(80.0, query_delivery["target_count"])
        self.assertEqual(4, query_delivery["attempted"])
        self.assertEqual(4, query_delivery["completed"])
        self.assertIn("query_offer_underdelivery", step["saturation"]["violations"])
        self.assertIn(
            "query_completion_underdelivery", step["saturation"]["violations"]
        )
        self.assertFalse(step["operating_point_eligible"])

    def test_query_and_mixed_cells_qualify_for_every_binding_without_drain_throughput(
        self,
    ) -> None:
        arguments = {
            "source_revision": "383b14e389ac7ec4873c74034d6087ce9db0bea0",
            "run_timestamp": "2026-08-11T00:00:00Z",
            "architecture": "x86_64",
        }
        for binding in ("php", "python", "rust"):
            for cell_id in ("query-inspection", "mixed"):
                with self.subTest(binding=binding, cell_id=cell_id):
                    suite, observations = self.compliant_query_cell_observations(
                        cell_id, binding
                    )
                    result = capacity_suite.reduce_result(
                        suite,
                        capacity_suite.DEFAULT_SUITE,
                        self.profile,
                        capacity_suite.DEFAULT_PROFILE,
                        observations,
                        **arguments,
                    )
                    step = result["load_steps"][0]

                    self.assertTrue(result["publishable"])
                    self.assertTrue(step["operating_point_eligible"])
                    self.assertEqual(1.0, step["completion_ratio"])
                    self.assertEqual(
                        0.0 if cell_id == "query-inspection" else 4.0,
                        step["rates"]["workflow_completions_per_second"],
                    )
                    self.assertEqual(
                        capacity_suite.long_lived_query_target(
                            next(
                                cell for cell in suite["cells"] if cell["id"] == cell_id
                            )
                        ),
                        step["workflow_completion_evidence"][
                            "long_lived_query_workflows"
                        ]["measurement_minimum"],
                    )
                    self.assertGreater(step["drain"]["workflow_completions"], 0)
                    self.assertEqual(0, step["drain"]["final_open_workflows"])

    def test_drain_only_query_performance_cannot_qualify_query_or_mixed_cells(
        self,
    ) -> None:
        for cell_id in ("query-inspection", "mixed"):
            with self.subTest(cell_id=cell_id):
                suite, observations = self.compliant_query_cell_observations(
                    cell_id, "python"
                )
                for row in observations[:-1]:
                    row["demand"]["query_operations"] = {
                        "attempted": 0,
                        "accepted": 0,
                        "completed": 0,
                        "rejected": 0,
                        "throttled": 0,
                    }
                    row["latencies_ms"]["query"] = []
                drain = observations[-1]
                drain["demand"]["query_operations"] = {
                    "attempted": 80,
                    "accepted": 80,
                    "completed": 80,
                    "rejected": 0,
                    "throttled": 0,
                }
                drain["latencies_ms"]["query"] = [1.0] * 80

                result = capacity_suite.reduce_result(
                    suite,
                    capacity_suite.DEFAULT_SUITE,
                    self.profile,
                    capacity_suite.DEFAULT_PROFILE,
                    observations,
                    source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
                    run_timestamp="2026-08-11T00:00:00Z",
                    architecture="x86_64",
                )
                step = result["load_steps"][0]

                self.assertFalse(step["operating_point_eligible"])
                self.assertIn(
                    "query_completion_underdelivery",
                    step["saturation"]["violations"],
                )
                self.assertIn("missing_query_latency", step["saturation"]["violations"])
                self.assertEqual(
                    0, step["offered_load"]["query_operations"]["completed"]
                )

    def test_mixed_drain_completions_cannot_fill_the_measurement_denominator(
        self,
    ) -> None:
        suite, observations = self.compliant_query_cell_observations("mixed", "rust")
        completion_required = 0
        open_workflows = capacity_suite.long_lived_query_target(
            next(cell for cell in suite["cells"] if cell["id"] == "mixed")
        )
        for row in observations[:-1]:
            completion_required += row["counters"]["workflow_completions"]
            row["counters"]["workflow_completions"] = 0
            row["workflow_cohorts"]["completion_required"]["completions"] = 0
            row["demand"]["workflow_starts"]["completed"] = 0
            open_workflows += row["counters"]["workflow_starts"]
            row["concurrent_open_workflows"] = open_workflows
        drain = observations[-1]
        drain["counters"]["workflow_completions"] += completion_required
        drain["workflow_cohorts"]["completion_required"]["completions"] = (
            completion_required
        )
        drain["demand"]["workflow_starts"]["completed"] += completion_required

        result = capacity_suite.reduce_result(
            suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
        )
        step = result["load_steps"][0]

        self.assertEqual(0.0, step["completion_ratio"])
        self.assertEqual(
            0, step["workflow_completion_evidence"]["eligible_completions"]
        )
        self.assertIn("completion_ratio", step["saturation"]["violations"])
        self.assertFalse(step["operating_point_eligible"])

    def test_query_cohort_churn_cannot_replace_full_window_residency(self) -> None:
        suite, observations = self.compliant_query_cell_observations(
            "query-inspection", "php"
        )
        row = observations[1]
        row["counters"]["workflow_starts"] = 1
        row["counters"]["workflow_completions"] = 1
        row["workflow_cohorts"]["long_lived_query"] = {
            "starts": 1,
            "completions": 1,
        }
        row["demand"]["workflow_starts"] = {
            "attempted": 1,
            "accepted": 1,
            "completed": 1,
            "rejected": 0,
            "throttled": 0,
        }

        result = capacity_suite.reduce_result(
            suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
        )
        step = result["load_steps"][0]

        self.assertIn("long_lived_query_cohort_churn", step["saturation"]["violations"])
        self.assertFalse(step["operating_point_eligible"])

    def test_query_cohort_allocation_and_measurement_reset_are_deterministic(
        self,
    ) -> None:
        query_cell = next(
            cell for cell in self.suite["cells"] if cell["id"] == "query-inspection"
        )
        mixed_cell = next(cell for cell in self.suite["cells"] if cell["id"] == "mixed")
        self.assertEqual(500, capacity_suite.long_lived_query_target(query_cell))
        self.assertEqual(37, capacity_suite.long_lived_query_target(mixed_cell))

        runner = capacity_matrix.CellRunner.__new__(capacity_matrix.CellRunner)
        runner.cell = mixed_cell
        runner.random = capacity_matrix.random.Random(
            mixed_cell["execution"]["deterministic_seed"]
        )
        first_sequence = [runner._mixed_shape() for _ in range(1_000)]
        runner.random.seed(mixed_cell["execution"]["deterministic_seed"])
        self.assertEqual(first_sequence, [runner._mixed_shape() for _ in range(1_000)])
        self.assertNotIn("query-inspection", first_sequence)
        self.assertEqual(
            set(mixed_cell["workload"]["mix"]) - {"query-inspection"},
            set(first_sequence),
        )

        metrics = capacity_matrix.MetricBuffer()
        metrics.begin_measurement(10.0)
        metrics.workflow_attempted(recorded_at=9.0)
        metrics.started(long_lived_query=True, recorded_at=9.1)
        metrics.restart_measurement(20.0)
        _, _, demand, cohorts, open_workflows, query_workflows = metrics.snapshot(
            "measurement"
        )
        self.assertEqual(self.empty_demand(), demand)
        self.assertEqual(self.empty_workflow_cohorts(), cohorts)
        self.assertEqual(1, open_workflows)
        self.assertEqual(1, query_workflows)

        metrics.completed(
            {"activity_dispatches": 0, "schedule_to_start": [999.0], "replay": []},
            include_replay=False,
            long_lived_query=True,
            recorded_at=20.0,
        )
        _, latencies, _, cohorts, open_workflows, query_workflows = metrics.snapshot(
            "drain"
        )
        self.assertEqual({"starts": 0, "completions": 1}, cohorts["long_lived_query"])
        self.assertEqual([999.0], latencies["schedule_to_start"])
        self.assertEqual(0, open_workflows)
        self.assertEqual(0, query_workflows)

    def test_drain_performance_cannot_qualify_a_measurement_window(self) -> None:
        measurement = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        starts = sum(row["counters"]["workflow_starts"] for row in measurement)
        open_workflows = 0
        for row in measurement:
            open_workflows += row["counters"]["workflow_starts"]
            row["counters"]["workflow_completions"] = 0
            row["workflow_cohorts"]["completion_required"]["completions"] = 0
            row["demand"]["workflow_starts"]["completed"] = 0
            row["counters"]["activity_dispatches"] = 0
            row["latencies_ms"]["schedule_to_start"] = [10.0]
            row["concurrent_open_workflows"] = open_workflows

        drain = copy.deepcopy(measurement[-1])
        drain["sample_index"] = len(measurement)
        drain["phase"] = "drain"
        drain["interval_seconds"] = 1.0
        drain["counters"] = {
            "workflow_starts": 0,
            "workflow_completions": starts,
            "activity_dispatches": 1_000,
            "errors": 7,
            "throttles": 11,
        }
        drain["demand"] = self.empty_demand()
        drain["demand"]["workflow_starts"]["completed"] = starts
        drain["workflow_cohorts"] = self.empty_workflow_cohorts()
        drain["workflow_cohorts"]["completion_required"]["completions"] = starts
        drain["demand"]["query_operations"] = {
            "attempted": 1,
            "accepted": 1,
            "completed": 1,
            "rejected": 0,
            "throttled": 0,
        }
        drain["latencies_ms"] = {
            "schedule_to_start": [100_000.0],
            "replay": [100_000.0],
            "query": [100_000.0],
        }
        drain["concurrent_open_workflows"] = 0
        drain["infrastructure"]["queue_backlog"] = 0
        drain["infrastructure"]["durable_storage"]["used_bytes"] += 1_000_000
        drain["infrastructure"]["durable_storage"]["write_bytes"] += 1_000_000

        step = capacity_suite.reduce_step(
            [*measurement, drain],
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="simple-start-complete",
        )

        self.assertEqual(0.0, step["rates"]["workflow_completions_per_second"])
        self.assertEqual(0.0, step["rates"]["activity_dispatches_per_second"])
        self.assertEqual(0.0, step["completion_ratio"])
        self.assertEqual(0.0, step["error_rate"])
        self.assertEqual(0.0, step["throttle_rate"])
        self.assertEqual(10.0, step["latency_ms"]["schedule_to_start"]["p99"])
        self.assertLess(step["storage"]["growth_bytes"], 1_000_000)
        self.assertLess(step["storage"]["write_bytes"], 1_000_000)
        self.assertFalse(step["operating_point_eligible"])
        self.assertIn(
            "missing_measurement_completions", step["saturation"]["violations"]
        )

        capacity_run = copy.deepcopy(self.suite)
        cell = next(
            cell
            for cell in capacity_run["cells"]
            if cell["id"] == "simple-start-complete"
        )
        cell["execution"]["load_steps"] = [4]
        cell["execution"]["duration_seconds"] = 4
        control = {
            "suite_version": capacity_run["suite_version"],
            "deterministic_seed": cell["execution"]["deterministic_seed"],
            "concurrent_open_workflows": cell["execution"]["concurrent_open_workflows"],
            "client_concurrency": cell["execution"]["client_concurrency"],
            "worker_concurrency": cell["execution"]["worker_concurrency"],
            "warmup_seconds": cell["execution"]["warmup_seconds"],
            "duration_seconds": cell["execution"]["duration_seconds"],
            "offered_load": self.offered_load(capacity_run, cell, 4),
            "termination": cell["execution"]["termination"],
        }
        capacity_observations = copy.deepcopy([*measurement, drain])
        for row in capacity_observations:
            row["binding"] = "php"
            row["control"] = copy.deepcopy(control)
        result = capacity_suite.reduce_result(
            capacity_run,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            capacity_observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
        )

        self.assertFalse(result["publishable"])
        self.assertIsNone(result["maximum_sustained_operating_point"])
        self.assertEqual(
            starts, result["load_steps"][0]["drain"]["workflow_completions"]
        )
        self.assertTrue(result["load_steps"][0]["drain"]["converged"])

    def test_controller_records_late_callbacks_only_as_drain_evidence(self) -> None:
        metrics = capacity_matrix.MetricBuffer()
        metrics.begin_measurement(10.0)
        evidence = {
            "activity_dispatches": 2,
            "schedule_to_start": [5.0],
            "replay": [7.0],
        }

        with mock.patch.object(capacity_matrix.time, "monotonic", return_value=9.0):
            metrics.workflow_attempted()
            metrics.started()
            metrics.completed(evidence, include_replay=True)
            metrics.workflow_attempted()
            metrics.started()
            metrics.query_attempted()
            metrics.query_completed(11.0)
        with mock.patch.object(capacity_matrix.time, "monotonic", return_value=10.0):
            metrics.completed(evidence, include_replay=True)
            metrics.workflow_attempted()
            metrics.started()
            metrics.completed(evidence, include_replay=True)
            metrics.failed({"status": 429, "error": "throttled"})
            metrics.query_attempted()
            metrics.query_completed(999.0)
            metrics.workflow_attempted(recorded_at=9.5)
            metrics.started(recorded_at=9.5)
            metrics.completed(evidence, include_replay=True, recorded_at=9.5)

        (
            measurement_counters,
            measurement_latencies,
            measurement_demand,
            measurement_cohorts,
            measurement_open,
            measurement_query_open,
        ) = metrics.snapshot("measurement")
        (
            drain_counters,
            drain_latencies,
            drain_demand,
            drain_cohorts,
            drain_open,
            drain_query_open,
        ) = metrics.snapshot("drain")

        self.assertEqual(3, measurement_counters["workflow_starts"])
        self.assertEqual(2, measurement_counters["workflow_completions"])
        self.assertEqual(4, measurement_counters["activity_dispatches"])
        self.assertEqual([5.0, 5.0], measurement_latencies["schedule_to_start"])
        self.assertEqual([7.0, 7.0], measurement_latencies["replay"])
        self.assertEqual([11.0], measurement_latencies["query"])
        self.assertEqual(
            {
                "attempted": 3,
                "accepted": 3,
                "completed": 2,
                "rejected": 0,
                "throttled": 0,
            },
            measurement_demand["workflow_starts"],
        )
        self.assertEqual(1, measurement_demand["query_operations"]["completed"])
        self.assertEqual(1, measurement_open)
        self.assertEqual(
            {"starts": 3, "completions": 2},
            measurement_cohorts["completion_required"],
        )
        self.assertEqual(0, measurement_query_open)

        self.assertEqual(1, drain_counters["workflow_starts"])
        self.assertEqual(2, drain_counters["workflow_completions"])
        self.assertEqual(4, drain_counters["activity_dispatches"])
        self.assertEqual(1, drain_counters["throttles"])
        self.assertEqual([5.0, 5.0], drain_latencies["schedule_to_start"])
        self.assertEqual([7.0, 7.0], drain_latencies["replay"])
        self.assertEqual([999.0], drain_latencies["query"])
        self.assertEqual(1, drain_demand["query_operations"]["attempted"])
        self.assertEqual(1, drain_demand["query_operations"]["completed"])
        self.assertEqual(0, drain_open)
        self.assertEqual(
            {"starts": 1, "completions": 2},
            drain_cohorts["completion_required"],
        )
        self.assertEqual(0, drain_query_open)
        with self.assertRaisesRegex(
            capacity_matrix.MatrixError, "measurement phase has already begun"
        ):
            metrics.begin_measurement(11.0)

    def test_demand_accounting_separates_rejection_from_server_throttling(
        self,
    ) -> None:
        metrics = capacity_matrix.MetricBuffer()
        metrics.begin_measurement(10.0)

        metrics.workflow_attempted(recorded_at=9.0)
        metrics.workflow_rejected({"status": 429}, recorded_at=9.1)
        metrics.query_attempted(recorded_at=9.2)
        metrics.query_rejected({"error": "invalid query"}, recorded_at=9.3)

        counters, _, demand, _, _, _ = metrics.snapshot("measurement")

        self.assertEqual(1, counters["throttles"])
        self.assertEqual(1, counters["errors"])
        self.assertEqual(1, demand["workflow_starts"]["attempted"])
        self.assertEqual(1, demand["workflow_starts"]["throttled"])
        self.assertEqual(0, demand["workflow_starts"]["rejected"])
        self.assertEqual(1, demand["query_operations"]["attempted"])
        self.assertEqual(1, demand["query_operations"]["rejected"])
        self.assertEqual(0, demand["query_operations"]["throttled"])

    def test_forged_measurement_duration_and_unconverged_drain_fail_closed(
        self,
    ) -> None:
        measurement = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        measurement[-1]["interval_seconds"] += 1
        measurement[-1]["counters"]["workflow_completions"] -= 1
        measurement[-1]["workflow_cohorts"]["completion_required"]["completions"] -= 1
        measurement[-1]["demand"]["workflow_starts"]["completed"] -= 1
        measurement[-1]["concurrent_open_workflows"] = 1
        drain = copy.deepcopy(measurement[-1])
        drain["sample_index"] = len(measurement)
        drain["phase"] = "drain"
        drain["interval_seconds"] = 1.0
        drain["counters"] = {key: 0 for key in drain["counters"]}
        drain["workflow_cohorts"] = self.empty_workflow_cohorts()
        drain["demand"] = self.empty_demand()
        drain["latencies_ms"] = {key: [] for key in drain["latencies_ms"]}
        drain["concurrent_open_workflows"] = 1
        drain["infrastructure"]["queue_backlog"] = 1

        step = capacity_suite.reduce_step(
            [*measurement, drain],
            self.suite["operating_point_rule"],
            required_measurement_seconds=4,
            cell_id="simple-start-complete",
        )

        self.assertFalse(step["operating_point_eligible"])
        self.assertIn("complete_measurement_window", step["saturation"]["violations"])
        self.assertIn("open_workflows_not_drained", step["saturation"]["violations"])
        self.assertIn("queue_backlog_not_drained", step["saturation"]["violations"])

        contradictory_measurement = copy.deepcopy(measurement)
        contradictory_measurement[0]["concurrent_open_workflows"] += 1
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "measurement-phase open-work evidence"
        ):
            capacity_suite.reduce_step(
                contradictory_measurement,
                self.suite["operating_point_rule"],
                required_measurement_seconds=5,
                cell_id="simple-start-complete",
            )

        forged_drain = copy.deepcopy(drain)
        forged_drain["concurrent_open_workflows"] = 0
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "drain open-work evidence"
        ):
            capacity_suite.reduce_step(
                [*measurement, forged_drain],
                self.suite["operating_point_rule"],
                required_measurement_seconds=5,
                cell_id="simple-start-complete",
            )

        late_drain = copy.deepcopy(drain)
        late_drain["interval_seconds"] = (
            late_drain["control"]["termination"]["drain_timeout_seconds"] + 1
        )
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "declared drain timeout"
        ):
            capacity_suite.reduce_step(
                [*measurement, late_drain],
                self.suite["operating_point_rule"],
                required_measurement_seconds=5,
                cell_id="simple-start-complete",
            )

    def test_fractional_observation_counters_and_gauges_fail_closed(self) -> None:
        observation = copy.deepcopy(
            capacity_suite._reference_observations(self.suite, self.profile)[0]
        )
        component = next(iter(observation["infrastructure"]["components"]))
        adversarial_fields = (
            ("load_step",),
            ("sample_index",),
            ("counters", "workflow_starts"),
            ("counters", "workflow_completions"),
            ("counters", "activity_dispatches"),
            ("counters", "errors"),
            ("counters", "throttles"),
            ("workflow_cohorts", "completion_required", "starts"),
            ("workflow_cohorts", "completion_required", "completions"),
            ("workflow_cohorts", "long_lived_query", "starts"),
            ("workflow_cohorts", "long_lived_query", "completions"),
            ("demand", "workflow_starts", "attempted"),
            ("demand", "workflow_starts", "accepted"),
            ("demand", "workflow_starts", "completed"),
            ("demand", "workflow_starts", "rejected"),
            ("demand", "workflow_starts", "throttled"),
            ("demand", "query_operations", "attempted"),
            ("demand", "query_operations", "accepted"),
            ("demand", "query_operations", "completed"),
            ("demand", "query_operations", "rejected"),
            ("demand", "query_operations", "throttled"),
            ("concurrent_open_workflows",),
            ("concurrent_long_lived_query_workflows",),
            ("control", "offered_load", "long_lived_query_workflows"),
            ("infrastructure", "components", component, "assigned_memory_bytes"),
            ("infrastructure", "components", component, "consumed_memory_bytes"),
            ("infrastructure", "durable_storage", "used_bytes"),
            ("infrastructure", "durable_storage", "read_bytes"),
            ("infrastructure", "durable_storage", "write_bytes"),
            ("infrastructure", "durable_storage", "read_operations"),
            ("infrastructure", "durable_storage", "write_operations"),
            ("infrastructure", "database", "connections"),
            ("infrastructure", "database", "locks"),
            ("infrastructure", "database", "writes"),
            ("infrastructure", "redis", "memory_bytes"),
            ("infrastructure", "redis", "operations"),
            ("infrastructure", "queue_backlog"),
        )

        for field_path in adversarial_fields:
            with self.subTest(field=".".join(field_path)):
                forged = copy.deepcopy(observation)
                target = forged
                for field in field_path[:-1]:
                    target = target[field]
                target[field_path[-1]] = 1.5
                with self.assertRaisesRegex(
                    capacity_suite.ContractError, "must be an integer"
                ):
                    capacity_suite.validate_observation(forged, "forged")

        for field_path in (
            ("concurrent_open_workflows",),
            ("infrastructure", "queue_backlog"),
        ):
            with self.subTest(drain_field=".".join(field_path)):
                forged_drain = copy.deepcopy(observation)
                forged_drain["phase"] = "drain"
                target = forged_drain
                for field in field_path[:-1]:
                    target = target[field]
                target[field_path[-1]] = 0.9
                with self.assertRaisesRegex(
                    capacity_suite.ContractError, "must be an integer"
                ):
                    capacity_suite.validate_observation(forged_drain, "forged_drain")

    def test_underflowed_fractional_drain_gauges_fail_during_raw_loading(self) -> None:
        drain = copy.deepcopy(
            capacity_suite._reference_observations(self.suite, self.profile)[0]
        )
        drain["phase"] = "drain"
        drain["concurrent_open_workflows"] = 0
        drain["infrastructure"]["queue_backlog"] = 0
        encoded = json.dumps(drain, separators=(",", ":"))

        for field in ("concurrent_open_workflows", "queue_backlog"):
            with self.subTest(field=field), tempfile.TemporaryDirectory() as directory:
                forged = encoded.replace(f'"{field}":0', f'"{field}":1e-400')
                observation_path = Path(directory) / "observations.jsonl"
                observation_path.write_text(forged + "\n")

                with self.assertRaisesRegex(
                    capacity_suite.ContractError, "must be an integer"
                ):
                    capacity_suite.load_observations(observation_path)

    def test_publishable_mixed_result_fails_closed_without_replay_and_query_samples(
        self,
    ) -> None:
        mixed_suite = copy.deepcopy(self.suite)
        mixed_cell = next(
            cell for cell in mixed_suite["cells"] if cell["id"] == "mixed"
        )
        mixed_cell["execution"]["load_steps"] = [4]
        mixed_cell["execution"]["duration_seconds"] = 4
        query_cohort = capacity_suite.long_lived_query_target(mixed_cell)
        observations = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        for row in observations:
            row["cell_id"] = "mixed"
            row["binding"] = "php"
            row["control"] = {
                "suite_version": mixed_suite["suite_version"],
                "deterministic_seed": mixed_cell["execution"]["deterministic_seed"],
                "concurrent_open_workflows": mixed_cell["execution"][
                    "concurrent_open_workflows"
                ],
                "client_concurrency": mixed_cell["execution"]["client_concurrency"],
                "worker_concurrency": mixed_cell["execution"]["worker_concurrency"],
                "warmup_seconds": mixed_cell["execution"]["warmup_seconds"],
                "duration_seconds": mixed_cell["execution"]["duration_seconds"],
                "offered_load": self.offered_load(mixed_suite, mixed_cell, 4),
                "termination": mixed_cell["execution"]["termination"],
            }
            row["counters"]["activity_dispatches"] = 1
            row["concurrent_open_workflows"] += query_cohort
            row["concurrent_long_lived_query_workflows"] = query_cohort
            row["latencies_ms"]["replay"] = []
            row["latencies_ms"]["query"] = []
        drain = copy.deepcopy(observations[-1])
        drain["sample_index"] = len(observations)
        drain["phase"] = "drain"
        drain["interval_seconds"] = 0.001
        drain["counters"] = {
            "workflow_starts": 0,
            "workflow_completions": query_cohort,
            "activity_dispatches": 0,
            "errors": 0,
            "throttles": 0,
        }
        drain["workflow_cohorts"] = self.empty_workflow_cohorts()
        drain["workflow_cohorts"]["long_lived_query"]["completions"] = query_cohort
        drain["demand"] = self.empty_demand()
        drain["demand"]["workflow_starts"]["completed"] = query_cohort
        drain["latencies_ms"] = {"schedule_to_start": [], "replay": [], "query": []}
        drain["concurrent_open_workflows"] = 0
        drain["concurrent_long_lived_query_workflows"] = 0
        observations.append(drain)

        result = capacity_suite.reduce_result(
            mixed_suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
        )

        self.assertFalse(result["publishable"])
        self.assertIsNone(result["maximum_sustained_operating_point"])
        violations = result["load_steps"][0]["saturation"]["violations"]
        self.assertIn("missing_replay_latency", violations)
        self.assertIn("missing_query_latency", violations)
        self.assertFalse(result["load_steps"][0]["operating_point_eligible"])

    def test_publishable_observations_reject_forged_controls_and_missing_drain(
        self,
    ) -> None:
        suite = copy.deepcopy(self.suite)
        cell = next(
            cell for cell in suite["cells"] if cell["id"] == "simple-start-complete"
        )
        cell["execution"]["load_steps"] = [4]
        cell["execution"]["duration_seconds"] = 4
        rows = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        control = {
            "suite_version": suite["suite_version"],
            "deterministic_seed": cell["execution"]["deterministic_seed"],
            "concurrent_open_workflows": cell["execution"]["concurrent_open_workflows"],
            "client_concurrency": cell["execution"]["client_concurrency"],
            "worker_concurrency": cell["execution"]["worker_concurrency"],
            "warmup_seconds": cell["execution"]["warmup_seconds"],
            "duration_seconds": cell["execution"]["duration_seconds"],
            "offered_load": self.offered_load(suite, cell, 4),
            "termination": cell["execution"]["termination"],
        }
        for row in rows:
            row["binding"] = "python"
            row["control"] = copy.deepcopy(control)
            row["concurrent_open_workflows"] = 0
        arguments = {
            "source_revision": "383b14e389ac7ec4873c74034d6087ce9db0bea0",
            "run_timestamp": "2026-08-11T00:00:00Z",
            "architecture": "x86_64",
        }
        with self.assertRaisesRegex(capacity_suite.ContractError, "drain observation"):
            capacity_suite.reduce_result(
                suite,
                capacity_suite.DEFAULT_SUITE,
                self.profile,
                capacity_suite.DEFAULT_PROFILE,
                rows,
                **arguments,
            )

        drain = copy.deepcopy(rows[-1])
        drain["sample_index"] = len(rows)
        drain["phase"] = "drain"
        drain["interval_seconds"] = 0.001
        drain["counters"] = {key: 0 for key in drain["counters"]}
        drain["workflow_cohorts"] = self.empty_workflow_cohorts()
        drain["demand"] = self.empty_demand()
        drain["latencies_ms"] = {key: [] for key in drain["latencies_ms"]}
        drain["infrastructure"]["queue_backlog"] = 0
        rows.append(drain)
        result = capacity_suite.reduce_result(
            suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            rows,
            **arguments,
        )
        self.assertTrue(result["publishable"])
        self.assertEqual(4, result["maximum_sustained_operating_point"]["load_step"])

        rows[0]["control"]["worker_concurrency"] += 1
        with self.assertRaisesRegex(capacity_suite.ContractError, "declared execution"):
            capacity_suite.reduce_result(
                suite,
                capacity_suite.DEFAULT_SUITE,
                self.profile,
                capacity_suite.DEFAULT_PROFILE,
                rows,
                **arguments,
            )

    def test_mixed_metrics_cannot_mask_each_other(self) -> None:
        base = [
            copy.deepcopy(row)
            for row in capacity_suite._reference_observations(self.suite, self.profile)
            if row["load_step"] == 4
        ]
        for row in base:
            row["counters"]["activity_dispatches"] = 1

        adversarial_cases = (
            ("replay", "query", "missing_replay_latency"),
            ("query", "replay", "missing_query_latency"),
        )
        for absent, present, violation in adversarial_cases:
            with self.subTest(absent=absent):
                observations = copy.deepcopy(base)
                for row in observations:
                    row["latencies_ms"][absent] = []
                    row["latencies_ms"][present] = [10.0]
                    if present == "query":
                        row["demand"]["query_operations"] = {
                            "attempted": 1,
                            "accepted": 1,
                            "completed": 1,
                            "rejected": 0,
                            "throttled": 0,
                        }
                step = capacity_suite.reduce_step(
                    observations,
                    self.suite["operating_point_rule"],
                    required_measurement_seconds=4,
                    cell_id="mixed",
                )
                self.assertIn(violation, step["saturation"]["violations"])
                self.assertFalse(step["operating_point_eligible"])

    def test_incomplete_load_sweep_and_publishable_reference_fail_closed(self) -> None:
        observations = capacity_suite._reference_observations(self.suite, self.profile)
        incomplete = [row for row in observations if row["load_step"] != 12]
        arguments = {
            "source_revision": "383b14e389ac7ec4873c74034d6087ce9db0bea0",
            "run_timestamp": "2026-08-11T00:00:00Z",
            "architecture": "x86_64",
            "evidence_class": "harness_reference",
            "publishable": False,
        }
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "complete declared load-step sweep"
        ):
            capacity_suite.reduce_result(
                self.suite,
                capacity_suite.DEFAULT_SUITE,
                self.profile,
                capacity_suite.DEFAULT_PROFILE,
                incomplete,
                **arguments,
            )

        arguments["publishable"] = True
        with self.assertRaisesRegex(
            capacity_suite.ContractError, "cannot be publishable"
        ):
            capacity_suite.reduce_result(
                self.suite,
                capacity_suite.DEFAULT_SUITE,
                self.profile,
                capacity_suite.DEFAULT_PROFILE,
                observations,
                **arguments,
            )

    def test_unlike_result_identities_are_not_comparable(self) -> None:
        observations = capacity_suite._reference_observations(self.suite, self.profile)
        left = capacity_suite.reduce_result(
            self.suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
            evidence_class="harness_reference",
            publishable=False,
        )
        right = copy.deepcopy(left)
        right["identity"]["architecture"] = "aarch64"
        comparison = capacity_suite.compare_results(left, right)
        self.assertFalse(comparison["comparable"])
        self.assertEqual(
            {"left": "x86_64", "right": "aarch64"},
            comparison["identity_differences"]["architecture"],
        )

    def test_observation_reader_rejects_duplicate_keys(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "observations.jsonl"
            path.write_text('{"schema":"x","schema":"y"}\n')
            with self.assertRaisesRegex(
                capacity_suite.ContractError, "duplicate JSON key"
            ):
                capacity_suite.load_observations(path)

    def test_reference_result_round_trips_as_machine_readable_json(self) -> None:
        observations = capacity_suite._reference_observations(self.suite, self.profile)
        result = capacity_suite.reduce_result(
            self.suite,
            capacity_suite.DEFAULT_SUITE,
            self.profile,
            capacity_suite.DEFAULT_PROFILE,
            observations,
            source_revision="383b14e389ac7ec4873c74034d6087ce9db0bea0",
            run_timestamp="2026-08-11T00:00:00Z",
            architecture="x86_64",
            evidence_class="harness_reference",
            publishable=False,
        )
        encoded = json.dumps(result, sort_keys=True)
        self.assertEqual(result, json.loads(encoded))
        self.assertEqual(capacity_suite.RESULT_SCHEMA, result["schema"])


if __name__ == "__main__":
    unittest.main()
