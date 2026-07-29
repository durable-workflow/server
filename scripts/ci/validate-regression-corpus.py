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
ZERO_COMMIT = re.compile(r"^0+$")


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
        "command_sequence": command_sequence,
        "expected": expected,
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
    semantic = {
        "value": value if operation == "encode_reject" else None,
        "wire_base64": canonical_wire,
        "failure_policy": {"operation": operation, "error": error},
    }
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
                semantic_wire = [
                    _canonical_base64(
                        wire_value,
                        f"{path}.{section}[{index}].wire_base64[]",
                    )
                    for wire_value in _unique_strings(
                        wire,
                        f"{path}.{section}[{index}].wire_base64",
                    )
                ]
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
            semantic = {
                "framing": semantic_wire,
                "failure_policy": (
                    {"operation": "decode_reject", "error": entry.get("error")}
                    if section == "malformed"
                    else {"operation": "round_trip", "error": None}
                ),
            }
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
        candidate = root / path
        if candidate.is_file():
            content += candidate.read_text(encoding="utf-8", errors="replace")
        content += _run(["git", "show", f"{base_ref}:{path}"], root, check=False)

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

    proof_tests = [proof.test for proof in proofs]
    if len(proof_tests) != len(set(proof_tests)):
        raise CorpusError(
            "each guarded codec boundary must have its own counterfactual test"
        )

    claimed_boundaries: list[str] = []
    for proof in proofs:
        if len(proof.boundaries) != 1:
            raise CorpusError(
                f"{proof.path}.boundaries must name exactly one guarded codec boundary"
            )
        if proof.test not in changed_paths or proof.test not in current_files:
            raise CorpusError(
                f"{proof.path}.test must be added or changed with its guarded codec defect"
            )
        test_content = current_files[proof.test].decode("utf-8", errors="replace")
        if "SERVER_CODEC_REGRESSION_FIXTURE" not in test_content:
            raise CorpusError(
                f"{proof.path}.test must consume SERVER_CODEC_REGRESSION_FIXTURE"
            )
        unrelated_boundaries = set(proof.boundaries) - guarded_paths
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
    missing_boundaries = sorted(guarded_paths - set(claimed_boundaries))
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
    source_root: Path,
    bootstrap: Path | None = None,
    fixture_path: Path | None = None,
) -> subprocess.CompletedProcess[str]:
    command = [
        str(phpunit),
        "--no-progress",
        "--colors=never",
    ]
    if bootstrap is not None:
        command.extend(["--bootstrap", str(bootstrap)])
    command.append(proof.test)
    environment = os.environ.copy()
    environment.update(
        {
            "SERVER_CODEC_REGRESSION_FIXTURE": str(
                fixture_path if fixture_path is not None else root / proof.fixture
            ),
            "SERVER_CODEC_REGRESSION_PROOF": str(root / proof.path),
            "SERVER_CODEC_SOURCE_ROOT": str(source_root),
        }
    )
    return subprocess.run(
        command,
        cwd=root,
        env=environment,
        check=False,
        capture_output=True,
        text=True,
    )


def _write_app_snapshot(
    destination: Path,
    files: Mapping[str, bytes],
    *,
    boundary: str | None = None,
    boundary_content: bytes | None = None,
) -> Path:
    for path, content in files.items():
        if not path.startswith("app/"):
            continue
        target = destination / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)

    if boundary is not None:
        target = destination / boundary
        if boundary_content is None:
            target.unlink(missing_ok=True)
        else:
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(boundary_content)

    if not (destination / "app").is_dir():
        raise CorpusError("the source revision has no server source tree to exercise")

    bootstrap = destination / "counterfactual-bootstrap.php"
    bootstrap.write_text(
        """<?php

declare(strict_types=1);

$sourceRoot = getenv('SERVER_CODEC_SOURCE_ROOT');
if (! is_string($sourceRoot) || $sourceRoot === '') {
    throw new RuntimeException('SERVER_CODEC_SOURCE_ROOT is required.');
}

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
""",
        encoding="utf-8",
    )
    return bootstrap


def _verify_counterfactual_proofs(
    *,
    root: Path,
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    proofs: Sequence[CounterfactualProof],
    phpunit: Path,
    base_fixture_paths: Sequence[str],
) -> int:
    if not phpunit.is_file():
        raise CorpusError(
            f"PHPUnit is missing: {phpunit}; install dependencies before counterfactual validation"
        )

    with tempfile.TemporaryDirectory(prefix="server-codec-base-") as temporary:
        base_root = Path(temporary)
        bootstrap = _write_app_snapshot(base_root, base_files)
        if proofs and not base_fixture_paths:
            raise CorpusError(
                "counterfactual verification requires a previously executable "
                "codec fixture as a causality sentinel"
            )

        for index, proof in enumerate(proofs):
            candidate = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                source_root=root,
            )
            if candidate.returncode != 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} does not pass on the candidate "
                    f"through PHPUnit: {_process_detail(candidate)}"
                )

            defective = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                source_root=base_root,
                bootstrap=bootstrap,
            )
            if defective.returncode == 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} also passes on the defective base; "
                    f"fixture {proof.fixture} is not defect-specific"
                )
            if defective.returncode != 1:
                raise CorpusError(
                    f"counterfactual test {proof.test} did not produce a base assertion "
                    f"failure through PHPUnit: {_process_detail(defective)}"
                )

            sentinel_fixture = base_fixture_paths[0]
            sentinel_path = base_root / "fixture-sentinels" / str(index) / proof.fixture
            sentinel_path.parent.mkdir(parents=True, exist_ok=True)
            sentinel_path.write_bytes(
                _codec_causality_sentinel(
                    fixture_content=current_files[proof.fixture],
                    fixture_path=proof.fixture,
                    sentinel_content=base_files[sentinel_fixture],
                    sentinel_path=sentinel_fixture,
                )
            )
            sentinel = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                source_root=base_root,
                bootstrap=bootstrap,
                fixture_path=sentinel_path,
            )
            if sentinel.returncode != 0:
                raise CorpusError(
                    f"counterfactual test {proof.test} still fails on the defective base "
                    f"after fixture {proof.fixture} is replaced by previously executable "
                    f"sentinel {sentinel_fixture}; the counted fixture is not causally "
                    f"exercised through PHPUnit: {_process_detail(sentinel)}"
                )

            boundary = proof.boundaries[0]
            isolated_root = base_root / f"isolated-{index}"
            isolated_bootstrap = _write_app_snapshot(
                isolated_root,
                current_files,
                boundary=boundary,
                boundary_content=base_files.get(boundary),
            )
            isolated = _run_phpunit_proof(
                root=root,
                phpunit=phpunit,
                proof=proof,
                source_root=isolated_root,
                bootstrap=isolated_bootstrap,
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

    return len(proofs)


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
            if current_files.get(path) != base_files[path]:
                raise CorpusError(f"immutable fixture file {path} was changed, moved, or removed")
        for path in _proof_paths(base_files):
            if current_files.get(path) != base_files[path]:
                raise CorpusError(
                    f"immutable counterfactual proof file {path} was changed, moved, or removed"
                )
        base_evidence = _inventory(base_policy, base_files)
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

    counts: dict[str, dict[str, int | bool]] = {}
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
            related_paths = _guarded_paths(root, base_ref, changed, guards)
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
                    )
        counts[category_name] = {
            "base": base_count,
            "current": current_count,
            "related_change": related,
            "counterfactual_proofs": proof_count,
            "revision_verified": revision_verified,
        }
    return {
        "schema": POLICY_SCHEMA,
        "repository": policy["repository"],
        "base_ref": base_ref,
        "changed_paths": len(changed),
        "counts": counts,
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
