#!/usr/bin/env python3
"""Validate immutable regression evidence within each repository's owned corpus."""

from __future__ import annotations

import argparse
import base64
import binascii
import fnmatch
import hashlib
import json
import os
import re
import secrets
import subprocess
import sys
import tempfile
from collections import Counter
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any

POLICY_SCHEMA = "durable-workflow.regression-corpus-policy/v1"
CODEC_SCHEMA = "durable-workflow.codec-regression/v1"
REPLAY_SCHEMA = "durable-workflow.replay-regression/v1"
GOLDEN_HISTORY_SCHEMA = "durable-workflow.golden-history.v1"
SUPPORTED_FORMATS = {
    "avro-value-golden-v1",
    "codec-regression-v1",
    "golden-history-v1",
    "replay-regression-v1",
}
SUPPORTED_CATEGORIES = {"codec", "replay"}
SUPPORTED_BINDINGS = {"php", "python", "rust"}
OWNED_CATEGORIES = {
    "server": {"codec"},
}
SERVER_CODEC_RUNNER_FORMATS = {"codec-regression-v1"}
PORTABLE_PHP_FIXTURE_GLOB = re.compile(
    r"^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*(?:[A-Za-z0-9_-][A-Za-z0-9._-]*|\*)\.json$"
)
SERVER_CODEC_PROOF_SCHEMA = "durable-workflow.server-codec-counterfactual/v1"
SERVER_CODEC_PROOF_GLOB = "tests/Fixtures/CodecRegressionProofs/*.json"
SERVER_CODEC_EXECUTOR_FILES = (
    "tests/Support/ServerCodecRegressionBoundary.php",
    "tests/Support/ServerCodecRegressionBoundaryV2.php",
    "tests/Support/ServerCodecRegressionFixture.php",
    "tests/Support/ServerCodecRegressionFixtureExecutor.php",
    "tests/Support/ServerCodecRegressionFixtureExecutorV2.php",
    "tests/Support/ServerCodecRegressionFixtureExecutorV3.php",
    "tests/Support/ServerCodecRegressionLegacyRegistry.php",
)
SERVER_CODEC_EXECUTOR_CLASS = "ServerCodecRegressionFixtureExecutor"
SERVER_CODEC_EXECUTOR_METHOD = "exercise"
SERVER_CODEC_BOUNDARY_PROXIES = {
    "Avro": r"\Tests\Support\ServerCodecRegressionBoundary",
    "Serializer": r"\Tests\Support\ServerCodecRegressionBoundary",
    "CodecRegistry": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
    "PayloadCodecContract": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
    "PayloadEnvelopeResolver": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
    "AvroPayloadEnvelopeResolver": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
}
SERVER_JSON_CODEC_BOUNDARY_PROXIES = {
    **SERVER_CODEC_BOUNDARY_PROXIES,
    "Avro": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
    "Serializer": r"\Tests\Support\ServerCodecRegressionBoundaryV2",
}
SERVER_CODEC_BOUNDARY_EVIDENCE_PREFIX = "durable-workflow-codec-boundary/v1:"
SERVER_CODEC_BOUNDARY_METHODS = {
    "Avro": {
        "serialize": "serialize",
        "unserialize": "unserialize",
    },
    "Serializer": {
        "serializeWithCodec": "serializeWithCodec",
        "unserializeWithCodec": "unserializeWithCodec",
    },
    "CodecRegistry": {
        "canonicalize": "legacyCanonicalize",
        "defaultCodec": "defaultCodec",
        "universal": "legacyUniversal",
    },
    "PayloadCodecContract": {
        "canonicalize": "canonicalize",
        "universal": "universal",
    },
    "PayloadEnvelopeResolver": {
        "resolve": "legacyResolve",
        "resolveToArray": "legacyResolveToArray",
        "resolveCommandPayloadWithCodec": "legacyResolveCommandPayloadWithCodec",
    },
    "AvroPayloadEnvelopeResolver": {
        "resolve": "avroResolve",
        "resolveToArray": "avroResolveToArray",
        "resolveCommandPayloadWithCodec": "avroResolveCommandPayloadWithCodec",
    },
}
SERVER_DIRECT_CODEC_CLASSES = ("Avro", "Json", "Base64", "Y")
SERVER_DIRECT_CODEC_CLASS_PATTERN = "|".join(
    re.escape(class_name) for class_name in SERVER_DIRECT_CODEC_CLASSES
)
SERVER_CODEC_DEPENDENCY = re.compile(
    r"(?i)(?<![A-Za-z0-9_])(?:Avro|CodecRegistry|Serializer|SerializerInterface|"
    r"PayloadCodecContract|PayloadEnvelopeResolver|ExternalPayloads|"
    r"ExternalPayloadEnvelopeService)\b"
)
SERVER_CODEC_OPERATION = re.compile(
    r"(?ix)(?:"
    r"\b(?:serializeWithCodec|unserializeWithCodec|canonicalize|defaultCodec|"
    r"workerEnvelope|historyPayload|historyValue|resolveCommandPayloadWithCodec|"
    r"resolveToArray|externalizeForNamespace|wireEnvelope|storedEnvelope)\s*\("
    rf"|\b(?:{SERVER_DIRECT_CODEC_CLASS_PATTERN})\s*::\s*"
    r"(?:serialize|unserialize)\s*\("
    r"|\bSerializer\s*::\s*(?:serialize|unserialize)\s*\("
    r"|\bCodecRegistry\s*::\s*[A-Za-z_][A-Za-z0-9_]*\s*\("
    r"|\b[A-Za-z_][A-Za-z0-9_]*Codec[A-Za-z0-9_]*\s*\("
    r")"
)
SERVER_CODEC_VARIABLE_MARKER = re.compile(
    r"(?i)(?:\$[A-Za-z_][A-Za-z0-9_]*codec\b|\$(?:blob|wire)\b)"
)
PROOF_BRANCH_TOKENS = {
    "&&",
    "and",
    "case",
    "catch",
    "default",
    "die",
    "do",
    "else",
    "elseif",
    "exit",
    "finally",
    "for",
    "foreach",
    "goto",
    "if",
    "include",
    "include_once",
    "match",
    "or",
    "require",
    "require_once",
    "return",
    "switch",
    "throw",
    "try",
    "while",
    "xor",
    "yield",
    "yield from",
    "||",
    "?",
    "??",
    "??=",
    "?->",
}
PROOF_UNTRUSTED_CAPABILITIES = {
    "apache_getenv",
    "call_user_func",
    "call_user_func_array",
    "class_alias",
    "curl_exec",
    "directoryiterator",
    "eval",
    "exec",
    "file",
    "file_get_contents",
    "filesystemiterator",
    "fopen",
    "forward_static_call",
    "forward_static_call_array",
    "get_cfg_var",
    "get_included_files",
    "get_required_files",
    "getenv",
    "glob",
    "highlight_file",
    "include",
    "include_once",
    "ini_get",
    "opendir",
    "parse_ini_file",
    "passthru",
    "php_strip_whitespace",
    "popen",
    "proc_open",
    "readfile",
    "readdir",
    "recursivedirectoryiterator",
    "recursiveiteratoriterator",
    "reflectionclass",
    "reflectionfunction",
    "reflectionmethod",
    "reflectionobject",
    "reflectionproperty",
    "require",
    "require_once",
    "scandir",
    "shell_exec",
    "show_source",
    "spl_autoload_register",
    "spl_autoload_unregister",
    "splfileobject",
    "stream_get_contents",
    "stream_socket_client",
    "system",
    "uopz_set_return",
}
PROOF_INDIRECT_CALLABLE_APIS = {
    "array_filter",
    "array_map",
    "array_reduce",
    "array_udiff",
    "array_udiff_assoc",
    "array_udiff_uassoc",
    "array_uintersect",
    "array_uintersect_assoc",
    "array_uintersect_uassoc",
    "array_walk",
    "array_walk_recursive",
    "fromcallable",
    "header_register_callback",
    "iterator_apply",
    "ob_start",
    "pcntl_signal",
    "preg_replace_callback",
    "preg_replace_callback_array",
    "register_shutdown_function",
    "register_tick_function",
    "session_set_save_handler",
    "set_error_handler",
    "set_exception_handler",
    "uasort",
    "uksort",
    "usort",
}
PROOF_UNTRUSTED_OBJECT_TYPES = {
    "pendingprocess",
    "process",
}
PROOF_UNTRUSTED_PROCESS_METHODS = {
    "mustrun",
    "restart",
    "run",
    "signal",
    "start",
    "stop",
    "wait",
    "waituntil",
}
PROOF_SHORT_CIRCUIT_METHODS = {
    "marktestincomplete",
    "marktestskipped",
}
ZERO_COMMIT = re.compile(r"^0+$")
LEGACY_MALFORMED_WIRE_REPAIRS = {
    "%%%": "JSUl",
}
LEGACY_FRAMING_WIRE_REPAIRS = {
    (
        "wwHioz3/VYAiNwrWBWR3LWV4dGVybmFsLXBheWxvYWQ6djE6ZXlKamIyUmxZeUk2SW1GMmNtOGlM"
        "Q0psZUhSbGNtNWhiRjl6ZEc5eVlXZGxJanA3SW1OdlpHVmpJam9pWVhaeWJ5SXNJbk5qYUdWdFlT"
        "STZJbVIxY21GaWJHVXRkMjl5YTJac2IzY3Vkakl1WlhoMFpYSnVZV3d0Y0dGNWJHOWhaQzF5Wlda"
        "bGNtVnVZMlV1ZGpFaUxDSnphR0V5TlRZaU9pSmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0Zo"
        "WVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhJ"
        "aXdpYzJsNlpWOWllWFJsY3lJNk1USTRMQ0oxY21raU9pSm1hV3hsT2k4dkwzVnVZWFpoYVd4aFlt"
        "eGxMMkp2YjNSemRISmhjQzF3Y205dlppNWhkbkp2SW4xOQ=="
    ): (
        "wwHioz3/VYAiNwrGBWR3LWV4dGVybmFsLXBheWxvYWQ6djE6ZXlKamIyUmxZeUk2SW1GMmNtOGlM"
        "Q0psZUhSbGNtNWhiRjl6ZEc5eVlXZGxJanA3SW1OdlpHVmpJam9pWVhaeWJ5SXNJbk5qYUdWdFlT"
        "STZJbVIxY21GaWJHVXRkMjl5YTJac2IzY3Vkakl1WlhoMFpYSnVZV3d0Y0dGNWJHOWhaQzF5Wlda"
        "bGNtVnVZMlV1ZGpFaUxDSnphR0V5TlRZaU9pSmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0Zo"
        "WVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoWVdGaFlXRmhZV0ZoSWl3aWMybDZa"
        "VjlpZVhSbGN5STZNVEk0TENKMWNta2lPaUptYVd4bE9pOHZMM1Z1WVhaaGFXeGhZbXhsTDJKdmIz"
        "UnpkSEpoY0Mxd2NtOXZaaTVoZG5KdkluMTk="
    ),
}


class CorpusError(RuntimeError):
    """The regression-corpus contract is not satisfied."""


@dataclass(frozen=True)
class Evidence:
    category: str
    identity: str
    path: str
    protocol_version: str
    semantic_digest: str
    supersedes: tuple[str, ...] = ()


@dataclass(frozen=True)
class CounterfactualProof:
    path: str
    fixture: str
    test: str
    boundaries: tuple[str, ...]


@dataclass(frozen=True)
class PhpMethodUnit:
    name: str
    source: str
    signature: str


@dataclass(frozen=True)
class PhpStructure:
    methods: Mapping[tuple[str, int], PhpMethodUnit]
    top_level_source: str
    top_level_signature: str
    valid: bool


@dataclass(frozen=True)
class PhpCodecChange:
    related: bool
    review_required: bool


def _canonical_digest(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest()


def _object(value: Any, context: str) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise CorpusError(f"{context} must be an object")
    return value


def _list(value: Any, context: str, *, nonempty: bool = False) -> Sequence[Any]:
    if not isinstance(value, Sequence) or isinstance(value, str | bytes):
        raise CorpusError(f"{context} must be an array")
    if nonempty and not value:
        raise CorpusError(f"{context} must not be empty")
    return value


def _string(value: Any, context: str) -> str:
    if not isinstance(value, str) or not value:
        raise CorpusError(f"{context} must be a non-empty string")
    return value


def _nullable_string(value: Any, context: str) -> str | None:
    if value is None:
        return None
    return _string(value, context)


def _unique_strings(value: Any, context: str, *, allowed: set[str] | None = None) -> tuple[str, ...]:
    values = tuple(_string(item, f"{context}[]") for item in _list(value, context, nonempty=True))
    if len(values) != len(set(values)):
        raise CorpusError(f"{context} contains duplicates")
    if allowed is not None and not set(values) <= allowed:
        raise CorpusError(f"{context} contains unsupported values: {sorted(set(values) - allowed)}")
    return values


def _json(content: bytes, path: str) -> Mapping[str, Any]:
    try:
        value = json.loads(content)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise CorpusError(f"{path} is not valid UTF-8 JSON: {error}") from error
    return _object(value, path)


def _repository_path(value: Any, context: str) -> str:
    path = _string(value, context)
    parsed = PurePosixPath(path)
    if (
        parsed.is_absolute()
        or parsed.as_posix() != path
        or "." in parsed.parts
        or ".." in parsed.parts
    ):
        raise CorpusError(f"{context} must be a normalized repository-relative path")
    return parsed.as_posix()


def _canonical_base64(value: str, context: str) -> str:
    try:
        decoded = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError) as error:
        raise CorpusError(f"{context} is not canonical base64") from error
    canonical = base64.b64encode(decoded).decode("ascii")
    if value != canonical:
        raise CorpusError(f"{context} is not canonical base64")
    return canonical


def _canonical_wire_replacement(value: str) -> str | None:
    """Return the only permitted canonical replacement for a legacy wire."""

    try:
        decoded = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError):
        return LEGACY_MALFORMED_WIRE_REPAIRS.get(value)

    canonical = base64.b64encode(decoded).decode("ascii")
    return canonical if canonical != value else None


def _canonical_wire_migration(base_content: bytes, current_content: bytes) -> bool:
    """Allow enumerated primary-frame repairs and malformed-frame canonicalization."""

    try:
        base_document = json.loads(base_content)
        current_document = json.loads(current_content)
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False
    if not isinstance(base_document, dict) or not isinstance(current_document, dict):
        return False

    base_framing = base_document.get("framing")
    current_framing = current_document.get("framing")
    if isinstance(base_framing, dict) and isinstance(current_framing, dict):
        base_wire = base_framing.get("wire_base64")
        current_wire = current_framing.get("wire_base64")
        if base_wire != current_wire:
            if (
                not isinstance(base_wire, str)
                or not isinstance(current_wire, str)
                or LEGACY_FRAMING_WIRE_REPAIRS.get(base_wire) != current_wire
            ):
                return False
            try:
                _canonical_base64(current_wire, "current.framing.wire_base64")
            except CorpusError:
                return False
            base_framing["wire_base64"] = current_wire
            return base_document == current_document

    base_frames = base_document.get("malformed_frames")
    current_frames = current_document.get("malformed_frames")
    if not isinstance(base_frames, list) or not isinstance(current_frames, list):
        return False
    if len(base_frames) != len(current_frames):
        return False

    migrated = False
    for index, (base_frame, current_frame) in enumerate(
        zip(base_frames, current_frames, strict=True)
    ):
        if not isinstance(base_frame, dict) or not isinstance(current_frame, dict):
            return False
        base_wire = base_frame.get("wire_base64")
        current_wire = current_frame.get("wire_base64")
        if base_wire == current_wire:
            continue
        if not isinstance(base_wire, str) or not isinstance(current_wire, str):
            return False
        if current_wire != _canonical_wire_replacement(base_wire):
            return False
        try:
            _canonical_base64(
                current_wire,
                f"current.malformed_frames[{index}].wire_base64",
            )
        except CorpusError:
            return False
        base_frame["wire_base64"] = current_wire
        migrated = True

    return migrated and base_document == current_document


def _canonical_command_type(value: str) -> str:
    """Normalize runtime command class names to their wire discriminator."""

    words = re.sub(r"(.)([A-Z][a-z]+)", r"\1_\2", value)
    return re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", words).lower()


def _canonical_replay_command(value: Any) -> Any:
    """Normalize the command forms accepted by replay consumers."""

    if not isinstance(value, Mapping):
        return value

    command = dict(value)
    command_type = command.get("command_type")
    if not isinstance(command_type, str) or not command_type:
        return command

    wire_type = _canonical_command_type(command_type)
    declared_type = command.get("type")
    if declared_type is None or declared_type == wire_type:
        command.pop("command_type")
        command["type"] = wire_type
    return command


def _canonical_replay_commands(value: Any) -> Any:
    if not isinstance(value, Sequence) or isinstance(value, str | bytes):
        return value
    return [_canonical_replay_command(command) for command in value]


def _merge_replay_assertions(left: Any, right: Any, context: str) -> Any:
    """Merge two compatible partial assertions over the same replay output."""

    if isinstance(left, Mapping) and isinstance(right, Mapping):
        merged = dict(left)
        for key, value in right.items():
            if key in merged:
                merged[key] = _merge_replay_assertions(
                    merged[key],
                    value,
                    f"{context}.{key}",
                )
            else:
                merged[key] = value
        return merged

    if (
        isinstance(left, Sequence)
        and not isinstance(left, str | bytes)
        and isinstance(right, Sequence)
        and not isinstance(right, str | bytes)
    ):
        if len(left) != len(right):
            raise CorpusError(f"replay command assertions conflict at {context}")
        return [
            _merge_replay_assertions(left_item, right_item, f"{context}[{index}]")
            for index, (left_item, right_item) in enumerate(
                zip(left, right, strict=True)
            )
        ]

    if left != right:
        raise CorpusError(f"replay command assertions conflict at {context}")
    return left


def _canonical_executed_commands(
    command_sequence: Any,
    expected: Mapping[str, Any],
) -> Any:
    """Collapse every consumer-supported command assertion onto one output."""

    executed_commands = (
        _canonical_replay_commands(command_sequence)
        if command_sequence is not None
        else None
    )
    expected_sequence = expected.get("command_sequence")
    if expected_sequence is not None:
        canonical_expected = _canonical_replay_commands(expected_sequence)
        executed_commands = (
            canonical_expected
            if executed_commands is None
            else _merge_replay_assertions(
                executed_commands,
                canonical_expected,
                "command_sequence",
            )
        )

    first_command = {
        key: value
        for key, value in expected.items()
        if key != "command_sequence"
    }
    if first_command:
        canonical_first = _canonical_replay_command(first_command)
        if executed_commands is None:
            executed_commands = [canonical_first]
        elif (
            not isinstance(executed_commands, Sequence)
            or isinstance(executed_commands, str | bytes)
            or len(executed_commands) != 1
        ):
            raise CorpusError(
                "flattened expected command requires exactly one executed command"
            )
        else:
            executed_commands = [
                _merge_replay_assertions(
                    executed_commands[0],
                    canonical_first,
                    "command_sequence[0]",
                )
            ]

    return executed_commands


def _replay_semantic(
    *,
    workflow_type: str,
    workflow_input: Any,
    history: Any,
    command_sequence: Any,
    expected: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Project every replay representation onto consumer-executed values."""

    return {
        "workflow": {"type": workflow_type, "input": workflow_input},
        "history": history,
        "executed_commands": _canonical_executed_commands(
            command_sequence,
            expected,
        ),
    }


def _codec_semantic(
    *,
    value: Mapping[str, Any] | None,
    wire: str | Sequence[str] | None,
    operation: str,
    error: str | None,
) -> Mapping[str, Any]:
    """Project every codec representation onto consumer-executed values."""

    return {
        "value": value,
        "wire": wire,
        "failure_policy": {"operation": operation, "error": error},
    }


def _fixture_evidence(
    *,
    category: str,
    identity: str,
    path: str,
    protocol_version: str,
    semantic_value: Any,
    supersedes: tuple[str, ...] = (),
) -> Evidence:
    return Evidence(
        category=category,
        identity=identity,
        path=path,
        protocol_version=protocol_version,
        semantic_digest=_canonical_digest(semantic_value),
        supersedes=supersedes,
    )


def _codec_fixture(document: Mapping[str, Any], path: str, binding: str | None) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != CODEC_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={CODEC_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol = _object(document.get("protocol"), f"{path}.protocol")
    _string(protocol.get("codec"), f"{path}.protocol.codec")
    _string(protocol.get("schema"), f"{path}.protocol.schema")
    version = _string(protocol.get("version"), f"{path}.protocol.version")
    _nullable_string(protocol.get("fingerprint"), f"{path}.protocol.fingerprint")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")

    value = _object(document.get("value"), f"{path}.value")
    _string(value.get("type"), f"{path}.value.type")
    framing = _object(document.get("framing"), f"{path}.framing")
    _string(framing.get("encoding"), f"{path}.framing.encoding")
    wire = _nullable_string(framing.get("wire_base64"), f"{path}.framing.wire_base64")
    policy = _object(document.get("failure_policy"), f"{path}.failure_policy")
    operation = _string(policy.get("operation"), f"{path}.failure_policy.operation")
    if operation not in {"round_trip", "decode_reject", "encode_reject"}:
        raise CorpusError(f"{path}.failure_policy.operation is unsupported")
    error = _nullable_string(policy.get("error"), f"{path}.failure_policy.error")
    if operation in {"round_trip", "decode_reject"} and wire is None:
        raise CorpusError(f"{path} must include wire_base64 for {operation}")
    if operation == "round_trip" and error is not None:
        raise CorpusError(f"{path} round-trip evidence cannot declare an error")
    if operation != "round_trip" and error is None:
        raise CorpusError(f"{path} rejection evidence must declare its stable error policy")
    canonical_wire = (
        _canonical_base64(wire, f"{path}.framing.wire_base64")
        if wire is not None
        else None
    )

    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _codec_semantic(
        value=value if operation == "encode_reject" else None,
        wire=canonical_wire,
        operation=operation,
        error=error,
    )
    return [
        _fixture_evidence(
            category="codec",
            identity=identity,
            path=path,
            protocol_version=version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _replay_fixture(document: Mapping[str, Any], path: str, binding: str | None) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != REPLAY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={REPLAY_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol_version = _string(document.get("protocol_version"), f"{path}.protocol_version")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")
    workflow = _object(document.get("workflow"), f"{path}.workflow")
    _string(workflow.get("type"), f"{path}.workflow.type")
    history = document.get("history")
    commands = document.get("command_sequence")
    if history is None and commands is None:
        raise CorpusError(f"{path} must include history or command_sequence")
    if history is not None:
        _list(history, f"{path}.history", nonempty=True)
    if commands is not None:
        _list(commands, f"{path}.command_sequence", nonempty=True)
    expected = _object(document.get("expected"), f"{path}.expected")
    if not expected:
        raise CorpusError(f"{path}.expected must not be empty")
    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _replay_semantic(
        workflow_type=workflow["type"],
        workflow_input=workflow.get("input", workflow.get("arguments", [])),
        history=history if history is not None else [],
        command_sequence=commands,
        expected=expected,
    )
    return [
        _fixture_evidence(
            category="replay",
            identity=identity,
            path=path,
            protocol_version=protocol_version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _avro_golden_fixture(document: Mapping[str, Any], path: str) -> list[Evidence]:
    _string(document.get("schema"), f"{path}.schema")
    _string(document.get("fingerprint"), f"{path}.fingerprint")
    version = "avro-value-v1"
    evidence: list[Evidence] = []
    sections = {
        "case": _list(document.get("cases"), f"{path}.cases", nonempty=True),
        "malformed": _list(document.get("malformed_frames"), f"{path}.malformed_frames", nonempty=True),
        "alternate": _list(document.get("alternate_map_orders"), f"{path}.alternate_map_orders", nonempty=True),
    }
    for section, entries in sections.items():
        for index, raw_entry in enumerate(entries):
            entry = _object(raw_entry, f"{path}.{section}[{index}]")
            name = _string(entry.get("name"), f"{path}.{section}[{index}].name")
            wire = entry.get("wire_base64")
            if section == "alternate":
                semantic_wire = sorted(
                    _canonical_base64(
                        wire_value,
                        f"{path}.{section}[{index}].wire_base64[]",
                    )
                    for wire_value in _unique_strings(
                        wire,
                        f"{path}.{section}[{index}].wire_base64",
                    )
                )
            elif section == "case":
                wire_value = _string(wire, f"{path}.{section}[{index}].wire_base64")
                semantic_wire = _canonical_base64(
                    wire_value,
                    f"{path}.{section}[{index}].wire_base64",
                )
            elif not isinstance(wire, str):
                raise CorpusError(f"{path}.{section}[{index}].wire_base64 must be a string")
            else:
                semantic_wire = _canonical_base64(
                    wire,
                    f"{path}.{section}[{index}].wire_base64",
                )
            semantic = _codec_semantic(
                value=None,
                wire=semantic_wire,
                operation="decode_reject" if section == "malformed" else "round_trip",
                error=entry.get("error") if section == "malformed" else None,
            )
            evidence.append(
                _fixture_evidence(
                    category="codec",
                    identity=f"{version}:{section}:{name}",
                    path=path,
                    protocol_version=version,
                    semantic_value=semantic,
                )
            )
    return evidence


def _golden_history_fixture(
    document: Mapping[str, Any],
    path: str,
    *,
    require_single_case: bool,
) -> list[Evidence]:
    if document.get("fixture_schema") != GOLDEN_HISTORY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={GOLDEN_HISTORY_SCHEMA}")
    source = _object(document.get("source"), f"{path}.source")
    runtime = _string(source.get("runtime"), f"{path}.source.runtime")
    version = _string(source.get("version"), f"{path}.source.version")
    protocol_version = _string(
        source.get("worker_protocol_version"),
        f"{path}.source.worker_protocol_version",
    )
    cases = _list(document.get("cases"), f"{path}.cases", nonempty=True)
    if require_single_case and len(cases) != 1:
        raise CorpusError(f"new golden-history fixture {path} must contain exactly one minimal case")
    evidence: list[Evidence] = []
    for index, raw_case in enumerate(cases):
        case = _object(raw_case, f"{path}.cases[{index}]")
        name = _string(case.get("name"), f"{path}.cases[{index}].name")
        history = _list(case.get("history"), f"{path}.cases[{index}].history", nonempty=True)
        expected = case.get("expected", case.get("expected_state"))
        _object(expected, f"{path}.cases[{index}].expected")
        workflow_type = case.get("workflow_type", case.get("scenario"))
        _string(workflow_type, f"{path}.cases[{index}].workflow identity")
        semantic = _replay_semantic(
            workflow_type=workflow_type,
            workflow_input=case.get("start_input", []),
            history=history,
            command_sequence=case.get("command_sequence"),
            expected=expected,
        )
        evidence.append(
            _fixture_evidence(
                category="replay",
                identity=f"{runtime}@{version}:{name}",
                path=path,
                protocol_version=protocol_version,
                semantic_value=semantic,
            )
        )
    return evidence


def _run(command: Sequence[str], root: Path, *, check: bool = True) -> str:
    result = subprocess.run(
        command,
        cwd=root,
        check=False,
        capture_output=True,
        text=True,
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise CorpusError(f"{' '.join(command)} failed: {detail}")
    return result.stdout


def _policy(document: Mapping[str, Any], path: str) -> Mapping[str, Any]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("schema") != POLICY_SCHEMA:
        raise CorpusError(f"{path} must declare schema={POLICY_SCHEMA}")
    _string(document.get("repository"), f"{path}.repository")
    binding = document.get("binding")
    if binding is not None and binding not in SUPPORTED_BINDINGS:
        raise CorpusError(f"{path}.binding is unsupported")
    categories = _object(document.get("categories"), f"{path}.categories")
    if not categories or not set(categories) <= SUPPORTED_CATEGORIES:
        raise CorpusError(f"{path}.categories must contain only replay and/or codec")
    for name, raw_category in categories.items():
        category = _object(raw_category, f"{path}.categories.{name}")
        fixtures = _list(category.get("fixtures"), f"{path}.categories.{name}.fixtures", nonempty=True)
        for index, raw_fixture in enumerate(fixtures):
            fixture = _object(raw_fixture, f"{path}.categories.{name}.fixtures[{index}]")
            _string(fixture.get("glob"), f"{path}.categories.{name}.fixtures[{index}].glob")
            fixture_format = _string(
                fixture.get("format"),
                f"{path}.categories.{name}.fixtures[{index}].format",
            )
            if fixture_format not in SUPPORTED_FORMATS:
                raise CorpusError(f"{path}.categories.{name}.fixtures[{index}].format is unsupported")
            if not fixture_format.startswith(name) and not (
                name == "codec" and fixture_format == "avro-value-golden-v1"
            ) and not (name == "replay" and fixture_format == "golden-history-v1"):
                raise CorpusError(f"{path}.categories.{name} contains a fixture for another category")
        guards = _list(category.get("guards"), f"{path}.categories.{name}.guards", nonempty=True)
        for index, raw_guard in enumerate(guards):
            guard = _object(raw_guard, f"{path}.categories.{name}.guards[{index}]")
            _string(guard.get("glob"), f"{path}.categories.{name}.guards[{index}].glob")
            patterns = guard.get("content_patterns")
            if patterns is not None:
                for pattern in _unique_strings(
                    patterns,
                    f"{path}.categories.{name}.guards[{index}].content_patterns",
                ):
                    try:
                        re.compile(pattern)
                    except re.error as error:
                        raise CorpusError(f"invalid guard regex {pattern!r}: {error}") from error
    return document


def _require_owned_categories(policy: Mapping[str, Any], path: str) -> None:
    repository = _string(policy["repository"], f"{path}.repository")
    owned = OWNED_CATEGORIES.get(repository)
    categories = set(_object(policy["categories"], f"{path}.categories"))
    if owned is not None and not categories <= owned:
        raise CorpusError(
            f"{path}.categories contains categories not owned by {repository}: "
            f"{sorted(categories - owned)}"
        )


def _php_fixture_glob_matches(path: str, pattern: str) -> bool:
    path_parts = PurePosixPath(path).parts
    pattern_parts = PurePosixPath(pattern).parts
    if len(path_parts) != len(pattern_parts):
        return False
    for path_part, pattern_part in zip(path_parts, pattern_parts, strict=True):
        if pattern_part == "*.json":
            if path_part.startswith(".") or not path_part.endswith(".json"):
                return False
        elif path_part != pattern_part:
            return False
    return True


def _require_executable_inventory(
    policy: Mapping[str, Any],
    path: str,
    files: Mapping[str, bytes],
) -> None:
    repository = _string(policy["repository"], f"{path}.repository")
    if repository != "server":
        return
    if policy.get("binding") != "php":
        raise CorpusError(f"{path}.binding must be php for the server codec runner")

    categories = _object(policy["categories"], f"{path}.categories")
    codec = _object(categories["codec"], f"{path}.categories.codec")
    fixtures = _list(codec["fixtures"], f"{path}.categories.codec.fixtures")
    for index, raw_fixture in enumerate(fixtures):
        fixture = _object(
            raw_fixture,
            f"{path}.categories.codec.fixtures[{index}]",
        )
        fixture_format = _string(
            fixture["format"],
            f"{path}.categories.codec.fixtures[{index}].format",
        )
        if fixture_format not in SERVER_CODEC_RUNNER_FORMATS:
            raise CorpusError(
                f"{path}.categories.codec.fixtures[{index}].format is not executed "
                "by the official PHP codec runner"
            )
        fixture_glob = _string(
            fixture["glob"],
            f"{path}.categories.codec.fixtures[{index}].glob",
        )
        if PORTABLE_PHP_FIXTURE_GLOB.fullmatch(fixture_glob) is None:
            raise CorpusError(
                f"{path}.categories.codec.fixtures[{index}].glob is not portable "
                "to the official PHP codec runner"
            )
        unexecuted_paths = sorted(
            candidate
            for candidate in files
            if _matches(candidate, fixture_glob)
            and not _php_fixture_glob_matches(candidate, fixture_glob)
        )
        if unexecuted_paths:
            raise CorpusError(
                f"{path}.categories.codec.fixtures[{index}].glob selects fixture "
                "paths that PHP glob() does not execute: "
                f"{unexecuted_paths}"
            )


def _require_policy_extension(
    base_policy: Mapping[str, Any],
    current_policy: Mapping[str, Any],
    base_files: Mapping[str, bytes],
    path: str,
) -> None:
    for field in ("repository", "binding"):
        if current_policy.get(field) != base_policy.get(field):
            raise CorpusError(f"{path}.{field} cannot change from the base policy")

    base_categories = _object(base_policy["categories"], "base categories")
    current_categories = _object(current_policy["categories"], "current categories")
    for category_name, raw_base_category in base_categories.items():
        base_category = _object(raw_base_category, f"base categories.{category_name}")
        if category_name not in current_categories:
            selected_paths = {
                fixture_path
                for raw_fixture in _list(
                    base_category["fixtures"],
                    f"base categories.{category_name}.fixtures",
                )
                for fixture_path in base_files
                if fnmatch.fnmatchcase(
                    fixture_path,
                    _string(_object(raw_fixture, "fixture")["glob"], "fixture.glob"),
                )
            }
            if selected_paths:
                raise CorpusError(
                    f"{path}.categories.{category_name} cannot be removed from the base policy "
                    f"while it selects fixtures: {sorted(selected_paths)}"
                )
            continue
        current_category = _object(
            current_categories[category_name],
            f"current categories.{category_name}",
        )
        for selector_type in ("fixtures", "guards"):
            base_selectors = _list(
                base_category[selector_type],
                f"base categories.{category_name}.{selector_type}",
            )
            current_selectors = _list(
                current_category[selector_type],
                f"current categories.{category_name}.{selector_type}",
            )
            for base_selector in base_selectors:
                if base_selector not in current_selectors:
                    raise CorpusError(
                        f"{path}.categories.{category_name}.{selector_type} cannot remove "
                        "or change a base selector"
                    )


def _tracked_worktree_files(root: Path) -> dict[str, bytes]:
    paths = _run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        root,
    ).split("\0")
    return {
        path: (root / path).read_bytes()
        for path in paths
        if path and (root / path).is_file()
    }


def _ref_files(root: Path, ref: str) -> dict[str, bytes]:
    paths = _run(["git", "ls-tree", "-r", "--name-only", "-z", ref], root).split("\0")
    return {
        path: _run(["git", "show", f"{ref}:{path}"], root).encode()
        for path in paths
        if path
    }


def _matches(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def _inventory(
    policy: Mapping[str, Any],
    files: Mapping[str, bytes],
    *,
    new_paths: set[str] | None = None,
) -> list[Evidence]:
    binding = policy.get("binding")
    evidence: list[Evidence] = []
    selected_paths: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        category = _object(raw_category, f"categories.{category_name}")
        for raw_fixture in _list(category["fixtures"], f"categories.{category_name}.fixtures"):
            fixture = _object(raw_fixture, f"categories.{category_name}.fixtures[]")
            pattern = _string(fixture["glob"], "fixture.glob")
            fixture_format = _string(fixture["format"], "fixture.format")
            for path in sorted(candidate for candidate in files if _matches(candidate, pattern)):
                if path in selected_paths:
                    raise CorpusError(f"fixture path {path} is selected more than once")
                selected_paths.add(path)
                document = _json(files[path], path)
                if fixture_format == "codec-regression-v1":
                    parsed = _codec_fixture(document, path, binding if isinstance(binding, str) else None)
                elif fixture_format == "replay-regression-v1":
                    parsed = _replay_fixture(document, path, binding if isinstance(binding, str) else None)
                elif fixture_format == "avro-value-golden-v1":
                    parsed = _avro_golden_fixture(document, path)
                else:
                    parsed = _golden_history_fixture(
                        document,
                        path,
                        require_single_case=new_paths is not None and path in new_paths,
                    )
                if any(item.category != category_name for item in parsed):
                    raise CorpusError(f"{path} produced evidence for the wrong category")
                evidence.extend(parsed)

    identities = Counter(item.identity for item in evidence)
    repeated_identities = sorted(identity for identity, count in identities.items() if count > 1)
    if repeated_identities:
        raise CorpusError(f"duplicate fixture identities: {repeated_identities}")
    semantics = Counter((item.category, item.semantic_digest) for item in evidence)
    duplicate_semantics = sorted(key for key, count in semantics.items() if count > 1)
    if duplicate_semantics:
        paths = {
            key: sorted(item.path for item in evidence if (item.category, item.semantic_digest) == key)
            for key in duplicate_semantics
        }
        raise CorpusError(f"duplicate semantic fixtures: {paths}")
    return evidence


def _fixture_paths(policy: Mapping[str, Any], files: Mapping[str, bytes]) -> set[str]:
    return {
        path
        for raw_category in _object(policy["categories"], "categories").values()
        for raw_fixture in _list(
            _object(raw_category, "category")["fixtures"],
            "category.fixtures",
        )
        for path in files
        if _matches(path, _string(_object(raw_fixture, "fixture")["glob"], "fixture.glob"))
    }


def _changed_paths(root: Path, base_ref: str) -> tuple[set[str], set[str]]:
    output = _run(["git", "diff", "--name-status", "--find-renames", base_ref, "--"], root)
    changed: set[str] = set()
    added: set[str] = set()
    for line in output.splitlines():
        parts = line.split("\t")
        status = parts[0]
        paths = parts[1:]
        if not paths:
            continue
        changed.update(paths)
        if status.startswith("A"):
            added.add(paths[-1])
    untracked = {
        path
        for path in _run(
            ["git", "ls-files", "--others", "--exclude-standard"],
            root,
        ).splitlines()
        if path
    }
    return changed | untracked, added | untracked


def _php_lexical_views(
    source: str,
) -> tuple[str, str, tuple[tuple[int, int], ...], bool]:
    """Mask PHP comments and literals without changing source offsets."""

    structural = list(source)
    uncommented = list(source)
    literals: list[tuple[int, int]] = []
    index = 0
    while index < len(source):
        if source.startswith("//", index) or (
            source[index] == "#" and not source.startswith("#[", index)
        ):
            end = source.find("\n", index + 1)
            end = len(source) if end == -1 else end
            for position in range(index, end):
                structural[position] = " "
                uncommented[position] = " "
            index = end
            continue
        if source.startswith("/*", index):
            end = source.find("*/", index + 2)
            if end == -1:
                return source, source, (), False
            end += 2
            for position in range(index, end):
                if source[position] not in "\r\n":
                    structural[position] = " "
                    uncommented[position] = " "
            index = end
            continue
        if source.startswith("<<<", index):
            opener = re.match(
                r"<<<[ \t]*(?:'([A-Za-z_][A-Za-z0-9_]*)'|"
                r'"([A-Za-z_][A-Za-z0-9_]*)"|'
                r"([A-Za-z_][A-Za-z0-9_]*))[ \t]*\r?\n",
                source[index:],
            )
            if opener is None:
                return source, source, (), False
            label = next(group for group in opener.groups() if group is not None)
            content_start = index + opener.end()
            terminator = re.search(
                rf"(?m)^[ \t]*{re.escape(label)}(?=;|,|\)|\]|\r?$)",
                source[content_start:],
            )
            if terminator is None:
                return source, source, (), False
            end = content_start + terminator.end()
            literals.append((index, end))
            for position in range(index, end):
                if source[position] not in "\r\n":
                    structural[position] = " "
            index = end
            continue
        if source[index] in {"'", '"', "`"}:
            quote = source[index]
            start = index
            index += 1
            while index < len(source):
                if source[index] == "\\":
                    index += 2
                    continue
                if source[index] == quote:
                    index += 1
                    break
                index += 1
            else:
                return source, source, (), False
            literals.append((start, min(index, len(source))))
            for position in range(start, min(index, len(source))):
                if source[position] not in "\r\n":
                    structural[position] = " "
            continue
        index += 1

    return "".join(structural), "".join(uncommented), tuple(literals), True


def _php_signature(source: str) -> tuple[str, bool]:
    """Return a comment/format-insensitive signature that preserves literals."""

    _, uncommented, literals, valid = _php_lexical_views(source)
    if not valid:
        return source, False
    literal_ends = {start: end for start, end in literals}
    signature: list[str] = []
    index = 0
    while index < len(uncommented):
        literal_end = literal_ends.get(index)
        if literal_end is not None:
            literal = source[index:literal_end].encode()
            signature.append(f"<literal:{hashlib.sha256(literal).hexdigest()}>")
            index = literal_end
            continue
        if not uncommented[index].isspace():
            signature.append(uncommented[index])
        index += 1
    return "".join(signature), True


def _php_structural_signature(source: str) -> tuple[str, bool]:
    """Return executable PHP structure while ignoring comments and literals."""

    structural, _, _, valid = _php_lexical_views(source)
    if not valid:
        return source, False

    return "".join(character for character in structural if not character.isspace()), True


def _php_balanced_end(
    masked: str, start: int, opening: str, closing: str
) -> int | None:
    depth = 0
    for index in range(start, len(masked)):
        if masked[index] == opening:
            depth += 1
        elif masked[index] == closing:
            depth -= 1
            if depth == 0:
                return index + 1
    return None


def _php_balanced(masked: str) -> bool:
    pairs = {")": "(", "]": "[", "}": "{"}
    stack: list[str] = []
    for character in masked:
        if character in "([{":
            stack.append(character)
        elif character in pairs:
            if not stack or stack.pop() != pairs[character]:
                return False
    return not stack


def _php_method_units(source: str) -> PhpStructure:
    """Parse bounded named function units and the state outside those units."""

    if source and not source.lstrip().startswith(("<?php", "<?=")):
        return PhpStructure({}, source, source, False)
    masked, _, _, valid = _php_lexical_views(source)
    if not valid or not _php_balanced(masked):
        return PhpStructure({}, source, source, False)

    methods: dict[tuple[str, int], PhpMethodUnit] = {}
    occurrences: Counter[str] = Counter()
    spans: list[tuple[int, int]] = []
    for function_match in re.finditer(r"\bfunction\b", masked):
        if re.search(r"\buse\s+$", masked[: function_match.start()]) is not None:
            continue
        cursor = function_match.end()
        while cursor < len(masked) and masked[cursor].isspace():
            cursor += 1
        if cursor < len(masked) and masked[cursor] == "&":
            cursor += 1
            while cursor < len(masked) and masked[cursor].isspace():
                cursor += 1
        if cursor < len(masked) and masked[cursor] == "(":
            continue
        name_match = re.match(
            r"[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*",
            masked[cursor:],
        )
        if name_match is None:
            return PhpStructure(methods, source, source, False)
        name = name_match.group(0)
        cursor += name_match.end()
        while cursor < len(masked) and masked[cursor].isspace():
            cursor += 1
        if cursor >= len(masked) or masked[cursor] != "(":
            return PhpStructure(methods, source, source, False)
        parameters_end = _php_balanced_end(masked, cursor, "(", ")")
        if parameters_end is None:
            return PhpStructure(methods, source, source, False)
        body_start = None
        position = parameters_end
        while position < len(masked):
            if masked[position] in {"{", ";"}:
                body_start = position
                break
            if masked[position] == "}" or re.match(
                r"function\b", masked[position:]
            ):
                return PhpStructure(methods, source, source, False)
            position += 1
        if body_start is None:
            return PhpStructure(methods, source, source, False)
        body_end = (
            body_start + 1
            if masked[body_start] == ";"
            else _php_balanced_end(masked, body_start, "{", "}")
        )
        if body_end is None:
            return PhpStructure(methods, source, source, False)
        unit_start = function_match.start()
        line_start = source.rfind("\n", 0, unit_start) + 1
        modifiers = masked[line_start:unit_start]
        if (
            re.fullmatch(
                r"[ \t]*(?:(?:public|protected|private|static|final|abstract|readonly)[ \t]+)*",
                modifiers,
            )
            is not None
        ):
            unit_start = line_start
        method_source = source[unit_start:body_end]
        signature, method_valid = _php_signature(method_source)
        if not method_valid:
            return PhpStructure(methods, source, source, False)
        occurrence = occurrences[name]
        occurrences[name] += 1
        methods[(name, occurrence)] = PhpMethodUnit(name, method_source, signature)
        spans.append((unit_start, body_end))

    merged_spans: list[tuple[int, int]] = []
    for start, end in sorted(spans):
        if merged_spans and start <= merged_spans[-1][1]:
            merged_spans[-1] = (merged_spans[-1][0], max(end, merged_spans[-1][1]))
        else:
            merged_spans.append((start, end))
    top_level_parts: list[str] = []
    cursor = 0
    for start, end in merged_spans:
        top_level_parts.append(source[cursor:start])
        cursor = end
    top_level_parts.append(source[cursor:])
    top_level_source = "".join(top_level_parts)
    top_level_signature, top_level_valid = _php_signature(top_level_source)
    return PhpStructure(
        methods,
        top_level_source,
        top_level_signature,
        top_level_valid,
    )


def _php_has_direct_codec_operation(source: str) -> bool:
    structural, _, literals, valid = _php_lexical_views(source)
    if not valid:
        return True
    if (
        SERVER_CODEC_OPERATION.search(structural) is not None
        or SERVER_CODEC_VARIABLE_MARKER.search(structural) is not None
    ):
        return True
    for start, end in literals:
        literal = source[start:end]
        if len(literal) < 2 or literal[0] not in {"'", '"'}:
            continue
        if literal[1:-1].lower() not in {"codec", "blob", "wire_base64"}:
            continue
        prefix = structural[max(0, start - 64) : start]
        if re.search(r"(?:\[|array_key_exists\s*\()\s*$", prefix, re.IGNORECASE):
            return True
    return False


def _php_method_owns_codec(method: PhpMethodUnit) -> bool:
    structural, _, _, valid = _php_lexical_views(method.source)
    return (
        not valid
        or SERVER_CODEC_DEPENDENCY.search(structural) is not None
        or _php_has_direct_codec_operation(method.source)
    )


def _php_statement_spans(masked: str) -> list[tuple[int, int]]:
    """Return semicolon-terminated PHP statements outside parenthesized headers."""

    spans: list[tuple[int, int]] = []
    start = 0
    parentheses = 0
    brackets = 0
    for index, character in enumerate(masked):
        if character == "(":
            parentheses += 1
        elif character == ")":
            parentheses = max(0, parentheses - 1)
        elif character == "[":
            brackets += 1
        elif character == "]":
            brackets = max(0, brackets - 1)
        elif character == ";" and parentheses == 0 and brackets == 0:
            spans.append((start, index + 1))
            start = index + 1
        elif character in "{}":
            start = index + 1

    return spans


def _php_codec_projection(source: str) -> tuple[str, ...] | None:
    """Project the statements and control flow that can affect codec operations."""

    masked, _, _, valid = _php_lexical_views(source)
    if not valid or not _php_balanced(masked):
        return None

    statements = _php_statement_spans(masked)
    direct: set[tuple[int, int]] = {
        span
        for span in statements
        if _php_has_direct_codec_operation(source[span[0] : span[1]])
    }
    if not direct:
        return ()

    variable_pattern = re.compile(r"\$[A-Za-z_][A-Za-z0-9_]*")
    assignment_pattern = re.compile(
        r"(?P<variable>\$[A-Za-z_][A-Za-z0-9_]*)"
        r"(?:\s*\[[^\]]*\])*\s*(?:\?\?=|\.=|\+=|-=|\*=|/=|=)(?!=|>)"
    )
    relevant_variables = {
        variable
        for start, end in direct
        for variable in variable_pattern.findall(masked[start:end])
        if variable != "$this"
    }
    selected = set(direct)

    changed = True
    while changed:
        changed = False
        for span in statements:
            statement = masked[span[0] : span[1]]
            assigned = {
                match.group("variable")
                for match in assignment_pattern.finditer(statement)
            }
            if not (assigned & relevant_variables):
                continue
            if span not in selected:
                selected.add(span)
                changed = True
            variables = set(variable_pattern.findall(statement)) - {"$this"}
            if not variables.issubset(relevant_variables):
                relevant_variables.update(variables)
                changed = True

    control_spans: set[tuple[int, int]] = set()
    for brace in (index for index, character in enumerate(masked) if character == "{"):
        header_start = max(
            masked.rfind(";", 0, brace),
            masked.rfind("{", 0, brace),
            masked.rfind("}", 0, brace),
        ) + 1
        header = masked[header_start:brace]
        if re.match(
            r"\s*(?:if|elseif|else|for|foreach|while|do|switch|case|catch)\b",
            header,
        ) is None:
            continue
        block_end = _php_balanced_end(masked, brace, "{", "}")
        if block_end is None:
            return None
        header_variables = set(variable_pattern.findall(header)) - {"$this"}
        encloses_selected = any(
            brace < statement_start < block_end
            for statement_start, _ in selected
        )
        if encloses_selected or header_variables & relevant_variables:
            control_spans.add((header_start, brace))

    signatures: list[str] = []
    for start, end in sorted(selected | control_spans):
        signature, segment_valid = _php_signature(source[start:end])
        if not segment_valid:
            return None
        signatures.append(signature)

    return tuple(signatures)


def _php_codec_change_classification(
    base_content: bytes | None,
    current_content: bytes | None,
    *,
    broad_path_guard: bool,
) -> PhpCodecChange:
    """Classify a guarded PHP edit without attempting PHP dataflow analysis."""

    try:
        base_source = base_content.decode("utf-8") if base_content is not None else ""
        current_source = (
            current_content.decode("utf-8") if current_content is not None else ""
        )
    except UnicodeDecodeError:
        return PhpCodecChange(related=True, review_required=False)

    base = _php_method_units(base_source)
    current = _php_method_units(current_source)
    if not base.valid or not current.valid:
        return PhpCodecChange(related=True, review_required=False)

    related = False
    review_required = False
    for key in set(base.methods) | set(current.methods):
        base_method = base.methods.get(key)
        current_method = current.methods.get(key)
        if (
            base_method is not None
            and current_method is not None
            and base_method.signature == current_method.signature
        ):
            continue
        if base_method is not None and current_method is not None:
            base_structure, base_structure_valid = _php_structural_signature(
                base_method.source
            )
            current_structure, current_structure_valid = _php_structural_signature(
                current_method.source
            )
            if (
                base_structure_valid
                and current_structure_valid
                and base_structure == current_structure
                and not _php_has_direct_codec_operation(base_method.source)
                and not _php_has_direct_codec_operation(current_method.source)
            ):
                continue
            if base_method.name == "__construct":
                if _php_has_direct_codec_operation(base_method.source) or (
                    _php_has_direct_codec_operation(current_method.source)
                ):
                    related = True
                elif broad_path_guard:
                    review_required = True
            else:
                base_direct = _php_has_direct_codec_operation(base_method.source)
                current_direct = _php_has_direct_codec_operation(current_method.source)
                if base_direct or current_direct:
                    base_projection = _php_codec_projection(base_method.source)
                    current_projection = _php_codec_projection(current_method.source)
                    if (
                        base_projection is not None
                        and current_projection is not None
                        and base_projection == current_projection
                    ):
                        review_required = True
                    else:
                        related = True
                elif _php_method_owns_codec(base_method) or _php_method_owns_codec(
                    current_method
                ):
                    review_required = True
        elif base_method is not None:
            if _php_method_owns_codec(base_method):
                related = True
        elif current_method is not None and _php_has_direct_codec_operation(
            current_method.source
        ):
            related = True

    top_level_changed = base.top_level_signature != current.top_level_signature
    if top_level_changed:
        if broad_path_guard:
            review_required = True
        elif _php_has_direct_codec_operation(base.top_level_source) or (
            _php_has_direct_codec_operation(current.top_level_source)
        ):
            related = True
        elif SERVER_CODEC_DEPENDENCY.search(base.top_level_source) or (
            SERVER_CODEC_DEPENDENCY.search(current.top_level_source)
        ):
            review_required = True

    if base_content is None and _php_has_direct_codec_operation(current_source):
        related = True
        review_required = True

    return PhpCodecChange(related=related, review_required=review_required)


def _php_codec_relevant_change(
    base_content: bytes | None,
    current_content: bytes | None,
    *,
    broad_path_guard: bool,
) -> bool:
    return _php_codec_change_classification(
        base_content,
        current_content,
        broad_path_guard=broad_path_guard,
    ).related


def _php_lint(root: Path, path: str) -> bool:
    """Validate a changed PHP file with the official parser exactly once."""

    result = subprocess.run(
        ["php", "-l", path],
        cwd=root,
        check=False,
        capture_output=True,
        text=True,
    )
    return result.returncode == 0


def _classified_server_codec_paths(
    root: Path,
    base_ref: str,
    changed: set[str],
    guards: Sequence[Any],
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
) -> tuple[set[str], set[str]]:
    related: set[str] = set()
    review_required: set[str] = set()
    for path in changed:
        matching_guards = [
            _object(raw_guard, "guard")
            for raw_guard in guards
            if _matches(
                path,
                _string(_object(raw_guard, "guard")["glob"], "guard.glob"),
            )
        ]
        if not matching_guards:
            continue
        if not path.endswith(".php"):
            if any(
                _guard_matches(root, base_ref, {path}, guard)
                for guard in matching_guards
            ):
                related.add(path)
            continue
        current_content = current_files.get(path)
        if current_content is not None and not _php_lint(root, path):
            related.add(path)
            review_required.add(path)
            continue
        classification = _php_codec_change_classification(
            base_files.get(path),
            current_content,
            broad_path_guard=any(
                guard.get("content_patterns") is None for guard in matching_guards
            ),
        )
        if classification.related:
            related.add(path)
        if classification.review_required:
            review_required.add(path)
    return related, review_required


def _guard_matches(
    root: Path,
    base_ref: str,
    changed: set[str],
    raw_guard: Any,
) -> bool:
    guard = _object(raw_guard, "guard")
    matching = sorted(path for path in changed if _matches(path, _string(guard["glob"], "guard.glob")))
    if not matching:
        return False
    patterns = guard.get("content_patterns")
    if patterns is None:
        return True

    content = ""
    for path in matching:
        diff = _run(
            [
                "git",
                "diff",
                "--unified=0",
                "--no-ext-diff",
                "--no-color",
                base_ref,
                "--",
                path,
            ],
            root,
        )
        in_hunk = False
        for line in diff.splitlines():
            if line.startswith("diff --git "):
                in_hunk = False
            elif line.startswith("@@"):
                in_hunk = True
            elif in_hunk and line.startswith(("+", "-")):
                content += line[1:] + "\n"

        if not diff and path in _run(
            ["git", "ls-files", "--others", "--exclude-standard", "--", path],
            root,
        ).splitlines():
            candidate = root / path
            if candidate.is_file():
                content += candidate.read_text(encoding="utf-8", errors="replace")

    return any(re.search(pattern, content) for pattern in patterns)


def _guarded_paths(
    root: Path,
    base_ref: str,
    changed: set[str],
    guards: Sequence[Any],
) -> set[str]:
    return {
        path
        for guard in guards
        for path in changed
        if _guard_matches(root, base_ref, {path}, guard)
    }


def _proof_paths(files: Mapping[str, bytes]) -> set[str]:
    return {path for path in files if _matches(path, SERVER_CODEC_PROOF_GLOB)}


def _php_code_tokens(
    content: bytes, path: str
) -> tuple[tuple[str, ...], tuple[str, ...]]:
    """Return proof-test code tokens and quoted values without comments."""

    try:
        source = content.decode("utf-8")
    except UnicodeDecodeError as error:
        raise CorpusError(f"{path} must be valid UTF-8 PHP source") from error

    tokens: list[str] = []
    string_literals: list[str] = []
    index = 0
    while index < len(source):
        if source.startswith("<?php", index):
            index += len("<?php")
            continue
        if source.startswith("?>", index):
            index += 2
            continue
        character = source[index]
        if character.isspace():
            index += 1
            continue
        if source.startswith("//", index) or character == "#":
            newline = source.find("\n", index + 1)
            index = len(source) if newline == -1 else newline + 1
            continue
        if source.startswith("/*", index):
            end = source.find("*/", index + 2)
            if end == -1:
                raise CorpusError(f"{path} contains an unterminated block comment")
            index = end + 2
            continue
        if character == "`":
            raise CorpusError(
                f"{path} cannot use executable backticks in a counterfactual proof"
            )
        if character in {"'", '"'}:
            quote = character
            index += 1
            literal: list[str] = []
            while index < len(source):
                if source[index] == "\\":
                    if index + 1 >= len(source):
                        raise CorpusError(
                            f"{path} contains an unterminated string literal"
                        )
                    escaped = source[index + 1]
                    if quote == "'" and escaped not in {"'", "\\"}:
                        literal.append("\\")
                    literal.append(escaped)
                    index += 2
                    continue
                if source[index] == quote:
                    index += 1
                    string_literals.append("".join(literal))
                    break
                literal.append(source[index])
                index += 1
            else:
                raise CorpusError(f"{path} contains an unterminated string literal")
            continue
        if source.startswith("<<<", index):
            raise CorpusError(
                f"{path} cannot use heredoc or nowdoc syntax in a counterfactual proof"
            )
        identifier = re.match(r"[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*", source[index:])
        if identifier is not None:
            token = identifier.group(0)
            tokens.append(token)
            index += len(token)
            continue
        operator = next(
            (
                candidate
                for candidate in ("?->", "??=", "??", "&&", "||", "::", "=>", "->")
                if source.startswith(candidate, index)
            ),
            None,
        )
        if operator is not None:
            tokens.append(operator)
            index += len(operator)
            continue
        tokens.append(character)
        index += 1

    return tuple(tokens), tuple(string_literals)


def _require_official_proof_adapter(content: bytes, path: str) -> None:
    tokens, string_literals = _php_code_tokens(content, path)
    normalized = tuple(token.lower() for token in tokens)
    normalized_callables = {literal.lower().lstrip("\\") for literal in string_literals}
    forbidden = sorted(
        {
            token
            for token in normalized
            if token in PROOF_BRANCH_TOKENS
            or token in PROOF_SHORT_CIRCUIT_METHODS
            or token in PROOF_UNTRUSTED_CAPABILITIES
            or token in PROOF_INDIRECT_CALLABLE_APIS
            or token in {"_env", "_server"}
        }
        | (normalized_callables & PROOF_UNTRUSTED_CAPABILITIES)
    )
    dynamic_variable_dispatch = any(
        (
            tokens[index] == "$"
            and (
                tokens[index + 2] in {"(", "::"}
                or (index > 0 and tokens[index - 1] == "new")
            )
        )
        for index in range(max(0, len(tokens) - 2))
    )
    dynamic_expression_dispatch = any(
        (tokens[index] in {")", "]", "}"} and tokens[index + 1] == "(")
        or (tokens[index], tokens[index + 1]) in {("->", "{"), ("::", "{")}
        for index in range(max(0, len(tokens) - 1))
    )
    dynamic_dispatch = dynamic_variable_dispatch or dynamic_expression_dispatch
    if forbidden or dynamic_dispatch:
        details = f": {forbidden}" if forbidden else ""
        raise CorpusError(
            f"{path} cannot access fixture data, mutate verifier state, or use "
            f"candidate-controlled branching or dynamic dispatch{details}"
        )

    object_grammar_violations = {
        token
        for token in normalized
        if token in {"clone", "new"} or token in PROOF_UNTRUSTED_OBJECT_TYPES
    }
    object_grammar_violations.update(
        normalized[index + 1]
        for index in range(max(0, len(normalized) - 1))
        if normalized[index] == "->"
        and normalized[index + 1] in PROOF_UNTRUSTED_PROCESS_METHODS
    )
    object_grammar_violations.update(
        object_type
        for literal in normalized_callables
        for object_type in PROOF_UNTRUSTED_OBJECT_TYPES
        if re.search(rf"(?:^|\\\\){object_type}(?:$|\\\\)", literal)
    )
    if object_grammar_violations:
        raise CorpusError(
            f"{path} counterfactual proof grammar forbids object construction "
            "and process method dispatch: "
            f"{sorted(object_grammar_violations)}"
        )

    calls = [
        index
        for index in range(max(0, len(tokens) - 3))
        if tokens[index] == SERVER_CODEC_EXECUTOR_CLASS
        and tokens[index + 1] == "::"
        and tokens[index + 2] == SERVER_CODEC_EXECUTOR_METHOD
        and tokens[index + 3] == "("
    ]
    if len(calls) != 1:
        raise CorpusError(
            f"{path} must call {SERVER_CODEC_EXECUTOR_CLASS}::"
            f"{SERVER_CODEC_EXECUTOR_METHOD} exactly once"
        )

    argument = calls[0] + 4
    if argument < len(tokens) and tokens[argument] == "static":
        argument += 1
    if argument >= len(tokens) or tokens[argument] not in {"fn", "function"}:
        raise CorpusError(
            f"{path} must give {SERVER_CODEC_EXECUTOR_CLASS}::"
            f"{SERVER_CODEC_EXECUTOR_METHOD} an inline callback"
        )
    if tokens[argument + 1 : argument + 3] != ("(", ")"):
        raise CorpusError(
            f"{path} counterfactual callback cannot receive fixture data; "
            f"{SERVER_CODEC_EXECUTOR_CLASS} owns fixture-to-boundary substitution"
        )
    if b"SERVER_CODEC_" in content:
        raise CorpusError(
            f"{path} cannot inspect server codec verifier configuration; "
            f"{SERVER_CODEC_EXECUTOR_CLASS} owns fixture selection"
        )


def _require_server_codec_executor(
    policy: Mapping[str, Any],
    *,
    current_files: Mapping[str, bytes],
    base_files: Mapping[str, bytes],
) -> None:
    if policy.get("repository") != "server":
        return
    for path in SERVER_CODEC_EXECUTOR_FILES:
        if path not in current_files:
            raise CorpusError(
                f"the server codec policy requires official executor file {path}"
            )
        if path in base_files and current_files[path] != base_files[path]:
            raise CorpusError(
                f"official server codec executor file {path} is immutable; "
                "add a versioned executor instead"
            )


def _counterfactual_proof(
    document: Mapping[str, Any], path: str
) -> CounterfactualProof:
    required = {"$schema", "proof_schema", "fixture", "test", "boundaries"}
    if set(document) != required:
        raise CorpusError(f"{path} must contain exactly {sorted(required)}")
    _string(document["$schema"], f"{path}.$schema")
    if document["proof_schema"] != SERVER_CODEC_PROOF_SCHEMA:
        raise CorpusError(
            f"{path} must declare proof_schema={SERVER_CODEC_PROOF_SCHEMA}"
        )
    fixture = _repository_path(document["fixture"], f"{path}.fixture")
    test = _repository_path(document["test"], f"{path}.test")
    if not test.startswith("tests/Feature/") or not test.endswith("Test.php"):
        raise CorpusError(f"{path}.test must name a Feature PHPUnit test")
    boundaries = tuple(
        _repository_path(item, f"{path}.boundaries[]")
        for item in _list(document["boundaries"], f"{path}.boundaries", nonempty=True)
    )
    if len(boundaries) != len(set(boundaries)):
        raise CorpusError(f"{path}.boundaries contains duplicates")
    return CounterfactualProof(
        path=path,
        fixture=fixture,
        test=test,
        boundaries=boundaries,
    )


def _counterfactual_proofs(
    *,
    current_files: Mapping[str, bytes],
    added_paths: set[str],
    changed_paths: set[str],
    new_fixture_paths: set[str],
    guarded_paths: set[str],
) -> list[CounterfactualProof]:
    proof_paths = _proof_paths(current_files)
    added_proof_paths = proof_paths & added_paths
    proofs = [
        _counterfactual_proof(_json(current_files[path], path), path)
        for path in sorted(added_proof_paths)
    ]
    proof_fixtures = [proof.fixture for proof in proofs]
    if len(proof_fixtures) != len(set(proof_fixtures)):
        raise CorpusError(
            "each new codec fixture must have exactly one counterfactual proof"
        )
    if set(proof_fixtures) != new_fixture_paths:
        missing = sorted(new_fixture_paths - set(proof_fixtures))
        unrelated = sorted(set(proof_fixtures) - new_fixture_paths)
        raise CorpusError(
            "new codec fixtures and counterfactual proofs must have the same inventory "
            f"(missing_proofs={missing}, unrelated_proofs={unrelated})"
        )

    claimed_boundaries: list[str] = []
    revertible_guarded_paths = guarded_paths - added_paths
    for proof in proofs:
        if proof.test not in changed_paths or proof.test not in current_files:
            raise CorpusError(
                f"{proof.path}.test must be added or changed with its guarded codec defect"
            )
        _require_official_proof_adapter(current_files[proof.test], proof.test)
        added_boundary_claims = set(proof.boundaries) & guarded_paths & added_paths
        if added_boundary_claims:
            raise CorpusError(
                f"{proof.path}.boundaries cannot claim newly added guarded paths "
                f"without a real base implementation: {sorted(added_boundary_claims)}"
            )
        unrelated_boundaries = set(proof.boundaries) - revertible_guarded_paths
        if unrelated_boundaries:
            raise CorpusError(
                f"{proof.path}.boundaries names unchanged or unguarded paths: "
                f"{sorted(unrelated_boundaries)}"
            )
        claimed_boundaries.extend(proof.boundaries)

    claims = Counter(claimed_boundaries)
    duplicate_boundaries = sorted(
        boundary for boundary, count in claims.items() if count > 1
    )
    missing_boundaries = sorted(revertible_guarded_paths - set(claimed_boundaries))
    if duplicate_boundaries or missing_boundaries:
        raise CorpusError(
            "each guarded codec boundary must have exactly one defect-specific "
            "counterfactual proof "
            f"(missing={missing_boundaries}, duplicate={duplicate_boundaries})"
        )
    return proofs


def _process_detail(result: subprocess.CompletedProcess[str]) -> str:
    return (
        result.stderr.strip()
        or result.stdout.strip()
        or f"exit status {result.returncode}"
    )


def _codec_causality_sentinel(
    *,
    fixture_content: bytes,
    fixture_path: str,
    sentinel_content: bytes,
    sentinel_path: str,
) -> bytes:
    fixture = dict(_json(fixture_content, fixture_path))
    sentinel = _json(sentinel_content, sentinel_path)
    for field in ("protocol", "value", "framing", "failure_policy"):
        fixture[field] = sentinel[field]
    _codec_fixture(fixture, fixture_path, "php")
    return (json.dumps(fixture, indent=2) + "\n").encode()


def _run_phpunit_proof(
    *,
    root: Path,
    phpunit: Path,
    proof: CounterfactualProof,
    bootstrap: Path,
    boundary_evidence: str,
    boundary: str,
    input_codec: str,
) -> subprocess.CompletedProcess[str]:
    command = [
        str(phpunit),
        "--no-progress",
        "--colors=never",
        "--bootstrap",
        str(bootstrap),
        str(root / proof.test),
    ]
    environment = {
        key: value
        for key, value in os.environ.items()
        if not key.startswith("SERVER_CODEC_")
    }
    environment["SERVER_CODEC_CLAIMED_BOUNDARY"] = boundary
    environment["SERVER_CODEC_PROOF_INPUT_CODEC"] = input_codec
    result = subprocess.run(
        command,
        cwd=root,
        env=environment,
        check=False,
        capture_output=True,
        text=True,
    )

    evidence_count = 0

    def without_evidence(output: str) -> str:
        nonlocal evidence_count
        retained: list[str] = []
        for line in output.splitlines(keepends=True):
            if line.strip() == boundary_evidence:
                evidence_count += 1
            else:
                retained.append(line)
        return "".join(retained)

    stdout = without_evidence(result.stdout)
    stderr = without_evidence(result.stderr)
    returncode = result.returncode
    if evidence_count == 0:
        detail = (
            "the official PHP executor did not attest that the counted fixture "
            "drove the claimed codec boundary"
        )
        stderr = f"{stderr.rstrip()}\n{detail}\n".lstrip()
        if returncode in {0, 1}:
            returncode = 2

    return subprocess.CompletedProcess(
        args=result.args,
        returncode=returncode,
        stdout=stdout,
        stderr=stderr,
    )


def _instrument_server_codec_boundary(
    content: bytes,
    path: str,
    *,
    fixture_content: bytes,
    boundary_path: Path,
    boundary_evidence: str,
) -> bytes:
    """Embed the fixture in the claimed boundary outside proof-test control flow."""

    try:
        source = content.decode("utf-8")
    except UnicodeDecodeError as error:
        raise CorpusError(f"guarded codec boundary {path} must be valid UTF-8 PHP") from error

    aliases: dict[str, str] = {}
    for match in re.finditer(
        r"\buse\s+\\?Workflow\\Serializers\\(Avro|Serializer|CodecRegistry)"
        r"(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;",
        source,
    ):
        class_name = match.group(1)
        aliases[match.group(2) or class_name] = class_name

    for match in re.finditer(
        r"\buse\s+\\?(Workflow\\V2\\Support\\PayloadEnvelopeResolver|"
        r"App\\Support\\AvroPayloadEnvelopeResolver)"
        r"(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;",
        source,
    ):
        class_name = match.group(1).rsplit("\\", 1)[-1]
        aliases[match.group(2) or class_name] = class_name

    for class_name in SERVER_CODEC_BOUNDARY_METHODS:
        aliases.setdefault(class_name, class_name)
        aliases[rf"\Workflow\Serializers\{class_name}"] = class_name
        aliases[rf"Workflow\Serializers\{class_name}"] = class_name
    aliases.setdefault("PayloadCodecContract", "PayloadCodecContract")

    def php_literal(value: str) -> str:
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

    encoded_fixture = base64.b64encode(fixture_content).decode()
    fixture_document = _json(fixture_content, path)
    boundary_proxies = (
        SERVER_JSON_CODEC_BOUNDARY_PROXIES
        if fixture_document.get("protocol", {}).get("codec") == "json"
        else SERVER_CODEC_BOUNDARY_PROXIES
    )
    proxy_arguments = (
        f"{php_literal(encoded_fixture)}, "
        f"{php_literal(str(boundary_path))}, "
        f"{php_literal(boundary_evidence)}, "
    )
    substitutions = 0
    for alias, class_name in sorted(aliases.items(), key=lambda item: -len(item[0])):
        for method, proxy_method in SERVER_CODEC_BOUNDARY_METHODS[class_name].items():
            pattern = re.compile(
                rf"(?<![A-Za-z0-9_\\]){re.escape(alias)}\s*::\s*{method}\s*\("
            )
            source, count = pattern.subn(
                lambda _: (
                    f"{boundary_proxies[class_name]}::{proxy_method}("
                    f"{proxy_arguments}"
                ),
                source,
            )
            substitutions += count

    method_pattern = re.compile(
        r"(\bpublic\s+(?:static\s+)?function\s+[A-Za-z_][A-Za-z0-9_]*"
        r"\s*\([^{}]*\)\s*(?::\s*[^\{]+)?\{)",
        re.DOTALL,
    )
    attestation = (
        "\n        \\Tests\\Support\\ServerCodecRegressionBoundaryV2::attestBoundary("
        f"{proxy_arguments[:-2]});"
    )
    source, entry_count = method_pattern.subn(
        lambda match: match.group(1) + attestation,
        source,
    )
    substitutions += entry_count

    if substitutions == 0:
        raise CorpusError(
            f"claimed codec boundary {path} has no supported public entry point "
            "or official PHP codec call to instrument"
        )

    return source.encode()


def _write_app_snapshot(
    destination: Path,
    files: Mapping[str, bytes],
    *,
    fixture_content: bytes,
    boundary: str | None = None,
    boundary_content: bytes | None = None,
    instrument_boundary: str | None = None,
    boundary_evidence: str | None = None,
) -> tuple[Path, str]:
    evidence = boundary_evidence or (
        SERVER_CODEC_BOUNDARY_EVIDENCE_PREFIX + secrets.token_hex(32)
    )
    for path, content in files.items():
        if not path.startswith("app/"):
            continue
        if path == boundary:
            if boundary_content is None:
                continue
            content = boundary_content
        if path == instrument_boundary:
            content = _instrument_server_codec_boundary(
                content,
                path,
                fixture_content=fixture_content,
                boundary_path=(destination / path).resolve(),
                boundary_evidence=evidence,
            )
        target = destination / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)

    if instrument_boundary is not None and not (destination / instrument_boundary).is_file():
        raise CorpusError(
            f"claimed codec boundary {instrument_boundary} is absent from the source revision"
        )

    if not (destination / "app").is_dir():
        raise CorpusError("the source revision has no server source tree to exercise")

    bootstrap = destination / "counterfactual-bootstrap.php"
    bootstrap.write_text(
        """<?php

declare(strict_types=1);

$sourceRoot = __DIR__;

spl_autoload_register(
    static function (string $class) use ($sourceRoot): void {
        $prefix = 'App\\\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\\\', '/', substr($class, strlen($prefix)));
        $path = $sourceRoot.'/app/'.$relative.'.php';
        if (is_file($path)) {
            require $path;
        }
    },
    true,
    true,
);

if (!class_exists(\\Workflow\\Serializers\\CodecRegistry::class, false)) {
    class_alias(
        \\Tests\\Support\\ServerCodecRegressionLegacyRegistry::class,
        \\Workflow\\Serializers\\CodecRegistry::class,
    );
}

foreach ([
    \\Tests\\Support\\ServerCodecRegressionBoundary::class,
    \\Tests\\Support\\ServerCodecRegressionBoundaryV2::class,
    \\Tests\\Support\\ServerCodecRegressionFixtureExecutor::class,
] as $trustedClass) {
    if (! class_exists($trustedClass)) {
        throw new RuntimeException("Unable to preload trusted codec executor {$trustedClass}.");
    }
}
""",
        encoding="utf-8",
    )
    return bootstrap, evidence


def _review_compatible_base_files(
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    compatibility_paths: set[str],
) -> dict[str, bytes]:
    """Carry review-only edits into payload counterfactual base snapshots."""

    compatible = dict(base_files)
    for path in compatibility_paths:
        content = current_files.get(path)
        if content is None:
            compatible.pop(path, None)
        else:
            compatible[path] = content

    return compatible


def _verify_counterfactual_proofs(
    *,
    root: Path,
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    proofs: Sequence[CounterfactualProof],
    phpunit: Path,
    base_fixture_paths: Sequence[str],
    compatibility_paths: set[str],
) -> int:
    if not phpunit.is_file():
        raise CorpusError(
            f"PHPUnit is missing: {phpunit}; install dependencies before counterfactual validation"
        )

    with tempfile.TemporaryDirectory(prefix="server-codec-base-") as temporary:
        snapshot_root = Path(temporary)
        compatible_base_files = _review_compatible_base_files(
            base_files,
            current_files,
            compatibility_paths,
        )
        if proofs and not base_fixture_paths:
            raise CorpusError(
                "counterfactual verification requires a previously executable "
                "codec fixture as a causality sentinel"
            )

        execution_count = 0
        executions = [
            (proof_index, boundary_index, proof, boundary)
            for proof_index, proof in enumerate(proofs)
            for boundary_index, boundary in enumerate(proof.boundaries)
        ]
        for proof_index, boundary_index, proof, boundary in executions:
            index = f"{proof_index}-{boundary_index}"
            execution_count += 1
            candidate_root = snapshot_root / f"candidate-{index}"
            candidate_bootstrap, candidate_evidence = _write_app_snapshot(
                candidate_root,
                current_files,
                fixture_content=current_files[proof.fixture],
                instrument_boundary=boundary,
            )
            candidate = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                bootstrap=candidate_bootstrap,
                boundary_evidence=candidate_evidence,
                boundary=boundary,
                input_codec="json",
            )
            if candidate.returncode != 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} does not pass on the candidate "
                    f"for {boundary} "
                    f"through PHPUnit: {_process_detail(candidate)}"
                )

            base_root = snapshot_root / f"base-{index}"
            bootstrap, base_evidence = _write_app_snapshot(
                base_root,
                compatible_base_files,
                fixture_content=current_files[proof.fixture],
                instrument_boundary=boundary,
            )
            defective = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                bootstrap=bootstrap,
                boundary_evidence=base_evidence,
                boundary=boundary,
                input_codec="json",
            )
            if defective.returncode == 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} also passes on the defective base "
                    f"for {boundary}; "
                    f"fixture {proof.fixture} is not defect-specific"
                )
            if defective.returncode != 1:
                raise CorpusError(
                    f"counterfactual test {proof.test} did not produce a base assertion "
                    f"failure for {boundary} "
                    f"through PHPUnit: {_process_detail(defective)}"
                )

            sentinel_fixture = base_fixture_paths[0]
            sentinel_content = _codec_causality_sentinel(
                fixture_content=current_files[proof.fixture],
                fixture_path=proof.fixture,
                sentinel_content=base_files[sentinel_fixture],
                sentinel_path=sentinel_fixture,
            )
            sentinel_root = snapshot_root / f"sentinel-{index}"
            sentinel_bootstrap, sentinel_evidence = _write_app_snapshot(
                sentinel_root,
                compatible_base_files,
                fixture_content=sentinel_content,
                instrument_boundary=boundary,
            )
            sentinel = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                bootstrap=sentinel_bootstrap,
                boundary_evidence=sentinel_evidence,
                boundary=boundary,
                input_codec="avro",
            )
            if sentinel.returncode != 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} still fails on the defective base "
                    f"for {boundary} "
                    f"after fixture {proof.fixture} is replaced by previously executable "
                    f"sentinel {sentinel_fixture}; the counted fixture is not causally "
                    f"exercised through PHPUnit: {_process_detail(sentinel)}"
                )

            isolated_root = snapshot_root / f"isolated-{index}"
            isolated_bootstrap, isolated_evidence = _write_app_snapshot(
                isolated_root,
                current_files,
                fixture_content=current_files[proof.fixture],
                boundary=boundary,
                boundary_content=base_files[boundary],
                instrument_boundary=boundary,
            )
            isolated = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                bootstrap=isolated_bootstrap,
                boundary_evidence=isolated_evidence,
                boundary=boundary,
                input_codec="json",
            )
            if isolated.returncode == 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} also passes when claimed boundary "
                    f"{boundary} is reverted to the defective base; proof attribution "
                    "is not boundary-specific"
                )
            if isolated.returncode != 1:
                raise CorpusError(
                    f"counterfactual test {proof.test} did not produce an assertion failure "
                    f"when claimed boundary {boundary} was reverted: "
                    f"{_process_detail(isolated)}"
                )

    return execution_count


def validate(
    root: Path,
    policy_path: Path,
    base_ref: str | None,
    *,
    verify_counterfactual: bool = False,
    phpunit_path: Path = Path("vendor/bin/phpunit"),
) -> dict[str, Any]:
    policy_file = (policy_path if policy_path.is_absolute() else root / policy_path).resolve()
    phpunit = (
        phpunit_path
        if phpunit_path.is_absolute()
        else root / phpunit_path
    ).resolve()
    if verify_counterfactual and not phpunit.is_file():
        raise CorpusError(
            f"PHPUnit is missing: {phpunit}; install dependencies before counterfactual validation"
        )
    try:
        policy_relative_path = policy_file.relative_to(root).as_posix()
    except ValueError as error:
        raise CorpusError("policy must be inside the repository root") from error
    policy = _policy(_json(policy_file.read_bytes(), str(policy_path)), str(policy_path))
    _require_owned_categories(policy, str(policy_path))
    current_files = _tracked_worktree_files(root)
    changed: set[str] = set()
    added_paths: set[str] = set()
    base_files: dict[str, bytes] = {}
    base_evidence: list[Evidence] = []
    if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
        _run(["git", "rev-parse", "--verify", f"{base_ref}^{{commit}}"], root)
        changed, added_paths = _changed_paths(root, base_ref)
        base_files = _ref_files(root, base_ref)
        raw_base_policy = base_files.get(policy_relative_path)
        base_policy = (
            _policy(_json(raw_base_policy, policy_relative_path), policy_relative_path)
            if raw_base_policy is not None
            else policy
        )
        if raw_base_policy is not None:
            _require_policy_extension(base_policy, policy, base_files, str(policy_path))
        _require_executable_inventory(base_policy, policy_relative_path, base_files)
        for path in _fixture_paths(base_policy, base_files):
            current_content = current_files.get(path)
            if (
                current_content != base_files[path]
                and current_content is not None
                and _canonical_wire_migration(base_files[path], current_content)
            ):
                base_files[path] = current_content
                continue
            if current_content != base_files[path]:
                raise CorpusError(f"immutable fixture file {path} was changed, moved, or removed")
        for path in _proof_paths(base_files):
            if current_files.get(path) != base_files[path]:
                raise CorpusError(
                    f"immutable counterfactual proof file {path} was changed, moved, or removed"
                )
        base_evidence = _inventory(base_policy, base_files)
    _require_server_codec_executor(
        policy,
        current_files=current_files,
        base_files=base_files,
    )
    _require_executable_inventory(policy, str(policy_path), current_files)
    for path in _proof_paths(current_files):
        _counterfactual_proof(_json(current_files[path], path), path)
    current_evidence = _inventory(policy, current_files, new_paths=added_paths)

    current_by_id = {item.identity: item for item in current_evidence}
    base_by_id = {item.identity: item for item in base_evidence}
    for identity, previous in base_by_id.items():
        current = current_by_id.get(identity)
        if current is None:
            raise CorpusError(f"immutable fixture {identity} was removed")
        if current.path != previous.path or current.semantic_digest != previous.semantic_digest:
            raise CorpusError(f"immutable fixture {identity} was changed; append a superseding fixture instead")
    for item in current_evidence:
        for superseded in item.supersedes:
            previous = current_by_id.get(superseded)
            if previous is None:
                raise CorpusError(f"{item.identity} supersedes unknown fixture {superseded}")
            if previous.category != item.category or previous.protocol_version == item.protocol_version:
                raise CorpusError(
                    f"{item.identity} must supersede evidence in the same category at an older protocol version"
                )

    counts: dict[str, dict[str, Any]] = {}
    review_required_paths: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        current_count = sum(item.category == category_name for item in current_evidence)
        base_count = sum(item.category == category_name for item in base_evidence)
        related = False
        proof_count = 0
        revision_verified = 0
        if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
            category = _object(raw_category, f"categories.{category_name}")
            guards = _list(
                category["guards"],
                f"categories.{category_name}.guards",
            )
            if category_name == "codec" and policy["repository"] == "server":
                related_paths, category_review_required = (
                    _classified_server_codec_paths(
                        root,
                        base_ref,
                        changed,
                        guards,
                        base_files,
                        current_files,
                    )
                )
                review_required_paths.update(category_review_required)
            else:
                related_paths = _guarded_paths(root, base_ref, changed, guards)
                category_review_required = set()
            related = bool(related_paths)
            if related and current_count <= base_count:
                raise CorpusError(
                    f"{category_name} implementation changed but its corpus did not grow "
                    f"(base={base_count}, current={current_count})"
                )
            if related and not any(
                item.category == category_name and item.path in added_paths
                for item in current_evidence
            ):
                raise CorpusError(
                    f"{category_name} implementation changed but no newly added fixture "
                    "provides corpus evidence"
                )
            if category_name == "codec" and related:
                new_fixture_paths = {
                    item.path
                    for item in current_evidence
                    if item.category == category_name and item.path in added_paths
                }
                proofs = _counterfactual_proofs(
                    current_files=current_files,
                    added_paths=added_paths,
                    changed_paths=changed,
                    new_fixture_paths=new_fixture_paths,
                    guarded_paths=related_paths,
                )
                proof_count = len(proofs)
                if verify_counterfactual:
                    revision_verified = _verify_counterfactual_proofs(
                        root=root,
                        base_files=base_files,
                        current_files=current_files,
                        proofs=proofs,
                        phpunit=phpunit,
                        base_fixture_paths=sorted(
                            {
                                item.path
                                for item in base_evidence
                                if item.category == category_name
                            }
                        ),
                        compatibility_paths=category_review_required - related_paths,
                    )
        counts[category_name] = {
            "base": base_count,
            "current": current_count,
            "related_change": related,
            "counterfactual_proofs": proof_count,
            "revision_verified": revision_verified,
            "review_required_paths": sorted(category_review_required),
        }
    return {
        "schema": POLICY_SCHEMA,
        "repository": policy["repository"],
        "base_ref": base_ref,
        "changed_paths": len(changed),
        "counts": counts,
        "review_required_paths": sorted(review_required_paths),
        "status": "pass",
    }


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--policy", type=Path, default=Path("regression-corpus-policy.json"))
    parser.add_argument("--base-ref")
    parser.add_argument("--verify-counterfactual", action="store_true")
    parser.add_argument("--phpunit", type=Path, default=Path("vendor/bin/phpunit"))
    args = parser.parse_args(argv)
    try:
        result = validate(
            args.root.resolve(),
            args.policy,
            args.base_ref,
            verify_counterfactual=args.verify_counterfactual,
            phpunit_path=args.phpunit,
        )
    except (CorpusError, OSError) as error:
        print(f"regression corpus validation failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
