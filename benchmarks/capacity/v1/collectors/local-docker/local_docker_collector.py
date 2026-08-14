#!/usr/bin/env python3
"""Fail-closed local Docker resource collector for capacity suite v1."""

from __future__ import annotations

import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Any
from urllib import error as urlerror
from urllib import parse as urlparse
from urllib import request as urlrequest


DESCRIPTOR_PATH = Path(__file__).with_name("collector.json")
BYTE_UNITS = {
    "B": 1,
    "KB": 1_000,
    "MB": 1_000_000,
    "GB": 1_000_000_000,
    "TB": 1_000_000_000_000,
    "KiB": 1_024,
    "MiB": 1_048_576,
    "GiB": 1_073_741_824,
    "TiB": 1_099_511_627_776,
}
CONTAINER_ID_PATTERN = re.compile(r"[0-9a-f]{12,64}")


class CollectorError(RuntimeError):
    """A resource sample cannot satisfy its declared profile identity."""


def descriptor() -> dict[str, Any]:
    value = json.loads(DESCRIPTOR_PATH.read_text())
    if not isinstance(value, dict):
        raise CollectorError("collector descriptor must contain an object")
    return value


def _object(value: Any, name: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise CollectorError(f"{name} must be an object")
    return value


def _run(command: list[str], *, environment: dict[str, str] | None = None) -> str:
    try:
        completed = subprocess.run(
            command,
            check=True,
            capture_output=True,
            text=True,
            env=environment,
            timeout=20,
        )
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        stderr = getattr(exc, "stderr", "")
        detail = str(stderr).strip() if isinstance(stderr, str) else ""
        raise CollectorError(
            f"collector command failed: {' '.join(command[:3])}"
            + (f": {detail}" if detail else "")
        ) from exc
    return completed.stdout


def _bytes(value: str) -> int:
    match = re.fullmatch(r"\s*([0-9]+(?:\.[0-9]+)?)\s*([A-Za-z]+)\s*", value)
    if match is None or match.group(2) not in BYTE_UNITS:
        raise CollectorError(f"unsupported Docker byte value: {value!r}")
    return int(float(match.group(1)) * BYTE_UNITS[match.group(2)])


def _percentage(value: str) -> float:
    try:
        parsed = float(value.strip().removesuffix("%")) / 100.0
    except ValueError as exc:
        raise CollectorError(f"invalid Docker percentage: {value!r}") from exc
    if not math.isfinite(parsed) or parsed < 0:
        raise CollectorError(f"invalid Docker percentage: {value!r}")
    return parsed


def _same_container_id(expected: str, actual: str) -> bool:
    """Match Docker's full IDs to the unambiguous short IDs from stats."""
    expected = expected.strip().lower()
    actual = actual.strip().lower()
    if (
        CONTAINER_ID_PATTERN.fullmatch(expected) is None
        or CONTAINER_ID_PATTERN.fullmatch(actual) is None
    ):
        return False
    return expected.startswith(actual) or actual.startswith(expected)


class LocalDockerCollector:
    def __init__(self, profile: dict[str, Any], runtime_url: str, namespace: str):
        self.profile = profile
        self.runtime_url = runtime_url.rstrip("/")
        self.namespace = namespace
        self.containers = self._container_names()
        self.inspections: dict[str, dict[str, Any]] = {}
        self._verify_runtime()
        self._verify_profile()
        self._verify_storage()
        self._verify_database_configuration()
        self._verify_redis_configuration()

    def _container_names(self) -> dict[str, str]:
        mapping = _object(
            descriptor().get("component_containers"), "component_containers"
        )
        expected = _object(self.profile.get("components"), "profile.components")
        if set(mapping) != set(expected):
            raise CollectorError(
                "collector component inventory differs from the profile"
            )
        resolved: dict[str, str] = {}
        for component, environment_name in mapping.items():
            if not isinstance(environment_name, str):
                raise CollectorError(
                    f"container environment for {component} is invalid"
                )
            value = os.environ.get(environment_name, "").strip()
            if not value:
                raise CollectorError(
                    f"set {environment_name} for profile component {component}"
                )
            resolved[component] = value
        return resolved

    def _inspect(self, container: str) -> dict[str, Any]:
        values = json.loads(_run(["docker", "inspect", container]))
        if (
            not isinstance(values, list)
            or len(values) != 1
            or not isinstance(values[0], dict)
        ):
            raise CollectorError(
                f"docker inspect returned an invalid record for {container}"
            )
        return values[0]

    @staticmethod
    def _json_command(command: list[str], name: str) -> dict[str, Any]:
        try:
            value = json.loads(_run(command))
        except json.JSONDecodeError as exc:
            raise CollectorError(f"{name} emitted invalid JSON") from exc
        if not isinstance(value, dict):
            raise CollectorError(f"{name} must emit an object")
        return value

    @staticmethod
    def _normalized_architecture(value: Any) -> str:
        normalized = str(value or "").strip().lower()
        aliases = {"amd64": "x86_64", "x64": "x86_64"}
        return aliases.get(normalized, normalized)

    def _verify_runtime(self) -> None:
        server = self._json_command(
            ["docker", "version", "--format", "{{json .Server}}"],
            "Docker server version",
        )
        info = self._json_command(
            ["docker", "info", "--format", "{{json .}}"], "Docker server info"
        )
        runtime = _object(self.profile.get("runtime"), "profile.runtime")
        expected_version = str(runtime["container_engine"]).removeprefix("docker-")
        components = {
            str(component.get("Name") or ""): str(component.get("Version") or "")
            for component in server.get("Components", [])
            if isinstance(component, dict)
        }
        actual = {
            "container_engine": str(server.get("Version") or ""),
            "containerd_version": components.get("containerd", ""),
            "runc_version": components.get("runc", ""),
            "docker_init_version": components.get("docker-init", ""),
            "operating_system": str(server.get("Os") or "").lower(),
            "architecture": self._normalized_architecture(server.get("Arch")),
            "default_runtime": str(info.get("DefaultRuntime") or ""),
            "cgroup_version": str(info.get("CgroupVersion") or ""),
            "cgroup_driver": str(info.get("CgroupDriver") or ""),
            "storage_driver": str(info.get("Driver") or ""),
        }
        expected = {
            "container_engine": expected_version,
            "containerd_version": str(runtime["containerd_version"]),
            "runc_version": str(runtime["runc_version"]),
            "docker_init_version": str(runtime["docker_init_version"]),
            "operating_system": str(runtime["operating_system"]),
            "architecture": self._normalized_architecture(
                self.profile["architecture"]["machine"]
            ),
            "default_runtime": str(runtime["default_runtime"]),
            "cgroup_version": str(runtime["cgroup_version"]),
            "cgroup_driver": str(runtime["cgroup_driver"]),
            "storage_driver": str(runtime["storage_driver"]),
        }
        for name, value in expected.items():
            if actual[name] != value:
                raise CollectorError(
                    f"runtime {name} {actual[name]!r} differs from {value!r}"
                )
        kernel_match = re.fullmatch(
            r"linux-(\d+\.\d+)-cgroup-v2", str(runtime["kernel_profile"])
        )
        kernel = str(info.get("KernelVersion") or "")
        if kernel_match is None or not kernel.startswith(kernel_match.group(1) + "."):
            raise CollectorError(
                f"runtime kernel {kernel!r} differs from {runtime['kernel_profile']!r}"
            )

    @staticmethod
    def _assigned_cpu(host: dict[str, Any]) -> float:
        nano = int(host.get("NanoCpus") or 0)
        if nano > 0:
            return nano / 1_000_000_000
        quota = int(host.get("CpuQuota") or 0)
        period = int(host.get("CpuPeriod") or 0)
        return quota / period if quota > 0 and period > 0 else 0.0

    def _verify_profile(self) -> None:
        expected_project = os.environ.get("CAPACITY_DOCKER_PROJECT", "").strip()
        expected_network = os.environ.get("CAPACITY_DOCKER_NETWORK", "").strip()
        if not expected_project or not expected_network:
            raise CollectorError(
                "set CAPACITY_DOCKER_PROJECT and CAPACITY_DOCKER_NETWORK"
            )
        expected_profile = str(self.profile["profile_id"])
        network_values = json.loads(
            _run(["docker", "network", "inspect", expected_network])
        )
        if (
            not isinstance(network_values, list)
            or len(network_values) != 1
            or not isinstance(network_values[0], dict)
        ):
            raise CollectorError("cannot inspect the capacity network")
        network = network_values[0]
        network_labels = _object(network.get("Labels"), "capacity network labels")
        expected_network_policy = _object(
            self.profile.get("networking"), "profile.networking"
        )
        if (
            network.get("Driver") != expected_network_policy["driver"]
            or network.get("Internal") != expected_network_policy["internal"]
            or network.get("Attachable") != expected_network_policy["attachable"]
            or network_labels.get("com.docker.compose.project") != expected_project
            or network_labels.get("dev.durable-workflow.capacity.profile")
            != expected_profile
        ):
            raise CollectorError("capacity network policy differs from the profile")
        image_architectures: dict[str, str] = {}
        for component, expected in self.profile["components"].items():
            inspected = self._inspect(self.containers[component])
            self.inspections[component] = inspected
            config = _object(inspected.get("Config"), f"inspect[{component}].Config")
            host = _object(
                inspected.get("HostConfig"), f"inspect[{component}].HostConfig"
            )
            state = _object(inspected.get("State"), f"inspect[{component}].State")
            if state.get("Running") is not True:
                raise CollectorError(f"{component} is not running")
            health = _object(state.get("Health"), f"inspect[{component}].Health")
            if health.get("Status") != "healthy":
                raise CollectorError(f"{component} did not pass its health contract")
            if config.get("Image") != expected["image"]:
                raise CollectorError(
                    f"{component} image {config.get('Image')!r} differs from {expected['image']!r}"
                )
            assigned_cpu = self._assigned_cpu(host)
            assigned_memory = int(host.get("Memory") or 0)
            if assigned_cpu != float(expected["cpu_cores"]):
                raise CollectorError(f"{component} CPU limit differs from the profile")
            if assigned_memory != int(expected["memory_bytes"]):
                raise CollectorError(
                    f"{component} memory limit differs from the profile"
                )
            restart = _object(
                host.get("RestartPolicy"), f"inspect[{component}].RestartPolicy"
            )
            if restart.get("Name") not in {"", "no"}:
                raise CollectorError(
                    f"{component} restart policy differs from deterministic cleanup"
                )
            labels = _object(config.get("Labels"), f"inspect[{component}].Labels")
            if (
                labels.get("dev.durable-workflow.capacity.component") != component
                or labels.get("dev.durable-workflow.capacity.profile")
                != expected_profile
                or labels.get("com.docker.compose.project") != expected_project
            ):
                raise CollectorError(f"{component} identity labels differ")
            networking = _object(
                inspected.get("NetworkSettings"),
                f"inspect[{component}].NetworkSettings",
            )
            networks = _object(
                networking.get("Networks"), f"inspect[{component}].Networks"
            )
            if set(networks) != {expected_network}:
                raise CollectorError(f"{component} network differs from the profile")
            image_id = str(inspected.get("Image") or "")
            if image_id not in image_architectures:
                image_values = json.loads(
                    _run(["docker", "image", "inspect", image_id])
                )
                if (
                    not isinstance(image_values, list)
                    or len(image_values) != 1
                    or not isinstance(image_values[0], dict)
                ):
                    raise CollectorError(f"cannot inspect the image for {component}")
                image_architectures[image_id] = self._normalized_architecture(
                    image_values[0].get("Architecture")
                )
                if str(image_values[0].get("Os") or "") != "linux":
                    raise CollectorError(f"{component} image is not Linux")
            if image_architectures[image_id] != self._normalized_architecture(
                self.profile["architecture"]["machine"]
            ):
                raise CollectorError(f"{component} image architecture differs")
        self._verify_server_configuration()

    @staticmethod
    def _environment_map(config: dict[str, Any], component: str) -> dict[str, str]:
        raw = config.get("Env")
        if not isinstance(raw, list):
            raise CollectorError(f"{component} environment is invalid")
        result: dict[str, str] = {}
        for item in raw:
            if not isinstance(item, str) or "=" not in item:
                raise CollectorError(
                    f"{component} environment contains an invalid entry"
                )
            name, value = item.split("=", 1)
            result[name] = value
        return result

    def _verify_server_configuration(self) -> None:
        server = _object(self.profile.get("server"), "profile.server")
        expected_environment = _object(
            server.get("environment"), "profile.server.environment"
        )
        process_classes = _object(
            server.get("process_classes"), "profile.server.process_classes"
        )
        for component in ("server", "server-worker", "scheduler"):
            config = _object(
                self.inspections[component].get("Config"),
                f"inspect[{component}].Config",
            )
            environment = self._environment_map(config, component)
            expected = {
                **expected_environment,
                "DW_SERVER_PROCESS_CLASS": process_classes[component],
            }
            drift = {
                name: (environment.get(name), value)
                for name, value in expected.items()
                if environment.get(name) != value
            }
            if drift:
                raise CollectorError(
                    f"{component} Server configuration differs: {sorted(drift)}"
                )
        if (
            self.inspections["server-worker"]["Config"].get("Cmd")
            != server["worker_command"]
        ):
            raise CollectorError("server-worker command differs from the profile")
        if (
            self.inspections["scheduler"]["Config"].get("Cmd")
            != server["scheduler_command"]
        ):
            raise CollectorError("scheduler command differs from the profile")

    def _volume_at(self, component: str, destination: str) -> dict[str, Any]:
        mounts = self.inspections[component].get("Mounts")
        if not isinstance(mounts, list):
            raise CollectorError(f"{component} mounts are invalid")
        matches = [
            mount
            for mount in mounts
            if isinstance(mount, dict) and mount.get("Destination") == destination
        ]
        if len(matches) != 1:
            raise CollectorError(f"{component} must have one volume at {destination}")
        mount = matches[0]
        if mount.get("Type") != "volume" or mount.get("RW") is not True:
            raise CollectorError(
                f"{component} durable storage must be a writable Docker volume"
            )
        return mount

    def _verify_storage(self) -> None:
        storage = _object(
            self.profile.get("durable_storage"), "profile.durable_storage"
        )
        expected_profile = str(self.profile["profile_id"])
        for component, destination, role in (
            ("mysql", str(storage["database_destination"]), "mysql"),
            ("redis", str(storage["redis_destination"]), "redis"),
        ):
            mount = self._volume_at(component, destination)
            volume_values = json.loads(
                _run(["docker", "volume", "inspect", str(mount.get("Name") or "")])
            )
            if (
                not isinstance(volume_values, list)
                or len(volume_values) != 1
                or not isinstance(volume_values[0], dict)
            ):
                raise CollectorError(f"cannot inspect {component} durable volume")
            volume = volume_values[0]
            labels = _object(volume.get("Labels"), f"volume[{component}].Labels")
            if (
                volume.get("Driver") != "local"
                or labels.get("dev.durable-workflow.capacity.storage") != role
                or labels.get("dev.durable-workflow.capacity.profile")
                != expected_profile
            ):
                raise CollectorError(f"{component} durable volume identity differs")
        filesystem = _run(
            [
                "docker",
                "exec",
                "-i",
                self.containers["mysql"],
                "stat",
                "-f",
                "-c",
                "%T",
                str(storage["database_destination"]),
            ]
        ).strip()
        if filesystem not in {"ext2/ext3", "ext4"}:
            raise CollectorError(
                f"MySQL durable filesystem {filesystem!r} differs from ext4"
            )
        size_output = _run(
            [
                "docker",
                "exec",
                "-i",
                self.containers["mysql"],
                "df",
                "--block-size=1",
                "--output=size",
                str(storage["database_destination"]),
            ]
        )
        try:
            size = int(size_output.splitlines()[-1].strip())
        except (IndexError, ValueError) as exc:
            raise CollectorError("cannot parse MySQL durable-storage capacity") from exc
        if size < int(storage["capacity_bytes"]):
            raise CollectorError("MySQL durable-storage capacity is below the profile")

    def _mysql_query(self, query: str) -> list[list[str]]:
        user = os.environ.get("CAPACITY_MYSQL_USER", "root").strip() or "root"
        password = os.environ.get("CAPACITY_MYSQL_PASSWORD", "")
        environment = os.environ.copy()
        command = ["docker", "exec", "-i"]
        if password:
            environment["MYSQL_PWD"] = password
            command.extend(["-e", "MYSQL_PWD"])
        command.extend(
            [
                self.containers["mysql"],
                "mysql",
                "--batch",
                "--skip-column-names",
                "-u",
                user,
                "-e",
                query,
            ]
        )
        return [
            line.split("\t")
            for line in _run(command, environment=environment).splitlines()
        ]

    def _verify_database_configuration(self) -> None:
        database = _object(self.profile.get("database"), "profile.database")
        parameters = _object(database.get("parameters"), "profile.database.parameters")
        names = list(parameters)
        rows = self._mysql_query(
            "SELECT VERSION(), " + ", ".join(f"@@{name}" for name in names)
        )
        if len(rows) != 1 or len(rows[0]) != len(names) + 1:
            raise CollectorError("MySQL identity query returned an invalid record")
        if rows[0][0] != database["version"]:
            raise CollectorError("MySQL version differs from the profile")
        actual = dict(zip(names, rows[0][1:]))
        if actual != parameters:
            raise CollectorError(
                f"MySQL configuration differs from the profile: {sorted(name for name in parameters if actual.get(name) != parameters[name])}"
            )

    def _redis_command(self, *arguments: str) -> list[str]:
        password = os.environ.get("CAPACITY_REDIS_PASSWORD", "")
        environment = os.environ.copy()
        command = ["docker", "exec", "-i"]
        if password:
            environment["REDISCLI_AUTH"] = password
            command.extend(["-e", "REDISCLI_AUTH"])
        command.extend([self.containers["redis"], "redis-cli", "--raw", *arguments])
        return _run(command, environment=environment).splitlines()

    def _verify_redis_configuration(self) -> None:
        redis = _object(self.profile.get("redis"), "profile.redis")
        version = ""
        for line in self._redis_command("INFO", "server"):
            if line.startswith("redis_version:"):
                version = line.partition(":")[2].strip()
        if version != redis["version"]:
            raise CollectorError("Redis version differs from the profile")
        parameters = _object(redis.get("parameters"), "profile.redis.parameters")
        lines = self._redis_command("CONFIG", "GET", *parameters.keys())
        if len(lines) % 2:
            raise CollectorError("Redis configuration query returned an invalid record")
        actual = dict(zip(lines[0::2], lines[1::2]))
        if actual != parameters:
            raise CollectorError(
                f"Redis configuration differs from the profile: {sorted(name for name in parameters if actual.get(name) != parameters[name])}"
            )

    def _docker_stats(self) -> dict[str, dict[str, Any]]:
        output = _run(
            [
                "docker",
                "stats",
                "--no-stream",
                "--format",
                "{{json .}}",
                *self.containers.values(),
            ]
        )
        rows: list[tuple[str, dict[str, Any]]] = []
        for line in output.splitlines():
            if not line.strip():
                continue
            value = json.loads(line)
            if not isinstance(value, dict):
                raise CollectorError("docker stats emitted a non-object record")
            identity = str(value.get("ID") or "")
            if CONTAINER_ID_PATTERN.fullmatch(identity.strip().lower()) is None:
                raise CollectorError("docker stats omitted a valid container ID")
            rows.append((identity, value))
        result: dict[str, dict[str, Any]] = {}
        for component, container in self.containers.items():
            matches = [
                value
                for identity, value in rows
                if _same_container_id(container, identity)
            ]
            if not matches:
                raise CollectorError(f"docker stats omitted {component} ({container})")
            if len(matches) != 1:
                raise CollectorError(
                    f"docker stats returned an ambiguous ID for {component} ({container})"
                )
            sample = matches[0]
            memory_usage = str(sample.get("MemUsage") or "").split("/", 1)[0]
            assigned = self.profile["components"][component]
            result[component] = {
                "assigned_cpu_cores": float(assigned["cpu_cores"]),
                # Docker reports 100% for one fully consumed CPU and can
                # exceed 100% for multi-core containers.
                "consumed_cpu_cores": round(
                    _percentage(str(sample.get("CPUPerc") or "")), 6
                ),
                "assigned_memory_bytes": int(assigned["memory_bytes"]),
                "consumed_memory_bytes": _bytes(memory_usage),
            }
        return result

    def _mysql_status(self) -> dict[str, int]:
        values: dict[str, int] = {}
        for fields in self._mysql_query(
            "SHOW GLOBAL STATUS WHERE Variable_name IN "
            "('Threads_connected','Innodb_row_lock_current_waits',"
            "'Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted',"
            "'Innodb_data_read','Innodb_data_written','Innodb_data_reads','Innodb_data_writes')"
        ):
            if len(fields) == 2:
                values[fields[0]] = int(fields[1])
        required = {
            "Threads_connected",
            "Innodb_row_lock_current_waits",
            "Innodb_rows_inserted",
            "Innodb_rows_updated",
            "Innodb_rows_deleted",
            "Innodb_data_read",
            "Innodb_data_written",
            "Innodb_data_reads",
            "Innodb_data_writes",
        }
        if set(values) != required:
            raise CollectorError(
                f"MySQL status omitted {sorted(required - set(values))}"
            )
        return values

    def _mysql_used_bytes(self) -> int:
        output = _run(
            [
                "docker",
                "exec",
                "-i",
                self.containers["mysql"],
                "du",
                "-sb",
                "/var/lib/mysql",
            ]
        )
        try:
            return int(output.split()[0])
        except (IndexError, ValueError) as exc:
            raise CollectorError("cannot parse MySQL durable-storage usage") from exc

    def _redis_info(self) -> dict[str, int]:
        values: dict[str, int] = {}
        for line in self._redis_command("INFO"):
            key, separator, raw = line.partition(":")
            if separator and key in {"used_memory", "total_commands_processed"}:
                values[key] = int(raw.strip())
        if set(values) != {"used_memory", "total_commands_processed"}:
            raise CollectorError("Redis INFO omitted memory or operation counters")
        return values

    def _queue_backlog(self, task_queue: str) -> int:
        path = "/api/task-queues/" + urlparse.quote(task_queue, safe="")
        headers = {
            "Accept": "application/json",
            "X-Namespace": self.namespace,
            "X-Durable-Workflow-Control-Plane-Version": "2",
        }
        token = (
            os.environ.get("DURABLE_WORKFLOW_TOKEN", "").strip()
            or os.environ.get("DURABLE_WORKFLOW_CLIENT_TOKEN", "").strip()
        )
        if token:
            headers["Authorization"] = f"Bearer {token}"
        try:
            with urlrequest.urlopen(
                urlrequest.Request(self.runtime_url + path, headers=headers), timeout=10
            ) as response:
                value = json.loads(response.read())
        except (OSError, urlerror.URLError, json.JSONDecodeError) as exc:
            raise CollectorError("task-queue visibility collection failed") from exc
        try:
            return int(value["stats"]["approximate_backlog_count"])
        except (KeyError, TypeError, ValueError) as exc:
            raise CollectorError(
                "task-queue visibility omitted approximate backlog"
            ) from exc

    def sample(self, task_queue: str) -> dict[str, Any]:
        mysql = self._mysql_status()
        redis = self._redis_info()
        return {
            "components": self._docker_stats(),
            "durable_storage": {
                "used_bytes": self._mysql_used_bytes(),
                "read_bytes": mysql["Innodb_data_read"],
                "write_bytes": mysql["Innodb_data_written"],
                "read_operations": mysql["Innodb_data_reads"],
                "write_operations": mysql["Innodb_data_writes"],
            },
            "database": {
                "connections": mysql["Threads_connected"],
                "locks": mysql["Innodb_row_lock_current_waits"],
                "writes": mysql["Innodb_rows_inserted"]
                + mysql["Innodb_rows_updated"]
                + mysql["Innodb_rows_deleted"],
            },
            "redis": {
                "memory_bytes": redis["used_memory"],
                "operations": redis["total_commands_processed"],
            },
            "queue_backlog": self._queue_backlog(task_queue),
        }


def response_ok(operation: str, result: Any) -> dict[str, Any]:
    return {"ok": True, "operation": operation, "result": result}


def run_protocol() -> None:
    collector: LocalDockerCollector | None = None
    for line in sys.stdin:
        try:
            command = json.loads(line)
            if not isinstance(command, dict):
                raise CollectorError("collector command must be an object")
            operation = command.get("operation")
            if operation == "initialize":
                profile = _object(command.get("profile"), "initialize.profile")
                if profile.get("profile_id") != descriptor()["profile_id"]:
                    raise CollectorError("collector profile identity does not match")
                runtime_url = str(command.get("runtime_url") or "").strip()
                namespace = str(command.get("namespace") or "").strip()
                if not runtime_url or not namespace:
                    raise CollectorError(
                        "initialize requires runtime_url and namespace"
                    )
                collector = LocalDockerCollector(profile, runtime_url, namespace)
                response = response_ok(operation, {"profile_id": profile["profile_id"]})
            elif operation == "sample":
                if collector is None:
                    raise CollectorError("initialize must succeed before sampling")
                task_queue = str(command.get("task_queue") or "").strip()
                if not task_queue:
                    raise CollectorError("sample requires task_queue")
                response = response_ok(operation, collector.sample(task_queue))
            else:
                raise CollectorError(f"unsupported collector operation: {operation!r}")
        except Exception as exc:  # JSONL boundary must return a typed failure.
            response = {
                "ok": False,
                "error_type": type(exc).__name__,
                "error": str(exc),
            }
        print(json.dumps(response, separators=(",", ":"), sort_keys=True), flush=True)


def main() -> None:
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    if mode == "describe":
        print(json.dumps(descriptor(), separators=(",", ":"), sort_keys=True))
    elif mode == "sample":
        run_protocol()
    else:
        raise SystemExit("usage: local_docker_collector.py describe|sample")


if __name__ == "__main__":
    main()
