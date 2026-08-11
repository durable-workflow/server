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


class LocalDockerCollector:
    def __init__(self, profile: dict[str, Any], runtime_url: str, namespace: str):
        self.profile = profile
        self.runtime_url = runtime_url.rstrip("/")
        self.namespace = namespace
        self.containers = self._container_names()
        self._verify_profile()

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
    def _assigned_cpu(host: dict[str, Any]) -> float:
        nano = int(host.get("NanoCpus") or 0)
        if nano > 0:
            return nano / 1_000_000_000
        quota = int(host.get("CpuQuota") or 0)
        period = int(host.get("CpuPeriod") or 0)
        return quota / period if quota > 0 and period > 0 else 0.0

    def _verify_profile(self) -> None:
        for component, expected in self.profile["components"].items():
            inspected = self._inspect(self.containers[component])
            config = _object(inspected.get("Config"), f"inspect[{component}].Config")
            host = _object(
                inspected.get("HostConfig"), f"inspect[{component}].HostConfig"
            )
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
        by_container: dict[str, dict[str, Any]] = {}
        for line in output.splitlines():
            if not line.strip():
                continue
            value = json.loads(line)
            if not isinstance(value, dict):
                raise CollectorError("docker stats emitted a non-object record")
            identity = str(value.get("Name") or value.get("Container") or "")
            by_container[identity] = value
        result: dict[str, dict[str, Any]] = {}
        for component, container in self.containers.items():
            sample = by_container.get(container)
            if sample is None:
                matches = [
                    value
                    for name, value in by_container.items()
                    if name.startswith(container) or container.startswith(name)
                ]
                sample = matches[0] if len(matches) == 1 else None
            if sample is None:
                raise CollectorError(f"docker stats omitted {component} ({container})")
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
                "SHOW GLOBAL STATUS WHERE Variable_name IN "
                "('Threads_connected','Innodb_row_lock_current_waits',"
                "'Innodb_rows_inserted','Innodb_rows_updated','Innodb_rows_deleted',"
                "'Innodb_data_read','Innodb_data_written','Innodb_data_reads','Innodb_data_writes')",
            ]
        )
        values: dict[str, int] = {}
        for line in _run(command, environment=environment).splitlines():
            fields = line.split("\t")
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
        password = os.environ.get("CAPACITY_REDIS_PASSWORD", "")
        environment = os.environ.copy()
        command = ["docker", "exec", "-i"]
        if password:
            environment["REDISCLI_AUTH"] = password
            command.extend(["-e", "REDISCLI_AUTH"])
        command.extend([self.containers["redis"], "redis-cli", "--raw", "INFO"])
        values: dict[str, int] = {}
        for line in _run(command, environment=environment).splitlines():
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
