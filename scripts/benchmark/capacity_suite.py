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
    "workflow_starts_per_second",
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
    for field in ("kernel_profile", "container_engine", "scheduling_policy"):
        _text(runtime.get(field), f"infrastructure.runtime.{field}")
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
    storage = _object(profile.get("durable_storage"), "infrastructure.durable_storage")
    for field in ("driver", "medium", "mount_options"):
        _text(storage.get(field), f"infrastructure.durable_storage.{field}")
    _integer(
        storage.get("capacity_bytes"),
        "infrastructure.durable_storage.capacity_bytes",
        minimum=1,
    )
    for dependency in ("database", "redis"):
        service = _object(profile.get(dependency), f"infrastructure.{dependency}")
        _text(service.get("engine"), f"infrastructure.{dependency}.engine")
        _exact_version(service.get("version"), f"infrastructure.{dependency}.version")
        _text(
            service.get("configuration"), f"infrastructure.{dependency}.configuration"
        )


def validate_suite(suite: dict[str, Any], suite_path: Path = DEFAULT_SUITE) -> None:
    if suite.get("schema") != SUITE_SCHEMA:
        raise ContractError(f"suite schema must be {SUITE_SCHEMA}")
    if suite.get("suite_version") != "1.0.0":
        raise ContractError("v1 suite path must contain suite_version 1.0.0")
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
    adapters = _object(driver.get("adapters"), "driver_contract.adapters")
    if adapters != {
        "schema": "schemas/adapter.schema.json",
        "descriptor_pattern": "bindings/{binding}/adapter.json",
        "modes": ["describe", "worker", "client"],
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
            if len(definition_types) != len(set(definition_types)):
                raise ContractError(
                    f"{path}.workload.{definition_kind} cannot repeat a type"
                )
        payload = _object(workload.get("payload"), f"{path}.workload.payload")
        for field in (
            "workflow_input_bytes",
            "workflow_result_bytes",
            "activity_input_bytes",
            "activity_result_bytes",
            "signal_bytes",
        ):
            _integer(payload.get(field), f"{path}.workload.payload.{field}", minimum=0)
        history = _object(workload.get("history"), f"{path}.workload.history")
        _integer(
            history.get("target_event_count"),
            f"{path}.workload.history.target_event_count",
            minimum=1,
        )
        _list(history.get("shape"), f"{path}.workload.history.shape", nonempty=True)
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
    if control.get("suite_version") != "1.0.0":
        raise ContractError(f"{path}.control.suite_version must be 1.0.0")
    for field, minimum in (
        ("deterministic_seed", 0),
        ("concurrent_open_workflows", 1),
        ("client_concurrency", 1),
        ("worker_concurrency", 1),
        ("warmup_seconds", 0),
        ("duration_seconds", 1),
    ):
        _integer(control.get(field), f"{path}.control.{field}", minimum=minimum)
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
    expected_open_workflows = 0
    for row in measurement:
        expected_open_workflows += int(row["counters"]["workflow_starts"])
        expected_open_workflows -= int(row["counters"]["workflow_completions"])
        if (
            expected_open_workflows < 0
            or int(row["concurrent_open_workflows"]) != expected_open_workflows
        ):
            raise ContractError(
                "measurement-phase open-work evidence contradicts its start and completion counters"
            )
    if drain:
        expected_open_workflows += int(drain[0]["counters"]["workflow_starts"])
        expected_open_workflows -= int(drain[0]["counters"]["workflow_completions"])
        if (
            expected_open_workflows < 0
            or int(drain[0]["concurrent_open_workflows"]) != expected_open_workflows
        ):
            raise ContractError(
                "drain open-work evidence contradicts the measurement boundary and drain counters"
            )
        drain_timeout = float(
            measurement[0]["control"]["termination"]["drain_timeout_seconds"]
        )
        if float(drain[0]["interval_seconds"]) > drain_timeout + 1e-6:
            raise ContractError("drain evidence exceeds the declared drain timeout")
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
    completion_ratio = (
        totals["workflow_completions"] / totals["workflow_starts"]
        if totals["workflow_starts"]
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
            totals["workflow_completions"] == 0,
            "missing_measurement_completions",
        ),
        (
            latencies["schedule_to_start"]["p99"] is None,
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
        "load_step": int(ordered[0]["load_step"]),
        "measurement_seconds": round(duration, 6),
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
    expected_control = (
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
            "deterministic_seed": expected_control["deterministic_seed"],
            "concurrent_open_workflows": expected_control["concurrent_open_workflows"],
            "client_concurrency": expected_control["client_concurrency"],
            "worker_concurrency": expected_control["worker_concurrency"],
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
            jitter = ((seed + load_step * 31 + sample_index * 17) % 9) - 4
            starts = load_step * 2 + jitter // 3
            saturated = load_step == max(reference["load_steps"])
            completions = starts - (1 if saturated and sample_index % 2 == 0 else 0)
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
                        "throttles": 1 if saturated and sample_index % 2 == 0 else 0,
                    },
                    "latencies_ms": {
                        "schedule_to_start": schedule_latencies,
                        "replay": [],
                        "query": [],
                    },
                    "concurrent_open_workflows": open_workflows,
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
        print(f"capacity benchmark contract error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
