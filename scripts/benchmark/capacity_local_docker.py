#!/usr/bin/env python3
"""Provision and run the fail-closed local Docker capacity topology."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import platform
import re
import shutil
import subprocess
import sys
import time
from typing import Any

import capacity_suite


ROOT = capacity_suite.ROOT
SUITE_ROOT = capacity_suite.SUITE_ROOT
TOPOLOGY_ROOT = SUITE_ROOT / "topologies/local-docker"
TOPOLOGY_PATH = TOPOLOGY_ROOT / "topology.json"
COMPOSE_PATH = TOPOLOGY_ROOT / "compose.json"
SMOKE_PATH = TOPOLOGY_ROOT / "smoke.json"
PROFILE_PATH = SUITE_ROOT / "profiles/local-docker-amd64.json"
COLLECTOR_PATH = SUITE_ROOT / "collectors/local-docker/collector.json"
COMPONENT_LABEL = "dev.durable-workflow.capacity.component"
PROFILE_LABEL = "dev.durable-workflow.capacity.profile"


class TopologyError(RuntimeError):
    """The host or executable topology differs from its profile identity."""


def _object(value: Any, name: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise TopologyError(f"{name} must be an object")
    return value


def _load(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text())
    except (OSError, json.JSONDecodeError) as exc:
        raise TopologyError(f"cannot load {path.relative_to(ROOT)}: {exc}") from exc
    return _object(value, str(path.relative_to(ROOT)))


def _run(
    command: list[str],
    *,
    environment: dict[str, str] | None = None,
    cwd: Path = ROOT,
    timeout: int = 900,
) -> str:
    try:
        completed = subprocess.run(
            command,
            check=True,
            capture_output=True,
            text=True,
            cwd=cwd,
            env=environment,
            timeout=timeout,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        stderr = getattr(exc, "stderr", "")
        detail = str(stderr).strip() if isinstance(stderr, str) else ""
        raise TopologyError(
            f"command failed: {' '.join(command[:5])}"
            + (f": {detail[-2000:]}" if detail else "")
        ) from exc
    return completed.stdout


def _service_environment(service: dict[str, Any], name: str) -> dict[str, str]:
    environment = _object(
        service.get("environment", {}), f"services.{name}.environment"
    )
    if not all(
        isinstance(key, str) and isinstance(value, str)
        for key, value in environment.items()
    ):
        raise TopologyError(f"services.{name}.environment must contain strings")
    return environment


def _mount_destinations(service: dict[str, Any], name: str) -> dict[str, str]:
    result: dict[str, str] = {}
    volumes = service.get("volumes", [])
    if not isinstance(volumes, list):
        raise TopologyError(f"services.{name}.volumes must be a list")
    for raw in volumes:
        if not isinstance(raw, str):
            raise TopologyError(f"services.{name}.volumes must use string mounts")
        fields = raw.split(":")
        if len(fields) < 2:
            raise TopologyError(f"services.{name} contains an invalid mount")
        result[fields[1]] = fields[0]
    return result


def validate_topology() -> dict[str, Any]:
    suite = _load(capacity_suite.DEFAULT_SUITE)
    profile = _load(PROFILE_PATH)
    topology = _load(TOPOLOGY_PATH)
    compose = _load(COMPOSE_PATH)
    smoke = _load(SMOKE_PATH)
    collector = _load(COLLECTOR_PATH)
    capacity_suite.validate_suite(suite)
    capacity_suite.validate_profile(profile)
    capacity_suite.validate_collector(collector, COLLECTOR_PATH, suite, profile)

    if topology.get("schema") != "durable-workflow.capacity-local-docker-topology/v1":
        raise TopologyError("topology schema identity is invalid")
    for field in ("suite_version", "profile_id"):
        expected = (
            suite["suite_version"]
            if field == "suite_version"
            else profile["profile_id"]
        )
        if topology.get(field) != expected:
            raise TopologyError(f"topology {field} differs from the suite profile")
    if compose.get("name") != topology.get("project_name"):
        raise TopologyError("Compose project name differs from the topology descriptor")

    components = _object(profile.get("components"), "profile.components")
    mapping = _object(topology.get("component_services"), "component_services")
    if set(mapping) != set(components) or len(set(mapping.values())) != len(mapping):
        raise TopologyError("topology must map every profile component exactly once")
    services = _object(compose.get("services"), "compose.services")
    network_name = str(topology.get("network_name") or "")
    for component, service_name in mapping.items():
        if not isinstance(service_name, str) or service_name not in services:
            raise TopologyError(f"Compose service is missing for {component}")
        service = _object(services[service_name], f"services.{service_name}")
        expected = _object(components[component], f"components.{component}")
        if service.get("image") != expected["image"]:
            raise TopologyError(f"{component} image differs from the profile")
        if service.get("platform") != profile["architecture"]["container"]:
            raise TopologyError(f"{component} platform differs from the profile")
        if float(service.get("cpus", 0)) != float(expected["cpu_cores"]):
            raise TopologyError(f"{component} CPU limit differs from the profile")
        if int(service.get("mem_limit", 0)) != int(expected["memory_bytes"]):
            raise TopologyError(f"{component} memory limit differs from the profile")
        if service.get("networks") != [network_name]:
            raise TopologyError(f"{component} must use only the capacity network")
        labels = _object(service.get("labels"), f"services.{service_name}.labels")
        if (
            labels.get(COMPONENT_LABEL) != component
            or labels.get(PROFILE_LABEL) != profile["profile_id"]
        ):
            raise TopologyError(f"{component} identity labels differ from the profile")
        if service.get("restart") != "no":
            raise TopologyError(f"{component} must use deterministic no-restart policy")

    networks = _object(compose.get("networks"), "compose.networks")
    actual_network = _object(networks.get(network_name), f"networks.{network_name}")
    expected_network = _object(profile.get("networking"), "profile.networking")
    for field in ("driver", "internal", "attachable"):
        if actual_network.get(field) != expected_network[field]:
            raise TopologyError(f"capacity network {field} differs from the profile")

    server = _object(profile.get("server"), "profile.server")
    server_environment = _object(
        server.get("environment"), "profile.server.environment"
    )
    process_classes = _object(
        server.get("process_classes"), "profile.server.process_classes"
    )
    for component in ("server", "server-worker", "scheduler"):
        service = _object(
            services[mapping[component]], f"services.{mapping[component]}"
        )
        expected_environment = {
            **server_environment,
            "DW_SERVER_PROCESS_CLASS": process_classes[component],
        }
        if _service_environment(service, mapping[component]) != expected_environment:
            raise TopologyError(
                f"{component} Server configuration differs from the profile"
            )
    if services[mapping["server-worker"]].get("command") != server["worker_command"]:
        raise TopologyError("server-worker command differs from the profile")
    if services[mapping["scheduler"]].get("command") != server["scheduler_command"]:
        raise TopologyError("scheduler command differs from the profile")
    bootstrap = _object(services.get("bootstrap"), "services.bootstrap")
    expected_bootstrap_environment = {
        **server_environment,
        "DW_SERVER_PROCESS_CLASS": process_classes["server"],
    }
    if (
        bootstrap.get("image") != components["server"]["image"]
        or bootstrap.get("command") != ["server-bootstrap"]
        or _service_environment(bootstrap, "bootstrap")
        != expected_bootstrap_environment
    ):
        raise TopologyError("Server bootstrap configuration differs from the profile")

    database = _object(profile.get("database"), "profile.database")
    mysql_command = services[mapping["mysql"]].get("command")
    expected_mysql_options = {
        f"--{name.replace('_', '-')}={value}"
        for name, value in database["parameters"].items()
    }
    if not isinstance(mysql_command, list) or not expected_mysql_options.issubset(
        set(mysql_command)
    ):
        raise TopologyError("MySQL command does not apply the profile configuration")
    redis = _object(profile.get("redis"), "profile.redis")
    redis_command = services[mapping["redis"]].get("command")
    if not isinstance(redis_command, list):
        raise TopologyError("Redis command must be an argument list")
    redis_pairs = dict(zip(redis_command[1::2], redis_command[2::2]))
    for name, value in redis["parameters"].items():
        if redis_pairs.get(f"--{name}") != value:
            raise TopologyError(f"Redis parameter {name} differs from the profile")

    storage = _object(profile.get("durable_storage"), "profile.durable_storage")
    mysql_mounts = _mount_destinations(services[mapping["mysql"]], mapping["mysql"])
    redis_mounts = _mount_destinations(services[mapping["redis"]], mapping["redis"])
    if mysql_mounts.get(storage["database_destination"]) != "mysql-data":
        raise TopologyError("MySQL durable volume differs from the profile")
    if redis_mounts.get(storage["redis_destination"]) != "redis-data":
        raise TopologyError("Redis durable volume differs from the profile")
    volumes = _object(compose.get("volumes"), "compose.volumes")
    for volume, role in (("mysql-data", "mysql"), ("redis-data", "redis")):
        definition = _object(volumes.get(volume), f"volumes.{volume}")
        labels = _object(definition.get("labels"), f"volumes.{volume}.labels")
        if definition.get("driver") != "local" or labels != {
            "dev.durable-workflow.capacity.storage": role,
            PROFILE_LABEL: profile["profile_id"],
        }:
            raise TopologyError(
                f"{volume} does not carry the standard storage identity"
            )

    installation_images = _object(
        topology.get("installation_images"), "installation_images"
    )
    if services.get("composer-installer", {}).get("image") != installation_images.get(
        "composer"
    ):
        raise TopologyError("Composer installation image differs from the topology")
    if services.get("docker-cli-installer", {}).get("image") != installation_images.get(
        "docker_cli"
    ):
        raise TopologyError("Docker CLI installation image differs from the topology")
    adapters = _object(topology.get("adapter_services"), "adapter_services")
    if set(adapters) != capacity_suite.REQUIRED_BINDINGS:
        raise TopologyError("topology must install every first-party adapter")
    if {adapters[name] for name in adapters} != {
        mapping["sdk-php-worker"],
        mapping["sdk-python-worker"],
        mapping["sdk-rust-worker"],
    }:
        raise TopologyError("adapter services differ from the SDK component inventory")

    if smoke != {
        "schema": "durable-workflow.capacity-local-docker-smoke/v1",
        "suite_version": suite["suite_version"],
        "profile_id": profile["profile_id"],
        "cell_id": "simple-start-complete",
        "binding": "python",
        "load_step": 1,
        "sample_interval_seconds": 1,
        "execution": {
            "concurrent_open_workflows": 2,
            "client_concurrency": 1,
            "worker_concurrency": 2,
            "duration_seconds": 5,
            "warmup_seconds": 2,
            "deterministic_seed": 104729,
            "termination": {
                "condition": "duration_elapsed_then_open_workflows_drained",
                "drain_timeout_seconds": 30,
            },
        },
        "evidence_class": "local_topology_smoke",
        "publishable": False,
        "teardown": "always",
    }:
        raise TopologyError("bounded local smoke contract differs from the standard")
    return {
        "suite": suite,
        "profile": profile,
        "topology": topology,
        "compose": compose,
        "collector": collector,
    }


def _docker_environment() -> dict[str, str]:
    environment = os.environ.copy()
    output_root = ROOT / "build/capacity-v1"
    output_root.mkdir(parents=True, exist_ok=True)
    environment.update(
        {
            "CAPACITY_REPOSITORY_ROOT": str(ROOT.resolve()),
            "CAPACITY_OUTPUT_ROOT": str(output_root.resolve()),
        }
    )
    return environment


def _default_compose_project_name(topology: dict[str, Any]) -> str:
    github_scope = tuple(
        os.environ.get(variable, "").strip()
        for variable in ("GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT", "GITHUB_JOB")
    )
    if any(github_scope) and not all(github_scope):
        raise TopologyError(
            "GitHub Compose scope requires run id, run attempt, and job identity"
        )
    if all(github_scope):
        return f"{topology['project_name']}-{'-'.join(github_scope)}"
    return str(topology["project_name"])


def _validate_compose_project_name(project_name: str) -> None:
    if re.fullmatch(r"[a-z0-9][a-z0-9_-]*", project_name) is None:
        raise TopologyError(
            "CAPACITY_DOCKER_PROJECT must be a lowercase Compose project name"
        )


def _compose_project_name(topology: dict[str, Any]) -> str:
    project_name = os.environ.get(
        "CAPACITY_DOCKER_PROJECT", ""
    ).strip() or _default_compose_project_name(topology)
    _validate_compose_project_name(project_name)
    return project_name


def _compose_command(topology: dict[str, Any], *arguments: str) -> list[str]:
    # Keep the environment lookup at this boundary so static workflow policy can
    # prove that every Compose invocation uses the job-scoped project identity.
    fallback = _default_compose_project_name(topology)
    project = os.environ.get("CAPACITY_DOCKER_PROJECT", "").strip() or fallback
    _validate_compose_project_name(project)
    return [
        "docker",
        "compose",
        "--project-name",
        project,
        "--file",
        str(COMPOSE_PATH),
        *arguments,
    ]


def _version_tuple(value: str) -> tuple[int, ...]:
    match = re.match(r"^(\d+(?:\.\d+)+)", value.strip())
    if match is None:
        raise TopologyError(f"cannot parse version {value!r}")
    return tuple(int(part) for part in match.group(1).split("."))


def verify_host(profile: dict[str, Any]) -> None:
    if shutil.which("docker") is None:
        raise TopologyError("Docker CLI is required")
    runtime = _object(profile.get("runtime"), "profile.runtime")
    server = json.loads(
        _run(["docker", "version", "--format", "{{json .Server}}"], timeout=30)
    )
    info = json.loads(_run(["docker", "info", "--format", "{{json .}}"], timeout=30))
    expected_engine = str(runtime["container_engine"]).removeprefix("docker-")
    components = {
        str(component.get("Name") or ""): str(component.get("Version") or "")
        for component in server.get("Components", [])
        if isinstance(component, dict)
    }
    facts = {
        "version": str(server.get("Version") or ""),
        "containerd_version": components.get("containerd", ""),
        "runc_version": components.get("runc", ""),
        "docker_init_version": components.get("docker-init", ""),
        "operating_system": str(server.get("Os") or "").lower(),
        "architecture": capacity_suite.normalize_architecture(
            str(server.get("Arch") or "")
        ),
        "default_runtime": str(info.get("DefaultRuntime") or ""),
        "cgroup_version": str(info.get("CgroupVersion") or ""),
        "cgroup_driver": str(info.get("CgroupDriver") or ""),
        "storage_driver": str(info.get("Driver") or ""),
        "kernel": str(info.get("KernelVersion") or platform.release()),
    }
    expected = {
        "version": expected_engine,
        "containerd_version": str(runtime["containerd_version"]),
        "runc_version": str(runtime["runc_version"]),
        "docker_init_version": str(runtime["docker_init_version"]),
        "operating_system": str(runtime["operating_system"]),
        "architecture": capacity_suite.normalize_architecture(
            profile["architecture"]["machine"]
        ),
        "default_runtime": str(runtime["default_runtime"]),
        "cgroup_version": str(runtime["cgroup_version"]),
        "cgroup_driver": str(runtime["cgroup_driver"]),
        "storage_driver": str(runtime["storage_driver"]),
    }
    for name, value in expected.items():
        if facts[name] != value:
            raise TopologyError(f"Docker {name} {facts[name]!r} differs from {value!r}")
    kernel_match = re.fullmatch(
        r"linux-(\d+\.\d+)-cgroup-v2", str(runtime["kernel_profile"])
    )
    if kernel_match is None or not facts["kernel"].startswith(
        kernel_match.group(1) + "."
    ):
        raise TopologyError(
            f"kernel {facts['kernel']!r} differs from {runtime['kernel_profile']!r}"
        )
    compose_version = _run(
        ["docker", "compose", "version", "--short"], timeout=30
    ).strip()
    if _version_tuple(compose_version) < (2, 20, 0):
        raise TopologyError("Docker Compose 2.20.0 or newer is required")

    docker_root = str(info.get("DockerRootDir") or "").strip()
    if not docker_root:
        raise TopologyError("Docker did not report its root directory")
    mount = _run(
        [
            "findmnt",
            "--json",
            "--output",
            "SOURCE,FSTYPE,OPTIONS",
            "--target",
            docker_root,
        ],
        timeout=30,
    )
    filesystems = json.loads(mount).get("filesystems", [])
    if not isinstance(filesystems, list) or len(filesystems) != 1:
        raise TopologyError("cannot resolve Docker durable-storage filesystem")
    filesystem = _object(filesystems[0], "Docker durable-storage filesystem")
    if filesystem.get("fstype") != "ext4":
        raise TopologyError("Docker durable storage must use ext4")
    mount_options = {
        value.strip()
        for value in str(filesystem.get("options") or "").split(",")
        if value.strip()
    }
    if "rw" not in mount_options or mount_options.intersection(
        {"ro", "sync", "dirsync", "noatime", "nodiratime", "discard"}
    ):
        raise TopologyError("Docker durable storage differs from ext4 defaults")
    source = str(filesystem.get("source") or "")
    block_path = source.split("[", 1)[0]
    ancestry = _run(
        ["lsblk", "--json", "--inverse", "--output", "NAME,ROTA,TRAN,TYPE", block_path],
        timeout=30,
    )
    blockdevices = json.loads(ancestry).get("blockdevices", [])

    def flatten(rows: list[Any]) -> list[dict[str, Any]]:
        flattened: list[dict[str, Any]] = []
        for row in rows:
            if not isinstance(row, dict):
                continue
            flattened.append(row)
            children = row.get("children", [])
            if isinstance(children, list):
                flattened.extend(flatten(children))
        return flattened

    physical = [row for row in flatten(blockdevices) if row.get("type") == "disk"]
    if not physical or any(
        bool(row.get("rota")) or str(row.get("tran") or "") != "nvme"
        for row in physical
    ):
        raise TopologyError("Docker durable storage must resolve to non-rotating NVMe")
    capacity = int(profile["durable_storage"]["capacity_bytes"])
    if shutil.disk_usage(docker_root).total < capacity:
        raise TopologyError(f"Docker durable storage is smaller than {capacity} bytes")


def _compose(
    topology: dict[str, Any],
    *arguments: str,
    timeout: int = 900,
) -> str:
    return _run(
        _compose_command(topology, *arguments),
        environment=_docker_environment(),
        timeout=timeout,
    )


def _up(context: dict[str, Any]) -> None:
    verify_host(context["profile"])
    topology = context["topology"]
    _compose(topology, "pull", "--quiet", timeout=1800)
    try:
        _compose(
            topology,
            "up",
            "--detach",
            timeout=1200,
        )
        _wait_for_topology(context)
    except (TopologyError, json.JSONDecodeError):
        try:
            _down(topology)
        except TopologyError:
            pass
        raise


def _wait_for_topology(context: dict[str, Any]) -> None:
    topology = context["topology"]
    persistent = set(topology["component_services"].values())
    completed = {"bootstrap", "composer-installer", "docker-cli-installer"}
    deadline = time.monotonic() + 900
    last: dict[str, str] = {}
    while time.monotonic() < deadline:
        ready = True
        for service in sorted(persistent | completed):
            try:
                container = _container_id(topology, service)
            except TopologyError:
                ready = False
                last[service] = "not-created"
                continue
            values = json.loads(_run(["docker", "inspect", container], timeout=30))
            if (
                not isinstance(values, list)
                or len(values) != 1
                or not isinstance(values[0], dict)
            ):
                raise TopologyError(f"cannot inspect Compose service {service}")
            state = _object(values[0].get("State"), f"services.{service}.state")
            status = str(state.get("Status") or "")
            if service in completed:
                exit_code = int(state.get("ExitCode", -1))
                if status == "exited" and exit_code == 0:
                    last[service] = "completed"
                    continue
                if status == "exited":
                    raise TopologyError(
                        f"Compose service {service} exited with status {exit_code}"
                    )
                ready = False
                last[service] = status or "pending"
                continue
            health = state.get("Health")
            health_status = (
                str(health.get("Status") or "") if isinstance(health, dict) else ""
            )
            if status == "running" and health_status == "healthy":
                last[service] = "healthy"
                continue
            if status == "exited" or health_status == "unhealthy":
                raise TopologyError(
                    f"Compose service {service} failed readiness "
                    f"(state={status}, health={health_status})"
                )
            ready = False
            last[service] = f"{status}/{health_status}".strip("/")
        if ready:
            return
        time.sleep(1)
    pending = sorted(
        name for name, status in last.items() if status not in {"healthy", "completed"}
    )
    raise TopologyError(f"capacity topology readiness timed out: {pending}")


def _down(topology: dict[str, Any]) -> None:
    _compose(
        topology,
        "down",
        "--volumes",
        "--remove-orphans",
        "--timeout",
        "30",
        timeout=180,
    )


def _container_id(topology: dict[str, Any], service: str) -> str:
    lines = [
        line.strip()
        for line in _compose(
            topology, "ps", "--all", "--quiet", service, timeout=30
        ).splitlines()
        if line.strip()
    ]
    if len(lines) != 1:
        raise TopologyError(
            f"expected one resolved container for {service}, got {len(lines)}"
        )
    return lines[0]


def _execution_environment(context: dict[str, Any]) -> dict[str, str]:
    topology = context["topology"]
    collector = context["collector"]
    mapping = topology["component_services"]
    project_name = _compose_project_name(topology)
    resolved = {
        component: _container_id(topology, service)
        for component, service in mapping.items()
    }
    environment = {
        "CAPACITY_DOCKER_PROJECT": project_name,
        "CAPACITY_DOCKER_NETWORK": f"{project_name}_{topology['network_name']}",
        "CAPACITY_MYSQL_USER": "root",
        "CAPACITY_MYSQL_PASSWORD": "capacity-root",
        "CAPACITY_REDIS_PASSWORD": "capacity-redis",
        "DURABLE_WORKFLOW_RUNTIME_URL": "http://server:8080",
        "DURABLE_WORKFLOW_NAMESPACE": "default",
    }
    for component, variable in collector["component_containers"].items():
        environment[str(variable)] = resolved[component]
    for binding, service in topology["adapter_services"].items():
        component = next(name for name, value in mapping.items() if value == service)
        environment[f"CAPACITY_ADAPTER_CONTAINER_{binding.upper()}"] = resolved[
            component
        ]
        environment[f"CAPACITY_ADAPTER_WORKDIR_{binding.upper()}"] = "/capacity"
    return environment


def _execute_matrix(
    context: dict[str, Any], command: str, arguments: list[str]
) -> None:
    topology = context["topology"]
    load_generator = _container_id(
        topology, topology["component_services"]["load-generator"]
    )
    environment = _execution_environment(context)
    source_revision = _run(["git", "rev-parse", "HEAD"], timeout=30).strip()
    if not re.fullmatch(r"[0-9a-f]{40}", source_revision):
        raise TopologyError("source revision must be a full Git commit")
    matrix_arguments = [command, *arguments]
    if "--source-revision" not in matrix_arguments:
        matrix_arguments.extend(["--source-revision", source_revision])
    docker_exec = ["docker", "exec", "--interactive", "--workdir", "/workspace"]
    for name, value in sorted(environment.items()):
        docker_exec.extend(["--env", f"{name}={value}"])
    docker_exec.extend(
        [
            load_generator,
            "python3",
            "scripts/benchmark/capacity_matrix.py",
            *matrix_arguments,
        ]
    )
    output = _run(docker_exec, environment=_docker_environment(), timeout=7200)
    print(output, end="")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    subparsers.add_parser("validate", help="validate versioned topology inputs")
    subparsers.add_parser("up", help="provision and verify the topology")
    subparsers.add_parser("down", help="remove containers, network, and volumes")
    subparsers.add_parser(
        "run", help="provision, run capacity selections, and tear down"
    )
    subparsers.add_parser("smoke", help="run the bounded non-publishable live smoke")
    args, remaining = parser.parse_known_args(argv)
    if args.command != "run" and remaining:
        parser.error(f"unrecognized arguments: {' '.join(remaining)}")
    args.matrix_arguments = remaining
    return args


def main(argv: list[str] | None = None) -> int:
    try:
        args = parse_args(argv)
        context = validate_topology()
        if args.command == "validate":
            print(
                f"capacity topology {context['profile']['profile_id']} is valid "
                f"({len(context['profile']['components'])} components)"
            )
            return 0
        if args.command == "down":
            _down(context["topology"])
            return 0
        if args.command == "up":
            _up(context)
            return 0
        started = False
        try:
            _up(context)
            started = True
            _execute_matrix(
                context,
                "smoke" if args.command == "smoke" else "run",
                [] if args.command == "smoke" else args.matrix_arguments,
            )
        finally:
            if started:
                _down(context["topology"])
        return 0
    except (capacity_suite.ContractError, TopologyError, json.JSONDecodeError) as exc:
        print(f"capacity topology error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
