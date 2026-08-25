#!/usr/bin/env python3
"""Validate and reduce Durable Workflow capacity benchmark observations."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import platform
import re
import subprocess
import sys
import tomllib
import urllib.error
import urllib.request
from collections.abc import Iterable
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
SUITE_ROOT = ROOT / "benchmarks" / "capacity" / "v1"
DEFAULT_SUITE = SUITE_ROOT / "suite.json"
DEFAULT_PROFILE = SUITE_ROOT / "profiles" / "local-docker-amd64.json"
CORPUS = SUITE_ROOT / "regression-corpus.json"

SUITE_SCHEMA = "durable-workflow.capacity-benchmark-suite/v1"
PROFILE_SCHEMA = "durable-workflow.capacity-benchmark-infrastructure/v1"
OBSERVATION_SCHEMA = "durable-workflow.capacity-benchmark-observation/v1"
RESULT_SCHEMA = "durable-workflow.capacity-benchmark-result/v1"
CORPUS_SCHEMA = "durable-workflow.capacity-benchmark-regression-corpus/v1"
ADAPTER_SCHEMA = "durable-workflow.capacity-benchmark-adapter/v1"
COLLECTOR_SCHEMA = "durable-workflow.capacity-benchmark-collector/v1"
SUITE_VERSION = "1.4.0"

REQUIRED_CELL_IDS = {
    "simple-start-complete",
    "one-activity",
    "multiple-activities",
    "timer",
    "signal",
    "child-workflow-fanout",
    "replay-heavy-history",
    "query-inspection",
    "mixed",
}
REQUIRED_BINDINGS = {"php", "python", "rust"}
REQUIRED_METRICS = {
    "completion_eligible_workflows",
    "long_lived_query_workflows",
    "offered_load_delivery",
    "query_acceptances",
    "query_attempts",
    "query_completions",
    "query_rejections",
    "query_throttles",
    "workflow_starts_per_second",
    "workflow_start_acceptances",
    "workflow_start_attempts",
    "workflow_start_rejections",
    "workflow_start_throttles",
    "workflow_completions_per_second",
    "activity_dispatches_per_second",
    "schedule_to_start_latency_ms",
    "replay_latency_ms",
    "query_latency_ms",
    "concurrent_open_workflows",
    "error_rate",
    "throttle_rate",
    "storage_growth_bytes",
    "saturation",
}
REQUIRED_ARTIFACTS = {"server", "workflow_php", "sdk_php", "sdk_python", "sdk_rust"}
REQUIRED_SCHEMAS = {
    "suite": "schemas/suite.schema.json",
    "infrastructure": "schemas/infrastructure.schema.json",
    "observation": "schemas/observation.schema.json",
    "result": "schemas/result.schema.json",
    "adapter": "schemas/adapter.schema.json",
    "collector": "schemas/collector.schema.json",
}
SCHEMA_PUBLICATION = "schema-publication.json"
SCHEMA_PUBLICATION_CONTRACT = (
    "durable-workflow.capacity-benchmark-schema-publication/v1"
)
SCHEMA_PUBLICATION_URL = (
    "https://durable-workflow.github.io/schemas/capacity-benchmark/v1/manifest.json"
)
SCHEMA_ID_PREFIX = "https://durable-workflow.github.io/schemas/capacity-benchmark-"
ADAPTER_ARTIFACTS = {
    "php": "sdk_php",
    "python": "sdk_python",
    "rust": "sdk_rust",
}


class ContractError(ValueError):
    """Raised when benchmark evidence does not satisfy the public contract."""


def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ContractError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(), object_pairs_hook=_unique_object)
    except (OSError, json.JSONDecodeError) as exc:
        raise ContractError(f"cannot read JSON from {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise ContractError(f"{path} must contain a JSON object")
    return value


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _object(value: Any, path: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ContractError(f"{path} must be an object")
    return value


def _list(value: Any, path: str, *, nonempty: bool = False) -> list[Any]:
    if not isinstance(value, list) or (nonempty and not value):
        suffix = " and cannot be empty" if nonempty else ""
        raise ContractError(f"{path} must be an array{suffix}")
    return value


def _text(value: Any, path: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ContractError(f"{path} must be a non-empty string")
    return value


def _number(value: Any, path: str, *, minimum: float | None = None) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float, Decimal)):
        raise ContractError(f"{path} must be a finite number")
    if isinstance(value, Decimal):
        finite = value.is_finite()
    elif isinstance(value, int):
        finite = True
    else:
        finite = math.isfinite(value)
    try:
        number = float(value)
    except (OverflowError, ValueError):
        finite = False
        number = math.inf
    if not finite or not math.isfinite(number):
        raise ContractError(f"{path} must be a finite number")
    threshold = Decimal(str(minimum)) if isinstance(value, Decimal) else minimum
    if threshold is not None and value < threshold:
        raise ContractError(f"{path} must be at least {minimum}")
    return number


def _integer(value: Any, path: str, *, minimum: int | None = None) -> int:
    _number(value, path, minimum=minimum)
    if int(value) != value:
        raise ContractError(f"{path} must be an integer")
    return int(value)


def _exact_version(value: Any, path: str) -> str:
    version = _text(value, path)
    lowered = version.lower()
    if any(
        token in lowered
        for token in ("latest", "main", "master", "nightly", "*", ">", "<", "^", "~")
    ):
        raise ContractError(f"{path} must identify one exact artifact version")
    return version


def _validate_history_match(value: Any, path: str) -> dict[str, Any]:
    match = _object(value, path)
    if not match:
        raise ContractError(f"{path} cannot be empty")
    for field, expected in match.items():
        if field == "type_prefix":
            _text(expected, f"{path}.{field}")
        elif field == "type_in":
            choices = _list(expected, f"{path}.{field}", nonempty=True)
            for index, choice in enumerate(choices):
                _text(choice, f"{path}.{field}[{index}]")
        elif not isinstance(expected, (str, int, bool)) or isinstance(expected, bool):
            raise ContractError(f"{path}.{field} must be a string or integer selector")
    return match


def _validate_history_contract(history: dict[str, Any], path: str) -> None:
    _integer(history.get("target_event_count"), f"{path}.target_event_count", minimum=1)
    if history.get("count_scope") not in {"root", "workflow_tree"}:
        raise ContractError(f"{path}.count_scope is unsupported")
    evidence = _list(history.get("evidence"), f"{path}.evidence", nonempty=True)
    for index, raw_requirement in enumerate(evidence):
        requirement_path = f"{path}.evidence[{index}]"
        requirement = _object(raw_requirement, requirement_path)
        if requirement.get("scope", "root") not in {"root", "children"}:
            raise ContractError(f"{requirement_path}.scope is unsupported")
        if requirement.get("source") not in {
            "history_events",
            "tasks",
            "signals",
        }:
            raise ContractError(f"{requirement_path}.source is unsupported")
        _validate_history_match(requirement.get("match"), f"{requirement_path}.match")
        _integer(requirement.get("count"), f"{requirement_path}.count", minimum=0)
    for index, raw_requirement in enumerate(
        _list(history.get("forbidden_evidence", []), f"{path}.forbidden_evidence")
    ):
        requirement_path = f"{path}.forbidden_evidence[{index}]"
        requirement = _object(raw_requirement, requirement_path)
        if requirement.get("scope", "root") not in {"root", "children"}:
            raise ContractError(f"{requirement_path}.scope is unsupported")
        if requirement.get("source") not in {
            "history_events",
            "tasks",
            "signals",
        }:
            raise ContractError(f"{requirement_path}.source is unsupported")
        _validate_history_match(requirement.get("match"), f"{requirement_path}.match")
    raw_pattern = history.get("ordered_history_event_pattern")
    if raw_pattern is not None:
        pattern = _object(raw_pattern, f"{path}.ordered_history_event_pattern")
        if set(pattern) != {"prefix", "repeat", "repeat_count", "suffix"}:
            raise ContractError(
                f"{path}.ordered_history_event_pattern has an unsupported shape"
            )
        for field in ("prefix", "repeat", "suffix"):
            values = _list(
                pattern.get(field),
                f"{path}.ordered_history_event_pattern.{field}",
                nonempty=field != "suffix",
            )
            for index, value in enumerate(values):
                _text(
                    value,
                    f"{path}.ordered_history_event_pattern.{field}[{index}]",
                )
        _integer(
            pattern.get("repeat_count"),
            f"{path}.ordered_history_event_pattern.repeat_count",
            minimum=1,
        )


def workload_contract(
    suite: dict[str, Any], cell: dict[str, Any], shape: str
) -> dict[str, Any]:
    """Resolve the executable workload contract for one admitted workflow."""
    if cell.get("id") != "mixed":
        if shape != cell.get("id"):
            raise ContractError(
                f"cell {cell.get('id')} cannot execute undeclared shape {shape}"
            )
        return _object(cell.get("workload"), f"cell {cell.get('id')}.workload")
    mix = _object(cell.get("workload", {}).get("mix"), "mixed.workload.mix")
    if shape not in mix:
        raise ContractError(f"mixed workload selected undeclared shape {shape}")
    for component in _list(suite.get("cells"), "cells", nonempty=True):
        if isinstance(component, dict) and component.get("id") == shape:
            return _object(component.get("workload"), f"cell {shape}.workload")
    raise ContractError(f"mixed workload component is absent from the suite: {shape}")


def _history_record_matches(record: Any, match: dict[str, Any]) -> bool:
    if not isinstance(record, dict):
        return False
    for field, expected in match.items():
        if field == "type_prefix":
            actual = record.get("type") or record.get("event_type")
            if not isinstance(actual, str) or not actual.startswith(str(expected)):
                return False
        elif field == "type_in":
            actual = record.get("type") or record.get("event_type")
            if actual not in expected:
                return False
        else:
            actual = record.get(field)
            if field == "type" and actual is None:
                actual = record.get("event_type")
            if actual != expected:
                return False
    return True


def validate_history_evidence(
    history: dict[str, Any],
    root_bundle: dict[str, Any],
    child_bundles: list[dict[str, Any]] | None = None,
    *,
    path: str = "history",
) -> dict[str, Any]:
    """Fail closed unless a closed runtime export matches its typed declaration."""
    _validate_history_contract(history, path)
    children = child_bundles or []
    bundles = [root_bundle, *children]
    if any(bundle.get("history_complete") is not True for bundle in bundles):
        raise ContractError(f"{path} requires complete closed-run history exports")
    count_bundles = (
        bundles if history["count_scope"] == "workflow_tree" else [root_bundle]
    )
    actual_count = sum(
        len(_list(bundle.get("history_events"), f"{path}.history_events"))
        for bundle in count_bundles
    )
    target_count = int(history["target_event_count"])
    if actual_count != target_count:
        raise ContractError(
            f"{path} target event count drifted: expected {target_count}, observed {actual_count}"
        )

    def selected(requirement: dict[str, Any]) -> list[Any]:
        selected_bundles = (
            [root_bundle] if requirement.get("scope", "root") == "root" else children
        )
        records: list[Any] = []
        for bundle in selected_bundles:
            records.extend(
                _list(
                    bundle.get(str(requirement["source"])),
                    f"{path}.{requirement['source']}",
                )
            )
        return records

    for index, requirement in enumerate(history["evidence"]):
        matches = sum(
            _history_record_matches(record, requirement["match"])
            for record in selected(requirement)
        )
        if matches != int(requirement["count"]):
            raise ContractError(
                f"{path}.evidence[{index}] drifted: expected {requirement['count']}, observed {matches}"
            )
    for index, requirement in enumerate(history.get("forbidden_evidence", [])):
        if any(
            _history_record_matches(record, requirement["match"])
            for record in selected(requirement)
        ):
            raise ContractError(f"{path}.forbidden_evidence[{index}] was observed")

    pattern = history.get("ordered_history_event_pattern")
    if isinstance(pattern, dict):
        expected = [str(value) for value in pattern["prefix"]]
        expected.extend(
            str(value)
            for _ in range(int(pattern["repeat_count"]))
            for value in pattern["repeat"]
        )
        expected.extend(str(value) for value in pattern["suffix"])
        expected_types = set(expected)
        actual = [
            str(event.get("type") or event.get("event_type"))
            for event in _list(root_bundle.get("history_events"), "history_events")
            if isinstance(event, dict)
            and (event.get("type") or event.get("event_type")) in expected_types
        ]
        if actual != expected:
            raise ContractError(
                f"{path}.ordered_history_event_pattern drifted: expected {expected}, observed {actual}"
            )
    return {
        "target_event_count": target_count,
        "observed_event_count": actual_count,
        "child_run_count": len(children),
        "matches": True,
    }


def _source_revision(value: Any) -> str:
    revision = _text(value, "source_revision")
    if re.fullmatch(r"[0-9a-f]{40,64}", revision) is None:
        raise ContractError(
            "source_revision must be a full lowercase Git commit identity"
        )
    return revision


def normalize_architecture(value: str) -> str:
    aliases = {
        "amd64": "x86_64",
        "x64": "x86_64",
        "x86_64": "x86_64",
        "arm64": "aarch64",
        "aarch64": "aarch64",
    }
    normalized = aliases.get(value.strip().lower())
    if normalized is None:
        raise ContractError(f"unsupported architecture identity: {value}")
    return normalized


def _validate_artifacts(value: Any, path: str) -> dict[str, Any]:
    artifacts = _object(value, path)
    if set(artifacts) != REQUIRED_ARTIFACTS:
        raise ContractError(f"{path} must contain exactly {sorted(REQUIRED_ARTIFACTS)}")
    for name, raw_artifact in artifacts.items():
        artifact = _object(raw_artifact, f"{path}.{name}")
        _text(artifact.get("registry"), f"{path}.{name}.registry")
        _text(artifact.get("name"), f"{path}.{name}.name")
        version = _exact_version(artifact.get("version"), f"{path}.{name}.version")
        reference = _text(artifact.get("reference"), f"{path}.{name}.reference")
        if version not in reference:
            raise ContractError(
                f"{path}.{name}.reference must include its exact version"
            )
    return artifacts


def validate_profile(profile: dict[str, Any]) -> None:
    if profile.get("schema") != PROFILE_SCHEMA:
        raise ContractError(f"infrastructure schema must be {PROFILE_SCHEMA}")
    _text(profile.get("profile_id"), "infrastructure.profile_id")
    architecture = _object(profile.get("architecture"), "infrastructure.architecture")
    _text(architecture.get("machine"), "infrastructure.architecture.machine")
    _text(architecture.get("container"), "infrastructure.architecture.container")
    runtime = _object(profile.get("runtime"), "infrastructure.runtime")
    for field in (
        "kernel_profile",
        "container_engine",
        "containerd_version",
        "runc_version",
        "docker_init_version",
        "operating_system",
        "default_runtime",
        "cgroup_version",
        "cgroup_driver",
        "storage_driver",
        "scheduling_policy",
    ):
        _text(runtime.get(field), f"infrastructure.runtime.{field}")
    networking = _object(profile.get("networking"), "infrastructure.networking")
    if networking != {
        "driver": "bridge",
        "internal": False,
        "attachable": False,
        "service_networks": 1,
    }:
        raise ContractError(
            "infrastructure.networking must declare the standard bridge policy"
        )
    components = _object(profile.get("components"), "infrastructure.components")
    required_components = (
        "server",
        "server-worker",
        "scheduler",
        "mysql",
        "redis",
        "sdk-php-worker",
        "sdk-python-worker",
        "sdk-rust-worker",
        "load-generator",
    )
    if set(components) != set(required_components):
        raise ContractError(
            f"infrastructure.components must contain exactly {sorted(required_components)}"
        )
    for name in required_components:
        component = _object(components.get(name), f"infrastructure.components.{name}")
        _number(
            component.get("cpu_cores"),
            f"infrastructure.components.{name}.cpu_cores",
            minimum=0.001,
        )
        _integer(
            component.get("memory_bytes"),
            f"infrastructure.components.{name}.memory_bytes",
            minimum=1,
        )
        _text(component.get("image"), f"infrastructure.components.{name}.image")
    server = _object(profile.get("server"), "infrastructure.server")
    _text(server.get("configuration"), "infrastructure.server.configuration")
    server_environment = _object(
        server.get("environment"), "infrastructure.server.environment"
    )
    for name, value in server_environment.items():
        _text(name, "infrastructure.server.environment key")
        _text(value, f"infrastructure.server.environment.{name}")
    process_classes = _object(
        server.get("process_classes"), "infrastructure.server.process_classes"
    )
    if set(process_classes) != {"server", "server-worker", "scheduler"}:
        raise ContractError(
            "infrastructure.server.process_classes must cover every Server role"
        )
    for name, value in process_classes.items():
        _text(value, f"infrastructure.server.process_classes.{name}")
    for command_name in ("worker_command", "scheduler_command"):
        command = _list(
            server.get(command_name),
            f"infrastructure.server.{command_name}",
            nonempty=True,
        )
        for index, value in enumerate(command):
            _text(value, f"infrastructure.server.{command_name}[{index}]")
    storage = _object(profile.get("durable_storage"), "infrastructure.durable_storage")
    for field in (
        "driver",
        "medium",
        "mount_options",
        "capacity_policy",
        "database_destination",
        "redis_destination",
    ):
        _text(storage.get(field), f"infrastructure.durable_storage.{field}")
    _integer(
        storage.get("capacity_bytes"),
        "infrastructure.durable_storage.capacity_bytes",
        minimum=1,
    )
    if storage.get("capacity_policy") != "minimum":
        raise ContractError(
            "infrastructure.durable_storage.capacity_policy must be minimum"
        )
    for dependency in ("database", "redis"):
        service = _object(profile.get(dependency), f"infrastructure.{dependency}")
        _text(service.get("engine"), f"infrastructure.{dependency}.engine")
        _exact_version(service.get("version"), f"infrastructure.{dependency}.version")
        _text(
            service.get("configuration"), f"infrastructure.{dependency}.configuration"
        )
        parameters = _object(
            service.get("parameters"), f"infrastructure.{dependency}.parameters"
        )
        if not parameters:
            raise ContractError(
                f"infrastructure.{dependency}.parameters cannot be empty"
            )
        for name, value in parameters.items():
            _text(name, f"infrastructure.{dependency}.parameters key")
            if not isinstance(value, str):
                raise ContractError(
                    f"infrastructure.{dependency}.parameters.{name} must be a string"
                )


def _validate_schema_publication(suite_root: Path) -> None:
    publication = load_json(suite_root / SCHEMA_PUBLICATION)
    if set(publication) != {"schema", "suite_version", "canonical_url", "schemas"}:
        raise ContractError("schema publication manifest has an unsupported shape")
    if publication.get("schema") != SCHEMA_PUBLICATION_CONTRACT:
        raise ContractError(
            f"schema publication manifest must be {SCHEMA_PUBLICATION_CONTRACT}"
        )
    if publication.get("suite_version") != SUITE_VERSION:
        raise ContractError(f"schema publication suite_version must be {SUITE_VERSION}")
    if publication.get("canonical_url") != SCHEMA_PUBLICATION_URL:
        raise ContractError(
            f"schema publication canonical_url must be {SCHEMA_PUBLICATION_URL}"
        )

    schemas = _object(publication.get("schemas"), "schema publication schemas")
    expected_names = set(REQUIRED_SCHEMAS)
    source_names = {
        path.name.removesuffix(".schema.json")
        for path in (suite_root / "schemas").glob("*.schema.json")
    }
    if source_names != expected_names or set(schemas) != expected_names:
        raise ContractError(
            "schema publication must cover every capacity schema source exactly once"
        )

    for name, relative in REQUIRED_SCHEMAS.items():
        entry = _object(schemas.get(name), f"schema publication schemas.{name}")
        if set(entry) != {"$id", "sha256"}:
            raise ContractError(
                f"schema publication schemas.{name} has an unsupported shape"
            )
        schema_path = suite_root / relative
        schema = load_json(schema_path)
        expected_id = f"{SCHEMA_ID_PREFIX}{name}/v1.json"
        if schema.get("$id") != expected_id or entry.get("$id") != expected_id:
            raise ContractError(
                f"{relative} must use its canonical public schema identifier"
            )
        if entry.get("sha256") != sha256_file(schema_path):
            raise ContractError(
                f"schema publication digest for {name} does not match its v1 source"
            )


def _fetch_public_json(
    url: str, opener: Any | None = None
) -> tuple[bytes, str]:
    if opener is None:
        opener = urllib.request.urlopen
    request = urllib.request.Request(
        url,
        headers={
            "Cache-Control": "no-cache",
            "User-Agent": "durable-workflow-capacity-schema-qualification",
        },
    )
    try:
        with opener(request, timeout=15) as response:
            status = getattr(response, "status", None)
            final_url = response.geturl()
            content_type = response.headers.get("Content-Type", "")
            body = response.read()
    except urllib.error.HTTPError as exc:
        raise ContractError(
            f"public schema route {url} returned HTTP {exc.code}"
        ) from exc
    except OSError as exc:
        raise ContractError(
            f"cannot retrieve public schema route {url}: {exc}"
        ) from exc

    if status != 200:
        raise ContractError(f"public schema route {url} returned HTTP {status}")
    if not final_url.startswith("https://"):
        raise ContractError(f"public schema route {url} resolved outside HTTPS")
    media_type = content_type.partition(";")[0].strip().lower()
    if media_type != "application/json" and not (
        media_type.startswith("application/") and media_type.endswith("+json")
    ):
        raise ContractError(
            f"public schema route {url} returned non-JSON content type {content_type!r}"
        )
    try:
        json.loads(body)
    except json.JSONDecodeError as exc:
        raise ContractError(f"public schema route {url} returned invalid JSON") from exc
    return body, final_url


def verify_schema_publication(
    suite_root: Path = SUITE_ROOT, opener: Any | None = None
) -> None:
    _validate_schema_publication(suite_root)
    publication_path = suite_root / SCHEMA_PUBLICATION
    publication = load_json(publication_path)
    public_manifest, _ = _fetch_public_json(SCHEMA_PUBLICATION_URL, opener)
    try:
        live_publication = json.loads(public_manifest)
    except json.JSONDecodeError as exc:
        raise ContractError(
            "public schema publication manifest is invalid JSON"
        ) from exc
    if live_publication != publication:
        raise ContractError(
            "public schema publication manifest diverges from the v1 source inventory"
        )

    for name, entry in publication["schemas"].items():
        body, _ = _fetch_public_json(entry["$id"], opener)
        if hashlib.sha256(body).hexdigest() != entry["sha256"]:
            raise ContractError(
                f"public schema route for {name} diverges from its immutable v1 source"
            )
        document = json.loads(body)
        if document.get("$id") != entry["$id"]:
            raise ContractError(
                f"public schema route for {name} returned the wrong schema identifier"
            )


def validate_suite(suite: dict[str, Any], suite_path: Path = DEFAULT_SUITE) -> None:
    if suite.get("schema") != SUITE_SCHEMA:
        raise ContractError(f"suite schema must be {SUITE_SCHEMA}")
    if suite.get("suite_version") != SUITE_VERSION:
        raise ContractError(f"v1 suite path must contain suite_version {SUITE_VERSION}")
    if suite_path.parent.name != "v1":
        raise ContractError("suite.json must remain under its immutable v1 path")

    schemas = _object(suite.get("schemas"), "schemas")
    if schemas != REQUIRED_SCHEMAS:
        raise ContractError("suite schemas must name the complete versioned schema set")
    for relative in schemas.values():
        schema = load_json(suite_path.parent / relative)
        _text(schema.get("$id"), f"{relative}.$id")
        if schema.get("type") != "object":
            raise ContractError(f"{relative} must describe a JSON object")
    _validate_schema_publication(suite_path.parent)

    artifact_tuple = _validate_artifacts(suite.get("artifacts"), "artifacts")
    metrics = set(
        _list(suite.get("required_metrics"), "required_metrics", nonempty=True)
    )
    if metrics != REQUIRED_METRICS:
        raise ContractError(
            f"required_metrics must contain exactly {sorted(REQUIRED_METRICS)}"
        )

    rule = _object(suite.get("operating_point_rule"), "operating_point_rule")
    if (
        rule.get("selection")
        != "highest_load_step_meeting_every_limit_for_the_complete_measurement_window"
    ):
        raise ContractError("operating point selection must reject transient spikes")
    for field in (
        "minimum_offered_load_delivery_ratio",
        "minimum_completion_ratio",
        "maximum_error_rate",
        "maximum_throttle_rate",
        "maximum_schedule_to_start_p99_ms",
        "maximum_replay_p99_ms",
        "maximum_query_p99_ms",
        "maximum_cpu_utilization",
        "maximum_memory_utilization",
        "maximum_queue_backlog",
    ):
        _number(rule.get(field), f"operating_point_rule.{field}", minimum=0)
    if float(rule["minimum_offered_load_delivery_ratio"]) > 1:
        raise ContractError(
            "operating_point_rule.minimum_offered_load_delivery_ratio cannot exceed 1"
        )

    driver = _object(suite.get("driver_contract"), "driver_contract")
    if driver.get("protocol") != "newline_delimited_capacity_observation_v1":
        raise ContractError("driver contract must use capacity observation v1 JSONL")
    if (
        set(_list(driver.get("required_bindings"), "driver_contract.required_bindings"))
        != REQUIRED_BINDINGS
    ):
        raise ContractError("driver contract must run every first-party binding")
    if driver.get("matrix") != "each_cell_by_each_first_party_binding":
        raise ContractError(
            "driver contract must define the full cell-by-binding matrix"
        )
    if (
        driver.get("server_capacity_comparison")
        != "never_pool_bindings_or_unlike_result_identities"
    ):
        raise ContractError("driver contract must prevent pooled unlike results")
    if driver.get("payload_size_semantics") != {
        "unit": "utf8_encoded_bytes",
        "measured_value": "synthetic_string_value",
        "codec_envelope": "excluded",
        "argument_container": "excluded",
    }:
        raise ContractError(
            "driver contract must define the encoded workload payload boundary"
        )
    if driver.get("history_evidence_semantics") != {
        "target_event_count": "history_export_history_events_in_count_scope",
        "shape": "typed_history_export_records",
        "closed_run_required": True,
    }:
        raise ContractError(
            "driver contract must define the runtime history evidence boundary"
        )
    adapters = _object(driver.get("adapters"), "driver_contract.adapters")
    if adapters != {
        "schema": "schemas/adapter.schema.json",
        "descriptor_pattern": "bindings/{binding}/adapter.json",
        "modes": ["describe", "conformance", "worker", "client"],
        "client_protocol": "stdin_stdout_jsonl",
    }:
        raise ContractError(
            "driver contract must name the complete executable adapter surface"
        )
    controller = _object(driver.get("controller"), "driver_contract.controller")
    if controller != {
        "entrypoint": ["python3", "scripts/benchmark/capacity_matrix.py"],
        "source_files": ["scripts/benchmark/capacity_matrix.py"],
        "commands": ["dry-run", "run"],
    }:
        raise ContractError("driver contract must name the versioned matrix controller")
    for index, value in enumerate(controller["source_files"]):
        source = _safe_relative_path(
            value, f"driver_contract.controller.source_files[{index}]"
        )
        if not (ROOT / source).is_file():
            raise ContractError(f"matrix controller source does not exist: {source}")
    collector_contract = _object(driver.get("collector"), "driver_contract.collector")
    if collector_contract != {
        "schema": "schemas/collector.schema.json",
        "descriptor": "collectors/local-docker/collector.json",
        "protocol": "stdin_stdout_jsonl",
        "operations": ["initialize", "sample"],
    }:
        raise ContractError(
            "driver contract must name the executable resource collector"
        )
    topology_contract = _object(driver.get("topology"), "driver_contract.topology")
    if topology_contract != {
        "profile": "profiles/local-docker-amd64.json",
        "descriptor": "topologies/local-docker/topology.json",
        "compose": "topologies/local-docker/compose.json",
        "smoke": "topologies/local-docker/smoke.json",
        "launcher": ["python3", "scripts/benchmark/capacity_local_docker.py"],
        "commands": ["validate", "up", "run", "smoke", "down"],
    }:
        raise ContractError(
            "driver contract must name the executable local Docker topology"
        )
    for field in ("profile", "descriptor", "compose", "smoke"):
        source = _safe_relative_path(
            topology_contract[field], f"driver_contract.topology.{field}"
        )
        if not (suite_path.parent / source).is_file():
            raise ContractError(f"capacity topology source does not exist: {source}")
    launcher = topology_contract["launcher"]
    launcher_source = _safe_relative_path(
        launcher[-1], "driver_contract.topology.launcher[-1]"
    )
    if not (ROOT / launcher_source).is_file():
        raise ContractError(
            f"capacity topology launcher does not exist: {launcher_source}"
        )

    cells = _list(suite.get("cells"), "cells", nonempty=True)
    cell_ids: set[str] = set()
    bindings: set[str] = set()
    cells_by_id: dict[str, dict[str, Any]] = {}
    for index, raw_cell in enumerate(cells):
        path = f"cells[{index}]"
        cell = _object(raw_cell, path)
        cell_id = _text(cell.get("id"), f"{path}.id")
        if cell_id in cell_ids:
            raise ContractError(f"duplicate benchmark cell id: {cell_id}")
        cell_ids.add(cell_id)
        cells_by_id[cell_id] = cell
        if cell.get("artifacts") != artifact_tuple:
            raise ContractError(
                f"{path}.artifacts must repeat the suite's exact artifact tuple"
            )
        workload = _object(cell.get("workload"), f"{path}.workload")
        eligibility = _object(
            workload.get("measurement_eligibility"),
            f"{path}.workload.measurement_eligibility",
        )
        if (
            eligibility.get("completion_denominator")
            != "completion_required_workflows_started_during_measurement"
        ):
            raise ContractError(
                f"{path}.workload.measurement_eligibility must define the completion denominator"
            )
        query_cohort = _object(
            eligibility.get("long_lived_query_cohort"),
            f"{path}.workload.measurement_eligibility.long_lived_query_cohort",
        )
        query_cohort_shape = query_cohort.get("shape")
        query_cohort_share = _number(
            query_cohort.get("concurrent_open_workflow_share"),
            f"{path}.workload.measurement_eligibility.long_lived_query_cohort.concurrent_open_workflow_share",
            minimum=0,
        )
        if query_cohort_share > 1:
            raise ContractError(
                f"{path}.workload.measurement_eligibility long-lived query share cannot exceed 1"
            )
        declared_mix = (
            _object(workload.get("mix"), f"{path}.workload.mix")
            if cell_id == "mixed"
            else None
        )
        expected_query_share = (
            1.0
            if cell_id == "query-inspection"
            else _number(
                declared_mix.get("query-inspection") if declared_mix else None,
                f"{path}.workload.mix.query-inspection",
                minimum=0.001,
            )
            if cell_id == "mixed"
            else 0.0
        )
        expected_query_shape = "query-inspection" if expected_query_share else None
        if query_cohort_shape != expected_query_shape or not math.isclose(
            query_cohort_share,
            expected_query_share,
            rel_tol=0.0,
            abs_tol=1e-12,
        ):
            raise ContractError(
                f"{path}.workload.measurement_eligibility must match its declared query workload"
            )
        if cell_id == "mixed":
            assert declared_mix is not None
            mix = declared_mix
            expected_shapes = REQUIRED_CELL_IDS - {"mixed"}
            if set(mix) != expected_shapes:
                raise ContractError(
                    f"{path}.workload.mix must contain every component workload exactly once"
                )
            mix_total = sum(
                _number(weight, f"{path}.workload.mix.{shape}", minimum=0.001)
                for shape, weight in mix.items()
            )
            if not math.isclose(mix_total, 1.0, rel_tol=0.0, abs_tol=1e-12):
                raise ContractError(f"{path}.workload.mix weights must sum to one")
        elif "mix" in workload:
            raise ContractError(f"{path}.workload.mix is only valid for the mixed cell")
        workflow = _object(workload.get("workflow"), f"{path}.workload.workflow")
        _text(workflow.get("type"), f"{path}.workload.workflow.type")
        _list(workflow.get("steps"), f"{path}.workload.workflow.steps", nonempty=True)
        for definition_kind in ("activities", "signals", "queries"):
            definitions = _list(
                workload.get(definition_kind),
                f"{path}.workload.{definition_kind}",
            )
            definition_types = []
            for definition_index, raw_definition in enumerate(definitions):
                definition = _object(
                    raw_definition,
                    f"{path}.workload.{definition_kind}[{definition_index}]",
                )
                definition_types.append(
                    _text(
                        definition.get("type"),
                        f"{path}.workload.{definition_kind}[{definition_index}].type",
                    )
                )
                if definition_kind == "queries":
                    _number(
                        definition.get("rate_per_load_unit_per_second"),
                        f"{path}.workload.queries[{definition_index}].rate_per_load_unit_per_second",
                        minimum=0.001,
                    )
            if len(definition_types) != len(set(definition_types)):
                raise ContractError(
                    f"{path}.workload.{definition_kind} cannot repeat a type"
                )
        payload = _object(workload.get("payload"), f"{path}.workload.payload")
        if cell_id == "mixed":
            if payload != {
                "codec": "avro",
                "contract": "selected_component_workload",
            }:
                raise ContractError(
                    f"{path}.workload.payload must inherit the selected component workload"
                )
        else:
            if payload.get("codec") != "avro":
                raise ContractError(f"{path}.workload.payload.codec must be avro")
            for field in (
                "workflow_input_bytes",
                "workflow_result_bytes",
                "activity_input_bytes",
                "activity_result_bytes",
                "signal_bytes",
            ):
                _integer(
                    payload.get(field),
                    f"{path}.workload.payload.{field}",
                    minimum=0,
                )
        history = _object(workload.get("history"), f"{path}.workload.history")
        _list(history.get("shape"), f"{path}.workload.history.shape", nonempty=True)
        if cell_id == "mixed":
            if history.get("contract") != "selected_component_workload":
                raise ContractError(
                    f"{path}.workload.history must inherit the selected component workload"
                )
            if set(history) != {"contract", "shape"}:
                raise ContractError(
                    f"{path}.workload.history cannot declare an aggregate selected-shape target"
                )
        else:
            _validate_history_contract(history, f"{path}.workload.history")
        execution = _object(cell.get("execution"), f"{path}.execution")
        for field, minimum in (
            ("concurrent_open_workflows", 1),
            ("client_concurrency", 1),
            ("worker_concurrency", 1),
            ("duration_seconds", 1),
            ("warmup_seconds", 0),
            ("deterministic_seed", 0),
        ):
            _integer(execution.get(field), f"{path}.execution.{field}", minimum=minimum)
        load_steps = [
            _integer(value, f"{path}.execution.load_steps[{load_index}]", minimum=1)
            for load_index, value in enumerate(
                _list(
                    execution.get("load_steps"),
                    f"{path}.execution.load_steps",
                    nonempty=True,
                )
            )
        ]
        if load_steps != sorted(set(load_steps)):
            raise ContractError(
                f"{path}.execution.load_steps must be unique and strictly increasing"
            )
        termination = _object(
            execution.get("termination"), f"{path}.execution.termination"
        )
        if (
            termination.get("condition")
            != "duration_elapsed_then_open_workflows_drained"
        ):
            raise ContractError(
                f"{path}.execution.termination.condition is unsupported"
            )
        _integer(
            termination.get("drain_timeout_seconds"),
            f"{path}.execution.termination.drain_timeout_seconds",
            minimum=1,
        )
        cell_bindings = _list(cell.get("bindings"), f"{path}.bindings", nonempty=True)
        for binding_index, raw_binding in enumerate(cell_bindings):
            binding = _object(raw_binding, f"{path}.bindings[{binding_index}]")
            language = _text(
                binding.get("language"), f"{path}.bindings[{binding_index}].language"
            )
            if language not in REQUIRED_BINDINGS:
                raise ContractError(f"unsupported first-party binding: {language}")
            bindings.add(language)
            _list(
                binding.get("roles"),
                f"{path}.bindings[{binding_index}].roles",
                nonempty=True,
            )

    if cell_ids != REQUIRED_CELL_IDS:
        raise ContractError(
            f"suite cells must contain exactly {sorted(REQUIRED_CELL_IDS)}"
        )
    if bindings != REQUIRED_BINDINGS:
        raise ContractError(
            f"suite must exercise first-party bindings {sorted(REQUIRED_BINDINGS)}"
        )
    validate_adapters(suite, suite_path, cells_by_id)

    reference = _object(suite.get("reference_qualification"), "reference_qualification")
    if (
        reference.get("publishable") is not False
        or reference.get("driver") != "deterministic-model"
        or reference.get("evidence_class") != "harness_reference"
    ):
        raise ContractError(
            "bounded reference qualification must remain synthetic and non-publishable"
        )
    if reference.get("cell_id") not in cell_ids:
        raise ContractError("reference qualification must select a suite cell")
    _integer(
        reference.get("deterministic_seed"),
        "reference_qualification.deterministic_seed",
        minimum=0,
    )
    reference_load_steps = [
        _integer(
            value,
            f"reference_qualification.load_steps[{index}]",
            minimum=1,
        )
        for index, value in enumerate(
            _list(
                reference.get("load_steps"),
                "reference_qualification.load_steps",
                nonempty=True,
            )
        )
    ]
    if reference_load_steps != sorted(set(reference_load_steps)):
        raise ContractError(
            "reference_qualification.load_steps must be unique and strictly increasing"
        )
    _integer(
        reference.get("samples_per_step"),
        "reference_qualification.samples_per_step",
        minimum=1,
    )
    _number(
        reference.get("sample_interval_seconds"),
        "reference_qualification.sample_interval_seconds",
        minimum=0.001,
    )
    expected_reference_step = _integer(
        reference.get("expected_maximum_sustained_load_step"),
        "reference_qualification.expected_maximum_sustained_load_step",
        minimum=1,
    )
    if expected_reference_step not in reference_load_steps:
        raise ContractError(
            "reference_qualification expected operating point must be a load step"
        )

    corpus = load_json(suite_path.parent / "regression-corpus.json")
    if (
        corpus.get("schema") != CORPUS_SCHEMA
        or corpus.get("suite_version") != suite["suite_version"]
    ):
        raise ContractError("regression corpus must be bound to this suite version")
    if (
        corpus.get("growth_rule")
        != "append_the_smallest_fixture_that_reproduces_a_missing_field_workload_shape"
    ):
        raise ContractError(
            "regression corpus must retain the smallest-fixture growth rule"
        )
    for index, fixture in enumerate(
        _list(corpus.get("fixtures"), "regression-corpus.fixtures")
    ):
        fixture_path = suite_path.parent / _text(
            _object(fixture, f"regression-corpus.fixtures[{index}]").get("path"),
            f"regression-corpus.fixtures[{index}].path",
        )
        if not fixture_path.is_file():
            raise ContractError(f"regression fixture does not exist: {fixture_path}")

    profile_path = suite_path.parent / "profiles/local-docker-amd64.json"
    collector_path = suite_path.parent / str(collector_contract["descriptor"])
    validate_collector(
        load_json(collector_path),
        collector_path,
        suite,
        load_json(profile_path),
    )


def _safe_relative_path(value: Any, path: str) -> Path:
    text = _text(value, path)
    relative = Path(text)
    if relative.is_absolute() or ".." in relative.parts:
        raise ContractError(f"{path} must remain within its adapter directory")
    return relative


def _adapter_definition_types(workload: dict[str, Any], kind: str) -> set[str]:
    return {
        str(_object(value, f"workload.{kind}[]")["type"])
        for value in _list(workload[kind], f"workload.{kind}")
    }


def _validate_adapter_dependency(
    binding: str, adapter_root: Path, expected_version: str
) -> None:
    if binding == "php":
        manifest = load_json(adapter_root / "composer.json")
        require = _object(manifest.get("require"), "php adapter composer.require")
        if require.get("durable-workflow/sdk") != expected_version:
            raise ContractError(
                "PHP adapter must require the suite's exact SDK artifact"
            )
        lock = load_json(adapter_root / "composer.lock")
        locked_packages = {
            str(package.get("name")): str(package.get("version"))
            for package in _list(
                lock.get("packages"), "php adapter composer.lock.packages"
            )
            if isinstance(package, dict)
        }
        if locked_packages.get("durable-workflow/sdk") != expected_version:
            raise ContractError("PHP adapter lock must retain the exact SDK artifact")
        return
    if binding == "python":
        requirement = (adapter_root / "requirements.txt").read_text().strip()
        if requirement != f"durable-workflow=={expected_version}":
            raise ContractError(
                "Python adapter must require the suite's exact SDK artifact"
            )
        locked_requirements = (
            (adapter_root / "requirements.lock").read_text().splitlines()
        )
        if f"durable-workflow=={expected_version}" not in locked_requirements or any(
            "==" not in requirement
            for requirement in locked_requirements
            if requirement
        ):
            raise ContractError(
                "Python adapter lock must pin its complete dependency graph"
            )
        return
    try:
        manifest = tomllib.loads((adapter_root / "Cargo.toml").read_text())
    except (OSError, tomllib.TOMLDecodeError) as exc:
        raise ContractError(f"cannot read Rust adapter Cargo.toml: {exc}") from exc
    dependency = _object(manifest.get("dependencies"), "rust adapter dependencies").get(
        "durable-workflow"
    )
    if dependency != f"={expected_version}":
        raise ContractError("Rust adapter must require the suite's exact SDK artifact")
    try:
        lock = tomllib.loads((adapter_root / "Cargo.lock").read_text())
    except (OSError, tomllib.TOMLDecodeError) as exc:
        raise ContractError(f"cannot read Rust adapter Cargo.lock: {exc}") from exc
    locked_packages = {
        str(package.get("name")): str(package.get("version"))
        for package in _list(lock.get("package"), "rust adapter Cargo.lock.package")
        if isinstance(package, dict)
    }
    if locked_packages.get("durable-workflow") != expected_version:
        raise ContractError("Rust adapter lock must retain the exact SDK artifact")


def validate_adapters(
    suite: dict[str, Any],
    suite_path: Path,
    cells_by_id: dict[str, dict[str, Any]],
) -> None:
    suite_root = suite_path.parent
    for binding in sorted(REQUIRED_BINDINGS):
        descriptor_path = suite_root / "bindings" / binding / "adapter.json"
        descriptor = load_json(descriptor_path)
        path = f"adapter[{binding}]"
        if descriptor.get("schema") != ADAPTER_SCHEMA:
            raise ContractError(f"{path}.schema must be {ADAPTER_SCHEMA}")
        if descriptor.get("suite_version") != suite["suite_version"]:
            raise ContractError(f"{path} must use the suite version")
        if descriptor.get("binding") != binding:
            raise ContractError(f"{path}.binding must be {binding}")
        schema_reference = descriptor.get("$schema")
        if (
            not isinstance(schema_reference, str)
            or (descriptor_path.parent / schema_reference).resolve()
            != (suite_root / "schemas" / "adapter.schema.json").resolve()
        ):
            raise ContractError(f"{path} must reference the versioned adapter schema")

        artifact_key = ADAPTER_ARTIFACTS[binding]
        if descriptor.get("artifact_key") != artifact_key:
            raise ContractError(f"{path}.artifact_key must be {artifact_key}")
        if descriptor.get("artifact") != suite["artifacts"][artifact_key]:
            raise ContractError(f"{path}.artifact must match the exact suite artifact")
        _validate_adapter_dependency(
            binding,
            descriptor_path.parent,
            str(suite["artifacts"][artifact_key]["version"]),
        )

        entrypoint = _list(
            descriptor.get("entrypoint"), f"{path}.entrypoint", nonempty=True
        )
        for index, value in enumerate(entrypoint):
            _text(value, f"{path}.entrypoint[{index}]")
        source_files = _list(
            descriptor.get("source_files"), f"{path}.source_files", nonempty=True
        )
        for index, source_file in enumerate(source_files):
            relative = _safe_relative_path(source_file, f"{path}.source_files[{index}]")
            if not (descriptor_path.parent / relative).is_file():
                raise ContractError(f"{path} source file does not exist: {relative}")

        worker_concurrency = _object(
            descriptor.get("worker_concurrency"), f"{path}.worker_concurrency"
        )
        expected_model = "processes" if binding == "php" else "slots"
        if worker_concurrency != {
            "model": expected_model,
            "environment": "DURABLE_WORKFLOW_WORKER_CONCURRENCY",
        }:
            raise ContractError(
                f"{path} must expose its exact worker-concurrency enforcement model"
            )

        protocol = _object(descriptor.get("client_protocol"), f"{path}.client_protocol")
        if protocol.get("transport") != "stdin_stdout_jsonl" or set(
            _list(protocol.get("operations"), f"{path}.client_protocol.operations")
        ) != {"start", "signal", "query", "result"}:
            raise ContractError(
                f"{path} must implement the complete JSONL client protocol"
            )

        workloads = _object(descriptor.get("workloads"), f"{path}.workloads")
        if set(workloads) != set(cells_by_id):
            raise ContractError(f"{path} must implement every suite cell")
        for cell_id, cell in cells_by_id.items():
            adapter_workload = _object(
                workloads[cell_id], f"{path}.workloads.{cell_id}"
            )
            suite_workload = cell["workload"]
            expected_workflows = {str(suite_workload["workflow"]["type"])}
            child_type = suite_workload["workflow"].get("child_type")
            if child_type is not None:
                expected_workflows.add(str(child_type))
            expected_roles = set(
                next(
                    binding_value["roles"]
                    for binding_value in cell["bindings"]
                    if binding_value["language"] == binding
                )
            )
            expected = {
                "workflow_types": expected_workflows,
                "activity_types": _adapter_definition_types(
                    suite_workload, "activities"
                ),
                "signal_types": _adapter_definition_types(suite_workload, "signals"),
                "query_types": _adapter_definition_types(suite_workload, "queries"),
                "roles": expected_roles,
            }
            for field, expected_values in expected.items():
                actual_values = set(
                    _list(
                        adapter_workload.get(field),
                        f"{path}.workloads.{cell_id}.{field}",
                    )
                )
                if actual_values != expected_values:
                    raise ContractError(
                        f"{path}.workloads.{cell_id}.{field} must match the suite cell"
                    )


def validate_collector(
    descriptor: dict[str, Any],
    descriptor_path: Path,
    suite: dict[str, Any],
    profile: dict[str, Any],
) -> None:
    if descriptor.get("schema") != COLLECTOR_SCHEMA:
        raise ContractError(f"collector.schema must be {COLLECTOR_SCHEMA}")
    if descriptor.get("suite_version") != suite["suite_version"]:
        raise ContractError("collector must use the suite version")
    if descriptor.get("profile_id") != profile["profile_id"]:
        raise ContractError("collector must use the infrastructure profile identity")
    schema_reference = descriptor.get("$schema")
    expected_schema = descriptor_path.parents[2] / "schemas/collector.schema.json"
    if (
        not isinstance(schema_reference, str)
        or (descriptor_path.parent / schema_reference).resolve()
        != expected_schema.resolve()
    ):
        raise ContractError("collector must reference the versioned collector schema")
    entrypoint = _list(
        descriptor.get("entrypoint"), "collector.entrypoint", nonempty=True
    )
    for index, value in enumerate(entrypoint):
        _text(value, f"collector.entrypoint[{index}]")
    for index, value in enumerate(
        _list(descriptor.get("source_files"), "collector.source_files", nonempty=True)
    ):
        relative = _safe_relative_path(value, f"collector.source_files[{index}]")
        if not (descriptor_path.parent / relative).is_file():
            raise ContractError(f"collector source file does not exist: {relative}")
    protocol = _object(descriptor.get("protocol"), "collector.protocol")
    if protocol != {
        "transport": "stdin_stdout_jsonl",
        "operations": ["initialize", "sample"],
    }:
        raise ContractError("collector must implement initialize and sample over JSONL")
    component_containers = _object(
        descriptor.get("component_containers"), "collector.component_containers"
    )
    if set(component_containers) != set(profile["components"]):
        raise ContractError("collector component inventory must match the profile")
    if descriptor.get("runtime_environment") != [
        "CAPACITY_DOCKER_PROJECT",
        "CAPACITY_DOCKER_NETWORK",
    ]:
        raise ContractError("collector must declare its Docker topology environment")
    if set(_object(descriptor.get("data_sources"), "collector.data_sources")) != {
        "component_resources",
        "durable_storage",
        "database",
        "redis",
        "queue_backlog",
    }:
        raise ContractError("collector must declare every infrastructure data source")


def load_observations(path: Path) -> list[dict[str, Any]]:
    observations: list[dict[str, Any]] = []
    try:
        lines = path.read_text().splitlines()
    except OSError as exc:
        raise ContractError(f"cannot read observations from {path}: {exc}") from exc
    for line_number, line in enumerate(lines, start=1):
        if not line.strip():
            continue
        try:
            value = json.loads(
                line,
                object_pairs_hook=_unique_object,
                parse_float=Decimal,
            )
        except json.JSONDecodeError as exc:
            raise ContractError(f"{path}:{line_number} is invalid JSON: {exc}") from exc
        if not isinstance(value, dict):
            raise ContractError(f"{path}:{line_number} must contain an object")
        validate_observation(value, f"observations[{line_number}]")
        observations.append(value)
    if not observations:
        raise ContractError("observation stream cannot be empty")
    return observations


def validate_observation(observation: dict[str, Any], path: str) -> None:
    if observation.get("schema") != OBSERVATION_SCHEMA:
        raise ContractError(f"{path}.schema must be {OBSERVATION_SCHEMA}")
    _text(observation.get("cell_id"), f"{path}.cell_id")
    binding = _text(observation.get("binding"), f"{path}.binding")
    if binding not in REQUIRED_BINDINGS | {"deterministic-model"}:
        raise ContractError(f"{path}.binding is unsupported")
    _integer(observation.get("load_step"), f"{path}.load_step", minimum=1)
    _integer(observation.get("sample_index"), f"{path}.sample_index", minimum=0)
    phase = observation.get("phase")
    if phase not in {"measurement", "drain"}:
        raise ContractError(f"{path}.phase must be measurement or drain")
    _number(
        observation.get("interval_seconds"), f"{path}.interval_seconds", minimum=0.001
    )
    control = _object(observation.get("control"), f"{path}.control")
    if control.get("suite_version") != SUITE_VERSION:
        raise ContractError(f"{path}.control.suite_version must be {SUITE_VERSION}")
    for field, minimum in (
        ("deterministic_seed", 0),
        ("concurrent_open_workflows", 1),
        ("client_concurrency", 1),
        ("worker_concurrency", 1),
        ("warmup_seconds", 0),
        ("duration_seconds", 1),
    ):
        _integer(control.get(field), f"{path}.control.{field}", minimum=minimum)
    offered_load = _object(control.get("offered_load"), f"{path}.control.offered_load")
    _number(
        offered_load.get("workflow_starts_per_second"),
        f"{path}.control.offered_load.workflow_starts_per_second",
        minimum=0,
    )
    _number(
        offered_load.get("query_operations_per_second"),
        f"{path}.control.offered_load.query_operations_per_second",
        minimum=0,
    )
    _integer(
        offered_load.get("long_lived_query_workflows"),
        f"{path}.control.offered_load.long_lived_query_workflows",
        minimum=0,
    )
    minimum_delivery_ratio = _number(
        offered_load.get("minimum_delivery_ratio"),
        f"{path}.control.offered_load.minimum_delivery_ratio",
        minimum=0,
    )
    if minimum_delivery_ratio > 1:
        raise ContractError(
            f"{path}.control.offered_load.minimum_delivery_ratio cannot exceed 1"
        )
    termination = _object(control.get("termination"), f"{path}.control.termination")
    if termination.get("condition") != "duration_elapsed_then_open_workflows_drained":
        raise ContractError(f"{path}.control.termination.condition is unsupported")
    _integer(
        termination.get("drain_timeout_seconds"),
        f"{path}.control.termination.drain_timeout_seconds",
        minimum=1,
    )
    counters = _object(observation.get("counters"), f"{path}.counters")
    for field in (
        "workflow_starts",
        "workflow_completions",
        "activity_dispatches",
        "errors",
        "throttles",
    ):
        _integer(counters.get(field), f"{path}.counters.{field}", minimum=0)
    cohorts = _object(observation.get("workflow_cohorts"), f"{path}.workflow_cohorts")
    for cohort in ("completion_required", "long_lived_query"):
        cohort_counters = _object(
            cohorts.get(cohort), f"{path}.workflow_cohorts.{cohort}"
        )
        for field in ("starts", "completions"):
            _integer(
                cohort_counters.get(field),
                f"{path}.workflow_cohorts.{cohort}.{field}",
                minimum=0,
            )
    demand = _object(observation.get("demand"), f"{path}.demand")
    for operation in ("workflow_starts", "query_operations"):
        operation_demand = _object(demand.get(operation), f"{path}.demand.{operation}")
        for field in ("attempted", "accepted", "completed", "rejected", "throttled"):
            _integer(
                operation_demand.get(field),
                f"{path}.demand.{operation}.{field}",
                minimum=0,
            )
    latencies = _object(observation.get("latencies_ms"), f"{path}.latencies_ms")
    for field in ("schedule_to_start", "replay", "query"):
        for index, value in enumerate(
            _list(latencies.get(field), f"{path}.latencies_ms.{field}")
        ):
            _number(value, f"{path}.latencies_ms.{field}[{index}]", minimum=0)
    _integer(
        observation.get("concurrent_open_workflows"),
        f"{path}.concurrent_open_workflows",
        minimum=0,
    )
    _integer(
        observation.get("concurrent_long_lived_query_workflows"),
        f"{path}.concurrent_long_lived_query_workflows",
        minimum=0,
    )
    infrastructure = _object(
        observation.get("infrastructure"), f"{path}.infrastructure"
    )
    components = _object(
        infrastructure.get("components"), f"{path}.infrastructure.components"
    )
    if not components:
        raise ContractError(f"{path}.infrastructure.components cannot be empty")
    for name, raw_component in components.items():
        component = _object(raw_component, f"{path}.infrastructure.components.{name}")
        for field in ("assigned_cpu_cores", "consumed_cpu_cores"):
            _number(
                component.get(field),
                f"{path}.infrastructure.components.{name}.{field}",
                minimum=0,
            )
        for field in ("assigned_memory_bytes", "consumed_memory_bytes"):
            _integer(
                component.get(field),
                f"{path}.infrastructure.components.{name}.{field}",
                minimum=0,
            )
    storage = _object(
        infrastructure.get("durable_storage"), f"{path}.infrastructure.durable_storage"
    )
    for field in (
        "used_bytes",
        "read_bytes",
        "write_bytes",
        "read_operations",
        "write_operations",
    ):
        _integer(
            storage.get(field),
            f"{path}.infrastructure.durable_storage.{field}",
            minimum=0,
        )
    database = _object(
        infrastructure.get("database"), f"{path}.infrastructure.database"
    )
    for field in ("connections", "locks", "writes"):
        _integer(
            database.get(field), f"{path}.infrastructure.database.{field}", minimum=0
        )
    redis = _object(infrastructure.get("redis"), f"{path}.infrastructure.redis")
    for field in ("memory_bytes", "operations"):
        _integer(redis.get(field), f"{path}.infrastructure.redis.{field}", minimum=0)
    _integer(
        infrastructure.get("queue_backlog"),
        f"{path}.infrastructure.queue_backlog",
        minimum=0,
    )


def percentile(values: Iterable[float], quantile: float) -> float | None:
    ordered = sorted(float(value) for value in values)
    if not ordered:
        return None
    index = max(0, math.ceil(len(ordered) * quantile) - 1)
    return round(ordered[index], 3)


def latency_summary(values: list[float]) -> dict[str, float | None]:
    return {
        "p50": percentile(values, 0.50),
        "p95": percentile(values, 0.95),
        "p99": percentile(values, 0.99),
    }


def _component_summary(observations: list[dict[str, Any]]) -> dict[str, Any]:
    names = set()
    for observation in observations:
        names.update(observation["infrastructure"]["components"])
    result: dict[str, Any] = {}
    for name in sorted(names):
        samples = [
            observation["infrastructure"]["components"][name]
            for observation in observations
            if name in observation["infrastructure"]["components"]
        ]
        assigned_cpu = max(float(sample["assigned_cpu_cores"]) for sample in samples)
        consumed_cpu = max(float(sample["consumed_cpu_cores"]) for sample in samples)
        assigned_memory = max(
            int(sample["assigned_memory_bytes"]) for sample in samples
        )
        consumed_memory = max(
            int(sample["consumed_memory_bytes"]) for sample in samples
        )
        result[name] = {
            "assigned_cpu_cores": assigned_cpu,
            "peak_consumed_cpu_cores": consumed_cpu,
            "peak_cpu_utilization": round(consumed_cpu / assigned_cpu, 6)
            if assigned_cpu
            else None,
            "assigned_memory_bytes": assigned_memory,
            "peak_consumed_memory_bytes": consumed_memory,
            "peak_memory_utilization": round(consumed_memory / assigned_memory, 6)
            if assigned_memory
            else None,
        }
    return result


def reduce_step(
    observations: list[dict[str, Any]],
    rule: dict[str, Any],
    required_measurement_seconds: float,
    cell_id: str,
) -> dict[str, Any]:
    ordered = sorted(observations, key=lambda row: int(row["sample_index"]))
    sample_indices = [int(row["sample_index"]) for row in ordered]
    if len(sample_indices) != len(set(sample_indices)):
        raise ContractError("a load step cannot contain duplicate sample indices")
    if sample_indices != list(range(len(sample_indices))):
        raise ContractError(
            "load-step sample indices must be contiguous and start at zero"
        )
    measurement = [row for row in ordered if row["phase"] == "measurement"]
    if not measurement:
        raise ContractError("a load step must contain measurement observations")
    drain = [row for row in ordered if row["phase"] == "drain"]
    if drain and (
        len(drain) != 1
        or ordered[-1]["phase"] != "drain"
        or any(row["phase"] != "measurement" for row in ordered[:-1])
    ):
        raise ContractError(
            "a load step may contain only measurement observations followed by one drain observation"
        )
    offered_load = measurement[0]["control"]["offered_load"]
    initial_long_lived_queries = int(offered_load["long_lived_query_workflows"])
    expected_open_workflows = initial_long_lived_queries
    expected_long_lived_queries = initial_long_lived_queries
    for row in measurement:
        cohort_starts = sum(
            int(row["workflow_cohorts"][cohort]["starts"])
            for cohort in ("completion_required", "long_lived_query")
        )
        cohort_completions = sum(
            int(row["workflow_cohorts"][cohort]["completions"])
            for cohort in ("completion_required", "long_lived_query")
        )
        if cohort_starts != int(
            row["counters"]["workflow_starts"]
        ) or cohort_completions != int(row["counters"]["workflow_completions"]):
            raise ContractError(
                "workflow cohort evidence contradicts aggregate workflow counters"
            )
        expected_open_workflows += int(row["counters"]["workflow_starts"])
        expected_open_workflows -= int(row["counters"]["workflow_completions"])
        expected_long_lived_queries += int(
            row["workflow_cohorts"]["long_lived_query"]["starts"]
        )
        expected_long_lived_queries -= int(
            row["workflow_cohorts"]["long_lived_query"]["completions"]
        )
        if (
            expected_open_workflows < 0
            or int(row["concurrent_open_workflows"]) != expected_open_workflows
        ):
            raise ContractError(
                "measurement-phase open-work evidence contradicts its start and completion counters"
            )
        if (
            expected_long_lived_queries < 0
            or int(row["concurrent_long_lived_query_workflows"])
            != expected_long_lived_queries
        ):
            raise ContractError(
                "measurement-phase query-cohort evidence contradicts its start and completion counters"
            )
    if drain:
        cohort_starts = sum(
            int(drain[0]["workflow_cohorts"][cohort]["starts"])
            for cohort in ("completion_required", "long_lived_query")
        )
        cohort_completions = sum(
            int(drain[0]["workflow_cohorts"][cohort]["completions"])
            for cohort in ("completion_required", "long_lived_query")
        )
        if cohort_starts != int(
            drain[0]["counters"]["workflow_starts"]
        ) or cohort_completions != int(drain[0]["counters"]["workflow_completions"]):
            raise ContractError(
                "workflow cohort evidence contradicts aggregate workflow counters"
            )
        expected_open_workflows += int(drain[0]["counters"]["workflow_starts"])
        expected_open_workflows -= int(drain[0]["counters"]["workflow_completions"])
        expected_long_lived_queries += int(
            drain[0]["workflow_cohorts"]["long_lived_query"]["starts"]
        )
        expected_long_lived_queries -= int(
            drain[0]["workflow_cohorts"]["long_lived_query"]["completions"]
        )
        if (
            expected_open_workflows < 0
            or int(drain[0]["concurrent_open_workflows"]) != expected_open_workflows
        ):
            raise ContractError(
                "drain open-work evidence contradicts the measurement boundary and drain counters"
            )
        if (
            expected_long_lived_queries < 0
            or int(drain[0]["concurrent_long_lived_query_workflows"])
            != expected_long_lived_queries
        ):
            raise ContractError(
                "drain query-cohort evidence contradicts the measurement boundary and cohort counters"
            )
        drain_timeout = float(
            measurement[0]["control"]["termination"]["drain_timeout_seconds"]
        )
        if float(drain[0]["interval_seconds"]) > drain_timeout + 1e-6:
            raise ContractError("drain evidence exceeds the declared drain timeout")
    if any(row["control"]["offered_load"] != offered_load for row in ordered[1:]):
        raise ContractError("a load step must retain one offered-load contract")
    load_step = int(ordered[0]["load_step"])
    if not math.isclose(
        float(offered_load["workflow_starts_per_second"]),
        0.0 if cell_id == "query-inspection" else float(load_step),
        rel_tol=0.0,
        abs_tol=1e-9,
    ):
        raise ContractError(
            "workflow offered-load rate must equal the declared load step"
        )
    duration = sum(float(row["interval_seconds"]) for row in measurement)
    totals = {
        field: sum(int(row["counters"][field]) for row in measurement)
        for field in (
            "workflow_starts",
            "workflow_completions",
            "activity_dispatches",
            "errors",
            "throttles",
        )
    }
    demand_fields = ("attempted", "accepted", "completed", "rejected", "throttled")
    demand_totals = {
        operation: {
            field: sum(int(row["demand"][operation][field]) for row in measurement)
            for field in demand_fields
        }
        for operation in ("workflow_starts", "query_operations")
    }
    all_demand_totals = {
        operation: {
            field: sum(int(row["demand"][operation][field]) for row in ordered)
            for field in demand_fields
        }
        for operation in ("workflow_starts", "query_operations")
    }
    for operation, operation_demand in all_demand_totals.items():
        resolved = (
            operation_demand["accepted"]
            + operation_demand["rejected"]
            + operation_demand["throttled"]
        )
        if operation_demand["attempted"] != resolved:
            raise ContractError(
                f"{operation} demand attempts must resolve as accepted, rejected, or throttled"
            )
    all_counter_totals = {
        field: sum(int(row["counters"][field]) for row in ordered)
        for field in ("workflow_starts", "workflow_completions", "errors", "throttles")
    }
    if (
        all_demand_totals["workflow_starts"]["accepted"]
        != all_counter_totals["workflow_starts"]
        or all_demand_totals["workflow_starts"]["completed"]
        != all_counter_totals["workflow_completions"]
    ):
        raise ContractError(
            "workflow demand evidence contradicts workflow start and completion counters"
        )
    demand_rejections = sum(
        operation["rejected"] for operation in all_demand_totals.values()
    )
    demand_throttles = sum(
        operation["throttled"] for operation in all_demand_totals.values()
    )
    if (
        demand_rejections > all_counter_totals["errors"]
        or demand_throttles > all_counter_totals["throttles"]
    ):
        raise ContractError(
            "rejected and throttled demand cannot exceed aggregate failure counters"
        )
    query_latency_samples = sum(len(row["latencies_ms"]["query"]) for row in ordered)
    if (
        all_demand_totals["query_operations"]["accepted"]
        != all_demand_totals["query_operations"]["completed"]
        or all_demand_totals["query_operations"]["completed"] != query_latency_samples
    ):
        raise ContractError(
            "query demand evidence contradicts completed query latency samples"
        )
    attempts = totals["workflow_starts"] + totals["errors"] + totals["throttles"]
    components = _component_summary(measurement)
    cpu_utilizations = [
        component["peak_cpu_utilization"]
        for component in components.values()
        if component["peak_cpu_utilization"] is not None
    ]
    memory_utilizations = [
        component["peak_memory_utilization"]
        for component in components.values()
        if component["peak_memory_utilization"] is not None
    ]
    latencies = {
        name: latency_summary(
            [float(value) for row in measurement for value in row["latencies_ms"][name]]
        )
        for name in ("schedule_to_start", "replay", "query")
    }
    first_storage = measurement[0]["infrastructure"]["durable_storage"]
    final_storage = measurement[-1]["infrastructure"]["durable_storage"]
    database_samples = [row["infrastructure"]["database"] for row in measurement]
    redis_samples = [row["infrastructure"]["redis"] for row in measurement]
    queue_samples = [int(row["infrastructure"]["queue_backlog"]) for row in measurement]
    monotonic_series = {
        "durable_storage.read_bytes": [
            int(row["infrastructure"]["durable_storage"]["read_bytes"])
            for row in ordered
        ],
        "durable_storage.used_bytes": [
            int(row["infrastructure"]["durable_storage"]["used_bytes"])
            for row in ordered
        ],
        "durable_storage.write_bytes": [
            int(row["infrastructure"]["durable_storage"]["write_bytes"])
            for row in ordered
        ],
        "durable_storage.read_operations": [
            int(row["infrastructure"]["durable_storage"]["read_operations"])
            for row in ordered
        ],
        "durable_storage.write_operations": [
            int(row["infrastructure"]["durable_storage"]["write_operations"])
            for row in ordered
        ],
        "database.writes": [
            int(row["infrastructure"]["database"]["writes"]) for row in ordered
        ],
        "redis.operations": [
            int(row["infrastructure"]["redis"]["operations"]) for row in ordered
        ],
    }
    for name, values in monotonic_series.items():
        if values != sorted(values):
            raise ContractError(f"{name} must be a monotonic cumulative counter")
    completion_required_starts = sum(
        int(row["workflow_cohorts"]["completion_required"]["starts"])
        for row in measurement
    )
    completion_required_completions = sum(
        int(row["workflow_cohorts"]["completion_required"]["completions"])
        for row in measurement
    )
    if completion_required_completions > completion_required_starts:
        raise ContractError(
            "completion-required workflows cannot complete without a measurement start"
        )
    measurement_query_cohort_starts = sum(
        int(row["workflow_cohorts"]["long_lived_query"]["starts"])
        for row in measurement
    )
    measurement_query_cohort_completions = sum(
        int(row["workflow_cohorts"]["long_lived_query"]["completions"])
        for row in measurement
    )
    measurement_query_cohort = [
        int(row["concurrent_long_lived_query_workflows"]) for row in measurement
    ]
    completion_ratio = (
        completion_required_completions / completion_required_starts
        if completion_required_starts
        else 1.0
        if initial_long_lived_queries
        else 0.0
    )
    error_rate = totals["errors"] / attempts if attempts else 0.0
    throttle_rate = totals["throttles"] / attempts if attempts else 0.0
    termination = drain[0] if drain else measurement[-1]
    drain_converged = (
        int(termination["concurrent_open_workflows"]) == 0
        and int(termination["infrastructure"]["queue_backlog"]) == 0
    )
    duration_tolerance = max(1e-6, len(measurement) * 5e-7)
    minimum_delivery_ratio = float(offered_load["minimum_delivery_ratio"])

    def delivery_summary(
        operation: str, target_rate: float, delivered_field: str
    ) -> dict[str, Any]:
        target_count = target_rate * required_measurement_seconds
        minimum_count = math.ceil(target_count * minimum_delivery_ratio - 1e-9)
        operation_demand = demand_totals[operation]
        attempted = operation_demand["attempted"]
        delivered = operation_demand[delivered_field]
        return {
            "target_per_second": round(target_rate, 6),
            "target_count": round(target_count, 6),
            "minimum_delivery_ratio": minimum_delivery_ratio,
            "delivery_tolerance_ratio": round(1 - minimum_delivery_ratio, 6),
            "minimum_count": minimum_count,
            **operation_demand,
            "unoffered": max(0, math.ceil(target_count - 1e-9) - attempted),
            "attempted_ratio": round(attempted / target_count, 6)
            if target_count
            else None,
            "delivered_ratio": round(delivered / target_count, 6)
            if target_count
            else None,
        }

    workflow_delivery = delivery_summary(
        "workflow_starts",
        float(offered_load["workflow_starts_per_second"]),
        "accepted",
    )
    query_delivery = delivery_summary(
        "query_operations",
        float(offered_load["query_operations_per_second"]),
        "completed",
    )

    violations: list[str] = []
    checks = (
        (
            not math.isclose(
                duration,
                required_measurement_seconds,
                rel_tol=0.0,
                abs_tol=duration_tolerance,
            ),
            "complete_measurement_window",
        ),
        (
            completion_required_starts > 0 and completion_required_completions == 0,
            "missing_measurement_completions",
        ),
        (
            initial_long_lived_queries > 0
            and (
                min(measurement_query_cohort, default=0) != initial_long_lived_queries
                or max(measurement_query_cohort, default=0)
                != initial_long_lived_queries
            ),
            "incomplete_long_lived_query_cohort",
        ),
        (
            measurement_query_cohort_starts > 0
            or measurement_query_cohort_completions > 0,
            "long_lived_query_cohort_churn",
        ),
        (
            workflow_delivery["attempted"] < workflow_delivery["minimum_count"],
            "workflow_offer_underdelivery",
        ),
        (
            workflow_delivery["accepted"] < workflow_delivery["minimum_count"],
            "workflow_start_underdelivery",
        ),
        (
            query_delivery["target_count"] > 0
            and query_delivery["attempted"] < query_delivery["minimum_count"],
            "query_offer_underdelivery",
        ),
        (
            query_delivery["target_count"] > 0
            and query_delivery["completed"] < query_delivery["minimum_count"],
            "query_completion_underdelivery",
        ),
        (
            completion_required_starts > 0
            and latencies["schedule_to_start"]["p99"] is None,
            "missing_schedule_to_start_latency",
        ),
        (
            cell_id in {"replay-heavy-history", "mixed"}
            and latencies["replay"]["p99"] is None,
            "missing_replay_latency",
        ),
        (
            cell_id in {"query-inspection", "mixed"}
            and latencies["query"]["p99"] is None,
            "missing_query_latency",
        ),
        (
            cell_id in {"one-activity", "multiple-activities", "mixed"}
            and totals["activity_dispatches"] == 0,
            "missing_activity_dispatches",
        ),
        (
            int(termination["concurrent_open_workflows"]) != 0,
            "open_workflows_not_drained",
        ),
        (
            int(termination["concurrent_long_lived_query_workflows"]) != 0,
            "long_lived_query_workflows_not_drained",
        ),
        (
            bool(drain) and int(termination["infrastructure"]["queue_backlog"]) != 0,
            "queue_backlog_not_drained",
        ),
        (
            completion_ratio < float(rule["minimum_completion_ratio"]),
            "completion_ratio",
        ),
        (error_rate > float(rule["maximum_error_rate"]), "error_rate"),
        (throttle_rate > float(rule["maximum_throttle_rate"]), "throttle_rate"),
        (
            (latencies["schedule_to_start"]["p99"] or 0)
            > float(rule["maximum_schedule_to_start_p99_ms"]),
            "schedule_to_start_p99_ms",
        ),
        (
            latencies["replay"]["p99"] is not None
            and float(latencies["replay"]["p99"] or 0)
            > float(rule["maximum_replay_p99_ms"]),
            "replay_p99_ms",
        ),
        (
            latencies["query"]["p99"] is not None
            and float(latencies["query"]["p99"] or 0)
            > float(rule["maximum_query_p99_ms"]),
            "query_p99_ms",
        ),
        (
            max(cpu_utilizations, default=0.0) > float(rule["maximum_cpu_utilization"]),
            "cpu_utilization",
        ),
        (
            max(memory_utilizations, default=0.0)
            > float(rule["maximum_memory_utilization"]),
            "memory_utilization",
        ),
        (
            max(queue_samples, default=0) > int(rule["maximum_queue_backlog"]),
            "queue_backlog",
        ),
    )
    violations.extend(name for failed, name in checks if failed)

    return {
        "load_step": load_step,
        "measurement_seconds": round(duration, 6),
        "offered_load": {
            "workflow_starts": workflow_delivery,
            "query_operations": query_delivery,
        },
        "rates": {
            "workflow_starts_per_second": round(
                totals["workflow_starts"] / duration, 6
            ),
            "workflow_completions_per_second": round(
                totals["workflow_completions"] / duration, 6
            ),
            "activity_dispatches_per_second": round(
                totals["activity_dispatches"] / duration, 6
            ),
        },
        "latency_ms": latencies,
        "concurrent_open_workflows": {
            "maximum": max(
                int(row["concurrent_open_workflows"]) for row in measurement
            ),
            "measurement_final": int(measurement[-1]["concurrent_open_workflows"]),
            "final": int(termination["concurrent_open_workflows"]),
        },
        "workflow_completion_evidence": {
            "denominator": "completion_required_workflows_started_during_measurement",
            "eligible_starts": completion_required_starts,
            "eligible_completions": completion_required_completions,
            "long_lived_query_workflows": {
                "target": initial_long_lived_queries,
                "measurement_minimum": min(measurement_query_cohort, default=0),
                "measurement_final": measurement_query_cohort[-1],
                "final": int(termination["concurrent_long_lived_query_workflows"]),
            },
        },
        "completion_ratio": round(completion_ratio, 6),
        "error_rate": round(error_rate, 6),
        "throttle_rate": round(throttle_rate, 6),
        "storage": {
            "growth_bytes": int(final_storage["used_bytes"])
            - int(first_storage["used_bytes"]),
            "read_bytes": int(final_storage["read_bytes"])
            - int(first_storage["read_bytes"]),
            "write_bytes": int(final_storage["write_bytes"])
            - int(first_storage["write_bytes"]),
            "read_operations": int(final_storage["read_operations"])
            - int(first_storage["read_operations"]),
            "write_operations": int(final_storage["write_operations"])
            - int(first_storage["write_operations"]),
        },
        "infrastructure": {
            "components": components,
            "database": {
                "peak_connections": max(
                    int(sample["connections"]) for sample in database_samples
                ),
                "peak_locks": max(int(sample["locks"]) for sample in database_samples),
                "writes": max(int(sample["writes"]) for sample in database_samples)
                - min(int(sample["writes"]) for sample in database_samples),
            },
            "redis": {
                "peak_memory_bytes": max(
                    int(sample["memory_bytes"]) for sample in redis_samples
                ),
                "operations": max(int(sample["operations"]) for sample in redis_samples)
                - min(int(sample["operations"]) for sample in redis_samples),
            },
            "queue_backlog": {
                "maximum": max(queue_samples),
                "final": queue_samples[-1],
                "drain_final": int(termination["infrastructure"]["queue_backlog"])
                if drain
                else None,
            },
        },
        "drain": (
            {
                "seconds": round(float(drain[0]["interval_seconds"]), 6),
                "workflow_completions": int(
                    drain[0]["counters"]["workflow_completions"]
                ),
                "activity_dispatches": int(drain[0]["counters"]["activity_dispatches"]),
                "errors": int(drain[0]["counters"]["errors"]),
                "throttles": int(drain[0]["counters"]["throttles"]),
                "demand": drain[0]["demand"],
                "workflow_cohorts": drain[0]["workflow_cohorts"],
                "latency_samples": sum(
                    len(drain[0]["latencies_ms"][name])
                    for name in ("schedule_to_start", "replay", "query")
                ),
                "final_open_workflows": int(drain[0]["concurrent_open_workflows"]),
                "final_queue_backlog": int(drain[0]["infrastructure"]["queue_backlog"]),
                "converged": drain_converged,
            }
            if drain
            else None
        ),
        "saturation": {"sustained": len(violations) > 0, "violations": violations},
        "operating_point_eligible": len(violations) == 0,
    }


def _long_lived_query_share(cell: dict[str, Any]) -> float:
    eligibility = cell["workload"]["measurement_eligibility"]
    return float(
        eligibility["long_lived_query_cohort"]["concurrent_open_workflow_share"]
    )


def long_lived_query_target(cell: dict[str, Any]) -> int:
    share = _long_lived_query_share(cell)
    concurrency = int(cell["execution"]["concurrent_open_workflows"])
    if share == 0:
        return 0
    if cell["id"] != "mixed":
        return int(math.floor(concurrency * share + 0.5))

    mix = cell["workload"]["mix"]
    exact = [
        (shape, Decimal(str(weight)) * concurrency) for shape, weight in mix.items()
    ]
    allocated = {shape: int(value) for shape, value in exact}
    remaining = concurrency - sum(allocated.values())
    ranked = sorted(
        enumerate(exact),
        key=lambda item: (-(item[1][1] - int(item[1][1])), item[0]),
    )
    for _, (shape, _) in ranked[:remaining]:
        allocated[shape] += 1
    return allocated["query-inspection"]


def _offered_load_contract(
    cell: dict[str, Any], load_step: int, rule: dict[str, Any]
) -> dict[str, float | int]:
    query_rate = sum(
        float(definition.get("rate_per_load_unit_per_second", 0))
        for definition in cell["workload"].get("queries", [])
        if isinstance(definition, dict)
    )
    long_lived_queries = long_lived_query_target(cell)
    return {
        "workflow_starts_per_second": 0.0
        if _long_lived_query_share(cell) == 1
        else float(load_step),
        "query_operations_per_second": round(query_rate * load_step, 6),
        "long_lived_query_workflows": long_lived_queries,
        "minimum_delivery_ratio": float(rule["minimum_offered_load_delivery_ratio"]),
    }


def reduce_result(
    suite: dict[str, Any],
    suite_path: Path,
    profile: dict[str, Any],
    profile_path: Path,
    observations: list[dict[str, Any]],
    *,
    source_revision: str,
    run_timestamp: str,
    architecture: str,
    evidence_class: str = "capacity",
    publishable: bool = True,
) -> dict[str, Any]:
    if evidence_class not in {"capacity", "harness_reference"}:
        raise ContractError("unsupported benchmark evidence class")
    if evidence_class == "harness_reference" and publishable:
        raise ContractError(
            "synthetic harness reference evidence cannot be publishable"
        )
    for index, observation in enumerate(observations):
        validate_observation(observation, f"observations[{index}]")
    cell_ids = {str(row["cell_id"]) for row in observations}
    if len(cell_ids) != 1:
        raise ContractError("one result may contain observations for exactly one cell")
    cell_id = next(iter(cell_ids))
    if cell_id not in {cell["id"] for cell in suite["cells"]}:
        raise ContractError(f"observation cell is absent from suite: {cell_id}")
    bindings = {str(row["binding"]) for row in observations}
    if len(bindings) != 1:
        raise ContractError(
            "one result may contain observations for exactly one SDK binding"
        )
    binding = next(iter(bindings))
    if evidence_class == "capacity" and binding not in REQUIRED_BINDINGS:
        raise ContractError(
            "capacity evidence must come from a first-party PHP, Python, or Rust binding"
        )
    if evidence_class == "harness_reference" and binding != "deterministic-model":
        raise ContractError(
            "reference evidence must retain its deterministic-model identity"
        )
    cell = next(cell for cell in suite["cells"] if cell["id"] == cell_id)
    expected_base_control = (
        {
            "suite_version": suite["suite_version"],
            "deterministic_seed": int(
                suite["reference_qualification"]["deterministic_seed"]
            ),
            "concurrent_open_workflows": 1,
            "client_concurrency": 1,
            "worker_concurrency": 1,
            "warmup_seconds": 0,
            "duration_seconds": int(
                suite["reference_qualification"]["samples_per_step"]
            )
            * int(suite["reference_qualification"]["sample_interval_seconds"]),
            "termination": {
                "condition": "duration_elapsed_then_open_workflows_drained",
                "drain_timeout_seconds": 1,
            },
        }
        if evidence_class == "harness_reference"
        else {
            "suite_version": suite["suite_version"],
            "deterministic_seed": int(cell["execution"]["deterministic_seed"]),
            "concurrent_open_workflows": int(
                cell["execution"]["concurrent_open_workflows"]
            ),
            "client_concurrency": int(cell["execution"]["client_concurrency"]),
            "worker_concurrency": int(cell["execution"]["worker_concurrency"]),
            "warmup_seconds": int(cell["execution"]["warmup_seconds"]),
            "duration_seconds": int(cell["execution"]["duration_seconds"]),
            "termination": cell["execution"]["termination"],
        }
    )
    for index, observation in enumerate(observations):
        expected_control = {
            **expected_base_control,
            "offered_load": _offered_load_contract(
                cell,
                int(observation["load_step"]),
                suite["operating_point_rule"],
            ),
        }
        if observation["control"] != expected_control:
            raise ContractError(
                f"observations[{index}].control must exactly match the declared execution"
            )
    expected_components = profile["components"]
    for index, observation in enumerate(observations):
        observed_components = observation["infrastructure"]["components"]
        if set(observed_components) != set(expected_components):
            raise ContractError(
                f"observations[{index}] component inventory does not match the infrastructure profile"
            )
        for name, expected in expected_components.items():
            observed = observed_components[name]
            if float(observed["assigned_cpu_cores"]) != float(expected["cpu_cores"]):
                raise ContractError(
                    f"observations[{index}] {name} assigned CPU differs from the profile"
                )
            if int(observed["assigned_memory_bytes"]) != int(expected["memory_bytes"]):
                raise ContractError(
                    f"observations[{index}] {name} assigned memory differs from the profile"
                )
    normalized_architecture = normalize_architecture(architecture)
    if normalized_architecture != normalize_architecture(
        profile["architecture"]["machine"]
    ):
        raise ContractError(
            "run architecture does not match the infrastructure profile"
        )
    required_measurement_seconds = (
        float(suite["reference_qualification"]["samples_per_step"])
        * float(suite["reference_qualification"]["sample_interval_seconds"])
        if evidence_class == "harness_reference"
        else float(cell["execution"]["duration_seconds"])
    )
    grouped: dict[int, list[dict[str, Any]]] = {}
    for observation in observations:
        grouped.setdefault(int(observation["load_step"]), []).append(observation)
    expected_load_steps = set(
        suite["reference_qualification"]["load_steps"]
        if evidence_class == "harness_reference"
        else cell["execution"]["load_steps"]
    )
    if set(grouped) != expected_load_steps:
        raise ContractError(
            "result observations must contain the complete declared load-step sweep"
        )
    for load_step, rows in grouped.items():
        phases = [
            row["phase"] for row in sorted(rows, key=lambda row: row["sample_index"])
        ]
        if evidence_class == "capacity" and (
            phases[-1:] != ["drain"]
            or phases.count("drain") != 1
            or any(phase != "measurement" for phase in phases[:-1])
        ):
            raise ContractError(
                f"load step {load_step} must end with exactly one drain observation"
            )
        if evidence_class == "harness_reference" and set(phases) != {"measurement"}:
            raise ContractError("reference observations cannot contain a drain phase")
    steps = [
        reduce_step(
            grouped[load_step],
            suite["operating_point_rule"],
            required_measurement_seconds,
            cell_id,
        )
        for load_step in sorted(grouped)
    ]
    eligible = [step for step in steps if step["operating_point_eligible"]]
    maximum = eligible[-1] if eligible else None
    return {
        "schema": RESULT_SCHEMA,
        "identity": {
            "suite_version": suite["suite_version"],
            "suite_sha256": sha256_file(suite_path),
            "source_revision": _source_revision(source_revision),
            "artifacts": suite["artifacts"],
            "infrastructure_profile": profile["profile_id"],
            "infrastructure_profile_sha256": sha256_file(profile_path),
            "architecture": normalized_architecture,
            "binding": binding,
            "run_timestamp": _timestamp(run_timestamp),
        },
        "evidence_class": evidence_class,
        "publishable": bool(publishable and maximum is not None),
        "cell_id": cell_id,
        "measurement_contract": {
            "warmup_seconds": 0
            if evidence_class == "harness_reference"
            else cell["execution"]["warmup_seconds"],
            "duration_seconds": required_measurement_seconds,
            "deterministic_seed": expected_base_control["deterministic_seed"],
            "concurrent_open_workflows": expected_base_control[
                "concurrent_open_workflows"
            ],
            "client_concurrency": expected_base_control["client_concurrency"],
            "worker_concurrency": expected_base_control["worker_concurrency"],
            "termination": (
                {
                    "condition": "bounded_reference_samples_emitted",
                    "drain_timeout_seconds": 0,
                }
                if evidence_class == "harness_reference"
                else cell["execution"]["termination"]
            ),
        },
        "operating_point_rule": suite["operating_point_rule"],
        "load_steps": steps,
        "maximum_sustained_operating_point": maximum,
    }


def _reference_observations(
    suite: dict[str, Any], profile: dict[str, Any]
) -> list[dict[str, Any]]:
    reference = suite["reference_qualification"]
    seed = int(reference["deterministic_seed"])
    observations: list[dict[str, Any]] = []
    component_profile = profile["components"]
    cumulative_storage = 1_000_000
    cumulative_read = 0
    cumulative_write = 0
    cumulative_read_ops = 0
    cumulative_write_ops = 0
    database_writes = 0
    redis_operations = 0
    for load_step in reference["load_steps"]:
        open_workflows = 0
        for sample_index in range(int(reference["samples_per_step"])):
            # The arithmetic is deliberately transparent and stable across Python versions.
            starts = load_step
            saturated = load_step == max(reference["load_steps"])
            completions = starts - (1 if saturated and sample_index % 2 == 0 else 0)
            throttles = 1 if saturated and sample_index % 2 == 0 else 0
            open_workflows += starts - completions
            latency_base = load_step * (110 if saturated else 35)
            schedule_latencies = [
                latency_base + ((seed + sample_index * 13 + index * 7) % 19)
                for index in range(max(1, starts))
            ]
            cumulative_storage += max(1, starts) * 128
            cumulative_read += max(1, starts) * 96
            cumulative_write += max(1, starts) * 160
            cumulative_read_ops += max(1, starts)
            cumulative_write_ops += max(1, starts) * 2
            database_writes += max(1, starts) * 3
            redis_operations += max(1, starts) * 5
            utilization = min(
                0.98,
                0.20
                + load_step * (0.07 if saturated else 0.045)
                + sample_index * 0.005,
            )
            components = {
                name: {
                    "assigned_cpu_cores": component["cpu_cores"],
                    "consumed_cpu_cores": round(
                        component["cpu_cores"] * utilization, 6
                    ),
                    "assigned_memory_bytes": component["memory_bytes"],
                    "consumed_memory_bytes": int(
                        component["memory_bytes"] * min(0.94, utilization * 0.9)
                    ),
                }
                for name, component in component_profile.items()
            }
            observations.append(
                {
                    "schema": OBSERVATION_SCHEMA,
                    "cell_id": reference["cell_id"],
                    "binding": "deterministic-model",
                    "load_step": load_step,
                    "sample_index": sample_index,
                    "phase": "measurement",
                    "interval_seconds": reference["sample_interval_seconds"],
                    "control": {
                        "suite_version": suite["suite_version"],
                        "deterministic_seed": seed,
                        "concurrent_open_workflows": 1,
                        "client_concurrency": 1,
                        "worker_concurrency": 1,
                        "warmup_seconds": 0,
                        "duration_seconds": int(reference["samples_per_step"])
                        * int(reference["sample_interval_seconds"]),
                        "offered_load": _offered_load_contract(
                            next(
                                cell
                                for cell in suite["cells"]
                                if cell["id"] == reference["cell_id"]
                            ),
                            int(load_step),
                            suite["operating_point_rule"],
                        ),
                        "termination": {
                            "condition": "duration_elapsed_then_open_workflows_drained",
                            "drain_timeout_seconds": 1,
                        },
                    },
                    "counters": {
                        "workflow_starts": starts,
                        "workflow_completions": completions,
                        "activity_dispatches": 0,
                        "errors": 0,
                        "throttles": throttles,
                    },
                    "workflow_cohorts": {
                        "completion_required": {
                            "starts": starts,
                            "completions": completions,
                        },
                        "long_lived_query": {"starts": 0, "completions": 0},
                    },
                    "demand": {
                        "workflow_starts": {
                            "attempted": starts + throttles,
                            "accepted": starts,
                            "completed": completions,
                            "rejected": 0,
                            "throttled": throttles,
                        },
                        "query_operations": {
                            "attempted": 0,
                            "accepted": 0,
                            "completed": 0,
                            "rejected": 0,
                            "throttled": 0,
                        },
                    },
                    "latencies_ms": {
                        "schedule_to_start": schedule_latencies,
                        "replay": [],
                        "query": [],
                    },
                    "concurrent_open_workflows": open_workflows,
                    "concurrent_long_lived_query_workflows": 0,
                    "infrastructure": {
                        "components": components,
                        "durable_storage": {
                            "used_bytes": cumulative_storage,
                            "read_bytes": cumulative_read,
                            "write_bytes": cumulative_write,
                            "read_operations": cumulative_read_ops,
                            "write_operations": cumulative_write_ops,
                        },
                        "database": {
                            "connections": 4 + load_step,
                            "locks": 1 + (1 if saturated else 0),
                            "writes": database_writes,
                        },
                        "redis": {
                            "memory_bytes": 4_000_000 + load_step * 1_000,
                            "operations": redis_operations,
                        },
                        "queue_backlog": load_step * 2 if saturated else sample_index,
                    },
                }
            )
    for index, observation in enumerate(observations):
        validate_observation(observation, f"reference[{index}]")
    return observations


def _git_revision() -> str:
    try:
        return subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL
        ).strip()
    except (OSError, subprocess.CalledProcessError):
        return "unknown-local-revision"


def _timestamp(value: str | None) -> str:
    if value:
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError as exc:
            raise ContractError("run timestamp must be ISO 8601") from exc
        if parsed.tzinfo is None:
            raise ContractError("run timestamp must include a timezone")
        return value
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def _write_result(result: dict[str, Any], output: Path | None) -> None:
    encoded = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if output is None:
        print(encoded, end="")
    else:
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(encoded)


def comparison_identity(result: dict[str, Any]) -> dict[str, Any]:
    identity = _object(result.get("identity"), "result.identity")
    return {
        key: identity.get(key)
        for key in (
            "suite_version",
            "suite_sha256",
            "source_revision",
            "artifacts",
            "infrastructure_profile",
            "infrastructure_profile_sha256",
            "architecture",
            "binding",
        )
    }


def compare_results(left: dict[str, Any], right: dict[str, Any]) -> dict[str, Any]:
    if left.get("schema") != RESULT_SCHEMA or right.get("schema") != RESULT_SCHEMA:
        raise ContractError(
            "both comparison inputs must be capacity result v1 documents"
        )
    left_identity = comparison_identity(left)
    right_identity = comparison_identity(right)
    differences = {
        key: {"left": left_identity[key], "right": right_identity[key]}
        for key in left_identity
        if left_identity[key] != right_identity[key]
    }
    return {"comparable": not differences, "identity_differences": differences}


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--suite", type=Path, default=DEFAULT_SUITE)
    parser.add_argument("--profile", type=Path, default=DEFAULT_PROFILE)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser(
        "validate", help="validate the suite, schemas, profile, and corpus"
    )
    commands.add_parser(
        "verify-publication",
        help="verify the canonical HTTPS schema routes and content",
    )
    reference = commands.add_parser(
        "reference", help="run the deterministic non-capacity qualification cell"
    )
    reference.add_argument("--source-revision", default=None)
    reference.add_argument("--run-timestamp", default=None)
    reference.add_argument("--architecture", default=None)
    reference.add_argument("--output", type=Path)
    evaluate = commands.add_parser(
        "evaluate", help="reduce SDK-driver JSONL observations"
    )
    evaluate.add_argument("observations", type=Path)
    evaluate.add_argument("--source-revision", required=True)
    evaluate.add_argument("--run-timestamp", required=True)
    evaluate.add_argument("--architecture", required=True)
    evaluate.add_argument("--output", type=Path)
    compare = commands.add_parser(
        "compare", help="refuse silent comparison of unlike result identities"
    )
    compare.add_argument("left", type=Path)
    compare.add_argument("right", type=Path)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        suite = load_json(args.suite)
        validate_suite(suite, args.suite)
        profile = load_json(args.profile)
        validate_profile(profile)
        if args.command == "validate":
            print(
                f"capacity suite {suite['suite_version']} is valid ({len(suite['cells'])} cells)"
            )
            return 0
        if args.command == "verify-publication":
            verify_schema_publication(args.suite.parent)
            print(
                f"capacity schema publication is live ({len(REQUIRED_SCHEMAS)} schemas)"
            )
            return 0
        if args.command == "compare":
            comparison = compare_results(load_json(args.left), load_json(args.right))
            print(json.dumps(comparison, indent=2, sort_keys=True))
            return 0 if comparison["comparable"] else 2
        observations = (
            _reference_observations(suite, profile)
            if args.command == "reference"
            else load_observations(args.observations)
        )
        result = reduce_result(
            suite,
            args.suite,
            profile,
            args.profile,
            observations,
            source_revision=args.source_revision or _git_revision(),
            run_timestamp=_timestamp(args.run_timestamp),
            architecture=args.architecture or platform.machine(),
            evidence_class="harness_reference"
            if args.command == "reference"
            else "capacity",
            publishable=args.command != "reference",
        )
        _write_result(result, args.output)
        return 0
    except ContractError as exc:
        if args.command == "verify-publication":
            print(f"capacity schema publication audit error: {exc}", file=sys.stderr)
        else:
            print(f"capacity benchmark contract error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
