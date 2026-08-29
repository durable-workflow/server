#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: signals-queries-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--focus CELL|--focus=CELL]

Writes a source-free signals/queries conformance split-out result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  signals-queries-result.json
  signals-queries-record.json
  signals-queries-findings.json
  signals-queries-rust-cell-results.json
  signals-queries-baseline-cell-results.json
  signals-queries-php-cli-signal-result.json (when --focus=php-worker-cli-signal)
  signals-queries-php-cli-signal-record.json (when --focus=php-worker-cli-signal)

Environment overrides:
  DW_SERVER_VERSION                         Published server version under test.
  DW_SERVER_IMAGE                           Optional server image for compose. Only exact
                                             durableworkflow/server tags or digest-pinned
                                             references matching DW_SERVER_VERSION prove
                                             published install evidence.
  DW_CLI_VERSION                            Published CLI version under test.
  DW_PYTHON_SDK_VERSION                     Published Python SDK version under test.
  DW_RUST_SDK_VERSION                       Exact published Rust SDK version from the artifact tuple.
  DW_PHP_SDK_VERSION                        Exact published durable-workflow/sdk version from Packagist.
  DW_WORKFLOW_PHP_VERSION                   Published PHP workflow version under test.
  DW_WATERLINE_VERSION                      Published Waterline version under test.
  DW_WATERLINE_SERVICE_IMAGE                Exact published Waterline service image. Must be
                                             docker.io/durableworkflow/waterline@sha256:<digest>;
                                             tags and local images are rejected.
  DW_SIGNALS_QUERIES_RESULT_DIR             Result directory when --result-dir is omitted.
  DW_SIGNALS_QUERIES_FOCUS                  Optional focused cell. The supported value is
                                             php-worker-cli-signal; it does not claim the broad
                                             signals/queries property.
  DW_SIGNALS_QUERIES_EVIDENCE               Optional JSON evidence from a real matrix run, including
                                             executed_distribution_identities captured from consumed bytes.
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE         Deprecated alias for DW_SIGNALS_QUERIES_EVIDENCE.
  DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE     Set to 0 to skip the live order/dedup/unknown shard.
  DW_SIGNALS_QUERIES_RUN_PHP_BASELINE_PROBE Set to 0 to skip the live PHP worker mirror shard.
  DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE  Set to 0 to skip the live malformed/unknown error shard.
  DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE
                                             Set to 0 to skip the live replay/terminal shard.
  DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE
                                             Set to 0 to skip the published Waterline observer shard.
  DW_SIGNALS_QUERIES_RUN_WATERLINE_SERVICE_PROBE
                                             Set to 0 to skip the published Waterline service-image shard.
  DW_SIGNALS_QUERIES_WATERLINE_SERVICE_BIND_HOST
                                             Docker host interface for the Waterline service port. Defaults
                                             to DW_SIGNALS_QUERIES_DOCKER_HOST_GATEWAY when configured,
                                             otherwise 127.0.0.1; wildcard interfaces are rejected.
  DW_SIGNALS_QUERIES_WATERLINE_SERVICE_CONNECT_HOST
                                             Preferred host/address to probe for the Waterline service port.
                                             Defaults to 127.0.0.1; gateway candidates remain fallbacks.
  DW_SIGNALS_QUERIES_DOCKER_HOST_GATEWAY     Explicit Docker daemon host gateway for containerized runners.
                                             Used as an ordered probe fallback and safe Waterline bind default.
  DW_SIGNALS_QUERIES_RUN_RUST_MATRIX_PROBE   Set to 0 to skip the mandatory crates.io Rust matrix.
  DW_SIGNALS_QUERIES_RUST_DOCKER_IMAGE       Rust build/runtime image. Defaults to rust:1.86-slim-bookworm.
  DW_SIGNALS_QUERIES_RUST_CACHE_DIR          Host-owned Rust dependency cache. Defaults to a private,
                                             user-specific directory under DW_CONFORMANCE_TMPDIR,
                                             or a sibling of the result directory when it is unset.
  DW_SIGNALS_QUERIES_RUST_CACHE_MAX_ENTRIES  Maximum compatible dependency graphs retained. Defaults to 4.
  DW_SIGNALS_QUERIES_RUST_CACHE_MAX_BYTES    Maximum cache size in bytes. Defaults to 8589934592 (8 GiB).
  DW_SIGNALS_QUERIES_RUST_CACHE_MAX_AGE_SECONDS
                                             Maximum unused cache-entry age. Defaults to 1209600 (14 days).
  DW_SIGNALS_QUERIES_SERVER_URL             Reuse an already-running published server for the adversarial shard.
  DW_SIGNALS_QUERIES_SERVER_CONNECT_HOST    Preferred host/address to probe for a self-started published server.
  DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS
                                             Host /api/ready timeout for published-server probes.
  DW_SIGNALS_QUERIES_AUTH_TOKEN             Bearer token for the adversarial shard. Defaults to dev-token.
  DW_SIGNALS_QUERIES_NAMESPACE              Namespace for the adversarial shard. Defaults to default.
  DW_SIGNALS_QUERIES_CLI_BIN                Optional configured dw binary path; does not prove published install.
  DW_SIGNALS_QUERIES_PYTHON                 Optional configured Python executable; does not prove published install.
  DW_SIGNALS_QUERIES_PHP_DOCKER_IMAGE       Docker image used to install and run the published PHP package.
                                             Defaults to composer:2.
  DW_SIGNALS_QUERIES_WATERLINE_PHP_DOCKER_IMAGE
                                             Docker image used to install and run the published Waterline observer.
                                             Defaults to durableworkflow/server:<DW_SERVER_VERSION> so pdo_mysql
                                             is available when observing a MySQL-backed server.
  DW_SIGNALS_QUERIES_KEEP_RUN_ROOT          Set to 1 to keep the adversarial shard scratch directory.
  DW_SIGNALS_QUERIES_CLEANUP_TERMINATION_READY_FILE
                                             Regression-only checkpoint written after every bind-mounted
                                             runtime path is populated; the runner then waits for termination.
USAGE
}

result_dir="${DW_SIGNALS_QUERIES_RESULT_DIR:-}"
focus="${DW_SIGNALS_QUERIES_FOCUS:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    --focus)
      focus="${2:?--focus requires a value}"
      shift 2
      ;;
    --focus=*)
      focus="${1#--focus=}"
      if [[ -z "$focus" ]]; then
        printf '%s\n' '--focus requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -n "$focus" && "$focus" != "php-worker-cli-signal" ]]; then
  printf 'unsupported focused cell: %s\n' "$focus" >&2
  usage >&2
  exit 2
fi

if [[ -n "$focus" ]]; then
  export DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE=0
  export DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE=0
  export DW_SIGNALS_QUERIES_RUN_RUST_MATRIX_PROBE=0
  export DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE=0
  export DW_SIGNALS_QUERIES_RUN_WATERLINE_SERVICE_PROBE=0
fi

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-signals-queries.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

exec env \
  RESULT_DIR="$result_dir" \
  STARTED_AT="$started_at" \
  REPO_ROOT="$repo_root" \
  DW_SIGNALS_QUERIES_FOCUS="$focus" \
  DW_SERVER_VERSION="${DW_SERVER_VERSION:-unresolved}" \
  DW_CLI_VERSION="${DW_CLI_VERSION:-unresolved}" \
  DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-unresolved}" \
  DW_RUST_SDK_VERSION="${DW_RUST_SDK_VERSION:-unresolved}" \
  DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-unresolved}" \
  DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-unresolved}" \
  DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-unresolved}" \
  DW_WATERLINE_SERVICE_IMAGE="${DW_WATERLINE_SERVICE_IMAGE:-}" \
  DW_SIGNALS_QUERIES_EVIDENCE="${DW_SIGNALS_QUERIES_EVIDENCE:-${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}}" \
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE="${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}" \
  python3 - <<'PY'
from __future__ import annotations

import atexit
import base64
import fcntl
import hashlib
import ipaddress
import io
import json
import math
import os
import re
import signal
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


DIAGNOSTIC_OUTPUT_LIMIT = 8192
PORTABLE_RESULT_LIMIT_BYTES = 1024 * 1024
PORTABLE_EVIDENCE_CELL_LIMIT_BYTES = 64 * 1024
PORTABLE_EVIDENCE_STRING_LIMIT = 2048
PORTABLE_EVIDENCE_COLLECTION_LIMIT = 64
PORTABLE_FINDING_LIMIT = 64
PORTABLE_DISTRIBUTION_ARTIFACT_LIMIT = 64
RUST_DEPENDENCY_CACHE_SCHEMA = "durable-workflow.v1.signals-queries-rust-dependency-cache"
RUST_DEPENDENCY_CACHE_DEFAULT_MAX_ENTRIES = 4
RUST_DEPENDENCY_CACHE_DEFAULT_MAX_BYTES = 8 * 1024 * 1024 * 1024
RUST_DEPENDENCY_CACHE_DEFAULT_MAX_AGE_SECONDS = 14 * 24 * 60 * 60
EXECUTED_DISTRIBUTION_IDENTITIES_PATH = (
    Path(os.environ["RESULT_DIR"]) / "executed-distribution-identities.json"
    if os.environ.get("RESULT_DIR")
    else None
)
DISTRIBUTION_COMPONENTS = {
    "workflow": ("composer", "durable-workflow/workflow"),
    "waterline": ("composer", "durable-workflow/waterline"),
    "waterline-service": ("oci", "docker.io/durableworkflow/waterline"),
    "server": ("oci", "docker.io/durableworkflow/server"),
    "cli": ("github-release", "durable-workflow/cli"),
    "sdk-php": ("composer", "durable-workflow/sdk"),
    "sdk-python": ("pypi", "durable-workflow"),
    "sdk-rust": ("crates.io", "durable-workflow"),
}
DISTRIBUTION_VERSION_COMPONENTS = {
    "waterline-service": "waterline",
}
REQUIRED_DISTRIBUTION_IDENTITIES = tuple(DISTRIBUTION_COMPONENTS)
DIST_VERSION_PATTERN = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
PYTHON_DIST_VERSION_PATTERN = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:(?:a|b|rc)(?:0|[1-9]\d*)|-(?:alpha|beta|rc)\.(?:0|[1-9]\d*))?$",
    re.IGNORECASE,
)
DIST_DIGEST_PATTERN = re.compile(r"^[0-9a-f]{64}$")


def python_release_identity(version: str) -> str | None:
    stable = re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", version)
    if stable:
        return version
    semver = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if semver:
        major, minor, patch, prerelease, ordinal = semver.groups()
        phase = {"alpha": "a", "beta": "b", "rc": "rc"}[prerelease.lower()]
        return f"{major}.{minor}.{patch}{phase}{ordinal}"
    pep440 = PYTHON_DIST_VERSION_PATTERN.fullmatch(version)
    if not pep440:
        return None
    pep440_match = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if not pep440_match:
        return version
    major, minor, patch, prerelease, ordinal = pep440_match.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"


def same_python_release(expected: str, observed: str) -> bool:
    expected_identity = python_release_identity(expected)
    return expected_identity is not None and expected_identity == python_release_identity(observed)


def python_registration_release_version(value: Any) -> str:
    registered = str(value or "").strip()
    prefix = "durable-workflow-python/"
    return registered[len(prefix):] if registered.startswith(prefix) else registered


def distribution_version(
    artifact_versions: dict[str, str],
    distribution: str,
) -> str:
    component = DISTRIBUTION_VERSION_COMPONENTS.get(distribution, distribution)
    return str(artifact_versions.get(component, ""))


def normalize_distribution_identity(
    component: str,
    observed: Any,
    expected_version: str | None = None,
) -> dict[str, Any]:
    if component not in DISTRIBUTION_COMPONENTS:
        raise RuntimeError(f"unknown executed distribution component: {component}")
    if not isinstance(observed, dict) or set(observed) != {"kind", "locator", "artifacts"}:
        raise RuntimeError(f"invalid executed distribution identity for {component}")

    kind, package = DISTRIBUTION_COMPONENTS[component]
    version_pattern = PYTHON_DIST_VERSION_PATTERN if component == "sdk-python" else DIST_VERSION_PATTERN
    version = str(expected_version or "").strip()
    if version:
        if not version_pattern.fullmatch(version):
            raise RuntimeError(f"invalid exact distribution version for {component}: {version}")
        locator_version = python_release_identity(version) if component == "sdk-python" else version
        expected_locator = f"{kind}:{package}@{locator_version}"
        if observed.get("kind") != kind or observed.get("locator") != expected_locator:
            raise RuntimeError(
                f"executed distribution locator for {component} does not match {expected_locator}"
            )
    else:
        locator_pattern = re.compile(
            rf"^{re.escape(kind)}:{re.escape(package)}@{version_pattern.pattern[1:-1]}$",
            version_pattern.flags,
        )
        if observed.get("kind") != kind or not locator_pattern.fullmatch(str(observed.get("locator", ""))):
            raise RuntimeError(f"invalid executed distribution locator for {component}")

    raw_artifacts = observed.get("artifacts")
    if not isinstance(raw_artifacts, list) or not raw_artifacts:
        raise RuntimeError(f"executed distribution identity has no artifacts for {component}")
    if len(raw_artifacts) > PORTABLE_DISTRIBUTION_ARTIFACT_LIMIT:
        raise RuntimeError(
            f"executed distribution identity has too many artifacts for {component}: "
            f"{len(raw_artifacts)} > {PORTABLE_DISTRIBUTION_ARTIFACT_LIMIT}"
        )
    artifacts: list[dict[str, str]] = []
    for artifact in raw_artifacts:
        if not isinstance(artifact, dict) or set(artifact) != {"name", "sha256"}:
            raise RuntimeError(f"invalid executed distribution artifact for {component}")
        name = artifact.get("name")
        digest = artifact.get("sha256")
        if (
            not isinstance(name, str)
            or not name
            or len(name) > 256
            or ("/" in name and component not in {"workflow", "waterline", "sdk-php"})
            or not isinstance(digest, str)
            or not DIST_DIGEST_PATTERN.fullmatch(digest)
        ):
            raise RuntimeError(f"invalid executed distribution artifact for {component}")
        artifacts.append({"name": name, "sha256": digest})
    artifacts.sort(key=lambda artifact: artifact["name"])
    names = [artifact["name"] for artifact in artifacts]
    if len(names) != len(set(names)):
        raise RuntimeError(f"duplicate executed distribution artifact for {component}")
    return {
        "kind": kind,
        "locator": str(observed["locator"]),
        "artifacts": artifacts,
    }


def load_distribution_identities(path: Path) -> dict[str, Any]:
    if not path.is_file():
        return {}
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError("executed distribution identities must be an object")
    return {
        component: normalize_distribution_identity(component, observed)
        for component, observed in value.items()
    }


def executed_distribution_identities_path() -> Path:
    if EXECUTED_DISTRIBUTION_IDENTITIES_PATH is None:
        raise RuntimeError("RESULT_DIR is required to record executed distribution identities")
    return EXECUTED_DISTRIBUTION_IDENTITIES_PATH


@contextmanager
def distribution_identity_store_lock(path: Path) -> Any:
    path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = path.with_name(f".{path.name}.lock")
    with lock_path.open("a+b") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def write_distribution_identities(path: Path, identities: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        dir=path.parent,
        prefix=f".{path.name}.",
        suffix=".tmp",
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        write_json(temporary, identities)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def distribution_identity(
    component: str,
    version: str,
    artifact_name: str,
    digest: str,
) -> dict[str, Any]:
    if component not in DISTRIBUTION_COMPONENTS:
        raise RuntimeError(f"unknown executed distribution component: {component}")
    kind, package = DISTRIBUTION_COMPONENTS[component]
    observed = {
        "kind": kind,
        "locator": f"{kind}:{package}@{python_release_identity(version) if component == 'sdk-python' else version}",
        "artifacts": [{"name": artifact_name, "sha256": digest}],
    }
    return normalize_distribution_identity(component, observed, version)


def record_distribution_identity(path: Path, component: str, observed: dict[str, Any]) -> None:
    normalized = normalize_distribution_identity(component, observed)
    with distribution_identity_store_lock(path):
        identities = load_distribution_identities(path)
        current = identities.get(component)
        if current is not None:
            if current["kind"] != normalized["kind"] or current["locator"] != normalized["locator"]:
                raise RuntimeError(f"conflicting executed distribution locator for {component}")
            artifacts = {artifact["name"]: artifact["sha256"] for artifact in current["artifacts"]}
            for artifact in normalized["artifacts"]:
                previous = artifacts.get(artifact["name"])
                if previous is not None and previous != artifact["sha256"]:
                    raise RuntimeError(f"conflicting consumed bytes for {component}:{artifact['name']}")
                artifacts[artifact["name"]] = artifact["sha256"]
            normalized["artifacts"] = [
                {"name": name, "sha256": artifacts[name]}
                for name in sorted(artifacts)
            ]
        identities[component] = normalized
        write_distribution_identities(path, identities)


def merge_distribution_identity_handoff(
    evidence: Any,
    path: Path,
    artifact_versions: dict[str, str],
) -> None:
    if not isinstance(evidence, dict):
        raise RuntimeError("executed distribution identity handoff must be a component map")
    for component, observed in evidence.items():
        expected_version = distribution_version(artifact_versions, component)
        normalized = normalize_distribution_identity(component, observed, expected_version)
        record_distribution_identity(path, component, normalized)


def validate_required_distribution_identities(
    path: Path,
    artifact_versions: dict[str, str],
) -> tuple[dict[str, Any], list[str]]:
    try:
        identities = load_distribution_identities(path)
    except Exception as exc:  # noqa: BLE001 - malformed identity evidence is a product failure.
        return {}, [f"executed distribution identity evidence is invalid: {type(exc).__name__}: {exc}"]

    failures: list[str] = []
    for component in REQUIRED_DISTRIBUTION_IDENTITIES:
        observed = identities.get(component)
        if observed is None:
            failures.append(f"missing executed distribution evidence for {component}")
            continue
        try:
            identities[component] = normalize_distribution_identity(
                component,
                observed,
                distribution_version(artifact_versions, component),
            )
        except Exception as exc:  # noqa: BLE001 - report every malformed required identity.
            failures.append(f"invalid executed distribution evidence for {component}: {exc}")
    return identities, failures


def distribution_sha256_file(path: Path) -> str:
    if not path.is_file():
        raise RuntimeError(f"executed distribution artifact is missing: {path}")
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def unique_distribution_file(root: Path, pattern: str) -> Path:
    matches = sorted(path for path in root.glob(pattern) if path.is_file())
    if len(matches) != 1:
        raise RuntimeError(
            f"expected one executed distribution artifact matching {pattern} under {root}, found {len(matches)}"
        )
    return matches[0]


def record_distribution_file(
    component: str,
    version: str,
    artifact: Path,
    artifact_name: str | None = None,
) -> None:
    record_distribution_identity(
        executed_distribution_identities_path(),
        component,
        distribution_identity(
            component,
            version,
            artifact_name or artifact.name,
            distribution_sha256_file(artifact),
        ),
    )


def record_unique_distribution_file(
    component: str,
    version: str,
    root: Path,
    pattern: str,
    artifact_name: str | None = None,
) -> None:
    record_distribution_file(
        component,
        version,
        unique_distribution_file(root, pattern),
        artifact_name,
    )


def record_distribution_digest(
    component: str,
    version: str,
    artifact_name: str,
    digest: str,
) -> None:
    record_distribution_identity(
        executed_distribution_identities_path(),
        component,
        distribution_identity(component, version, artifact_name, digest.removeprefix("sha256:").lower()),
    )


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def env_text(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def env_flag(name: str, default: bool) -> bool:
    value = env_text(name)
    if value is None:
        return default
    return value.lower() not in {"0", "false", "no", "off"}


def log_line(log_file: Path, message: str) -> None:
    with log_file.open("a", encoding="utf-8") as handle:
        handle.write(f"{now()} {message}\n")


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def url_join(base_url: str, path: str) -> str:
    return base_url.rstrip("/") + "/" + path.lstrip("/")


def api_path(*parts: str) -> str:
    return "/api/" + "/".join(urllib.parse.quote(part, safe="._:-") for part in parts)


def http_json(
    base_url: str,
    path: str,
    *,
    method: str = "GET",
    body: Any = None,
    token: str,
    namespace: str,
    worker: bool = False,
    timeout: float = 30.0,
) -> dict[str, Any]:
    data = None
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Namespace": namespace,
    }
    if worker:
        headers["X-Durable-Workflow-Protocol-Version"] = "1.13"
    else:
        headers["X-Durable-Workflow-Control-Plane-Version"] = "2"

    if body is not None:
        data = json.dumps(body).encode("utf-8")

    request = urllib.request.Request(
        url_join(base_url, path),
        data=data,
        headers=headers,
        method=method,
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
            return {
                "status_code": response.status,
                "body": json.loads(raw) if raw.strip() else {},
            }
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            body_value = json.loads(raw) if raw.strip() else {}
        except json.JSONDecodeError:
            body_value = {"raw": raw}
        return {
            "status_code": exc.code,
            "body": body_value,
        }


def response_sample(response: dict[str, Any]) -> dict[str, Any]:
    body = response.get("body")
    if not isinstance(body, dict):
        body = {}

    sample: dict[str, Any] = {
        "status_code": response.get("status_code"),
        "reason": body.get("reason"),
    }

    for key in (
        "message",
        "rejection_reason",
        "outcome",
        "command_status",
        "validation_errors",
        "errors",
        "workflow_id",
        "run_id",
        "signal_name",
        "query_name",
        "query_task_id",
        "query_task_attempt",
        "command_contract_source",
        "command_contract_backfill_needed",
        "command_contract_backfill_available",
        "declared_signals",
        "signal_admission",
    ):
        if key in body:
            sample[key] = body[key]

    return sample


def command_contract() -> dict[str, Any]:
    int_parameter = {
        "name": "amount",
        "position": 0,
        "required": True,
        "variadic": False,
        "default_available": False,
        "default": None,
        "type": "int",
        "allows_null": False,
    }
    minimum_parameter = dict(int_parameter)
    minimum_parameter["name"] = "minimum"

    return {
        "queries": ["count-at-least", "current", "state"],
        "query_contracts": [
            {"name": "current", "parameters": []},
            {"name": "state", "parameters": []},
            {"name": "count-at-least", "parameters": [minimum_parameter]},
        ],
        "signals": ["increment"],
        "signal_contracts": [
            {"name": "increment", "parameters": [int_parameter]},
        ],
        "updates": [],
        "update_contracts": [],
    }


def command_available(command_name: str) -> bool:
    return shutil.which(command_name) is not None


def run_command(
    command: list[str],
    *,
    log_file: Path,
    env: dict[str, str] | None = None,
    cwd: Path | None = None,
    timeout: float = 120.0,
) -> subprocess.CompletedProcess[str]:
    log_line(log_file, "run " + " ".join(command))
    completed = subprocess.run(
        command,
        cwd=str(cwd) if cwd is not None else None,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
    )
    if completed.stdout:
        log_line(log_file, "stdout " + completed.stdout.strip())
    if completed.stderr:
        log_line(log_file, "stderr " + completed.stderr.strip())
    return completed


ACTIVE_COMPOSE_PROJECTS: dict[str, tuple[Path, dict[str, str], Path]] = {}
ACTIVE_CONTAINER_NAMES: dict[str, Path] = {}
ACTIVE_SCRATCH_ROOTS: dict[Path, Path] = {}
DOCKER_RUN_RESOURCE_LABEL = f"com.durableworkflow.conformance.run=signals-queries-{os.getpid()}-{time.time_ns()}"
DOCKER_RUN_COMMAND_BUILT = False


class ConformanceCleanupError(RuntimeError):
    pass


def docker_host_user_options() -> list[str]:
    """Keep every bind-mounted output owned by the invoking host identity."""
    return [
        "--user",
        f"{os.getuid()}:{os.getgid()}",
        "-e",
        "HOME=/tmp/dw-home",
        "-e",
        "COMPOSER_HOME=/tmp/dw-composer",
        "-e",
        "CARGO_HOME=/tmp/dw-cargo",
    ]


def docker_run_resource_options() -> list[str]:
    global DOCKER_RUN_COMMAND_BUILT
    DOCKER_RUN_COMMAND_BUILT = True
    return [
        *docker_host_user_options(),
        "--label",
        DOCKER_RUN_RESOURCE_LABEL,
    ]


def register_scratch_root(path: Path, log_file: Path) -> None:
    if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
        ACTIVE_SCRATCH_ROOTS[path] = log_file


def remove_scratch_root(path: Path) -> None:
    if path.exists() or path.is_symlink():
        shutil.rmtree(path)
    if path.exists() or path.is_symlink():
        raise ConformanceCleanupError(f"scratch root still exists after removal: {path}")
    ACTIVE_SCRATCH_ROOTS.pop(path, None)


def register_container(container_name: str, log_file: Path) -> None:
    ACTIVE_CONTAINER_NAMES[container_name] = log_file


def cleanup_container(container_name: str, log_file: Path) -> None:
    removal = run_command(
        ["docker", "rm", "-f", container_name],
        log_file=log_file,
        timeout=60,
    )
    inspect = run_command(
        ["docker", "inspect", container_name],
        log_file=log_file,
        timeout=30,
    )
    if inspect.returncode == 0:
        raise ConformanceCleanupError(
            f"container still exists after removal: {container_name}; rm exit={removal.returncode}"
        )
    ACTIVE_CONTAINER_NAMES.pop(container_name, None)


def cleanup_labeled_docker_runs(log_file: Path) -> None:
    if not DOCKER_RUN_COMMAND_BUILT or not command_available("docker"):
        return
    listed = run_command(
        ["docker", "container", "ls", "-a", "-q", "--filter", f"label={DOCKER_RUN_RESOURCE_LABEL}"],
        log_file=log_file,
        timeout=30,
    )
    if listed.returncode != 0:
        raise ConformanceCleanupError("could not enumerate labeled signals/queries Docker runs")
    containers = [line.strip() for line in listed.stdout.splitlines() if line.strip()]
    if containers:
        run_command(["docker", "rm", "-f", *containers], log_file=log_file, timeout=120)
    verify = run_command(
        ["docker", "container", "ls", "-a", "-q", "--filter", f"label={DOCKER_RUN_RESOURCE_LABEL}"],
        log_file=log_file,
        timeout=30,
    )
    if verify.returncode != 0 or verify.stdout.strip():
        raise ConformanceCleanupError(
            f"labeled signals/queries Docker runs remain after cleanup: {verify.stdout.strip()}"
        )


def register_compose_project(
    project: str,
    compose: Path,
    env: dict[str, str],
    log_file: Path,
) -> None:
    # Registration precedes `compose up`, so partial setup and interruption are covered.
    ACTIVE_COMPOSE_PROJECTS[project] = (compose, dict(env), log_file)


def docker_project_resources(kind: str, project: str, log_file: Path) -> list[str]:
    command = [
        "docker",
        kind,
        "ls",
        "-q",
        "--filter",
        f"label=com.docker.compose.project={project}",
    ]
    if kind == "container":
        command.insert(3, "-a")
    listed = run_command(command, log_file=log_file, timeout=30)
    if listed.returncode != 0:
        raise ConformanceCleanupError(
            f"could not enumerate {kind} resources for Compose project {project}"
        )
    return [line.strip() for line in listed.stdout.splitlines() if line.strip()]


def cleanup_compose_project(project: str) -> None:
    registered = ACTIVE_COMPOSE_PROJECTS.get(project)
    if registered is None:
        return
    compose, env, log_file = registered
    prefix = ["docker", "compose", "-p", project, "-f", str(compose)]

    # Exact label-scoped fallback removal handles partial projects and interrupted down calls.
    run_command(
        [*prefix, "down", "-v", "--remove-orphans"],
        log_file=log_file,
        env=env,
        timeout=180,
    )
    remaining: dict[str, list[str]] = {}
    for _attempt in range(2):
        containers = docker_project_resources("container", project, log_file)
        if containers:
            run_command(["docker", "rm", "-f", *containers], log_file=log_file, timeout=120)

        volumes = docker_project_resources("volume", project, log_file)
        if volumes:
            run_command(["docker", "volume", "rm", "-f", *volumes], log_file=log_file, timeout=120)

        networks = docker_project_resources("network", project, log_file)
        if networks:
            run_command(["docker", "network", "rm", *networks], log_file=log_file, timeout=120)

        remaining = {
            kind: docker_project_resources(kind, project, log_file)
            for kind in ("container", "volume", "network")
        }
        if not any(remaining.values()):
            ACTIVE_COMPOSE_PROJECTS.pop(project, None)
            return

    raise ConformanceCleanupError(
        f"Compose project resources remain after cleanup: {project}: {remaining}"
    )


def cleanup_commands_deterministically(commands: list[list[str]]) -> None:
    for command in commands:
        try:
            project = command[command.index("-p") + 1]
        except (ValueError, IndexError):
            raise ConformanceCleanupError(f"cleanup command does not identify a Compose project: {command}")
        cleanup_compose_project(project)


def cleanup_registered_resources() -> None:
    failures: list[str] = []
    cleanup_log = Path(os.devnull)
    if ACTIVE_SCRATCH_ROOTS:
        cleanup_log = next(iter(ACTIVE_SCRATCH_ROOTS.values()))
    if ACTIVE_COMPOSE_PROJECTS:
        cleanup_log = next(iter(ACTIVE_COMPOSE_PROJECTS.values()))[2]
    if ACTIVE_CONTAINER_NAMES:
        cleanup_log = next(iter(ACTIVE_CONTAINER_NAMES.values()))
    if DOCKER_RUN_COMMAND_BUILT:
        try:
            cleanup_labeled_docker_runs(cleanup_log)
        except Exception as exc:  # noqa: BLE001 - finish every exact cleanup before reporting.
            failures.append(f"labeled Docker runs: {type(exc).__name__}: {exc}")
    for container_name, log_file in list(ACTIVE_CONTAINER_NAMES.items())[::-1]:
        try:
            cleanup_container(container_name, log_file)
        except Exception as exc:  # noqa: BLE001 - finish every exact cleanup before reporting.
            failures.append(f"container {container_name}: {type(exc).__name__}: {exc}")
    for project in list(ACTIVE_COMPOSE_PROJECTS)[::-1]:
        try:
            cleanup_compose_project(project)
        except Exception as exc:  # noqa: BLE001 - finish every exact cleanup before reporting.
            failures.append(f"Compose project {project}: {type(exc).__name__}: {exc}")
    for path in list(ACTIVE_SCRATCH_ROOTS)[::-1]:
        try:
            remove_scratch_root(path)
        except Exception as exc:  # noqa: BLE001 - finish every exact cleanup before reporting.
            failures.append(f"scratch root {path}: {type(exc).__name__}: {exc}")
    if failures:
        raise ConformanceCleanupError("; ".join(failures))


def emergency_cleanup() -> None:
    try:
        cleanup_registered_resources()
    except Exception as exc:  # noqa: BLE001 - cleanup may not fail silently at process exit.
        print(f"signals/queries conformance cleanup failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        sys.stderr.flush()
        os._exit(1)


def terminate_after_cleanup(signum: int, _frame: Any) -> None:
    # Ignore repeat termination while Python unwinds its `finally` blocks and atexit cleanup.
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    signal.signal(signal.SIGINT, signal.SIG_IGN)
    raise SystemExit(128 + signum)


atexit.register(emergency_cleanup)
signal.signal(signal.SIGTERM, terminate_after_cleanup)
signal.signal(signal.SIGINT, terminate_after_cleanup)


class ServerReadinessTopologyError(RuntimeError):
    def __init__(self, message: str, details: dict[str, Any]):
        super().__init__(message)
        self.details = details


def env_float(name: str, default: float) -> float:
    value = env_text(name)
    if value is None:
        return default
    try:
        parsed = float(value)
    except ValueError:
        return default
    if parsed <= 0:
        return default
    return parsed


def diagnostic_path(value: str) -> str:
    repo_root = os.environ.get("REPO_ROOT", "").rstrip(os.sep)
    if repo_root and value.startswith(repo_root + os.sep):
        return value[len(repo_root) + 1:]
    return value


def diagnostic_command(command: list[str]) -> list[str]:
    diagnostic: list[str] = []
    for index, part in enumerate(command):
        value = diagnostic_path(part)
        if index > 0 and command[index - 1] in {"-e", "--env"} and "=" in value:
            key, _ = value.split("=", 1)
            if "PASSWORD" in key or "TOKEN" in key or "SECRET" in key:
                value = f"{key}=<redacted>"
        diagnostic.append(value)
    return diagnostic


def diagnostic_output_tail(value: str, limit: int = 1024) -> str:
    text = value.strip()
    encoded = text.encode("utf-8", errors="replace")
    if len(encoded) <= limit:
        return text

    marker = "... output truncated; retained tail follows ...\n"
    retained_bytes = max(0, limit - len(marker.encode("utf-8")))
    tail = encoded[-retained_bytes:].decode("utf-8", errors="replace")
    bounded = marker + tail
    while len(bounded.encode("utf-8")) > limit and tail:
        tail = tail[1:]
        bounded = marker + tail
    return bounded


def command_summary(command: list[str], completed: subprocess.CompletedProcess[str]) -> dict[str, Any]:
    def bounded(value: str) -> str:
        if len(value) <= DIAGNOSTIC_OUTPUT_LIMIT:
            return value

        head_size = DIAGNOSTIC_OUTPUT_LIMIT // 4
        tail_size = DIAGNOSTIC_OUTPUT_LIMIT - head_size - 80
        omitted = len(value) - head_size - tail_size
        return (
            value[:head_size]
            + f"\n... {omitted} characters omitted ...\n"
            + value[-tail_size:]
        )

    return {
        "command": diagnostic_command(command),
        "exit_code": completed.returncode,
        "stdout": bounded(completed.stdout.strip()),
        "stderr": bounded(completed.stderr.strip()),
    }


def capture_command_summary(
    command: list[str],
    *,
    log_file: Path,
    env: dict[str, str] | None = None,
    timeout: float = 30.0,
) -> dict[str, Any]:
    try:
        return command_summary(
            command,
            run_command(command, log_file=log_file, env=env, timeout=timeout),
        )
    except Exception as exc:  # noqa: BLE001 - diagnostic capture must not hide the readiness failure.
        log_line(log_file, f"diagnostic command failed: {' '.join(command)}: {type(exc).__name__}: {exc}")
        return {
            "command": diagnostic_command(command),
            "error": f"{type(exc).__name__}: {exc}",
        }


def server_ready_timeout_seconds(default: float) -> float:
    return env_float("DW_SIGNALS_QUERIES_SERVER_READY_TIMEOUT_SECONDS", default)


def ordered_unique(values: list[str]) -> list[str]:
    seen: list[str] = []
    for value in values:
        normalized = value.strip().rstrip("/")
        if normalized and normalized not in seen:
            seen.append(normalized)
    return seen


def normalize_host(host: str | None) -> str:
    if host is None:
        return ""
    normalized = host.strip().strip("[]")
    if not normalized or normalized == "*":
        return normalized
    try:
        return str(ipaddress.ip_address(normalized))
    except ValueError:
        return normalized


def is_wildcard_host(host: str | None) -> bool:
    return normalize_host(host) in {"", "0.0.0.0", "::", "*"}


def host_port_url(host: str, port: int) -> str | None:
    host = normalize_host(host)
    if not host or port <= 0:
        return None
    if ":" in host:
        return f"http://[{host}]:{port}"
    return f"http://{host}:{port}"


def docker_host_from_env() -> str | None:
    value = env_text("DOCKER_HOST")
    if value is None:
        return None
    if value.startswith(("tcp://", "http://", "https://")):
        value = value.split("://", 1)[1].split("/", 1)[0]
        if value.startswith("[") and "]" in value:
            value = value[1:].split("]", 1)[0]
        else:
            value = value.rsplit(":", 1)[0]
        if value not in {"127.0.0.1", "localhost"}:
            return value
    return None


def default_route_gateway() -> str | None:
    try:
        with Path("/proc/net/route").open("r", encoding="utf-8") as route_file:
            next(route_file, None)
            for line in route_file:
                fields = line.strip().split()
                if len(fields) < 3 or fields[1] != "00000000" or fields[2] == "00000000":
                    continue
                return socket.inet_ntoa(bytes.fromhex(fields[2])[::-1])
    except OSError:
        return None
    return None


def docker_bridge_gateway(log_file: Path, env: dict[str, str] | None = None) -> str | None:
    try:
        completed = run_command(
            ["docker", "network", "inspect", "bridge", "--format", "{{(index .IPAM.Config 0).Gateway}}"],
            log_file=log_file,
            env=env,
            timeout=30,
        )
    except Exception as exc:  # noqa: BLE001 - best-effort topology diagnostics.
        log_line(log_file, f"docker bridge gateway discovery failed: {type(exc).__name__}: {exc}")
        return None

    value = completed.stdout.strip()
    if completed.returncode == 0 and value and value != "<no value>":
        return value
    return None


def server_url_candidates_for_port(
    port: int,
    *,
    bind_host: str | None = None,
    preferred_host: str | None = None,
    log_file: Path | None = None,
    env: dict[str, str] | None = None,
) -> list[str]:
    candidates: list[str] = []
    preferred_host = (
        preferred_host
        or env_text("DW_SIGNALS_QUERIES_SERVER_CONNECT_HOST")
        or "127.0.0.1"
    )

    for host in (preferred_host, "127.0.0.1", "localhost"):
        candidate = host_port_url(host, port)
        if candidate is not None:
            candidates.append(candidate)

    if not is_wildcard_host(bind_host) and bind_host not in {"127.0.0.1", "localhost"}:
        candidate = host_port_url(str(bind_host), port)
        if candidate is not None:
            candidates.append(candidate)

    for host in (
        env_text("DW_SIGNALS_QUERIES_DOCKER_HOST_GATEWAY"),
        env_text("DOCKER_HOST_GATEWAY"),
        env_text("HOST_DOCKER_INTERNAL"),
        docker_host_from_env(),
        default_route_gateway(),
    ):
        if host:
            candidate = host_port_url(host, port)
            if candidate is not None:
                candidates.append(candidate)

    if log_file is not None:
        gateway = docker_bridge_gateway(log_file, env)
        if gateway:
            candidate = host_port_url(gateway, port)
            if candidate is not None:
                candidates.append(candidate)

    for host in ("host.docker.internal", "gateway.docker.internal"):
        candidate = host_port_url(host, port)
        if candidate is not None:
            candidates.append(candidate)

    return ordered_unique(candidates)


def parse_host_port_binding(line: str) -> tuple[str, int] | None:
    binding = line.strip().split(maxsplit=1)[0] if line.strip() else ""
    if not binding:
        return None

    if binding.startswith("[") and "]:" in binding:
        host, port_text = binding[1:].split("]:", 1)
    else:
        host, separator, port_text = binding.rpartition(":")
        if not separator:
            return None

    if not port_text.isdigit():
        return None

    return host.strip("[]"), int(port_text)


def server_url_candidates_from_published_port(
    published_port_output: str,
    *,
    fallback_port: int,
    preferred_host: str | None = None,
    log_file: Path,
    env: dict[str, str],
) -> list[str]:
    candidates: list[str] = []
    for line in published_port_output.splitlines():
        parsed = parse_host_port_binding(line)
        if parsed is None:
            continue
        bind_host, mapped_port = parsed
        candidates.extend(
            server_url_candidates_for_port(
                mapped_port,
                bind_host=bind_host,
                preferred_host=preferred_host,
                log_file=log_file,
                env=env,
            )
        )

    if not candidates:
        candidates.extend(
            server_url_candidates_for_port(
                fallback_port,
                preferred_host=preferred_host,
                log_file=log_file,
                env=env,
            )
        )

    return ordered_unique(candidates)


def readiness_error_summary(errors: dict[str, str], candidates: list[str]) -> str:
    if not errors:
        return "no response before timeout"
    return " | ".join(
        f"{candidate}: {errors.get(candidate, 'no response before timeout')}"
        for candidate in candidates
    )


def wait_for_ready(
    base_url: str | list[str],
    log_file: Path,
    timeout_seconds: float = 90.0,
    diagnostics: dict[str, Any] | None = None,
) -> dict[str, Any]:
    candidates = ordered_unique([base_url] if isinstance(base_url, str) else base_url)
    if not candidates:
        candidates = ["http://127.0.0.1:8080"]

    deadline = time.time() + timeout_seconds
    details: dict[str, Any] = {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": candidates[0],
        "ready_url": url_join(candidates[0], "/api/ready"),
        "ready_urls": [url_join(candidate, "/api/ready") for candidate in candidates],
        "server_url_candidates": candidates,
        "timeout_seconds": timeout_seconds,
        "readiness_attempts": 0,
        "last_readiness_error": None,
        "candidate_readiness_errors": {},
    }
    if diagnostics:
        details.update(diagnostics)
        details["kind"] = "server_readiness_topology"
        details["effective_host_endpoint"] = candidates[0]
        details["ready_url"] = url_join(candidates[0], "/api/ready")
        details["ready_urls"] = [url_join(candidate, "/api/ready") for candidate in candidates]
        details["server_url_candidates"] = candidates
        details.setdefault("candidate_readiness_errors", {})

    candidate_errors: dict[str, str] = {}
    candidate_status_codes: dict[str, int] = {}

    while time.time() < deadline:
        for candidate in candidates:
            if time.time() >= deadline:
                break
            ready_url = url_join(candidate, "/api/ready")
            details["readiness_attempts"] = int(details["readiness_attempts"]) + 1
            details["effective_host_endpoint"] = candidate
            details["ready_url"] = ready_url
            try:
                request_timeout = min(2, max(0.2, deadline - time.time()))
                with urllib.request.urlopen(ready_url, timeout=request_timeout) as response:
                    details["last_readiness_status_code"] = response.status
                    candidate_status_codes[candidate] = response.status
                    if 200 <= response.status < 300:
                        details["ready_at"] = now()
                        details["candidate_readiness_errors"] = dict(candidate_errors)
                        details["candidate_readiness_status_codes"] = dict(candidate_status_codes)
                        log_line(log_file, f"published server ready at {ready_url}")
                        return details
                    candidate_errors[candidate] = f"HTTPStatus: {response.status}"
            except urllib.error.HTTPError as exc:
                details["last_readiness_status_code"] = exc.code
                candidate_status_codes[candidate] = exc.code
                body = exc.read().decode("utf-8", errors="replace")
                candidate_errors[candidate] = f"HTTPError: {exc.code} {body[:500]}"
            except Exception as exc:  # noqa: BLE001 - diagnostic best effort for conformance logs.
                candidate_errors[candidate] = f"{type(exc).__name__}: {exc}"

            details["candidate_readiness_errors"] = dict(candidate_errors)
            details["candidate_readiness_status_codes"] = dict(candidate_status_codes)
            details["last_readiness_error"] = readiness_error_summary(candidate_errors, candidates)
            log_line(log_file, f"readiness probe failed at {ready_url}: {candidate_errors[candidate]}")
        time.sleep(min(1, max(0, deadline - time.time())))

    if not details.get("last_readiness_error"):
        details["last_readiness_error"] = "readiness probe did not run before timeout"
    else:
        details["last_readiness_error"] = readiness_error_summary(candidate_errors, candidates)
    details["candidate_readiness_errors"] = dict(candidate_errors)
    details["candidate_readiness_status_codes"] = dict(candidate_status_codes)

    raise ServerReadinessTopologyError(
        "published server did not become ready from host endpoints "
        f"{', '.join(candidates)}: {details['last_readiness_error']}",
        details,
    )


SERVER_PATCH_TAG_RE = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
PUBLISHED_SERVER_IMAGE_REPOSITORIES = {
    "durableworkflow/server",
    "docker.io/durableworkflow/server",
    "index.docker.io/durableworkflow/server",
    "registry-1.docker.io/durableworkflow/server",
}


def normalize_docker_image_reference(image: str) -> str:
    return image.strip().removeprefix("docker://")


def server_repository_from_image(image: str) -> str:
    image = normalize_docker_image_reference(image)
    without_digest = image.split("@", 1)[0]
    tail = without_digest.rsplit("/", 1)[-1]
    if ":" in tail:
        without_digest = without_digest.rsplit(":", 1)[0]
    return without_digest


def server_tag_from_image(image: str) -> str | None:
    image = normalize_docker_image_reference(image)
    without_digest = image.split("@", 1)[0]
    tail = without_digest.rsplit("/", 1)[-1]
    if ":" not in tail:
        return None
    return tail.rsplit(":", 1)[1]


def is_exact_server_patch_tag(version: str) -> bool:
    normalized = version.strip()
    prerelease = normalized.partition("-")[2]
    rolling = {"latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder"}
    return SERVER_PATCH_TAG_RE.match(normalized) is not None and not any(
        identifier.lower() in rolling for identifier in prerelease.split(".") if identifier
    )


def is_digest_pinned_server_image(image: str) -> bool:
    image = normalize_docker_image_reference(image)
    if "@" not in image:
        return False
    digest = image.rsplit("@", 1)[1]
    return re.match(r"^sha256:[0-9a-fA-F]{64}$", digest) is not None


def published_server_image_install_proven(image: str, version: str) -> bool:
    image = normalize_docker_image_reference(image)
    version = version.strip()
    if not is_exact_server_patch_tag(version):
        return False
    if server_repository_from_image(image) not in PUBLISHED_SERVER_IMAGE_REPOSITORIES:
        return False

    tag = server_tag_from_image(image)
    if is_digest_pinned_server_image(image):
        return tag is None or not is_exact_server_patch_tag(tag) or tag == version

    if tag is not None:
        if not is_exact_server_patch_tag(tag) or tag != version:
            return False
        return True

    return False


def server_image_not_proved_reason(image: str, version: str) -> str:
    image = normalize_docker_image_reference(image)
    version = version.strip()
    if not is_exact_server_patch_tag(version):
        return "DW_SERVER_VERSION must be an exact SemVer Docker tag"
    if server_repository_from_image(image) not in PUBLISHED_SERVER_IMAGE_REPOSITORIES:
        return "DW_SERVER_IMAGE is not a durableworkflow/server published image reference"

    tag = server_tag_from_image(image)
    if "@" in image and not is_digest_pinned_server_image(image):
        return "DW_SERVER_IMAGE digest must be a sha256 digest-pinned reference"

    if tag is None:
        return "DW_SERVER_IMAGE must use an exact SemVer tag or an image digest"

    if tag is not None:
        if not is_exact_server_patch_tag(tag) and not is_digest_pinned_server_image(image):
            return "DW_SERVER_IMAGE must use an exact SemVer tag or an image digest"
        if tag != version:
            return f"DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {tag!r}"

    return (
        "DW_SERVER_IMAGE must be an exact durableworkflow/server tag or digest-pinned reference "
        "matching DW_SERVER_VERSION to prove published server install evidence"
    )


def server_image_for_compose(server_version: str) -> str:
    explicit = env_text("DW_SERVER_IMAGE")
    if explicit:
        return normalize_docker_image_reference(explicit)
    return f"durableworkflow/server:{server_version}"


def compose_published_server_diagnostics(
    *,
    project: str,
    compose: Path,
    env: dict[str, str],
    base_url: str,
    port: int,
    image: str,
    cleanup_command: list[str],
    log_file: Path,
) -> dict[str, Any]:
    compose_prefix = ["docker", "compose", "-p", project, "-f", str(compose)]
    compose_published_port = capture_command_summary(
        [*compose_prefix, "port", "server", "8080"],
        log_file=log_file,
        env=env,
        timeout=30,
    )
    mapped_port = str(compose_published_port.get("stdout") or "").strip()
    port_state = "reported" if mapped_port else "not_reported"
    if compose_published_port.get("exit_code") not in (0, None):
        port_state = "command_failed"
    if compose_published_port.get("error"):
        port_state = "command_error"
    server_url_candidates = server_url_candidates_from_published_port(
        mapped_port,
        fallback_port=port,
        log_file=log_file,
        env=env,
    )

    return {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": base_url,
        "compose_project": project,
        "compose_file": diagnostic_path(str(compose)),
        "compose_server_port": port,
        "mapped_server_port": mapped_port or None,
        "published_port_state": port_state,
        "server_url_candidates": server_url_candidates,
        "server_image": image,
        "cleanup_commands": [cleanup_command],
        "compose_published_port": compose_published_port,
        "compose_ps": capture_command_summary(
            [*compose_prefix, "ps"],
            log_file=log_file,
            env=env,
            timeout=30,
        ),
        "docker_containers": capture_command_summary(
            ["docker", "container", "ls", "-a", "--filter", f"label=com.docker.compose.project={project}"],
            log_file=log_file,
            env=env,
            timeout=30,
        ),
    }


def configured_server_diagnostics(base_url: str) -> dict[str, Any]:
    return {
        "kind": "server_readiness_topology",
        "effective_host_endpoint": base_url.rstrip("/"),
        "endpoint_source": "configured_server_url",
    }


def cleanup_commands_from_blocker(details: dict[str, Any]) -> list[list[str]]:
    commands = details.get("cleanup_commands")
    if not isinstance(commands, list):
        return []

    normalized: list[list[str]] = []
    for command in commands:
        if not isinstance(command, list):
            continue
        normalized_command = [str(part) for part in command if isinstance(part, str)]
        if normalized_command:
            normalized.append(normalized_command)

    return normalized


def start_published_server(run_root: Path, log_file: Path) -> tuple[str, list[list[str]], dict[str, Any]]:
    if not command_available("docker"):
        raise RuntimeError("docker is required to start the published server")

    compose = Path(os.environ.get("REPO_ROOT", os.getcwd())) / "docker-compose.published.yml"
    if not compose.is_file():
        raise RuntimeError(f"published compose file not found: {compose}")

    server_version = artifact_version_value(artifact_versions, "server")
    if is_placeholder_version(server_version):
        raise RuntimeError("DW_SERVER_VERSION must be a concrete published server version")

    port = int(env_text("DW_SIGNALS_QUERIES_SERVER_PORT") or free_port())
    token = env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN") or env_text("DURABLE_WORKFLOW_AUTH_TOKEN") or "dev-token"
    project = "dw-signals-queries-" + run_root.name.lower().replace(".", "-").replace("_", "-")
    env = os.environ.copy()
    image = server_image_for_compose(server_version)
    env.update(
        {
            "SERVER_PORT": str(port),
            "DW_SERVER_TAG": server_version,
            "DW_SERVER_IMAGE": image,
            "DW_AUTH_TOKEN": token,
            "DW_AUTH_BACKWARD_COMPATIBLE": "true",
        }
    )

    commands = [
        ["docker", "compose", "-p", project, "-f", str(compose), "down", "-v"],
        ["docker", "compose", "-p", project, "-f", str(compose), "up", "-d", "--wait", "server"],
    ]
    cleanup_command = commands[0]

    register_compose_project(project, compose, env, log_file)
    run_command(commands[0], log_file=log_file, env=env, timeout=120)
    up = run_command(commands[1], log_file=log_file, env=env, timeout=240)
    if up.returncode != 0:
        raise RuntimeError("docker compose failed to start the published server")

    inspection = run_command(
        ["docker", "image", "inspect", "--format", "{{json .RepoDigests}}", image],
        log_file=log_file,
        timeout=60,
    )
    try:
        repo_digests = json.loads(inspection.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError("pulled server image repository digests were not valid JSON") from exc
    executed_digest = next(
        (
            str(candidate).rsplit("@", 1)[1]
            for candidate in repo_digests
            if isinstance(candidate, str)
            and re.fullmatch(
                r"(?:(?:docker\.io|index\.docker\.io)/)?durableworkflow/server@sha256:[0-9a-f]{64}",
                candidate,
                re.IGNORECASE,
            )
        ),
        None,
    )
    if executed_digest is None:
        raise RuntimeError("pulled server image has no durableworkflow/server manifest digest")
    record_distribution_digest("server", server_version, "manifest", executed_digest)

    base_url = f"http://127.0.0.1:{port}"
    compose_diagnostics = compose_published_server_diagnostics(
        project=project,
        compose=compose,
        env=env,
        base_url=base_url,
        port=port,
        image=image,
        cleanup_command=cleanup_command,
        log_file=log_file,
    )
    try:
        server_url_candidates = [
            str(candidate)
            for candidate in compose_diagnostics.get("server_url_candidates", [])
            if isinstance(candidate, str)
        ] or [base_url]
        readiness = wait_for_ready(
            server_url_candidates,
            log_file,
            timeout_seconds=server_ready_timeout_seconds(90),
            diagnostics=compose_diagnostics,
        )
        base_url = str(readiness.get("effective_host_endpoint") or base_url).rstrip("/")
    except ServerReadinessTopologyError as exc:
        details = dict(compose_diagnostics)
        details.update(exc.details)
        raise ServerReadinessTopologyError(str(exc), details) from exc

    return base_url, [cleanup_command], readiness


def artifact_install_evidence_entry(
    *,
    artifact: str,
    version: str,
    source: str,
    status: str,
    install_method: str,
    installed_from_public_artifact: bool,
) -> dict[str, Any]:
    return {
        "artifact": artifact,
        "status": status,
        "version": version,
        "source": source,
        "install_method": install_method,
        "installed_from_public_artifact": installed_from_public_artifact,
        "local_product_source_checkouts_used": False,
    }


def configured_artifact_entry(artifact: str, version: str, source: str, install_method: str) -> dict[str, Any]:
    return artifact_install_evidence_entry(
        artifact=artifact,
        version=version,
        source=source,
        status="not_proved",
        install_method=install_method,
        installed_from_public_artifact=False,
    )


def installed_public_artifact_entry(artifact: str, version: str, source: str, install_method: str) -> dict[str, Any]:
    return artifact_install_evidence_entry(
        artifact=artifact,
        version=version,
        source=source,
        status="pass",
        install_method=install_method,
        installed_from_public_artifact=True,
    )


def server_install_entry(cleanup_commands: list[list[str]]) -> dict[str, Any]:
    version = artifact_version_value(artifact_versions, "server")
    if cleanup_commands:
        image = server_image_for_compose(version)
        if published_server_image_install_proven(image, version):
            entry = installed_public_artifact_entry(
                "server",
                version,
                EXPECTED_ARTIFACT_SOURCES["server"],
                "docker_compose_published_image",
            )
            entry["image"] = image
            entry["image_provenance"] = "durableworkflow_server_exact_tag_or_digest"
            return entry

        entry = configured_artifact_entry(
            "server",
            version,
            "configured_server_image",
            "docker_compose_configured_image_override",
        )
        entry["image"] = image
        entry["not_proved_reason"] = server_image_not_proved_reason(image, version)
        return entry

    return configured_artifact_entry(
        "server",
        version,
        "configured_server_endpoint",
        "configured_server_url",
    )


def install_cli(run_root: Path, log_file: Path) -> tuple[str, dict[str, Any]]:
    cli_version = artifact_version_value(artifact_versions, "cli")
    explicit = env_text("DW_SIGNALS_QUERIES_CLI_BIN") or env_text("DW_CLI_BIN")
    if explicit:
        if Path(explicit).is_file() and os.access(explicit, os.X_OK):
            return explicit, configured_artifact_entry(
                "cli",
                cli_version,
                "configured_cli_binary",
                "configured_cli_binary_override",
            )
        raise RuntimeError(f"configured CLI binary is not executable: {explicit}")

    if is_placeholder_version(cli_version):
        raise RuntimeError("DW_CLI_VERSION must be concrete to install the public CLI")

    cli_root = run_root / "cli"
    bin_dir = cli_root / "bin"
    cli_root.mkdir(parents=True, exist_ok=True)
    bin_dir.mkdir(parents=True, exist_ok=True)

    tags = [cli_version]
    if not cli_version.startswith("v"):
        tags.append(f"v{cli_version}")

    installer = cli_root / "install.sh"
    errors: list[str] = []
    for tag in tags:
        url = f"https://github.com/durable-workflow/cli/releases/download/{tag}/install.sh"
        try:
            with urllib.request.urlopen(url, timeout=30) as response:
                installer.write_bytes(response.read())
            break
        except Exception as exc:  # noqa: BLE001 - try both public tag spellings.
            errors.append(f"{url}: {type(exc).__name__}: {exc}")
    else:
        raise RuntimeError("official CLI installer is not downloadable: " + "; ".join(errors))

    record_distribution_file("cli", cli_version, installer, "install.sh")
    installer.chmod(0o755)
    env = os.environ.copy()
    env.update(
        {
            "VERSION": cli_version,
            "DURABLE_WORKFLOW_INSTALL_DIR": str(bin_dir),
            "DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS": "0",
        }
    )
    env["PATH"] = os.pathsep.join(
        part for part in (str(bin_dir), env.get("PATH", "")) if part
    )
    install = run_command(["sh", str(installer)], log_file=log_file, env=env, timeout=180)
    if install.returncode != 0:
        raise RuntimeError("official CLI installer failed")

    binary = bin_dir / "dw"
    if not binary.is_file() or not os.access(binary, os.X_OK):
        raise RuntimeError("official CLI installer did not create an executable dw binary")
    resolved_binary = shutil.which("dw", path=env["PATH"])
    if resolved_binary is None or Path(resolved_binary).resolve() != binary.resolve():
        raise RuntimeError("ordinary dw lookup did not resolve the exact installed CLI binary")

    return str(binary), installed_public_artifact_entry(
        "cli",
        cli_version,
        EXPECTED_ARTIFACT_SOURCES["cli"],
        "github_release_installer",
    )


def client_sample_field(sample: dict[str, Any], *field_names: str) -> Any:
    pending: list[dict[str, Any]] = [sample]
    visited: set[int] = set()
    while pending:
        candidate = pending.pop(0)
        identity = id(candidate)
        if identity in visited:
            continue
        visited.add(identity)
        for field_name in field_names:
            if field_name in candidate and candidate[field_name] is not None:
                return candidate[field_name]
        for nested_key in (
            "body",
            "details",
            "error",
            "output",
            "response",
            "server_response",
            "stderr_output",
        ):
            nested = candidate.get(nested_key)
            if isinstance(nested, dict):
                pending.append(nested)
    return None


def cli_json_sample(
    cli_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    command: list[str],
    log_file: Path,
) -> dict[str, Any]:
    env = os.environ.copy()
    env.update(
        {
            "DURABLE_WORKFLOW_SERVER_URL": base_url,
            "DURABLE_WORKFLOW_AUTH_TOKEN": token,
            "DURABLE_WORKFLOW_NAMESPACE": namespace,
            "DURABLE_WORKFLOW_TLS_VERIFY": "false",
        }
    )
    completed = run_command([cli_bin, *command], log_file=log_file, env=env, timeout=60)
    stdout_decoded = json_sample_from_stdout(completed.stdout)
    stderr_decoded = json_sample_from_stdout(completed.stderr)
    stdout_structured = bool(stdout_decoded) and set(stdout_decoded) != {"raw_stdout"}
    stderr_structured = bool(stderr_decoded) and set(stderr_decoded) != {"raw_stdout"}
    if stderr_structured and not stdout_structured:
        decoded = stderr_decoded
    else:
        decoded = stdout_decoded
        if stderr_structured:
            decoded["stderr_output"] = stderr_decoded
    sample = {
        "client": "cli",
        "operation": command[0] if command else None,
        "operation_name": command[2] if len(command) > 2 else None,
        "workflow_id": command[1] if len(command) > 1 else None,
        "command": "dw " + " ".join(command),
        "command_argv": ["dw", *command],
        "exit_code": completed.returncode,
        "ok": completed.returncode == 0,
        "status_code": client_sample_field(
            decoded,
            "status_code",
            "statusCode",
            "http_status",
            "httpStatus",
        ),
        "reason": client_sample_field(
            decoded,
            "reason",
            "public_reason",
            "publicReason",
            "rejection_reason",
            "rejectionReason",
        ),
        "validation_errors": client_sample_field(
            decoded,
            "validation_errors",
            "validationErrors",
        ),
        "server_response": client_sample_field(
            decoded,
            "server_response",
            "serverResponse",
            "response",
        ),
        "output": decoded,
        "stdout_tail": diagnostic_output_tail(completed.stdout),
        "stderr_tail": diagnostic_output_tail(completed.stderr),
    }
    if completed.returncode != 0 and completed.stderr.strip():
        sample["stderr"] = completed.stderr.strip()
    return sample


def ensure_python_sdk(run_root: Path, log_file: Path) -> tuple[str, dict[str, Any]]:
    sdk_version = artifact_version_value(artifact_versions, "sdk-python")
    explicit = env_text("DW_SIGNALS_QUERIES_PYTHON")
    if explicit:
        add_python_sdk_fastavro_dependency(explicit, log_file)
        return explicit, configured_artifact_entry(
            "sdk-python",
            sdk_version,
            "configured_python_environment",
            "configured_python_executable_override",
        )

    if is_placeholder_version(sdk_version):
        raise RuntimeError("DW_PYTHON_SDK_VERSION must be concrete to install the public Python SDK")

    venv_dir = run_root / "python-sdk"
    create = run_command([sys.executable, "-m", "venv", str(venv_dir)], log_file=log_file, timeout=120)
    if create.returncode != 0:
        raise RuntimeError("could not create Python SDK virtual environment")

    python_bin = venv_dir / "bin" / "python"
    pip = run_command([str(python_bin), "-m", "pip", "install", "--upgrade", "pip"], log_file=log_file, timeout=180)
    if pip.returncode != 0:
        raise RuntimeError("could not upgrade pip in Python SDK virtual environment")

    distribution_dir = venv_dir / "distributions"
    distribution_dir.mkdir(parents=True, exist_ok=True)
    download = run_command(
        [
            str(python_bin),
            "-m",
            "pip",
            "download",
            "--no-deps",
            "--dest",
            str(distribution_dir),
            f"durable-workflow=={sdk_version}",
        ],
        log_file=log_file,
        timeout=240,
    )
    if download.returncode != 0:
        raise RuntimeError("could not download the public Python SDK distribution")
    distribution = unique_distribution_file(distribution_dir, "*")
    record_distribution_file("sdk-python", sdk_version, distribution)
    install = run_command(
        [str(python_bin), "-m", "pip", "install", str(distribution)],
        log_file=log_file,
        timeout=240,
    )
    if install.returncode != 0:
        raise RuntimeError("could not install the public Python SDK artifact")

    add_python_sdk_fastavro_dependency(str(python_bin), log_file)
    return str(python_bin), installed_public_artifact_entry(
        "sdk-python",
        sdk_version,
        EXPECTED_ARTIFACT_SOURCES["sdk-python"],
        "pypi_package_install",
    )


def add_python_sdk_fastavro_dependency(python_bin: str, log_file: Path) -> None:
    locate = run_command(
        [
            python_bin,
            "-c",
            (
                "from pathlib import Path; import fastavro; "
                "print(Path(fastavro.__file__).resolve().parent.parent)"
            ),
        ],
        log_file=log_file,
        timeout=30,
    )
    if locate.returncode != 0:
        raise RuntimeError("Python SDK environment does not provide its required fastavro dependency")

    package_root = Path(locate.stdout.strip())
    if not package_root.is_dir():
        raise RuntimeError("Python SDK fastavro dependency path could not be resolved")

    package_root_text = str(package_root)
    if package_root_text not in sys.path:
        sys.path.insert(0, package_root_text)


def python_sdk_distribution_version(python_bin: str, log_file: Path) -> str:
    code = r'''
from importlib.metadata import PackageNotFoundError
from importlib.metadata import version

try:
    print(version("durable-workflow"))
except PackageNotFoundError:
    print("")
'''
    completed = run_command([python_bin, "-c", code], log_file=log_file, timeout=30)
    return completed.stdout.strip()


def sdk_php_docker_image() -> str:
    return env_text("DW_SIGNALS_QUERIES_PHP_DOCKER_IMAGE") or "composer:2"


class PhpSdkArtifactError(RuntimeError):
    def __init__(
        self,
        code: str,
        message: str,
        *,
        version: str,
        phase: str,
        command: list[str] | None = None,
        command_result: subprocess.CompletedProcess[str] | None = None,
        details: dict[str, Any] | None = None,
    ):
        super().__init__(message)
        self.code = code
        self.version = version
        self.phase = phase
        self.command = command
        self.command_result = command_result
        self.details = details or {}


def sdk_php_fixture_root(sdk_version: str) -> Path:
    exported_root = env_text("REPO_ROOT")
    if exported_root is None:
        raise PhpSdkArtifactError(
            "php_sdk_fixture_root_unavailable",
            "the published server contract did not export its packaged fixture root",
            version=sdk_version,
            phase="resolve_packaged_fixtures",
        )

    fixture_root = Path(exported_root) / "scripts" / "conformance" / "fixtures" / "php-sdk"
    fixture_names = ("signals-queries-worker.php", "signals-queries-client.php")
    missing = [name for name in fixture_names if not (fixture_root / name).is_file()]
    if missing:
        raise PhpSdkArtifactError(
            "php_sdk_packaged_fixtures_missing",
            "the published server contract is missing packaged PHP signal/query fixtures",
            version=sdk_version,
            phase="resolve_packaged_fixtures",
            details={
                "fixture_root": diagnostic_path(str(fixture_root)),
                "missing_fixtures": missing,
            },
        )

    return fixture_root


def composer_versions_match(expected: str, actual: str) -> bool:
    return expected.strip().lstrip("v") == actual.strip().lstrip("v")


def sdk_php_package_provenance(project_dir: Path, sdk_version: str) -> dict[str, Any]:
    lock_path = project_dir / "composer.lock"
    installed_path = project_dir / "vendor" / "composer" / "installed.json"
    try:
        lock = json.loads(lock_path.read_text(encoding="utf-8"))
        installed = json.loads(installed_path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise PhpSdkArtifactError(
            "php_sdk_composer_metadata_unreadable",
            "could not read Composer metadata for the installed PHP SDK",
            version=sdk_version,
            phase="inspect_composer_provenance",
            details={"metadata_error": f"{type(exc).__name__}: {exc}"},
        ) from exc

    locked_packages = lock.get("packages", []) if isinstance(lock, dict) else []
    installed_packages: list[Any]
    if isinstance(installed, list):
        installed_packages = installed
    elif isinstance(installed, dict) and isinstance(installed.get("packages"), list):
        installed_packages = installed["packages"]
    else:
        installed_packages = []

    locked_sdk = next(
        (
            package
            for package in locked_packages
            if isinstance(package, dict) and package.get("name") == "durable-workflow/sdk"
        ),
        None,
    )
    installed_sdk = next(
        (
            package
            for package in installed_packages
            if isinstance(package, dict) and package.get("name") == "durable-workflow/sdk"
        ),
        None,
    )
    locked_version = str(locked_sdk.get("version") or "") if isinstance(locked_sdk, dict) else ""
    installed_version = str(installed_sdk.get("version") or "") if isinstance(installed_sdk, dict) else ""
    dist = locked_sdk.get("dist") if isinstance(locked_sdk, dict) else None
    installation_source = (
        str(installed_sdk.get("installation-source") or "") if isinstance(installed_sdk, dict) else ""
    )
    dist_url = str(dist.get("url") or "") if isinstance(dist, dict) else ""
    source = locked_sdk.get("source") if isinstance(locked_sdk, dict) else None
    source_url = str(source.get("url") or "") if isinstance(source, dict) else ""
    local_source_pattern = re.compile(
        r"(^file://|^/|^\.\.?/|local[_ -]?(product[_ -]?)?(source|checkout|artifact))",
        re.IGNORECASE,
    )

    problems: list[str] = []
    if not isinstance(locked_sdk, dict) or not isinstance(installed_sdk, dict):
        problems.append("durable-workflow/sdk is absent from Composer metadata")
    if not composer_versions_match(sdk_version, locked_version):
        problems.append(f"locked version {locked_version!r} does not match {sdk_version!r}")
    if not composer_versions_match(sdk_version, installed_version):
        problems.append(f"installed version {installed_version!r} does not match {sdk_version!r}")
    if installation_source != "dist":
        problems.append(f"installation source {installation_source!r} is not dist")
    if dist_url == "":
        problems.append("Composer lock metadata does not contain a dist URL")
    if any(local_source_pattern.search(candidate) for candidate in (dist_url, source_url) if candidate):
        problems.append("Composer metadata resolves the PHP SDK from a local product source")
    if problems:
        raise PhpSdkArtifactError(
            "php_sdk_packagist_provenance_invalid",
            "the installed PHP SDK does not have exact Packagist distribution provenance",
            version=sdk_version,
            phase="inspect_composer_provenance",
            details={"provenance_failures": problems},
        )

    return {
        "package": "durable-workflow/sdk",
        "version": locked_version,
        "source": "packagist",
        "dist": dist,
        "source_reference": source,
        "composer_content_hash": lock.get("content-hash"),
        "install_preference": "dist",
        "installation_source": installation_source,
    }


def waterline_php_docker_image() -> str:
    configured = env_text("DW_SIGNALS_QUERIES_WATERLINE_PHP_DOCKER_IMAGE")
    if configured:
        return configured

    server_version = artifact_version_value(artifact_versions, "server")
    if server_version and not is_placeholder_version(server_version):
        return f"durableworkflow/server:{server_version}"

    return sdk_php_docker_image()


def docker_volume_spec(path: Path, container_path: str = "/app") -> str:
    return f"{path}:{container_path}"


def docker_bind_mount_spec(path: Path, container_path: str) -> str:
    if "," in str(path) or "," in container_path:
        raise RuntimeError("Docker bind mount paths must not contain commas")
    return f"type=bind,src={path},dst={container_path}"


def docker_host_base_url(base_url: str) -> str:
    parsed = urllib.parse.urlparse(base_url)
    host = parsed.hostname
    if host not in {"127.0.0.1", "localhost"}:
        return base_url.rstrip("/")

    port = f":{parsed.port}" if parsed.port is not None else ""
    netloc = f"host.docker.internal{port}"
    if parsed.username or parsed.password:
        auth = parsed.username or ""
        if parsed.password:
            auth += f":{parsed.password}"
        netloc = f"{auth}@{netloc}"

    return urllib.parse.urlunparse(
        (
            parsed.scheme or "http",
            netloc,
            parsed.path.rstrip("/"),
            "",
            parsed.query,
            parsed.fragment,
        )
    ).rstrip("/")


def php_docker_command(
    project_dir: Path,
    args: list[str],
    *,
    name: str | None = None,
    detach: bool = False,
    env: dict[str, str] | None = None,
) -> list[str]:
    command = ["docker", "run"]
    if detach:
        if not name:
            raise RuntimeError("detached PHP docker command requires a container name")
        command.extend(["-d", "--name", name])
    else:
        command.append("--rm")

    command.extend(
        [
            *docker_run_resource_options(),
            "--add-host",
            "host.docker.internal:host-gateway",
            "--env",
            "COMPOSER_CACHE_DIR=/app/.composer-cache",
            "-v",
            docker_volume_spec(project_dir),
            "-w",
            "/app",
        ]
    )
    for key, value in sorted((env or {}).items()):
        command.extend(["-e", f"{key}={value}"])
    command.append(sdk_php_docker_image())
    command.extend(args)
    return command


def write_sdk_php_project(project_dir: Path) -> None:
    sdk_version = artifact_version_value(artifact_versions, "sdk-php")
    write_json(
        project_dir / "composer.json",
        {
            "require": {"durable-workflow/sdk": sdk_version},
            "minimum-stability": "stable",
            "prefer-stable": True,
            "config": {
                "preferred-install": "dist",
                "sort-packages": True,
                "allow-plugins": {"php-http/discovery": True},
            },
        },
    )
    fixture_root = sdk_php_fixture_root(sdk_version)
    shutil.copyfile(fixture_root / "signals-queries-worker.php", project_dir / "php-counter-worker.php")
    shutil.copyfile(fixture_root / "signals-queries-client.php", project_dir / "php-workflow-client.php")


def ensure_sdk_php_sdk(run_root: Path, log_file: Path) -> tuple[Path, dict[str, Any]]:
    sdk_version = artifact_version_value(artifact_versions, "sdk-php")
    if is_placeholder_version(sdk_version):
        raise PhpSdkArtifactError(
            "php_sdk_version_not_exact",
            "DW_PHP_SDK_VERSION must be concrete to install durable-workflow/sdk",
            version=sdk_version,
            phase="validate_artifact_tuple",
        )
    if not command_available("docker"):
        raise PhpSdkArtifactError(
            "php_sdk_runtime_unavailable",
            "docker is required to install and run the published PHP SDK",
            version=sdk_version,
            phase="validate_runtime",
        )

    project_dir = run_root / "sdk-php"
    project_dir.mkdir(parents=True, exist_ok=True)
    write_sdk_php_project(project_dir)

    install_command = php_docker_command(
        project_dir,
        ["install", "--no-interaction", "--no-progress", "--prefer-dist"],
    )
    install = run_command(
        install_command,
        log_file=log_file,
        timeout=600,
    )
    if install.returncode != 0:
        raise PhpSdkArtifactError(
            "php_sdk_composer_install_failed",
            "could not install the public durable-workflow/sdk package",
            version=sdk_version,
            phase="composer_install",
            command=install_command,
            command_result=install,
        )

    provenance = sdk_php_package_provenance(project_dir, sdk_version)
    record_unique_distribution_file(
        "sdk-php",
        sdk_version,
        project_dir / ".composer-cache" / "files" / "durable-workflow" / "sdk",
        "**/*",
        "durable-workflow/sdk",
    )

    version_command = php_docker_command(
        project_dir,
        [
            "php",
            "-r",
            "require 'vendor/autoload.php'; echo Composer\\InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?: '';",
        ],
    )
    check = run_command(
        version_command,
        log_file=log_file,
        timeout=60,
    )
    installed_version = check.stdout.strip()
    if check.returncode != 0 or not composer_versions_match(sdk_version, installed_version):
        raise PhpSdkArtifactError(
            "php_sdk_runtime_version_mismatch",
            "the installed durable-workflow/sdk runtime version does not match the artifact tuple",
            version=sdk_version,
            phase="verify_installed_version",
            command=version_command,
            command_result=check,
            details={"installed_version": installed_version},
        )

    entry = installed_public_artifact_entry(
        "sdk-php",
        sdk_version,
        EXPECTED_ARTIFACT_SOURCES["sdk-php"],
        "composer_package_install",
    )
    entry["installed_version"] = installed_version
    entry["runtime_image"] = sdk_php_docker_image()
    entry["package_provenance"] = provenance

    return project_dir, entry


def php_workflow_client_sample(
    project_dir: Path,
    base_url: str,
    token: str,
    namespace: str,
    operation: str,
    workflow_type: str,
    workflow_id: str,
    task_queue: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    command = php_docker_command(
        project_dir,
        [
            "php",
            "php-workflow-client.php",
            operation,
            docker_host_base_url(base_url),
            token,
            namespace,
            workflow_type,
            workflow_id,
            task_queue,
            name,
            json.dumps(args or []),
        ],
    )
    completed = run_command(command, log_file=log_file, timeout=120)
    output = completed.stdout.strip()
    sample = json_sample_from_stdout(output)
    sample.setdefault("client", "sdk-php")
    sample.setdefault("operation", operation)
    sample.setdefault("operation_name", name)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    if completed.returncode != 0 and completed.stderr.strip():
        sample.setdefault("stderr", completed.stderr.strip())
    sample.setdefault("api_sample", {
        "client": "sdk-php",
        "operation": operation,
        "workflow_type": workflow_type,
        "workflow_id": workflow_id,
        "task_queue": task_queue,
        "operation_name": name,
        "arguments": args or [],
        "wire_input_shape": "durable-workflow.v2.payload-envelope",
        "result_shape": "decoded-result-or-exact-error-body",
    })
    return sample


def docker_container_running(container_name: str, log_file: Path) -> bool:
    inspect = run_command(
        ["docker", "inspect", "-f", "{{.State.Running}}", container_name],
        log_file=log_file,
        timeout=30,
    )
    return inspect.returncode == 0 and inspect.stdout.strip() == "true"


def wait_for_docker_worker_registered(
    *,
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    container_name: str,
    log_file: Path,
    timeout_seconds: float = 75.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_response: dict[str, Any] | None = None
    while time.time() < deadline:
        if not docker_container_running(container_name, log_file):
            logs = capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=30)
            raise RuntimeError(f"PHP worker container exited before registration: {logs}")

        response = http_json(
            base_url,
            api_path("workers", worker_id),
            token=token,
            namespace=namespace,
            timeout=5,
        )
        last_response = response
        if int(response.get("status_code") or 0) == 200 and isinstance(response.get("body"), dict):
            return response["body"]
        time.sleep(0.5)

    log_line(log_file, f"last PHP worker registration probe response: {last_response}")
    raise RuntimeError(f"PHP worker {worker_id} did not register within {timeout_seconds}s")


def wait_for_php_query_route_evidence(
    evidence_path: Path,
    *,
    workflow_id: str,
    worker_id: str,
    query_name: str = "current",
    timeout_seconds: float = 45.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    records: list[dict[str, Any]] = []
    while time.time() < deadline:
        records = load_query_route_records(evidence_path)
        for record in reversed(records):
            if record.get("workflow_id") != workflow_id:
                continue
            if record.get("worker_id") != worker_id:
                continue
            if record.get("query_name") != query_name:
                continue
            if str(record.get("status") or "").strip().lower() not in {"pass", "completed"}:
                continue
            return record
        time.sleep(0.5)

    raise RuntimeError(f"PHP worker did not record routed {query_name} query evidence: {records[-3:]}")


def sdk_error_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    operation: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError, WorkflowNotFound

base_url, token, namespace, workflow_id, operation, name = sys.argv[1:7]
args = json.loads(sys.argv[7]) if len(sys.argv) > 7 and sys.argv[7] else None

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    if not isinstance(reason, str) and isinstance(exc, WorkflowNotFound):
        reason = "instance_not_found"
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=15.0) as client:
        try:
            if operation == "signal":
                await client.signal_workflow(workflow_id, name, args=args)
            else:
                await client.query_workflow(workflow_id, name, args=args)
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 0

    print(json.dumps({
        "client": "sdk-python",
        "exception": None,
        "reason": "no_exception",
    }, sort_keys=True))
    return 1

raise SystemExit(asyncio.run(main()))
'''
    command = [python_bin, "-c", code, base_url, token, namespace, workflow_id, operation, name]
    if args is not None:
        command.append(json.dumps(args))
    completed = run_command(
        command,
        log_file=log_file,
        timeout=60,
    )
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("exit_code", completed.returncode)
    return sample


def sdk_success_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    operation: str,
    name: str,
    log_file: Path,
    args: list[Any] | None = None,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError

base_url, token, namespace, workflow_id, operation, name = sys.argv[1:7]
args = json.loads(sys.argv[7]) if len(sys.argv) > 7 and sys.argv[7] else None

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        try:
            if operation == "signal":
                result = await client.signal_workflow(workflow_id, name, args=args)
            else:
                result = await client.query_workflow(workflow_id, name, args=args)
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "operation": operation,
                "operation_name": name,
                "ok": False,
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 1

    print(json.dumps({
        "client": "sdk-python",
        "operation": operation,
        "operation_name": name,
        "ok": True,
        "result": result,
    }, sort_keys=True))
    return 0

raise SystemExit(asyncio.run(main()))
'''
    command = [python_bin, "-c", code, base_url, token, namespace, workflow_id, operation, name]
    if args is not None:
        command.append(json.dumps(args))
    completed = run_command(command, log_file=log_file, timeout=90)
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("operation", operation)
    sample.setdefault("operation_name", name)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    if completed.returncode != 0 and completed.stderr.strip():
        sample.setdefault("stderr", completed.stderr.strip())
    return sample


def sdk_start_workflow_sample(
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    workflow_type: str,
    task_queue: str,
    log_file: Path,
) -> dict[str, Any]:
    code = r'''
import asyncio
import json
import sys

from durable_workflow import Client, DurableWorkflowError

base_url, token, namespace, workflow_id, workflow_type, task_queue = sys.argv[1:7]

def exception_reason(exc):
    reason = getattr(exc, "reason", None)
    if callable(reason):
        reason = reason()
    body = getattr(exc, "body", None)
    if not isinstance(reason, str) and isinstance(body, dict):
        candidate = body.get("reason")
        if isinstance(candidate, str):
            reason = candidate
    return reason if isinstance(reason, str) else None

async def main():
    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        try:
            handle = await client.start_workflow(
                workflow_type=workflow_type,
                workflow_id=workflow_id,
                task_queue=task_queue,
                input=[],
            )
        except DurableWorkflowError as exc:
            print(json.dumps({
                "client": "sdk-python",
                "operation": "start",
                "operation_name": "start",
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
                "ok": False,
                "exception": type(exc).__name__,
                "status_code": getattr(exc, "status", None),
                "reason": exception_reason(exc),
                "validation_errors": getattr(exc, "validation_errors", None),
                "body": getattr(exc, "body", None),
            }, sort_keys=True))
            return 1

    print(json.dumps({
        "client": "sdk-python",
        "operation": "start",
        "operation_name": "start",
        "workflow_id": handle.workflow_id,
        "run_id": handle.run_id,
        "workflow_type": handle.workflow_type,
        "task_queue": task_queue,
        "ok": True,
        "api_sample": {
            "client": "sdk-python",
            "operation": "start",
            "workflow_type": workflow_type,
            "workflow_id": workflow_id,
            "task_queue": task_queue,
            "wire_input_shape": "durable-workflow.v2.payload-envelope",
            "result_shape": "workflow-handle",
        },
    }, sort_keys=True))
    return 0

raise SystemExit(asyncio.run(main()))
'''
    completed = run_command(
        [python_bin, "-c", code, base_url, token, namespace, workflow_id, workflow_type, task_queue],
        log_file=log_file,
        timeout=90,
    )
    output = completed.stdout.strip()
    try:
        sample = json.loads(output) if output else {}
    except json.JSONDecodeError:
        sample = {"raw_stdout": output}
    sample.setdefault("client", "sdk-python")
    sample.setdefault("operation", "start")
    sample.setdefault("operation_name", "start")
    sample.setdefault("workflow_id", workflow_id)
    sample.setdefault("workflow_type", workflow_type)
    sample.setdefault("task_queue", task_queue)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    if completed.returncode != 0 and completed.stderr.strip():
        sample.setdefault("stderr", completed.stderr.strip())
    return sample


def json_sample_from_stdout(output: str) -> dict[str, Any]:
    raw = output.strip()
    if raw == "":
        return {}

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        decoded = None

    if isinstance(decoded, dict):
        return decoded

    decoder = json.JSONDecoder()
    for index, character in enumerate(raw):
        if character != "{":
            continue

        try:
            decoded, end = decoder.raw_decode(raw, index)
        except json.JSONDecodeError:
            continue

        if not isinstance(decoded, dict):
            continue

        sample = dict(decoded)
        if raw[:index].strip() or raw[end:].strip():
            sample.setdefault("raw_stdout", raw)
        return sample

    return {"raw_stdout": raw}


_NO_SAMPLE_RESULT = object()


def envelope_result_value(candidate: dict[str, Any]) -> Any:
    for envelope_key in ("result_envelope", "resultEnvelope"):
        envelope = candidate.get(envelope_key)
        if not isinstance(envelope, dict):
            continue

        blob = envelope.get("blob")
        if not isinstance(blob, str):
            continue

        decoded = decode_json_blob(blob)
        if decoded is not None:
            return decoded

    return _NO_SAMPLE_RESULT


def looks_like_control_plane_result(value: Any) -> bool:
    return (
        isinstance(value, dict)
        and any(
            key in value
            for key in (
                "success",
                "workflow_id",
                "run_id",
                "query_name",
                "operation",
                "operation_name",
                "target_scope",
                "result_envelope",
                "resultEnvelope",
            )
        )
    )


def candidate_result_value(candidate: dict[str, Any]) -> Any:
    if "result" in candidate:
        result = candidate["result"]
        if looks_like_control_plane_result(result):
            nested = candidate_result_value(result)
            if nested is not _NO_SAMPLE_RESULT:
                return nested

        if result is not None:
            return result

        envelope = envelope_result_value(candidate)
        if envelope is not _NO_SAMPLE_RESULT:
            return envelope

        return None

    envelope = envelope_result_value(candidate)
    if envelope is not _NO_SAMPLE_RESULT:
        return envelope

    for nested_key in ("body", "data", "output", "server_response"):
        nested = candidate.get(nested_key)
        if not isinstance(nested, dict):
            continue

        value = candidate_result_value(nested)
        if value is not _NO_SAMPLE_RESULT:
            return value

    return _NO_SAMPLE_RESULT


def sample_result_value(sample: dict[str, Any]) -> Any:
    for candidate in (
        sample,
        sample.get("output"),
        sample.get("server_response"),
    ):
        if not isinstance(candidate, dict):
            continue

        value = candidate_result_value(candidate)
        if value is not _NO_SAMPLE_RESULT:
            return value

    return None


def workflow_start_run_id(sample: dict[str, Any]) -> str:
    candidates: list[Any] = [sample, sample.get("result"), sample.get("output"), sample.get("server_response")]
    seen: set[int] = set()
    nested_keys = (
        "result",
        "start",
        "start_result",
        "startResult",
        "workflow_start",
        "workflowStart",
        "body",
        "data",
        "output",
        "server_response",
        "serverResponse",
        "response",
        "workflow",
        "workflow_execution",
        "workflowExecution",
        "execution",
        "handle",
    )

    while candidates:
        candidate = candidates.pop(0)
        if not isinstance(candidate, dict):
            continue

        marker = id(candidate)
        if marker in seen:
            continue
        seen.add(marker)

        for key in ("run_id", "runId", "workflow_run_id", "workflowRunId"):
            value = candidate.get(key)
            if isinstance(value, str) and value.strip() != "":
                return value.strip()

        for key in nested_keys:
            nested = candidate.get(key)
            if isinstance(nested, dict):
                candidates.append(nested)

    return ""


def public_sample_ok(sample: dict[str, Any]) -> bool:
    if sample.get("ok") is True:
        return True
    exit_code = sample.get("exit_code")
    return isinstance(exit_code, int) and exit_code == 0


def load_query_route_records(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []

    records: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line == "":
            continue
        try:
            parsed = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(parsed, dict):
            records.append(parsed)

    return records


def routed_current_query_task_satisfied(value: Any, expected_runtime: str = "sdk-python") -> bool:
    if not isinstance(value, dict):
        return False

    if value.get("query_name") != "current":
        return False

    if str(value.get("public_query_surface") or "").strip() != "cli":
        return False

    status = str(value.get("status") or "").strip().lower()
    if status not in {"pass", "completed"}:
        return False

    for field in (
        "query_task_id",
        "workflow_id",
        "run_id",
        "workflow_type",
        "task_queue",
        "worker_id",
        "worker_runtime",
        "lease_owner",
        "server_route",
        "completion_route",
    ):
        if str(value.get(field) or "").strip() == "":
            return False

    try:
        attempt = int(value.get("query_task_attempt"))
    except (TypeError, ValueError):
        return False

    return attempt >= 1 and str(value.get("worker_runtime")) == expected_runtime


def routed_current_query_task_matches(
    record: dict[str, Any],
    *,
    workflow_id: str,
    run_id: str,
    workflow_type: str,
    task_queue: str,
    worker_id: str,
) -> bool:
    if record.get("query_name") != "current":
        return False
    if record.get("workflow_id") != workflow_id:
        return False
    if record.get("run_id") != run_id:
        return False
    if record.get("workflow_type") != workflow_type:
        return False
    if record.get("task_queue") != task_queue:
        return False
    if record.get("worker_id") != worker_id:
        return False
    status = str(record.get("status") or "").strip().lower()
    if status not in {"pass", "completed"}:
        return False
    return str(record.get("query_task_id") or "").strip() != ""


def wait_for_routed_current_query_task(
    *,
    evidence_path: Path,
    workflow_id: str,
    run_id: str,
    workflow_type: str,
    task_queue: str,
    worker_id: str,
    public_query_surface: str,
    log_file: Path,
    expected_sdk_version: str | None = None,
    timeout_seconds: float = 15.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    records: list[dict[str, Any]] = []
    while time.time() < deadline:
        records = load_query_route_records(evidence_path)
        for record in records:
            if not routed_current_query_task_matches(
                record,
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_type=workflow_type,
                task_queue=task_queue,
                worker_id=worker_id,
            ):
                continue
            if expected_sdk_version is not None and not same_python_release(
                expected_sdk_version,
                str(record.get("worker_sdk_version") or ""),
            ):
                continue

            routed = dict(record)
            routed["public_query_surface"] = public_query_surface
            if routed_current_query_task_satisfied(routed):
                return routed

        time.sleep(0.25)

    log_line(log_file, f"routed current query task records: {records}")
    raise RuntimeError("Python SDK baseline did not record routed current query task evidence")


def decode_json_blob(blob: Any) -> Any:
    if not isinstance(blob, str) or blob.strip() == "":
        return None
    try:
        return json.loads(blob)
    except json.JSONDecodeError:
        pass
    try:
        decoded_bytes = base64.b64decode(blob, validate=True)
    except Exception:
        return None

    try:
        return json.loads(decoded_bytes.decode("utf-8"))
    except Exception:
        return decode_avro_value(decoded_bytes)


AVRO_VALUE_SCHEMA_JSON = (
    '{"type":"record","name":"Value","namespace":"durable_workflow.protocol",'
    '"fields":[{"name":"value","type":["null",'
    '{"type":"record","name":"BooleanValue","fields":[{"name":"boolean","type":"boolean"}]},'
    '{"type":"record","name":"LongValue","fields":[{"name":"long","type":"long"}]},'
    '{"type":"record","name":"DoubleValue","fields":[{"name":"double","type":"double"}]},'
    '{"type":"record","name":"BytesValue","fields":[{"name":"bytes","type":"bytes"}]},'
    '{"type":"record","name":"StringValue","fields":[{"name":"string","type":"string"}]},'
    '{"type":"record","name":"ArrayValue","fields":[{"name":"items",'
    '"type":{"type":"array","items":"Value"}}]},'
    '{"type":"record","name":"MapValue","fields":[{"name":"entries",'
    '"type":{"type":"map","values":"Value"}}]}]}]}'
)
AVRO_VALUE_FINGERPRINT = bytes.fromhex("e2a33dff55802237")
avro_value_schema: Any = None


def decode_avro_value(data: bytes) -> Any:
    if len(data) < 10 or data[:2] != b"\xC3\x01" or data[2:10] != AVRO_VALUE_FINGERPRINT:
        return None

    try:
        import fastavro

        global avro_value_schema
        if avro_value_schema is None:
            avro_value_schema = fastavro.parse_schema(json.loads(AVRO_VALUE_SCHEMA_JSON))

        record = fastavro.schemaless_reader(
            io.BytesIO(data[10:]),
            avro_value_schema,
            avro_value_schema,
        )
        return native_avro_value(record)
    except Exception:
        return None


def native_avro_value(datum: Any) -> Any:
    if not isinstance(datum, dict) or "value" not in datum:
        return None
    branch = datum["value"]
    if branch is None:
        return None
    if not isinstance(branch, dict):
        raise ValueError("invalid Avro Value branch")
    for field in ("boolean", "long", "double", "bytes", "string"):
        if field in branch:
            return branch[field]
    if isinstance(branch.get("items"), list):
        return [native_avro_value(item) for item in branch["items"]]
    if isinstance(branch.get("entries"), dict):
        return {key: native_avro_value(item) for key, item in branch["entries"].items()}
    raise ValueError("unknown Avro Value branch")


def decode_signal_arguments(envelope: Any) -> Any:
    if not isinstance(envelope, dict):
        return None
    for key in ("decoded", "value", "payload", "arguments"):
        value = envelope.get(key)
        if isinstance(value, (list, dict, int, float, str, bool)):
            return value
    decoded = decode_json_blob(envelope.get("blob"))
    if decoded is not None:
        return decoded
    return None


def amount_from_arguments(arguments: Any) -> int | None:
    if isinstance(arguments, list) and arguments:
        value = arguments[0]
    elif isinstance(arguments, dict):
        value = arguments.get("amount")
        if value is None:
            value = arguments.get("n")
    else:
        value = arguments

    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.strip().lstrip("-").isdigit():
        return int(value.strip())
    return None


def signal_amount_from_task(task: dict[str, Any]) -> int | None:
    amount = amount_from_arguments(decode_signal_arguments(task.get("signal_arguments")))
    if amount is not None:
        return amount

    for event in reversed(task.get("history_events", [])):
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict):
            continue
        amount = amount_from_arguments(decode_signal_arguments(payload.get("arguments")))
        if amount is not None:
            return amount

    return None


def workflow_task_history_events(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
) -> list[dict[str, Any]]:
    events = [
        event
        for event in task.get("history_events", [])
        if isinstance(event, dict)
    ]
    next_token = task.get("next_history_page_token")
    seen_tokens: set[str] = set()

    while isinstance(next_token, str) and next_token.strip() != "":
        if next_token in seen_tokens:
            raise RuntimeError(f"workflow task history pagination repeated token {next_token!r}")
        seen_tokens.add(next_token)

        response = http_json(
            base_url,
            api_path("worker", "workflow-tasks", str(task["task_id"]), "history"),
            method="POST",
            body={
                "lease_owner": task["lease_owner"],
                "workflow_task_attempt": task["workflow_task_attempt"],
                "next_history_page_token": next_token,
                "history_page_size": 1000,
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(response["status_code"]) >= 400:
            raise RuntimeError(f"workflow task history page failed: {response}")

        body = response.get("body")
        if not isinstance(body, dict):
            break
        page_events = body.get("history_events")
        if isinstance(page_events, list):
            events.extend(event for event in page_events if isinstance(event, dict))
        next_token = body.get("next_history_page_token")

    return events


def signal_observations_from_events(events: list[dict[str, Any]], signal_name: str) -> list[dict[str, Any]]:
    observations: list[dict[str, Any]] = []
    for index, event in enumerate(events):
        if event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict) or payload.get("signal_name") != signal_name:
            continue
        amount = amount_from_arguments(decode_signal_arguments(payload.get("arguments")))
        if amount is None:
            continue

        observation = {
            "signal_name": signal_name,
            "signal_amount": amount,
            "history_event_index": index,
        }
        signal_id = payload.get("signal_id")
        if isinstance(signal_id, str) and signal_id:
            observation["signal_id"] = signal_id
        sequence = payload.get("workflow_sequence")
        if isinstance(sequence, int):
            observation["workflow_sequence"] = sequence
        observations.append(observation)

    return observations


def increment_signal_amounts_from_history_events(events: Any) -> list[int]:
    if not isinstance(events, list):
        return []

    return [
        observation["signal_amount"]
        for observation in signal_observations_from_events(
            [event for event in events if isinstance(event, dict)],
            "increment",
        )
        if isinstance(observation.get("signal_amount"), int)
    ]


def signal_observation_key(observation: dict[str, Any]) -> str:
    signal_id = observation.get("signal_id")
    if isinstance(signal_id, str) and signal_id:
        return f"signal:{signal_id}"
    sequence = observation.get("workflow_sequence")
    if isinstance(sequence, int):
        return f"sequence:{sequence}"
    return f"history-index:{observation.get('history_event_index')}"


def increment_signal_observations_from_task(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    events = workflow_task_history_events(base_url, token, namespace, task)
    amount = amount_from_arguments(decode_signal_arguments(task.get("signal_arguments")))
    if task.get("signal_name") == "increment" and amount is not None:
        return [
            {
                "signal_name": "increment",
                "signal_amount": amount,
                "signal_id": task.get("workflow_signal_id"),
                "workflow_sequence": task.get("workflow_sequence"),
                "history_event_index": f"task:{task.get('task_id')}:signal_arguments",
            }
        ], events

    observations = signal_observations_from_events(events, "increment")
    if observations:
        return observations, events

    return [], events


def count_signal_received(events_response: dict[str, Any], signal_name: str) -> int:
    body = events_response.get("body")
    if not isinstance(body, dict):
        return 0
    events = body.get("events")
    if not isinstance(events, list):
        return 0

    count = 0
    for event in events:
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if isinstance(payload, dict) and payload.get("signal_name") == signal_name:
            count += 1
    return count


def query_task_claim_binding(
    task: dict[str, Any],
    expected: dict[str, str],
) -> dict[str, Any]:
    claimed = {
        "workflow_id": task.get("workflow_id"),
        "run_id": task.get("run_id"),
        "query_name": task.get("query_name"),
        "task_queue": task.get("task_queue"),
        "worker_id": task.get("lease_owner"),
        "query_task_id": task.get("query_task_id"),
        "query_task_attempt": task.get("query_task_attempt"),
    }
    expected_identity = {
        field: expected[field]
        for field in ("workflow_id", "run_id", "query_name", "task_queue", "worker_id")
    }
    mismatches = [
        field
        for field, expected_value in expected_identity.items()
        if claimed.get(field) != expected_value
    ]
    if not isinstance(claimed["query_task_id"], str) or not claimed["query_task_id"]:
        mismatches.append("query_task_id")
    if (
        isinstance(claimed["query_task_attempt"], bool)
        or not isinstance(claimed["query_task_attempt"], int)
        or claimed["query_task_attempt"] < 1
    ):
        mismatches.append("query_task_attempt")

    return {
        "expected": expected_identity,
        "claimed": claimed,
        "mismatches": mismatches,
        "matches_expected": mismatches == [],
        "verified_at": now(),
    }


def query_task_completion_binding(
    task: dict[str, Any],
    request_body: dict[str, Any],
    response: dict[str, Any],
) -> dict[str, Any]:
    response_body = response.get("body") if isinstance(response.get("body"), dict) else {}
    status_code = response.get("status_code")
    request_matches_claim = (
        request_body.get("lease_owner") == task.get("lease_owner")
        and request_body.get("query_task_attempt") == task.get("query_task_attempt")
    )
    response_matches_claim = (
        isinstance(status_code, int)
        and 200 <= status_code < 300
        and response_body.get("query_task_id") == task.get("query_task_id")
        and response_body.get("query_task_attempt") == task.get("query_task_attempt")
        and response_body.get("outcome") == "completed"
    )

    return {
        "request": {
            "query_task_id": task.get("query_task_id"),
            "query_task_attempt": request_body.get("query_task_attempt"),
            "lease_owner": request_body.get("lease_owner"),
        },
        "response": response_sample(response),
        "request_matches_claim": request_matches_claim,
        "response_matches_claim": response_matches_claim,
        "authoritative": request_matches_claim and response_matches_claim,
        "verified_at": now(),
    }


def answer_next_query_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    result: Any,
    log_file: Path,
    holder: dict[str, Any],
    workflow_condition_key: str | None = None,
    poll_timeout: float = 45.0,
    ready_event: threading.Event | None = None,
    capture_claim_eligibility: bool = False,
    replay_blocked_event: threading.Event | None = None,
    completion_deadline_monotonic: float | None = None,
    completion_request_timeout: float = 15.0,
    completion_settle_seconds: float = 1.0,
    completion_done_event: threading.Event | None = None,
    expected_query_identity: dict[str, str] | None = None,
) -> None:
    holder["responder_started_at"] = now()
    responder_started_monotonic = time.monotonic()
    if completion_deadline_monotonic is not None:
        completion_budget_seconds = max(
            0.0,
            completion_deadline_monotonic - responder_started_monotonic,
        )
        holder["completion_budget_seconds"] = completion_budget_seconds
        holder["completion_budget_deadline_at"] = (
            datetime.fromtimestamp(
                time.time() + completion_budget_seconds,
                timezone.utc,
            )
            .isoformat(timespec="microseconds")
            .replace("+00:00", "Z")
        )

    try:
        deadline = time.monotonic() + poll_timeout
        if completion_deadline_monotonic is not None:
            deadline = min(
                deadline,
                completion_deadline_monotonic
                - max(0.0, completion_request_timeout)
                - max(0.0, completion_settle_seconds),
            )
        poll_attempt = 0
        empty_polls: list[dict[str, Any]] = []
        poll_transport_errors: list[dict[str, Any]] = []
        task: dict[str, Any] | None = None

        while time.monotonic() < deadline:
            poll_attempt += 1
            remaining = max(0.2, deadline - time.monotonic())
            # A synthetic responder must retain more than one opportunity to
            # claim. Under host load a single delayed transport request must
            # not consume the control-plane query's entire claim window.
            claim_poll_seconds = max(1, min(2, int(math.ceil(remaining))))
            if "query_poll_started_at" not in holder:
                holder["query_poll_started_at"] = now()
            if replay_blocked_event is None or replay_blocked_event.is_set():
                holder["heartbeat"] = heartbeat_worker(
                    base_url,
                    token,
                    namespace,
                    worker_id,
                    timeout=min(10.0, remaining),
                )
                heartbeat_status = holder["heartbeat"].get("status_code")
                if not isinstance(heartbeat_status, int) or heartbeat_status >= 400:
                    holder["error"] = f"query responder heartbeat failed: {holder['heartbeat']}"
                    holder["completion_state"] = "transport_failure"
                    return
                holder["heartbeat_acknowledged_at"] = now()
                if replay_blocked_event is not None:
                    holder["replay_blocked_heartbeat_acknowledged_at"] = holder["heartbeat_acknowledged_at"]
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            holder["query_poll_ready_at"] = now()
            if ready_event is not None:
                ready_event.set()
            try:
                poll = http_json(
                    base_url,
                    api_path("worker", "query-tasks", "poll"),
                    method="POST",
                    body={
                        "worker_id": worker_id,
                        "task_queue": task_queue,
                        "poll_request_id": f"query-{int(time.time() * 1000)}-{poll_attempt}",
                        "timeout_seconds": claim_poll_seconds,
                    },
                    token=token,
                    namespace=namespace,
                    worker=True,
                    timeout=max(0.2, min(remaining, claim_poll_seconds + 5.0)),
                )
            except TimeoutError as exc:
                # The server may still finish the abandoned poll and lease the
                # task. A successor poll by the same worker recovers that lease.
                poll_transport_errors.append({
                    "attempt": poll_attempt,
                    "timeout_seconds": claim_poll_seconds,
                    "failed_at": now(),
                    "error": f"{type(exc).__name__}: {exc}",
                })
                holder["poll_transport_errors"] = poll_transport_errors
                holder["query_poll_attempt_count"] = poll_attempt
                continue

            holder["poll"] = poll
            holder["query_poll_attempt_count"] = poll_attempt
            poll_body = poll.get("body") if isinstance(poll.get("body"), dict) else {}
            task_candidate = poll_body.get("task") if isinstance(poll_body, dict) else None
            if isinstance(task_candidate, dict):
                task = task_candidate
                break

            if isinstance(poll_body, dict) and poll_body.get("poll_status") == "workflow_task_leased":
                leased_count = int(holder.get("workflow_task_leased_count") or 0) + 1
                holder["workflow_task_leased_count"] = leased_count
                holder["workflow_task_leased_at"] = now()
                holder["workflow_task_leased_poll"] = {
                    "status_code": poll.get("status_code"),
                    "poll_status": poll_body.get("poll_status"),
                }
                if replay_blocked_event is not None:
                    replay_blocked_event.set()

            if (
                isinstance(poll_body, dict)
                and poll_body.get("poll_status") == "workflow_task_pending"
                and workflow_condition_key is not None
            ):
                pending_count = int(holder.get("workflow_task_pending_count") or 0) + 1
                holder["workflow_task_pending_count"] = pending_count
                workflow_poll = poll_workflow_task(
                    base_url,
                    token,
                    namespace,
                    worker_id,
                    task_queue,
                    timeout=min(10.0, max(1.0, remaining)),
                )
                holder["workflow_task_pending_poll"] = workflow_poll
                workflow_task = task_from_poll(
                    workflow_poll,
                    f"{worker_id} workflow task pending before query {pending_count}",
                )
                complete_pending = complete_open_wait(
                    base_url,
                    token,
                    namespace,
                    workflow_task,
                    workflow_condition_key,
                )
                holder["workflow_task_pending_complete"] = complete_pending
                if int(complete_pending.get("status_code") or 0) >= 400:
                    holder["error"] = f"workflow task pending completion failed: {complete_pending}"
                    holder["completion_state"] = "non_success_response"
                    return
                continue

            empty_polls.append(response_sample(poll))
            holder["empty_polls"] = empty_polls

            if int(poll.get("status_code") or 0) >= 400:
                holder["error"] = f"query task poll failed: {poll}"
                holder["completion_state"] = "non_success_response"
                return

            time.sleep(min(0.1, max(0.0, deadline - time.monotonic())))

        if task is None:
            holder["error"] = "query task poll returned no task before timeout"
            holder["completion_state"] = "timeout"
            return

        holder["query_handler_invoked_at"] = now()
        holder["query_task"] = task
        if expected_query_identity is not None:
            holder["query_claim_binding"] = query_task_claim_binding(
                task,
                expected_query_identity,
            )
            if holder["query_claim_binding"]["matches_expected"] is not True:
                holder["completion_state"] = "responder_failure"
                holder["error"] = (
                    "query responder claimed a task outside its designated identity: "
                    f"{holder['query_claim_binding']}"
                )
                return
        if capture_claim_eligibility:
            try:
                holder["worker_eligibility_when_claimed"] = worker_eligibility_sample(
                    http_json(
                        base_url,
                        api_path("workers", worker_id),
                        token=token,
                        namespace=namespace,
                        timeout=10,
                    ),
                    worker_id,
                    task_queue,
                )
            except Exception as exc:  # noqa: BLE001 - query completion remains authoritative claim evidence.
                holder["worker_eligibility_when_claimed"] = {
                    "eligible": False,
                    "error": f"{type(exc).__name__}: {exc}",
                    "observed_at": now(),
                }
        task_history_signal_order = increment_signal_amounts_from_history_events(task.get("history_events"))
        if task_history_signal_order:
            holder["history_signal_order"] = task_history_signal_order
        resolved_result = result(task, holder) if callable(result) else result
        holder["result"] = resolved_result
        request_timeout = max(0.0, completion_request_timeout)
        if completion_deadline_monotonic is not None:
            request_timeout = min(
                request_timeout,
                max(
                    0.0,
                    completion_deadline_monotonic
                    - time.monotonic()
                    - max(0.0, completion_settle_seconds),
                ),
            )
        if request_timeout <= 0:
            holder["completion_state"] = "timeout"
            holder["error"] = "query task completion had no time remaining in its responder budget"
            return

        holder["completion_request_started_at"] = now()
        holder["completion_state"] = "in_flight"
        completion_request_body = {
            "lease_owner": task.get("lease_owner") or worker_id,
            "query_task_attempt": task["query_task_attempt"],
            "result": resolved_result,
        }
        holder["completion_request"] = dict(completion_request_body)
        try:
            complete = http_json(
                base_url,
                api_path("worker", "query-tasks", str(task["query_task_id"]), "complete"),
                method="POST",
                body=completion_request_body,
                token=token,
                namespace=namespace,
                worker=True,
                timeout=request_timeout,
            )
        except (TimeoutError, socket.timeout) as exc:
            holder["completion_state"] = "timeout"
            holder["error"] = f"{type(exc).__name__}: {exc}"
            return
        except (urllib.error.URLError, OSError) as exc:
            holder["completion_state"] = "transport_failure"
            holder["error"] = f"{type(exc).__name__}: {exc}"
            return

        holder["complete"] = complete
        holder["query_completed_at"] = now()
        holder["query_completion_binding"] = query_task_completion_binding(
            task,
            completion_request_body,
            complete,
        )
        complete_status = complete.get("status_code")
        if (
            isinstance(complete_status, int)
            and 200 <= complete_status < 300
            and (
                expected_query_identity is None
                or holder["query_completion_binding"]["authoritative"] is True
            )
        ):
            holder["completion_state"] = "successful"
        elif isinstance(complete_status, int) and 200 <= complete_status < 300:
            holder["completion_state"] = "responder_failure"
            holder["error"] = (
                "query task completion response did not bind to the claimed task: "
                f"{holder['query_completion_binding']}"
            )
        else:
            holder["completion_state"] = "non_success_response"
            holder["error"] = f"query task completion returned a non-successful response: {complete}"
    except (TimeoutError, socket.timeout) as exc:
        holder["completion_state"] = "timeout"
        holder["error"] = f"{type(exc).__name__}: {exc}"
    except (urllib.error.URLError, OSError) as exc:
        holder["completion_state"] = "transport_failure"
        holder["error"] = f"{type(exc).__name__}: {exc}"
    except Exception as exc:  # noqa: BLE001 - captured into conformance evidence.
        holder["completion_state"] = "responder_failure"
        holder["error"] = f"{type(exc).__name__}: {exc}"
    finally:
        holder["responder_finished_at"] = now()
        if completion_done_event is not None:
            completion_done_event.set()


def poll_workflow_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    timeout: float = 45.0,
) -> dict[str, Any]:
    heartbeat_worker(base_url, token, namespace, worker_id)

    return http_json(
        base_url,
        api_path("worker", "workflow-tasks", "poll"),
        method="POST",
        body={
            "worker_id": worker_id,
            "task_queue": task_queue,
            "poll_request_id": f"workflow-{int(time.time() * 1000)}",
        },
        token=token,
        namespace=namespace,
        worker=True,
        timeout=timeout,
    )


def heartbeat_worker(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    timeout: float = 10.0,
) -> dict[str, Any]:
    return http_json(
        base_url,
        api_path("worker", "heartbeat"),
        method="POST",
        body={
            "worker_id": worker_id,
            "task_slots": {
                "workflow_available": 2,
                "activity_available": 0,
            },
            "heartbeat_interval_seconds": 10,
        },
        token=token,
        namespace=namespace,
        worker=True,
        timeout=timeout,
    )


class WorkerHeartbeatGuard:
    def __init__(
        self,
        base_url: str,
        token: str,
        namespace: str,
        worker_id: str,
        log_file: Path,
        interval_seconds: float = 5.0,
    ) -> None:
        self.base_url = base_url
        self.token = token
        self.namespace = namespace
        self.worker_id = worker_id
        self.log_file = log_file
        self.interval_seconds = max(0.1, interval_seconds)
        self.started_at: str | None = None
        self.stopped_at: str | None = None
        self.attempt_count = 0
        self.success_count = 0
        self.latest_success: dict[str, Any] | None = None
        self.latest_failure: dict[str, Any] | None = None
        self._lock = threading.Lock()
        self._eligible = threading.Event()
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        if self._thread is not None:
            raise RuntimeError(f"heartbeat guard for {self.worker_id} was already started")

        self.started_at = now()
        self._thread = threading.Thread(
            target=self._run,
            name=f"heartbeat-{self.worker_id}",
            daemon=True,
        )
        self._thread.start()

    def wait_until_eligible(self, timeout: float = 15.0) -> bool:
        return self._eligible.wait(timeout)

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=15)
        self.stopped_at = now()

    def snapshot(self) -> dict[str, Any]:
        with self._lock:
            return {
                "worker_id": self.worker_id,
                "started_at": self.started_at,
                "stopped_at": self.stopped_at,
                "attempt_count": self.attempt_count,
                "success_count": self.success_count,
                "eligible": self._eligible.is_set(),
                "latest_success": self.latest_success,
                "latest_failure": self.latest_failure,
            }

    def _run(self) -> None:
        while not self._stop.is_set():
            attempted_at = now()
            with self._lock:
                self.attempt_count += 1

            try:
                response = heartbeat_worker(
                    self.base_url,
                    self.token,
                    self.namespace,
                    self.worker_id,
                )
                status_code = response.get("status_code")
                if not isinstance(status_code, int) or status_code >= 400:
                    raise RuntimeError(f"worker heartbeat was not acknowledged: {response}")

                body = response.get("body") if isinstance(response.get("body"), dict) else {}
                with self._lock:
                    self.success_count += 1
                    self.latest_success = {
                        "attempted_at": attempted_at,
                        "acknowledged_at": now(),
                        "status_code": status_code,
                        "acknowledged": body.get("acknowledged"),
                        "heartbeat_interval_seconds": body.get("heartbeat_interval_seconds"),
                        "stale_after_seconds": body.get("stale_after_seconds"),
                    }
                self._eligible.set()
            except Exception as exc:  # noqa: BLE001 - retain heartbeat failures as runner evidence.
                failure = {
                    "attempted_at": attempted_at,
                    "failed_at": now(),
                    "error": f"{type(exc).__name__}: {exc}",
                }
                with self._lock:
                    self.latest_failure = failure
                log_line(self.log_file, f"worker heartbeat guard failed: {failure['error']}")

            self._stop.wait(self.interval_seconds)


def worker_eligibility_sample(
    response: dict[str, Any],
    worker_id: str,
    task_queue: str,
) -> dict[str, Any]:
    body = response.get("body") if isinstance(response.get("body"), dict) else {}
    capabilities = body.get("capabilities") if isinstance(body.get("capabilities"), list) else []
    status_code = response.get("status_code")
    eligible = (
        isinstance(status_code, int)
        and status_code < 400
        and body.get("worker_id") == worker_id
        and body.get("task_queue") == task_queue
        and body.get("status") == "active"
        and "query_tasks" in capabilities
        and isinstance(body.get("last_heartbeat_at"), str)
    )

    return {
        "eligible": eligible,
        "status_code": status_code,
        "worker_id": body.get("worker_id"),
        "task_queue": body.get("task_queue"),
        "status": body.get("status"),
        "capabilities": capabilities,
        "last_heartbeat_at": body.get("last_heartbeat_at"),
        "stale_after_seconds": body.get("stale_after_seconds"),
        "observed_at": now(),
    }


def complete_workflow_task(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
    commands: list[dict[str, Any]],
    timeout: float = 30.0,
) -> dict[str, Any]:
    return http_json(
        base_url,
        api_path("worker", "workflow-tasks", str(task["task_id"]), "complete"),
        method="POST",
        body={
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task["workflow_task_attempt"],
            "commands": commands,
        },
        token=token,
        namespace=namespace,
        worker=True,
        timeout=timeout,
    )


def complete_open_wait(
    base_url: str,
    token: str,
    namespace: str,
    task: dict[str, Any],
    condition_key: str,
) -> dict[str, Any]:
    return complete_workflow_task(
        base_url,
        token,
        namespace,
        task,
        [
            {
                "type": "open_condition_wait",
                "condition_key": condition_key,
                "timeout_seconds": 300,
            },
        ],
    )


def append_new_increment_observations(
    observations: list[dict[str, Any]],
    seen_signals: set[str],
    observed_amounts: list[int],
) -> list[int]:
    new_amounts: list[int] = []
    for observation in observations:
        key = signal_observation_key(observation)
        if key in seen_signals:
            continue
        seen_signals.add(key)
        amount = observation.get("signal_amount")
        if isinstance(amount, int):
            new_amounts.append(amount)
            observed_amounts.append(amount)
    return new_amounts


def signal_task_observation_summary(
    task: dict[str, Any],
    observations: list[dict[str, Any]],
    history_events: list[dict[str, Any]],
    new_amounts: list[int],
    poll_index: int,
) -> dict[str, Any]:
    return {
        "poll_index": poll_index,
        "task_id": task.get("task_id"),
        "signal_name": signal_name_from_task(task),
        "signal_amounts": new_amounts,
        "history_signal_amounts": [
            observation.get("signal_amount")
            for observation in observations
            if isinstance(observation.get("signal_amount"), int)
        ],
        "history_event_types": [
            event.get("event_type")
            for event in history_events
            if isinstance(event.get("event_type"), str)
        ],
    }


def collect_increment_signal_observations(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    seen_signals: set[str],
    observed_amounts: list[int],
    signal_tasks: list[dict[str, Any]],
    condition_key_prefix: str,
    label: str,
    expected_count: int,
    log_file: Path,
    poll_timeout: float = 45.0,
    allow_exhausted_after_observation: bool = False,
) -> None:
    poll_index = 0
    while len(observed_amounts) < expected_count and poll_index < expected_count:
        poll_index += 1
        try:
            poll = poll_workflow_task(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                timeout=poll_timeout,
            )
        except Exception as exc:  # noqa: BLE001 - dedup evidence may be complete after one delivered task.
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: {type(exc).__name__}: {exc}")
                break
            raise

        task = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
        if not isinstance(task, dict):
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: no further workflow task in {poll}")
                break
            raise RuntimeError(f"{label} signal poll {poll_index} returned no task: {poll}")

        observations, history_events = increment_signal_observations_from_task(
            base_url,
            token,
            namespace,
            task,
        )
        new_amounts = append_new_increment_observations(
            observations,
            seen_signals,
            observed_amounts,
        )
        if not new_amounts:
            if allow_exhausted_after_observation and observed_amounts:
                log_line(log_file, f"{label} signal poll stopped: no new increment signals in {task}")
                break
            raise RuntimeError(f"{label} signal poll {poll_index} did not expose new increment signals: {task}")

        complete = complete_open_wait(
            base_url,
            token,
            namespace,
            task,
            f"{condition_key_prefix}-{len(observed_amounts)}",
        )
        if int(complete["status_code"]) >= 400:
            raise RuntimeError(f"{label} signal poll {poll_index} task completion failed: {complete}")

        signal_tasks.append(
            signal_task_observation_summary(
                task,
                observations,
                history_events,
                new_amounts,
                poll_index,
            )
        )


def start_waiting_workflow(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_id: str,
    workflow_type: str,
    condition_key: str,
) -> str:
    start = http_json(
        base_url,
        api_path("workflows"),
        method="POST",
        body={
            "workflow_id": workflow_id,
            "workflow_type": workflow_type,
            "task_queue": task_queue,
        },
        token=token,
        namespace=namespace,
        timeout=30,
    )
    if int(start["status_code"]) >= 400:
        raise RuntimeError(f"workflow start failed: {start}")

    run_id = str(start["body"]["run_id"])
    initial_poll = poll_workflow_task(base_url, token, namespace, worker_id, task_queue)
    initial_task = task_from_poll(initial_poll, f"{workflow_id} initial")
    initial_complete = complete_open_wait(
        base_url,
        token,
        namespace,
        initial_task,
        condition_key,
    )
    if int(initial_complete["status_code"]) >= 400:
        raise RuntimeError(f"initial workflow task completion failed: {initial_complete}")
    return run_id


def complete_next_increment_task(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    condition_key: str,
    label: str,
    timeout: float = 45.0,
) -> tuple[int, dict[str, Any]]:
    poll = poll_workflow_task(base_url, token, namespace, worker_id, task_queue, timeout=timeout)
    task = task_from_poll(poll, label)
    signal_name = signal_name_from_task(task)
    if signal_name != "increment":
        raise RuntimeError(f"{label} task did not carry increment signal: {task}")
    amount = signal_amount_from_task(task)
    if amount is None:
        raise RuntimeError(f"{label} task did not expose decoded signal arguments: {task}")
    complete = complete_open_wait(base_url, token, namespace, task, condition_key)
    if int(complete["status_code"]) >= 400:
        raise RuntimeError(f"{label} task completion failed: {complete}")
    return amount, task


def workflow_query_call(
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    query_name: str,
    holder: dict[str, Any],
) -> None:
    holder["query_sent_at"] = now()
    holder["response"] = http_json(
        base_url,
        api_path("workflows", workflow_id, "query", query_name),
        method="POST",
        body={},
        token=token,
        namespace=namespace,
        timeout=60,
    )
    holder["query_completed_at"] = now()


def query_with_worker_result(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_id: str,
    query_name: str,
    result: Any,
    log_file: Path,
    call: Any,
    workflow_condition_key: str | None = None,
) -> dict[str, Any]:
    holder: dict[str, Any] = {}
    ready = threading.Event()
    responder = threading.Thread(
        target=answer_next_query_task,
        args=(base_url, token, namespace, worker_id, task_queue, result, log_file, holder, workflow_condition_key),
        kwargs={
            "ready_event": ready,
            "capture_claim_eligibility": True,
        },
        daemon=True,
    )
    responder.start()
    if not ready.wait(timeout=15):
        raise RuntimeError(
            f"{workflow_id} {query_name} query responder did not acknowledge a heartbeat before the query request"
        )
    sample = call()
    responder.join(timeout=20)
    responder_timed_out = responder.is_alive()
    if responder_timed_out or holder.get("error"):
        raise RuntimeError(f"{workflow_id} {query_name} query responder failed: {holder.get('error', 'timeout')}")

    claimed_query_task = holder.get("query_task")
    if not isinstance(claimed_query_task, dict):
        claimed_query_task = {}
    claim_eligibility = holder.get("worker_eligibility_when_claimed")
    if not isinstance(claim_eligibility, dict):
        claim_eligibility = {"eligible": False}
    completed_query = holder.get("complete")
    if not isinstance(completed_query, dict):
        completed_query = {}
    query_task_evidence = {
        "worker_id": worker_id,
        "task_queue": task_queue,
        "heartbeat_before_poll": holder.get("heartbeat"),
        "heartbeat_acknowledged_at": holder.get("heartbeat_acknowledged_at"),
        "query_poll_ready_at": holder.get("query_poll_ready_at"),
        "query_claimed_at": holder.get("query_handler_invoked_at"),
        "claim_eligibility": claim_eligibility,
        "claimed_query_task": {
            "query_task_id": claimed_query_task.get("query_task_id"),
            "query_task_attempt": claimed_query_task.get("query_task_attempt"),
            "workflow_id": claimed_query_task.get("workflow_id"),
            "run_id": claimed_query_task.get("run_id"),
            "query_name": claimed_query_task.get("query_name"),
            "task_queue": claimed_query_task.get("task_queue"),
            "lease_owner": claimed_query_task.get("lease_owner"),
        },
        "query_task_completion": response_sample(completed_query) if completed_query else None,
        "responder_error": holder.get("error"),
        "responder_timed_out": responder_timed_out,
        "poll_status_code": holder.get("poll", {}).get("status_code")
        if isinstance(holder.get("poll"), dict)
        else None,
        "complete_status_code": holder.get("complete", {}).get("status_code")
        if isinstance(holder.get("complete"), dict)
        else None,
        "query_handler_invoked_at": holder.get("query_handler_invoked_at"),
        "query_completed_at": holder.get("query_completed_at"),
    }
    query_task_evidence["eligible_when_claimed"] = (
        claim_eligibility.get("eligible") is True
        and isinstance(claimed_query_task.get("query_task_id"), str)
        and claimed_query_task.get("workflow_id") == workflow_id
        and claimed_query_task.get("query_name") == query_name
        and claimed_query_task.get("task_queue") == task_queue
        and claimed_query_task.get("lease_owner") == worker_id
        and isinstance(completed_query.get("status_code"), int)
        and int(completed_query["status_code"]) < 400
    )
    sample["query_task"] = query_task_evidence
    return sample


def task_from_poll(poll: dict[str, Any], label: str) -> dict[str, Any]:
    task = poll.get("body", {}).get("task") if isinstance(poll.get("body"), dict) else None
    if not isinstance(task, dict):
        raise RuntimeError(f"{label} workflow task poll returned no task: {poll}")
    return task


def history_event_types_from_task(task: dict[str, Any]) -> list[str]:
    events = task.get("history_events")
    if not isinstance(events, list):
        return []

    event_types: list[str] = []
    for event in events:
        if not isinstance(event, dict):
            continue
        event_type = event.get("event_type")
        if isinstance(event_type, str):
            event_types.append(event_type)
    return event_types


def public_history_events(history_body: Any) -> list[dict[str, Any]] | None:
    if not isinstance(history_body, dict):
        return None

    events = history_body.get("events")
    if not isinstance(events, list):
        events = history_body.get("history_events")
    if not isinstance(events, list):
        return None

    return [event for event in events if isinstance(event, dict)]


def observed_count_changed(before: dict[str, Any], after: dict[str, Any], key: str) -> bool | None:
    before_value = before.get(key)
    after_value = after.get(key)
    if isinstance(before_value, bool) or isinstance(after_value, bool):
        return None
    if isinstance(before_value, int) and isinstance(after_value, int):
        return before_value != after_value
    return None


def signal_name_from_task(task: dict[str, Any]) -> str | None:
    signal_name = task.get("signal_name")
    if isinstance(signal_name, str) and signal_name:
        return signal_name

    for event in task.get("history_events", []):
        if not isinstance(event, dict) or event.get("event_type") != "SignalReceived":
            continue
        payload = event.get("payload")
        if not isinstance(payload, dict):
            continue
        candidate = payload.get("signal_name")
        if isinstance(candidate, str) and candidate:
            return candidate

    return None


def run_status(base_url: str, token: str, namespace: str, workflow_id: str) -> str | None:
    response = http_json(
        base_url,
        api_path("workflows", workflow_id),
        method="GET",
        token=token,
        namespace=namespace,
        timeout=30,
    )
    body = response.get("body")
    if not isinstance(body, dict):
        return None
    status = body.get("status")
    return status if isinstance(status, str) else None


def workflow_public_snapshot(
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    run_id: str | None = None,
) -> dict[str, Any]:
    path = (
        api_path("workflows", workflow_id, "runs", run_id)
        if run_id
        else api_path("workflows", workflow_id)
    )
    response = http_json(
        base_url,
        path,
        method="GET",
        token=token,
        namespace=namespace,
        timeout=30,
    )
    body = response.get("body")
    if not isinstance(body, dict):
        body = {}

    snapshot: dict[str, Any] = {
        "status_code": response.get("status_code"),
        "workflow_id": workflow_id,
    }
    for key in (
        "run_id",
        "status",
        "workflow_type",
        "task_queue",
        "result",
        "output",
        "completed_at",
        "closed_at",
        "last_history_sequence",
        "history_sequence",
    ):
        if key in body:
            snapshot[key] = body[key]
    commands = body.get("commands")
    if isinstance(commands, list):
        snapshot["workflow_command_count"] = len(commands)
        snapshot["workflow_commands"] = [
            {
                key: command[key]
                for key in (
                    "id",
                    "sequence",
                    "type",
                    "target_scope",
                    "requested_run_id",
                    "resolved_run_id",
                    "target_name",
                    "status",
                    "outcome",
                    "reason",
                    "rejection_reason",
                    "validation_errors",
                    "accepted_at",
                    "applied_at",
                    "rejected_at",
                )
                if key in command
            }
            for command in commands[-16:]
            if isinstance(command, dict)
        ]
    if run_id is not None:
        snapshot.setdefault("run_id", run_id)

    snapshot_run_id = run_id or snapshot.get("run_id")
    if isinstance(snapshot_run_id, str) and snapshot_run_id:
        history = http_json(
            base_url,
            api_path("workflows", workflow_id, "runs", snapshot_run_id, "history") + "?page_size=1000",
            method="GET",
            token=token,
            namespace=namespace,
            timeout=30,
        )
        history_body = history.get("body")
        history_events = public_history_events(history_body)
        if history_events is not None:
            snapshot["history_event_count"] = len(history_events)
            snapshot["history_event_types"] = [
                event.get("event_type")
                for event in history_events
                if isinstance(event, dict) and isinstance(event.get("event_type"), str)
            ]
            workflow_command_count = 0
            for event in history_events:
                payload = event.get("payload") if isinstance(event, dict) else None
                commands = payload.get("commands") if isinstance(payload, dict) else None
                if isinstance(commands, list):
                    workflow_command_count += len(commands)
            snapshot.setdefault("workflow_command_count", workflow_command_count)

        debug = http_json(
            base_url,
            api_path("workflows", workflow_id, "runs", snapshot_run_id, "debug"),
            method="GET",
            token=token,
            namespace=namespace,
            timeout=30,
        )
        debug_body = debug.get("body") if isinstance(debug.get("body"), dict) else {}
        pending_tasks = debug_body.get("pending_workflow_tasks")
        if isinstance(pending_tasks, list):
            ready_or_leased_tasks = [
                {
                    key: task[key]
                    for key in (
                        "task_id",
                        "id",
                        "type",
                        "status",
                        "transport_state",
                        "lease_owner",
                        "workflow_wait_kind",
                        "workflow_resume_source_kind",
                        "workflow_resume_source_id",
                    )
                    if key in task
                }
                for task in pending_tasks
                if isinstance(task, dict)
            ]
            snapshot["ready_or_leased_workflow_tasks"] = ready_or_leased_tasks
            snapshot["ready_or_leased_workflow_task_count"] = len(ready_or_leased_tasks)
            snapshot["ready_or_leased_workflow_task_set_sha256"] = hashlib.sha256(
                json.dumps(
                    ready_or_leased_tasks,
                    separators=(",", ":"),
                    sort_keys=True,
                ).encode("utf-8")
            ).hexdigest()

    return snapshot


REJECTED_SIGNAL_AUDIT_OUTCOMES = {
    "unknown_signal": "rejected_unknown_signal",
    "invalid_signal_arguments": "rejected_invalid_arguments",
}


def rejected_signal_audit_spec(run_id: str, target_name: str, reason: str) -> dict[str, Any]:
    return {
        "type": "signal",
        "target_scope": "instance",
        "requested_run_id": None,
        "resolved_run_id": run_id,
        "target_name": target_name,
        "status": "rejected",
        "outcome": REJECTED_SIGNAL_AUDIT_OUTCOMES[reason],
        "reason": reason,
        "rejection_reason": reason,
        "accepted_at": None,
        "applied_at": None,
        "rejected_at_recorded": True,
    }


def rejected_signal_audit_row(command: dict[str, Any]) -> dict[str, Any]:
    return {
        "type": command.get("type"),
        "target_scope": command.get("target_scope"),
        "requested_run_id": command.get("requested_run_id"),
        "resolved_run_id": command.get("resolved_run_id"),
        "target_name": command.get("target_name"),
        "status": command.get("status"),
        "outcome": command.get("outcome"),
        "reason": command.get("reason"),
        "rejection_reason": command.get("rejection_reason"),
        "accepted_at": command.get("accepted_at"),
        "applied_at": command.get("applied_at"),
        "rejected_at_recorded": (
            isinstance(command.get("rejected_at"), str)
            and command["rejected_at"].strip() != ""
        ),
    }


def workflow_command_delta(
    before: dict[str, Any],
    after: dict[str, Any],
) -> list[dict[str, Any]] | None:
    before_commands = before.get("workflow_commands")
    after_commands = after.get("workflow_commands")
    if not isinstance(before_commands, list) or not isinstance(after_commands, list):
        return None

    before_ids = {
        command.get("id")
        for command in before_commands
        if isinstance(command, dict) and isinstance(command.get("id"), str)
    }
    return [
        command
        for command in after_commands
        if isinstance(command, dict)
        and isinstance(command.get("id"), str)
        and command.get("id") not in before_ids
    ]


def rejected_signal_audit_evidence(
    before: dict[str, Any],
    after: dict[str, Any],
    expected_rows: list[dict[str, Any]],
) -> dict[str, Any]:
    command_delta = workflow_command_delta(before, after)
    observed_rows = (
        [rejected_signal_audit_row(command) for command in command_delta]
        if command_delta is not None
        else []
    )
    exact_match = command_delta is not None and observed_rows == expected_rows
    executable_or_ready_command_count = sum(
        1
        for row in observed_rows
        if row.get("status") != "rejected"
        or row.get("accepted_at") is not None
        or row.get("applied_at") is not None
    )

    return {
        "expected_rows": expected_rows,
        "observed_rows": observed_rows,
        "exact_match": exact_match,
        "executable_or_ready_command_count": executable_or_ready_command_count,
    }


def ready_or_leased_workflow_tasks_unchanged(
    before: dict[str, Any],
    after: dict[str, Any],
) -> bool:
    before_count = before.get("ready_or_leased_workflow_task_count")
    after_count = after.get("ready_or_leased_workflow_task_count")
    before_digest = before.get("ready_or_leased_workflow_task_set_sha256")
    after_digest = after.get("ready_or_leased_workflow_task_set_sha256")
    return (
        isinstance(before_count, int)
        and not isinstance(before_count, bool)
        and before_count == after_count
        and isinstance(before_digest, str)
        and before_digest != ""
        and before_digest == after_digest
    )


def rejected_signal_handler_invocation_count(
    before: dict[str, Any],
    after: dict[str, Any],
) -> int | None:
    before_types = before.get("history_event_types")
    after_types = after.get("history_event_types")
    if not isinstance(before_types, list) or not isinstance(after_types, list):
        return None
    return after_types.count("SignalReceived") - before_types.count("SignalReceived")


def workflow_state_unchanged(before: dict[str, Any], after: dict[str, Any]) -> bool:
    if not isinstance(before.get("run_id"), str) or before.get("run_id") != after.get("run_id"):
        return False
    if not isinstance(before.get("status"), str) or before.get("status") != after.get("status"):
        return False

    return all(
        before.get(key) == after.get(key)
        for key in (
            "result",
            "output",
            "completed_at",
            "closed_at",
            "last_history_sequence",
            "history_sequence",
        )
    )


REPLAY_TIMING_SCENARIO_TITLES = {
    "signal_during_replay": "Signal during replay timing could not be proved",
    "query_during_replay": "Query during replay timing could not be proved",
}

REPLAY_TIMING_FAILURE_TYPES = {
    "signal_during_replay": "signal_query_signal_during_replay_timing_failed",
    "query_during_replay": "signal_query_query_during_replay_timing_failed",
}

REPLAY_TIMING_UNAVAILABLE_TYPES = {
    "signal_during_replay": "signal_query_signal_during_replay_probe_unavailable",
    "query_during_replay": "signal_query_query_during_replay_probe_unavailable",
}

REPLAY_TIMING_ACCEPTANCE = {
    "signal_during_replay": [
        "restart a worker with non-empty history",
        "send a signal while replay is in progress",
        "record the signal public response and prove application after replay completion",
    ],
    "query_during_replay": [
        "restart a worker with non-empty history",
        "query while replay is in progress",
        "record the query public response and prove the handler ran after replay completion",
    ],
}

COMPLETED_RUN_FAILURE_TYPE = "signal_query_completed_run_handling_failed"
COMPLETED_RUN_UNAVAILABLE_TYPE = "signal_query_completed_run_probe_unavailable"
COMPLETED_RUN_ACCEPTANCE = [
    "complete Counter cleanly and record the terminal transition",
    "send a signal after completion and capture the public terminal response",
    "query after completion and capture the final-state response or documented typed error",
    "record before/after terminal state and history readouts",
]


class ReplayTimingProbeFailure(Exception):
    def __init__(
        self,
        message: str,
        *,
        phase: str,
        status: str = "runner_blocked",
        owner: str = "conformance_harness",
        blocker_kind: str = "replay_timing_probe_unavailable",
    ) -> None:
        super().__init__(message)
        self.phase = phase
        self.status = status
        self.owner = owner
        self.blocker_kind = blocker_kind


def replay_timing_context_outputs(
    scenario: str,
    context: dict[str, Any],
    versions: dict[str, str],
    sources: dict[str, str],
) -> dict[str, Any]:
    keys = [
        "workflow_id",
        "run_id",
        "worker_id",
        "task_queue",
        "worker_restart_at",
        "replay_completed_at",
        "leased_replay_task_id",
    ]
    if scenario == "signal_during_replay":
        keys.extend([
            "signal_api_sample",
            "signal_status_code",
            "signal_sent_at",
            "signal_applied_at",
            "signal_application_task_id",
            "signal_application_history_event_types",
        ])
    else:
        keys.extend([
            "query_api_sample",
            "query_status_code",
            "query_sent_at",
            "query_poll_started_at",
            "query_handler_invoked_at",
            "query_completed_at",
            "query_answer",
            "expected_answer",
            "query_task_id",
        ])

    outputs = {
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }
    for key in keys:
        value = context.get(key)
        if value is not None:
            outputs[key] = value

    phase = context.get("phase")
    if isinstance(phase, str) and phase:
        outputs["probe_phase"] = phase

    return outputs


def replay_timing_missing_evidence(scenario: str, observed: dict[str, Any]) -> list[str]:
    missing = []
    for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, []):
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key)):
            missing.append(evidence_key)

    return missing


def replay_timing_finding(
    scenario: str,
    observed: dict[str, Any],
    *,
    status: str,
    reason: str,
    phase: str,
    owner: str,
    blocker_kind: str | None = None,
) -> dict[str, Any]:
    finding_type = (
        REPLAY_TIMING_UNAVAILABLE_TYPES[scenario]
        if status == "runner_blocked"
        else REPLAY_TIMING_FAILURE_TYPES[scenario]
    )
    missing_evidence = replay_timing_missing_evidence(scenario, observed)
    current_evidence = {
        "published_artifact_evidence_present": True,
        "probe_phase": phase,
        "reason": reason,
        "observed_outputs": observed,
    }
    if missing_evidence:
        current_evidence["missing_current_evidence"] = missing_evidence
    if scenario == "signal_during_replay":
        current_evidence["required_timestamp_order"] = [
            "worker_restart_at <= signal_sent_at",
            "signal_sent_at < replay_completed_at",
            "replay_completed_at <= signal_applied_at",
        ]
    else:
        current_evidence["required_timestamp_order"] = [
            "worker_restart_at <= query_sent_at",
            "query_sent_at <= query_poll_started_at",
            "query_poll_started_at < replay_completed_at",
            "replay_completed_at <= query_handler_invoked_at",
            "query_handler_invoked_at <= query_completed_at",
        ]

    finding = {
        "id": finding_type,
        "type": finding_type,
        "scenario_id": scenario,
        "owner": owner,
        "title": REPLAY_TIMING_SCENARIO_TITLES[scenario],
        "current_evidence": current_evidence,
        "acceptance": REPLAY_TIMING_ACCEPTANCE[scenario],
    }
    if status == "runner_blocked" and blocker_kind is not None:
        finding["blocker_kind"] = blocker_kind
    else:
        finding["observed_behavior"] = "current published artifacts did not prove the replay timing contract"

    return finding


def replay_timing_scenario_result(
    scenario: str,
    observed: dict[str, Any],
    *,
    status: str | None = None,
    reason: str = "",
    phase: str = "",
    owner: str = "workflow, sdk-python, server",
    blocker_kind: str | None = None,
) -> dict[str, Any]:
    if status is None and has_required_evidence(scenario, observed):
        return {
            "scenario_id": scenario,
            "status": "pass",
            "observed_outputs": observed,
        }

    status = status or "fail"
    reason = reason or "Observed replay timing output did not satisfy the scenario contract."
    phase = phase or str(observed.get("probe_phase") or "replay_timing_validation")
    finding = replay_timing_finding(
        scenario,
        observed,
        status=status,
        reason=reason,
        phase=phase,
        owner=owner,
        blocker_kind=blocker_kind,
    )

    return {
        "scenario_id": scenario,
        "status": status,
        "observed_outputs": observed,
        "linked_findings": [finding],
    }


def completed_run_context_outputs(
    context: dict[str, Any],
    versions: dict[str, str],
    sources: dict[str, str],
) -> dict[str, Any]:
    keys = [
        "completed_workflow_id",
        "completed_run_id",
        "completed_at",
        "terminal_status",
        "terminal_start_response",
        "terminal_complete_response",
        "signal_sent_at",
        "signal_api_sample",
        "signal_error",
        "query_sent_at",
        "query_api_sample",
        "query_result_or_error",
        "public_query_surfaces",
        "terminal_state_before_operations",
        "terminal_state_after_operations",
        "terminal_state_changed_after_operations",
        "terminal_result_changed_after_operations",
        "terminal_history_changed_after_operations",
        "run_status_after_operations",
    ]
    outputs = {
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }
    for key in keys:
        value = context.get(key)
        if value is not None:
            outputs[key] = value

    phase = context.get("phase")
    if isinstance(phase, str) and phase:
        outputs["probe_phase"] = phase

    reason = context.get("terminal_failure_reason")
    if isinstance(reason, str) and reason:
        outputs["failure_reason"] = reason

    return outputs


def completed_run_missing_evidence(observed: dict[str, Any]) -> list[str]:
    missing = []
    for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get("completed_run_signal_and_query", []):
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key)):
            missing.append(evidence_key)

    return missing


def completed_run_terminal_unchanged(observed: dict[str, Any]) -> bool:
    return (
        evidence_lookup(observed, "terminal_result_changed_after_operations") is False
        and evidence_lookup(observed, "terminal_history_changed_after_operations") is False
    )


def completed_run_finding(
    observed: dict[str, Any],
    *,
    status: str,
    reason: str,
    phase: str,
    owner: str,
    blocker_kind: str | None = None,
) -> dict[str, Any]:
    finding_type = (
        COMPLETED_RUN_UNAVAILABLE_TYPE
        if status == "runner_blocked"
        else COMPLETED_RUN_FAILURE_TYPE
    )
    current_evidence = {
        "published_artifact_evidence_present": True,
        "probe_phase": phase,
        "reason": reason,
        "observed_outputs": observed,
        "required_terminal_contract": {
            "signal_error.reason": "run_not_active",
            "signal_error.rejection_reason": "run_not_active",
            "signal_error.status_code": "400..499",
            "query_result_or_error.status_code": "200..499",
            "terminal_result_changed_after_operations": False,
            "terminal_history_changed_after_operations": False,
        },
    }
    missing_evidence = completed_run_missing_evidence(observed)
    if missing_evidence:
        current_evidence["missing_current_evidence"] = missing_evidence

    finding = {
        "id": finding_type,
        "type": finding_type,
        "scenario_id": "completed_run_signal_and_query",
        "owner": owner,
        "title": "Signals/queries completed-run handling could not be proved",
        "current_evidence": current_evidence,
        "acceptance": COMPLETED_RUN_ACCEPTANCE,
    }
    if status == "runner_blocked" and blocker_kind is not None:
        finding["blocker_kind"] = blocker_kind
    else:
        finding["observed_behavior"] = (
            "current published artifacts did not prove the completed-run signal/query contract"
        )

    return finding


def completed_run_scenario_result(
    observed: dict[str, Any],
    *,
    status: str | None = None,
    reason: str = "",
    phase: str = "",
    owner: str = "server, workflow, sdk-python, cli",
    blocker_kind: str | None = None,
) -> dict[str, Any]:
    if (
        status is None
        and has_required_evidence("completed_run_signal_and_query", observed)
        and completed_run_terminal_unchanged(observed)
    ):
        return {
            "scenario_id": "completed_run_signal_and_query",
            "status": "pass",
            "observed_outputs": observed,
        }

    status = status or "fail"
    reason = reason or "Observed completed-run output did not satisfy the terminal signal/query contract."
    phase = phase or str(observed.get("probe_phase") or "completed_run_validation")
    finding = completed_run_finding(
        observed,
        status=status,
        reason=reason,
        phase=phase,
        owner=owner,
        blocker_kind=blocker_kind,
    )

    return {
        "scenario_id": "completed_run_signal_and_query",
        "status": status,
        "observed_outputs": observed,
        "linked_findings": [finding],
    }


def replay_timing_results_from_context(
    context: dict[str, Any],
    versions: dict[str, str],
    sources: dict[str, str],
) -> dict[str, dict[str, Any]]:
    return {
        scenario: replay_timing_scenario_result(
            scenario,
            replay_timing_context_outputs(scenario, context, versions, sources),
        )
        for scenario in ("signal_during_replay", "query_during_replay")
    }


def replay_timing_probe_failure_evidence(
    failure: ReplayTimingProbeFailure,
    context: dict[str, Any],
    versions: dict[str, str],
    sources: dict[str, str],
) -> tuple[dict[str, Any], dict[str, Any]]:
    scenario_results = {}
    for scenario in ("signal_during_replay", "query_during_replay"):
        observed = replay_timing_context_outputs(scenario, context, versions, sources)
        observed["probe_error"] = {
            "type": type(failure).__name__,
            "message": str(failure),
        }
        scenario_results[scenario] = replay_timing_scenario_result(
            scenario,
            observed,
            status=failure.status,
            reason=str(failure),
            phase=failure.phase,
            owner=failure.owner,
            blocker_kind=failure.blocker_kind,
        )

    return {
        "artifact_versions": versions,
        "scenario_results": scenario_results,
    }, {
        "workflow_id": context.get("workflow_id"),
        "run_id": context.get("run_id"),
        "worker_id": context.get("worker_id"),
        "task_queue": context.get("task_queue"),
        "generated_scenarios": ["signal_during_replay", "query_during_replay"],
        "error": f"{type(failure).__name__}: {failure}",
        "probe_phase": failure.phase,
    }


def run_replay_terminal_probe(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    task_queue: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_REPLAY_TERMINAL_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    probe_context: dict[str, Any] = {"phase": "setup"}
    try:
        suffix = hashlib.sha1(f"{time.time()}-replay-terminal".encode("utf-8")).hexdigest()[:10]
        replay_workflow_id = f"wf-sq-replay-{suffix}"
        terminal_workflow_id = f"wf-sq-terminal-{suffix}"
        probe_task_queue = f"{task_queue}-replay-terminal-{suffix}"
        probe_worker_id = f"{worker_id}-replay-terminal-{suffix}"
        probe_context.update({
            "workflow_id": replay_workflow_id,
            "worker_id": probe_worker_id,
            "task_queue": probe_task_queue,
        })

        probe_context["phase"] = "worker_registration"
        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": probe_worker_id,
                "task_queue": probe_task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-replay-terminal-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise ReplayTimingProbeFailure(
                f"replay/terminal worker registration failed: {register}",
                phase="worker_registration",
                status="fail",
                owner="server",
                blocker_kind="replay_timing_worker_registration_failed",
            )

        probe_context["phase"] = "workflow_start"
        replay_start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": replay_workflow_id,
                "workflow_type": workflow_type,
                "task_queue": probe_task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(replay_start["status_code"]) >= 400:
            raise ReplayTimingProbeFailure(
                f"replay timing workflow start failed: {replay_start}",
                phase="workflow_start",
                status="fail",
                owner="server",
                blocker_kind="replay_timing_workflow_start_failed",
            )

        replay_run_id = str(replay_start["body"]["run_id"])
        worker_restart_at = now()
        probe_context.update({
            "run_id": replay_run_id,
            "worker_restart_at": worker_restart_at,
        })
        probe_context["phase"] = "replay_task_poll"
        replay_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        replay_task = task_from_poll(replay_poll, "replay timing")
        probe_context["leased_replay_task_id"] = replay_task.get("task_id")

        query_holder: dict[str, Any] = {}
        query_thread = threading.Thread(
            target=workflow_query_call,
            args=(base_url, token, namespace, replay_workflow_id, "state", query_holder),
            daemon=True,
        )
        query_thread.start()
        query_sent_deadline = time.time() + 2
        while "query_sent_at" not in query_holder and time.time() < query_sent_deadline:
            time.sleep(0.01)
        if "query_sent_at" not in query_holder:
            raise ReplayTimingProbeFailure(
                "query during replay thread did not start before replay completion",
                phase="query_during_replay_api_call_start",
            )
        probe_context["query_sent_at"] = query_holder.get("query_sent_at")

        query_task_holder: dict[str, Any] = {}
        query_blocked_by_replay = threading.Event()
        query_responder = threading.Thread(
            target=answer_next_query_task,
            args=(base_url, token, namespace, probe_worker_id, probe_task_queue, 0, log_file, query_task_holder),
            kwargs={"replay_blocked_event": query_blocked_by_replay},
            daemon=True,
        )
        query_responder.start()
        query_poll_deadline = time.time() + 2
        while "query_poll_started_at" not in query_task_holder and time.time() < query_poll_deadline:
            time.sleep(0.01)
        if "query_poll_started_at" not in query_task_holder:
            raise ReplayTimingProbeFailure(
                "query during replay responder did not start polling before replay completion",
                phase="query_during_replay_worker_poll_start",
            )
        probe_context["query_poll_started_at"] = query_task_holder.get("query_poll_started_at")
        if not query_blocked_by_replay.wait(timeout=10):
            raise ReplayTimingProbeFailure(
                "query during replay was not observed queued behind the active replay lease: "
                f"{query_task_holder.get('error', 'no workflow_task_leased poll status')}",
                phase="query_during_replay_enqueue_barrier",
                status="fail",
                owner="server",
                blocker_kind="query_during_replay_enqueue_barrier_failed",
            )
        probe_context["query_blocked_by_replay_at"] = query_task_holder.get("workflow_task_leased_at")
        probe_context["query_blocked_poll_status"] = "workflow_task_leased"
        replay_heartbeat_deadline = time.time() + 10
        while (
            "replay_blocked_heartbeat_acknowledged_at" not in query_task_holder
            and "error" not in query_task_holder
            and time.time() < replay_heartbeat_deadline
        ):
            time.sleep(0.01)
        if "replay_blocked_heartbeat_acknowledged_at" not in query_task_holder:
            raise ReplayTimingProbeFailure(
                "query responder heartbeat was not acknowledged after the query was queued behind replay: "
                f"{query_task_holder.get('error', 'heartbeat admission timed out')}",
                phase="query_during_replay_heartbeat_admission",
                status="fail",
                owner="server",
                blocker_kind="query_during_replay_heartbeat_admission_failed",
            )
        probe_context["replay_blocked_heartbeat_acknowledged_at"] = query_task_holder.get(
            "replay_blocked_heartbeat_acknowledged_at"
        )

        probe_context["phase"] = "signal_during_replay_api_call"
        signal_sent_at = now()
        signal_response = http_json(
            base_url,
            api_path("workflows", replay_workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": 5}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        probe_context.update({
            "signal_sent_at": signal_sent_at,
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 5}},
                "response": response_sample(signal_response),
            },
            "signal_status_code": signal_response.get("status_code"),
        })
        if int(signal_response["status_code"]) >= 400:
            raise ReplayTimingProbeFailure(
                f"signal during replay failed: {response_sample(signal_response)}",
                phase="signal_during_replay_api_call",
                status="fail",
                owner="server",
                blocker_kind="signal_during_replay_api_failed",
            )

        time.sleep(0.3)
        probe_context["phase"] = "replay_task_complete"
        replay_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            replay_task,
            [
                {
                    "type": "open_condition_wait",
                    "condition_key": "signals-queries-replay-barrier",
                    "timeout_seconds": 60,
                },
            ],
        )
        if int(replay_complete["status_code"]) >= 400:
            raise ReplayTimingProbeFailure(
                f"replay timing workflow task completion failed: {replay_complete}",
                phase="replay_task_complete",
                status="fail",
                owner="server, workflow, sdk-python",
                blocker_kind="replay_task_completion_failed",
            )
        replay_completed_at = now()
        probe_context["replay_completed_at"] = replay_completed_at

        query_responder.join(timeout=20)
        query_thread.join(timeout=20)
        if query_responder.is_alive() or query_task_holder.get("error"):
            raise ReplayTimingProbeFailure(
                f"query during replay responder failed: {query_task_holder.get('error', 'timeout')}",
                phase="query_during_replay_worker_response",
                status="fail",
                owner="server, workflow, sdk-python",
                blocker_kind="query_during_replay_worker_response_failed",
            )
        if query_thread.is_alive():
            raise ReplayTimingProbeFailure(
                "query during replay API call timed out",
                phase="query_during_replay_api_response",
                status="fail",
                owner="server",
                blocker_kind="query_during_replay_api_timeout",
            )

        probe_context["phase"] = "signal_application_after_replay"
        signal_apply_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        signal_apply_task = task_from_poll(signal_apply_poll, "signal application")
        if signal_name_from_task(signal_apply_task) != "increment":
            raise ReplayTimingProbeFailure(
                f"signal application task did not carry increment signal: {signal_apply_task}",
                phase="signal_application_after_replay",
                status="fail",
                owner="server, workflow, sdk-python",
                blocker_kind="signal_application_task_missing_signal",
            )

        signal_apply_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            signal_apply_task,
            [
                {
                    "type": "open_condition_wait",
                    "condition_key": "signals-queries-after-signal",
                    "timeout_seconds": 60,
                },
            ],
        )
        if int(signal_apply_complete["status_code"]) >= 400:
            raise ReplayTimingProbeFailure(
                f"signal application workflow task completion failed: {signal_apply_complete}",
                phase="signal_application_after_replay",
                status="fail",
                owner="server, workflow, sdk-python",
                blocker_kind="signal_application_task_completion_failed",
            )
        signal_applied_at = now()

        query_response = query_holder.get("response") if isinstance(query_holder.get("response"), dict) else {}
        query_body = query_response.get("body") if isinstance(query_response, dict) else {}
        query_answer = query_body.get("result") if isinstance(query_body, dict) else None
        probe_context.update({
            "phase": "replay_timing_observed",
            "signal_applied_at": signal_applied_at,
            "signal_application_task_id": signal_apply_task.get("task_id"),
            "signal_application_history_event_types": history_event_types_from_task(signal_apply_task),
            "query_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "query", "state"),
                "body": {},
                "response": response_sample(query_response),
            },
            "query_status_code": query_response.get("status_code"),
            "query_handler_invoked_at": query_task_holder.get("query_handler_invoked_at"),
            "query_completed_at": (
                query_holder.get("query_completed_at")
                or query_task_holder.get("query_completed_at")
            ),
            "query_answer": query_answer,
            "expected_answer": 0,
            "query_task_id": (
                query_task_holder.get("query_task", {}).get("query_task_id")
                if isinstance(query_task_holder.get("query_task"), dict)
                else None
            ),
            "replay_timing_observed": True,
        })

        probe_context["phase"] = "terminal_workflow_start"
        terminal_start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": terminal_workflow_id,
                "workflow_type": workflow_type,
                "task_queue": probe_task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        probe_context["terminal_start_response"] = response_sample(terminal_start)
        if int(terminal_start["status_code"]) >= 400:
            probe_context["terminal_failure_reason"] = f"terminal workflow start failed: {response_sample(terminal_start)}"
            raise RuntimeError(f"terminal workflow start failed: {terminal_start}")

        terminal_run_id = str(terminal_start["body"]["run_id"])
        probe_context.update({
            "completed_workflow_id": terminal_workflow_id,
            "completed_run_id": terminal_run_id,
        })
        probe_context["phase"] = "terminal_task_poll"
        terminal_poll = poll_workflow_task(base_url, token, namespace, probe_worker_id, probe_task_queue)
        terminal_task = task_from_poll(terminal_poll, "terminal")
        probe_context["phase"] = "terminal_task_complete"
        terminal_complete = complete_workflow_task(
            base_url,
            token,
            namespace,
            terminal_task,
            [
                {
                    "type": "complete_workflow",
                    "payload_codec": "avro",
                    "result": "wwHioz3/VYAiNw4EDmNvdW50ZXIEAAxzdGF0dXMKEmNvbXBsZXRlZAA=",
                },
            ],
        )
        probe_context["terminal_complete_response"] = response_sample(terminal_complete)
        if int(terminal_complete["status_code"]) >= 400:
            probe_context["terminal_failure_reason"] = f"terminal workflow completion failed: {response_sample(terminal_complete)}"
            raise RuntimeError(f"terminal workflow completion failed: {terminal_complete}")
        completed_at = now()
        terminal_state_before_operations = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            terminal_workflow_id,
            terminal_run_id,
        )
        terminal_status = terminal_state_before_operations.get("status") or run_status(
            base_url,
            token,
            namespace,
            terminal_workflow_id,
        )
        probe_context.update({
            "completed_at": completed_at,
            "terminal_status": terminal_status,
            "terminal_state_before_operations": terminal_state_before_operations,
        })

        probe_context["phase"] = "terminal_signal_after_completion"
        terminal_signal_sent_at = now()
        terminal_signal = http_json(
            base_url,
            api_path("workflows", terminal_workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": 1}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        terminal_signal_sample = response_sample(terminal_signal)
        probe_context.update({
            "signal_sent_at": terminal_signal_sent_at,
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", terminal_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 1}},
                "response": terminal_signal_sample,
            },
            "signal_error": terminal_signal_sample,
        })

        probe_context["phase"] = "terminal_query_after_completion"
        terminal_query_holder: dict[str, Any] = {}
        terminal_query_thread = threading.Thread(
            target=workflow_query_call,
            args=(base_url, token, namespace, terminal_workflow_id, "state", terminal_query_holder),
            daemon=True,
        )
        terminal_query_thread.start()
        terminal_query_sent_deadline = time.time() + 2
        while "query_sent_at" not in terminal_query_holder and time.time() < terminal_query_sent_deadline:
            time.sleep(0.01)
        probe_context["query_sent_at"] = terminal_query_holder.get("query_sent_at")

        terminal_query_task_holder: dict[str, Any] = {}
        terminal_query_responder: threading.Thread | None = None
        terminal_query_thread.join(timeout=0.5)
        if terminal_query_thread.is_alive():
            terminal_query_responder = threading.Thread(
                target=answer_next_query_task,
                args=(
                    base_url,
                    token,
                    namespace,
                    probe_worker_id,
                    probe_task_queue,
                    {"counter": 0, "status": "completed"},
                    log_file,
                    terminal_query_task_holder,
                    5.0,
                ),
                daemon=True,
            )
            terminal_query_responder.start()
            terminal_query_responder.join(timeout=8)
        terminal_query_thread.join(timeout=20)
        if terminal_query_thread.is_alive():
            probe_context["terminal_failure_reason"] = "completed-run query API call timed out"
            raise RuntimeError("completed-run query API call timed out")

        terminal_query = (
            terminal_query_holder.get("response")
            if isinstance(terminal_query_holder.get("response"), dict)
            else {}
        )
        terminal_query_body = terminal_query.get("body") if isinstance(terminal_query, dict) else {}
        terminal_query_status = int(terminal_query.get("status_code") or 0)
        if terminal_query_responder is not None and (
            terminal_query_responder.is_alive() or terminal_query_task_holder.get("error")
        ):
            if terminal_query_status < 400 or terminal_query_status > 499:
                probe_context["terminal_failure_reason"] = (
                    "completed-run query responder failed: "
                    f"{terminal_query_task_holder.get('error', 'timeout')}"
                )
                raise RuntimeError(
                    f"completed-run query responder failed: {terminal_query_task_holder.get('error', 'timeout')}"
                )

        terminal_state_after_operations = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            terminal_workflow_id,
            terminal_run_id,
        )
        before_result = terminal_state_before_operations.get("result", terminal_state_before_operations.get("output"))
        after_result = terminal_state_after_operations.get("result", terminal_state_after_operations.get("output"))
        terminal_history_changed_after_operations = observed_count_changed(
            terminal_state_before_operations,
            terminal_state_after_operations,
            "history_event_count",
        )
        query_result_or_error = {
            "status_code": terminal_query.get("status_code"),
            "reason": terminal_query_body.get("reason") if isinstance(terminal_query_body, dict) else None,
            "outcome": "completed_query_replayed_final_state"
            if int(terminal_query.get("status_code") or 0) < 400
            else "completed_query_typed_error",
            "result": terminal_query_body.get("result") if isinstance(terminal_query_body, dict) else None,
        }
        terminal_query_sample = {
            "method": "POST",
            "path": api_path("workflows", terminal_workflow_id, "query", "state"),
            "body": {},
            "response": response_sample(terminal_query),
        }
        probe_context.update({
            "query_api_sample": terminal_query_sample,
            "query_result_or_error": query_result_or_error,
            "public_query_surfaces": [
                "control-plane-api",
                "worker-query-task-protocol",
            ],
            "terminal_state_after_operations": terminal_state_after_operations,
            "terminal_state_changed_after_operations": terminal_state_before_operations != terminal_state_after_operations,
            "terminal_result_changed_after_operations": before_result != after_result,
            "terminal_history_changed_after_operations": terminal_history_changed_after_operations,
            "run_status_after_operations": terminal_state_after_operations.get("status") or run_status(
                base_url,
                token,
                namespace,
                terminal_workflow_id,
            ),
        })

        signal_outputs = {
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 5}},
                "response": response_sample(signal_response),
            },
            "signal_status_code": signal_response.get("status_code"),
            "worker_restart_at": worker_restart_at,
            "signal_sent_at": signal_sent_at,
            "replay_completed_at": replay_completed_at,
            "signal_applied_at": signal_applied_at,
            "workflow_id": replay_workflow_id,
            "run_id": replay_run_id,
            "leased_replay_task_id": replay_task.get("task_id"),
            "signal_application_task_id": signal_apply_task.get("task_id"),
            "signal_application_history_event_types": history_event_types_from_task(signal_apply_task),
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        query_outputs = {
            "query_api_sample": {
                "method": "POST",
                "path": api_path("workflows", replay_workflow_id, "query", "state"),
                "body": {},
                "response": response_sample(query_response),
            },
            "query_status_code": query_response.get("status_code"),
            "worker_restart_at": worker_restart_at,
            "query_sent_at": query_holder.get("query_sent_at"),
            "query_poll_started_at": query_task_holder.get("query_poll_started_at"),
            "query_blocked_by_replay_at": query_task_holder.get("workflow_task_leased_at"),
            "query_blocked_poll_status": "workflow_task_leased",
            "replay_blocked_heartbeat_acknowledged_at": query_task_holder.get(
                "replay_blocked_heartbeat_acknowledged_at"
            ),
            "replay_completed_at": replay_completed_at,
            "query_handler_invoked_at": query_task_holder.get("query_handler_invoked_at"),
            "query_completed_at": query_holder.get("query_completed_at") or query_task_holder.get("query_completed_at"),
            "query_answer": query_answer,
            "expected_answer": 0,
            "query_task_id": (
                query_task_holder.get("query_task", {}).get("query_task_id")
                if isinstance(query_task_holder.get("query_task"), dict)
                else None
            ),
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        terminal_outputs = {
            "completed_run_id": terminal_run_id,
            "completed_at": completed_at,
            "terminal_status": terminal_status,
            "signal_sent_at": terminal_signal_sent_at,
            "signal_api_sample": {
                "method": "POST",
                "path": api_path("workflows", terminal_workflow_id, "signal", "increment"),
                "body": {"input": {"amount": 1}},
                "response": terminal_signal_sample,
            },
            "signal_error": terminal_signal_sample,
            "query_sent_at": terminal_query_holder.get("query_sent_at"),
            "query_api_sample": terminal_query_sample,
            "query_result_or_error": query_result_or_error,
            "public_query_surfaces": [
                "control-plane-api",
                "worker-query-task-protocol",
            ],
            "terminal_state_before_operations": terminal_state_before_operations,
            "terminal_state_after_operations": terminal_state_after_operations,
            "terminal_state_changed_after_operations": terminal_state_before_operations != terminal_state_after_operations,
            "terminal_result_changed_after_operations": before_result != after_result,
            "terminal_history_changed_after_operations": terminal_history_changed_after_operations,
            "run_status_after_operations": probe_context["run_status_after_operations"],
            "workflow_id": terminal_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }

        evidence = {
            "artifact_versions": versions,
            "scenario_results": {
                "signal_during_replay": replay_timing_scenario_result(
                    "signal_during_replay",
                    signal_outputs,
                ),
                "query_during_replay": replay_timing_scenario_result(
                    "query_during_replay",
                    query_outputs,
                ),
                "completed_run_signal_and_query": completed_run_scenario_result(
                    terminal_outputs,
                ),
            },
        }
        descriptor = {
            "workflow_id": replay_workflow_id,
            "run_id": replay_run_id,
            "worker_id": probe_worker_id,
            "task_queue": probe_task_queue,
            "completed_workflow_id": terminal_workflow_id,
            "completed_run_id": terminal_run_id,
            "server_base_url": base_url,
            "generated_scenarios": [
                "signal_during_replay",
                "query_during_replay",
                "completed_run_signal_and_query",
            ],
        }
        return evidence, descriptor
    except ReplayTimingProbeFailure as exc:
        log_line(log_file, f"replay timing probe produced focused finding: {type(exc).__name__}: {exc}")
        terminal_status = "runner_blocked" if exc.status == "runner_blocked" else "fail"
        terminal_owner = "conformance_harness" if terminal_status == "runner_blocked" else exc.owner
        terminal_blocker_kind = (
            f"completed_run_probe_blocked_by_{exc.phase}"
            if terminal_status == "runner_blocked"
            else None
        )
        if terminal_status == "runner_blocked":
            terminal_reason = (
                "Completed-run signal/query handling was not exercised because the shared "
                f"replay/terminal probe stopped during {exc.phase}: {exc}"
            )
        else:
            terminal_reason = (
                "Completed-run signal/query handling was not exercised because the shared "
                f"replay/terminal probe first exposed product replay/query behavior during {exc.phase}: {exc}"
            )
        terminal_result = completed_run_scenario_result(
            completed_run_context_outputs(probe_context, versions, sources),
            status=terminal_status,
            reason=terminal_reason,
            phase=exc.phase,
            owner=terminal_owner,
            blocker_kind=terminal_blocker_kind,
        )
        if probe_context.get("replay_timing_observed"):
            scenario_results = replay_timing_results_from_context(probe_context, versions, sources)
            scenario_results["completed_run_signal_and_query"] = terminal_result
            return {
                "artifact_versions": versions,
                "scenario_results": scenario_results,
            }, {
                "workflow_id": probe_context.get("workflow_id"),
                "run_id": probe_context.get("run_id"),
                "worker_id": probe_context.get("worker_id"),
                "task_queue": probe_context.get("task_queue"),
                "server_base_url": base_url,
                "generated_scenarios": [
                    "signal_during_replay",
                    "query_during_replay",
                    "completed_run_signal_and_query",
                ],
                "completed_run_probe": {
                    "error": f"{type(exc).__name__}: {exc}",
                    "probe_phase": exc.phase,
                },
            }
        evidence, descriptor = replay_timing_probe_failure_evidence(exc, probe_context, versions, sources)
        scenario_results = evidence.setdefault("scenario_results", {})
        if isinstance(scenario_results, dict):
            scenario_results["completed_run_signal_and_query"] = terminal_result
        descriptor["generated_scenarios"] = [
            "signal_during_replay",
            "query_during_replay",
            "completed_run_signal_and_query",
        ]
        descriptor["completed_run_probe"] = {
            "error": f"{type(exc).__name__}: {exc}",
            "probe_phase": exc.phase,
        }
        return evidence, descriptor
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"replay/terminal probe failed: {type(exc).__name__}: {exc}")
        phase = str(probe_context.get("phase") or "replay_terminal_probe")
        reason = str(probe_context.get("terminal_failure_reason") or f"{type(exc).__name__}: {exc}")
        terminal_status = "fail" if phase.startswith("terminal_") else "runner_blocked"
        terminal_owner = "server, workflow, sdk-python, cli" if terminal_status == "fail" else "conformance_harness"
        terminal_result = completed_run_scenario_result(
            completed_run_context_outputs(probe_context, versions, sources),
            status=terminal_status,
            reason=reason,
            phase=phase,
            owner=terminal_owner,
            blocker_kind="completed_run_probe_unavailable" if terminal_status == "runner_blocked" else None,
        )
        if probe_context.get("replay_timing_observed"):
            scenario_results = replay_timing_results_from_context(probe_context, versions, sources)
            scenario_results["completed_run_signal_and_query"] = terminal_result
            return {
                "artifact_versions": versions,
                "scenario_results": scenario_results,
            }, {
                "workflow_id": probe_context.get("workflow_id"),
                "run_id": probe_context.get("run_id"),
                "worker_id": probe_context.get("worker_id"),
                "task_queue": probe_context.get("task_queue"),
                "server_base_url": base_url,
                "generated_scenarios": [
                    "signal_during_replay",
                    "query_during_replay",
                    "completed_run_signal_and_query",
                ],
                "completed_run_probe": {
                    "error": f"{type(exc).__name__}: {exc}",
                    "probe_phase": phase,
                },
            }
        failure = ReplayTimingProbeFailure(
            f"{type(exc).__name__}: {exc}",
            phase=phase,
        )
        evidence, descriptor = replay_timing_probe_failure_evidence(failure, probe_context, versions, sources)
        scenario_results = evidence.setdefault("scenario_results", {})
        if isinstance(scenario_results, dict):
            scenario_results["completed_run_signal_and_query"] = terminal_result
        descriptor["generated_scenarios"] = [
            "signal_during_replay",
            "query_during_replay",
            "completed_run_signal_and_query",
        ]
        descriptor["completed_run_probe"] = {
            "error": f"{type(exc).__name__}: {exc}",
            "probe_phase": phase,
        }
        return evidence, descriptor


def probe_artifact_versions() -> dict[str, str]:
    return {
        "server": artifact_version_value(artifact_versions, "server"),
        "cli": artifact_version_value(artifact_versions, "cli"),
        "sdk-python": artifact_version_value(artifact_versions, "sdk-python"),
        "sdk-rust": artifact_version_value(artifact_versions, "sdk-rust"),
        "sdk-php": artifact_version_value(artifact_versions, "sdk-php"),
        "workflow": artifact_version_value(artifact_versions, "workflow"),
        "waterline": artifact_version_value(artifact_versions, "waterline"),
    }


def probe_artifact_sources(
    cleanup_commands: list[list[str]],
    install_entries: dict[str, dict[str, Any]] | None = None,
) -> dict[str, str]:
    sources = dict(EXPECTED_ARTIFACT_SOURCES)
    if not cleanup_commands:
        sources["server"] = "configured_server_endpoint"
    for artifact, entry in (install_entries or {}).items():
        source = str(entry.get("source") or "").strip()
        if source:
            sources[artifact] = source
    return sources


def write_python_sdk_counter_worker(run_root: Path) -> Path:
    worker_script = run_root / "python-sdk-counter-worker.py"
    worker_script.write_text(
        r'''
from __future__ import annotations

import asyncio
from importlib.metadata import version
import json
import logging
from datetime import datetime, timezone
from pathlib import Path
import signal
import sys

from durable_workflow import Client, PassthroughWorkerInterceptor, Worker, workflow


@workflow.defn(name="conformance.counter")
class CounterWorkflow:
    def __init__(self) -> None:
        self.count = 0

    @workflow.signal("increment")
    def increment(self, amount: int) -> None:
        self.count += amount

    @workflow.query("state")
    def state(self) -> int:
        return self.count

    @workflow.query("current")
    def current(self) -> int:
        return self.count

    @workflow.query("count-at-least")
    def count_at_least(self, minimum: int) -> bool:
        return self.count >= minimum

    def run(self, ctx):  # type: ignore[no-untyped-def]
        yield ctx.wait_condition(lambda: False, key="signals-queries-baseline-open", timeout=3600)


class QueryRouteEvidenceInterceptor(PassthroughWorkerInterceptor):
    def __init__(self, evidence_path: Path, sdk_version: str) -> None:
        self.evidence_path = evidence_path
        self.sdk_version = sdk_version

    async def execute_query_task(self, context, next):  # type: ignore[no-untyped-def]
        task = context.task
        outcome = await next(context)
        record = {
            "schema": "durable-workflow.v2.signal-query-runtime.routed-current-query-task",
            "status": "pass" if outcome == "completed" else outcome,
            "worker_runtime": "sdk-python",
            "worker_sdk_version": self.sdk_version,
            "worker_id": context.worker_id,
            "task_queue": context.task_queue,
            "query_task_id": task.get("query_task_id"),
            "query_task_attempt": task.get("query_task_attempt"),
            "workflow_id": task.get("workflow_id"),
            "run_id": task.get("run_id"),
            "workflow_type": task.get("workflow_type"),
            "query_name": task.get("query_name"),
            "lease_owner": task.get("lease_owner"),
            "run_status": task.get("run_status"),
            "last_history_sequence": task.get("last_history_sequence"),
            "server_route": "worker_query_task_poll",
            "completion_route": "worker_query_task_complete",
            "observed_via": "sdk-python worker query task interceptor",
            "observed_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        }
        with self.evidence_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, sort_keys=True) + "\n")
        return outcome


async def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    base_url, token, namespace, task_queue, worker_id, evidence_path = sys.argv[1:7]
    sdk_version = version("durable-workflow")

    async with Client(base_url, token=token, namespace=namespace, timeout=30.0) as client:
        worker = Worker(
            client,
            task_queue=task_queue,
            workflows=[CounterWorkflow],
            worker_id=worker_id,
            interceptors=[QueryRouteEvidenceInterceptor(Path(evidence_path), sdk_version)],
            poll_timeout=5.0,
            max_concurrent_workflow_tasks=2,
            max_concurrent_activity_tasks=1,
            heartbeat_interval=5.0,
        )

        stop_task = asyncio.create_task(worker.run())

        def request_stop(_signum, _frame):  # type: ignore[no-untyped-def]
            worker._stop.set()  # noqa: SLF001 - conformance worker process owns this lifecycle.

        signal.signal(signal.SIGTERM, request_stop)
        signal.signal(signal.SIGINT, request_stop)

        await stop_task

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
'''.lstrip(),
        encoding="utf-8",
    )
    return worker_script


def start_python_sdk_counter_worker(
    *,
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
    worker_id: str,
    query_route_evidence_path: Path,
    run_root: Path,
    log_file: Path,
) -> subprocess.Popen[str]:
    script = write_python_sdk_counter_worker(run_root)
    env = os.environ.copy()
    env.update(
        {
            "PYTHONUNBUFFERED": "1",
            "DURABLE_WORKFLOW_SERVER_URL": base_url,
            "DURABLE_WORKFLOW_AUTH_TOKEN": token,
            "DURABLE_WORKFLOW_NAMESPACE": namespace,
        }
    )
    log_line(log_file, f"starting Python SDK worker {worker_id} on {task_queue}")
    worker_output = log_file.open("a", encoding="utf-8")
    try:
        worker_output.write(f"{now()} python-sdk-worker-output-begin\n")
        worker_output.flush()
        return subprocess.Popen(
            [
                python_bin,
                str(script),
                base_url,
                token,
                namespace,
                task_queue,
                worker_id,
                str(query_route_evidence_path),
            ],
            cwd=str(run_root),
            env=env,
            text=True,
            stdout=worker_output,
            stderr=worker_output,
        )
    finally:
        worker_output.close()


def stop_python_sdk_counter_worker(process: subprocess.Popen[str], log_file: Path) -> None:
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=10)


def rust_dependency_cache_limit(name: str, default: int) -> int:
    value = env_text(name)
    if value is None:
        return default
    try:
        parsed = int(value)
    except ValueError:
        return default
    return parsed if parsed > 0 else default


def path_contains(parent: Path, child: Path) -> bool:
    try:
        child.relative_to(parent)
    except ValueError:
        return False
    return True


def rust_dependency_cache_root(run_root: Path) -> Path:
    configured = env_text("DW_SIGNALS_QUERIES_RUST_CACHE_DIR")
    if configured is not None:
        cache_root = Path(configured).expanduser()
    else:
        result_root = Path(os.environ["RESULT_DIR"]).expanduser().resolve(strict=True)
        shared_tmp = env_text("DW_CONFORMANCE_TMPDIR")
        cache_base = (
            Path(shared_tmp).expanduser().resolve(strict=True)
            if shared_tmp is not None
            else result_root.parent
        )
        cache_root = (
            cache_base
            / f".durable-workflow-conformance-cache-{os.getuid()}"
            / "signals-queries"
            / "rust-dependencies"
        )

    cache_root = cache_root.absolute()
    if cache_root.is_symlink():
        raise RuntimeError("Rust dependency cache must not be a symlink")
    cache_root = cache_root.resolve(strict=False)
    protected_paths = [
        Path(os.environ["REPO_ROOT"]).resolve(strict=False),
        Path(os.environ["RESULT_DIR"]).resolve(strict=False),
        run_root.resolve(strict=False),
    ]
    for protected in protected_paths:
        if path_contains(protected, cache_root) or path_contains(cache_root, protected):
            raise RuntimeError(
                "DW_SIGNALS_QUERIES_RUST_CACHE_DIR must be separate from source, result, and run roots"
            )

    cache_root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if cache_root.is_symlink() or cache_root.stat().st_uid != os.getuid():
        raise RuntimeError("Rust dependency cache must be a host-owned directory, not a symlink")
    cache_root.chmod(0o700)
    return cache_root


@contextmanager
def rust_dependency_cache_lock(path: Path, *, shared: bool) -> Any:
    with path.open("a+", encoding="utf-8") as handle:
        if os.fstat(handle.fileno()).st_uid != os.getuid():
            raise RuntimeError(f"Rust dependency cache lock is not owned by uid {os.getuid()}")
        fcntl.flock(handle.fileno(), fcntl.LOCK_SH if shared else fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def rust_dependency_cache_directory_size(path: Path) -> int:
    total = 0
    for root, _directories, files in os.walk(path, followlinks=False):
        for filename in files:
            candidate = Path(root) / filename
            try:
                total += candidate.lstat().st_size
            except FileNotFoundError:
                continue
    return total


def prune_rust_dependency_cache(cache_root: Path, *, keep_key: str | None = None) -> None:
    max_entries = rust_dependency_cache_limit(
        "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_ENTRIES",
        RUST_DEPENDENCY_CACHE_DEFAULT_MAX_ENTRIES,
    )
    max_bytes = rust_dependency_cache_limit(
        "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_BYTES",
        RUST_DEPENDENCY_CACHE_DEFAULT_MAX_BYTES,
    )
    max_age = rust_dependency_cache_limit(
        "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_AGE_SECONDS",
        RUST_DEPENDENCY_CACHE_DEFAULT_MAX_AGE_SECONDS,
    )
    with rust_dependency_cache_lock(cache_root / ".cache.lock", shared=False):
        entries: list[dict[str, Any]] = []
        for candidate in cache_root.iterdir():
            if candidate.name.startswith(".") or re.fullmatch(r"[0-9a-f]{64}", candidate.name) is None:
                continue
            if candidate.is_symlink():
                candidate.unlink()
                continue
            if not candidate.is_dir() or candidate.stat().st_uid != os.getuid():
                raise RuntimeError(f"Rust dependency cache entry is not host-owned: {candidate.name}")
            entries.append({
                "path": candidate,
                "key": candidate.name,
                "last_used": candidate.stat().st_mtime,
                "size": rust_dependency_cache_directory_size(candidate),
            })

        entries.sort(key=lambda entry: (entry["last_used"], entry["key"]))
        total_size = sum(entry["size"] for entry in entries)
        now_epoch = time.time()
        for entry in list(entries):
            over_age = now_epoch - entry["last_used"] > max_age
            over_count = len(entries) > max_entries
            over_size = total_size > max_bytes
            if not (over_age or over_count or over_size) or entry["key"] == keep_key:
                continue
            shutil.rmtree(entry["path"])
            total_size -= entry["size"]
            entries.remove(entry)

        if len(entries) > max_entries or total_size > max_bytes:
            raise RuntimeError(
                "active Rust dependency cache entry exceeds the configured cache retention bounds"
            )


def clear_rust_dependency_cache_entry(entry: Path) -> None:
    for candidate in entry.iterdir():
        if candidate.name == ".entry.lock":
            continue
        if candidate.is_dir() and not candidate.is_symlink():
            shutil.rmtree(candidate)
        else:
            candidate.unlink()


def rust_dependency_cache_identity(
    *,
    toolchain: dict[str, str],
    sdk_version: str,
    cargo_lock: bytes,
    dependency_manifest: bytes,
) -> dict[str, Any]:
    identity = {
        "schema": RUST_DEPENDENCY_CACHE_SCHEMA,
        "runtime_image": rust_probe_image(),
        "runtime_image_id": toolchain.get("runtime_image_id", rust_probe_image()),
        "rustc": toolchain["rustc"],
        "cargo": toolchain["cargo"],
        "target": toolchain["target"],
        "sdk_version": sdk_version,
        "cargo_lock_sha256": hashlib.sha256(cargo_lock).hexdigest(),
        "dependency_manifest_sha256": hashlib.sha256(dependency_manifest).hexdigest(),
        "profile": "release",
    }
    encoded = json.dumps(identity, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return {"key": hashlib.sha256(encoded).hexdigest(), "identity": identity}


def rust_dependency_cache_marker(entry: Path) -> dict[str, Any] | None:
    marker_path = entry / "complete.json"
    if not marker_path.is_file() or marker_path.is_symlink():
        return None
    try:
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return marker if isinstance(marker, dict) else None


@contextmanager
def rust_dependency_cache_session(run_root: Path, cache_identity: dict[str, Any]) -> Any:
    cache_root = rust_dependency_cache_root(run_root)
    cache_key = str(cache_identity["key"])
    prune_rust_dependency_cache(cache_root)
    try:
        with rust_dependency_cache_lock(cache_root / ".cache.lock", shared=True):
            entry = cache_root / cache_key
            entry.mkdir(mode=0o700, exist_ok=True)
            if entry.is_symlink() or entry.stat().st_uid != os.getuid():
                raise RuntimeError("Rust dependency cache entry must be host-owned and must not be a symlink")
            entry.chmod(0o700)
            with rust_dependency_cache_lock(entry / ".entry.lock", shared=False):
                marker = rust_dependency_cache_marker(entry)
                warm = (
                    isinstance(marker, dict)
                    and marker.get("cache_identity") == cache_identity["identity"]
                    and (entry / "cargo-home").is_dir()
                    and not (entry / "cargo-home").is_symlink()
                    and (entry / "target" / "release" / "deps").is_dir()
                    and not (entry / "target").is_symlink()
                )
                if not warm:
                    clear_rust_dependency_cache_entry(entry)
                    marker = None
                cargo_home = entry / "cargo-home"
                cargo_target = entry / "target"
                cargo_home.mkdir(mode=0o700, exist_ok=True)
                cargo_target.mkdir(mode=0o700, exist_ok=True)
                for cache_directory in (cargo_home, cargo_target):
                    if cache_directory.is_symlink() or cache_directory.stat().st_uid != os.getuid():
                        raise RuntimeError("Rust dependency cache content must remain in host-owned directories")
                    cache_directory.chmod(0o700)
                (entry / "complete.json").unlink(missing_ok=True)
                session = {
                    "cache_root": cache_root,
                    "entry": entry,
                    "key": cache_key,
                    "identity": cache_identity["identity"],
                    "state_before_build": "warm" if warm else "cold",
                    "previous_timings": dict(marker.get("timings", {})) if marker is not None else {},
                    "cargo_home": cargo_home,
                    "cargo_target": cargo_target,
                }
                try:
                    yield session
                finally:
                    if not (entry / "complete.json").is_file():
                        clear_rust_dependency_cache_entry(entry)
    finally:
        prune_rust_dependency_cache(cache_root, keep_key=cache_key)


def complete_rust_dependency_cache_session(
    session: dict[str, Any],
    timing: dict[str, Any],
) -> dict[str, Any]:
    timings = dict(session["previous_timings"])
    timings[session["state_before_build"]] = timing
    marker = {
        "schema": RUST_DEPENDENCY_CACHE_SCHEMA,
        "cache_identity": session["identity"],
        "timings": timings,
        "last_used_at": now(),
    }
    atomic_write_json(session["entry"] / "complete.json", marker)
    os.utime(session["entry"], None)
    return {
        "schema": RUST_DEPENDENCY_CACHE_SCHEMA,
        "cache_key": session["key"],
        "cache_identity": session["identity"],
        "state_before_build": session["state_before_build"],
        "dependency_artifacts_reused": (
            session["state_before_build"] == "warm"
            and timing["compiled_dependency_package_count"] == 0
        ),
        "probe_binary_shared": False,
        "source_and_runtime_state_shared": False,
        "shared_content": [
            "official_crates_io_registry_downloads",
            "compiled_dependency_artifacts",
        ],
        "isolated_content": [
            "generated_probe_source",
            "probe_binary",
            "conformance_results",
            "credentials",
            "runtime_state",
        ],
        "host_uid": os.getuid(),
        "retention": {
            "max_entries": rust_dependency_cache_limit(
                "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_ENTRIES",
                RUST_DEPENDENCY_CACHE_DEFAULT_MAX_ENTRIES,
            ),
            "max_bytes": rust_dependency_cache_limit(
                "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_BYTES",
                RUST_DEPENDENCY_CACHE_DEFAULT_MAX_BYTES,
            ),
            "max_age_seconds": rust_dependency_cache_limit(
                "DW_SIGNALS_QUERIES_RUST_CACHE_MAX_AGE_SECONDS",
                RUST_DEPENDENCY_CACHE_DEFAULT_MAX_AGE_SECONDS,
            ),
        },
        "timings": timings,
    }


def purge_cached_rust_probe_outputs(cargo_target: Path) -> None:
    release = cargo_target / "release"
    for candidate in (
        release / "signals-queries-published-probe",
        release / "signals-queries-published-probe.d",
    ):
        candidate.unlink(missing_ok=True)
    for pattern in (
        "deps/signals_queries_published_probe-*",
        ".fingerprint/signals-queries-published-probe-*",
        "incremental/signals_queries_published_probe-*",
    ):
        for candidate in release.glob(pattern):
            if candidate.is_dir() and not candidate.is_symlink():
                shutil.rmtree(candidate)
            else:
                candidate.unlink(missing_ok=True)


def rust_dependency_build_target(
    run_root: Path,
    session: dict[str, Any],
) -> tuple[Path, dict[str, Any]]:
    build_target = run_root / f"rust-dependency-target-{session['key'][:12]}"
    if build_target.exists() or build_target.is_symlink():
        if build_target.is_dir() and not build_target.is_symlink():
            shutil.rmtree(build_target)
        else:
            build_target.unlink()

    restore_started = time.monotonic()
    restored = session["state_before_build"] == "warm"
    if restored:
        shutil.copytree(session["cargo_target"], build_target)
    else:
        build_target.mkdir(mode=0o700, parents=True)
    if build_target.is_symlink() or build_target.stat().st_uid != os.getuid():
        raise RuntimeError("per-run Rust dependency build target must remain host-owned")
    build_target.chmod(0o700)
    return build_target, {
        "dependency_artifacts_restored": restored,
        "restore_elapsed_seconds": round(time.monotonic() - restore_started, 3),
    }


def persist_rust_dependency_build_target(
    build_target: Path,
    session: dict[str, Any],
) -> float:
    persist_started = time.monotonic()
    cached_target = session["cargo_target"]
    if cached_target.exists() or cached_target.is_symlink():
        if cached_target.is_dir() and not cached_target.is_symlink():
            shutil.rmtree(cached_target)
        else:
            cached_target.unlink()
    shutil.copytree(build_target, cached_target)
    if cached_target.is_symlink() or cached_target.stat().st_uid != os.getuid():
        raise RuntimeError("persisted Rust dependency target must remain host-owned")
    cached_target.chmod(0o700)
    return round(time.monotonic() - persist_started, 3)


def rust_cache_filesystem_diagnostics(path: Path) -> dict[str, Any]:
    usage = shutil.disk_usage(path)
    stat = path.stat()
    return {
        "owner_uid": stat.st_uid,
        "mode": oct(stat.st_mode & 0o777),
        "total_bytes": usage.total,
        "used_bytes": usage.used,
        "free_bytes": usage.free,
    }


def rust_build_failure_diagnostics(
    *,
    build: subprocess.CompletedProcess[str],
    session: dict[str, Any],
    build_target: Path,
    run_root: Path,
    elapsed_seconds: float,
    compiled_packages: list[str],
) -> dict[str, Any]:
    return {
        "cache_state_before_build": session["state_before_build"],
        "elapsed_seconds": elapsed_seconds,
        "compiled_package_count": len(compiled_packages),
        "cache_filesystem": rust_cache_filesystem_diagnostics(session["cache_root"]),
        "build_target_filesystem": rust_cache_filesystem_diagnostics(build_target),
        "run_filesystem": rust_cache_filesystem_diagnostics(run_root),
        "process_return_code": build.returncode,
    }


def rust_probe_image() -> str:
    return env_text("DW_SIGNALS_QUERIES_RUST_DOCKER_IMAGE") or "rust:1.86-slim-bookworm"


def rust_probe_toolchain_identity(log_file: Path) -> dict[str, str]:
    command = [
        "docker",
        "run",
        "--rm",
        *docker_run_resource_options(),
        rust_probe_image(),
        "sh",
        "-c",
        "rustc -Vv && cargo -V",
    ]
    completed = run_command(command, log_file=log_file, timeout=120)
    if completed.returncode != 0:
        raise RuntimeError("could not identify the Rust toolchain used by the published probe")
    rustc_lines = [line.strip() for line in completed.stdout.splitlines() if line.strip()]
    cargo_line = next((line for line in rustc_lines if line.startswith("cargo ")), "")
    host_line = next((line for line in rustc_lines if line.startswith("host: ")), "")
    rustc_identity = "\n".join(line for line in rustc_lines if not line.startswith("cargo "))
    if not rustc_identity.startswith("rustc ") or not cargo_line or not host_line:
        raise RuntimeError("Rust toolchain identity output did not include rustc, cargo, and host target")
    inspect = run_command(
        ["docker", "image", "inspect", "--format", "{{.Id}}", rust_probe_image()],
        log_file=log_file,
        timeout=30,
    )
    image_id = inspect.stdout.strip()
    if inspect.returncode != 0 or re.fullmatch(r"sha256:[0-9a-f]{64}", image_id) is None:
        raise RuntimeError("could not resolve the immutable Rust runtime image identity")
    return {
        "rustc": rustc_identity,
        "cargo": cargo_line,
        "target": host_line.removeprefix("host: "),
        "runtime_image_id": image_id,
    }


def rust_probe_docker_command(
    project_dir: Path,
    args: list[str],
    *,
    env: dict[str, str] | None = None,
    name: str | None = None,
    detach: bool = False,
    cargo_home: Path | None = None,
    cargo_target: Path | None = None,
) -> list[str]:
    command = ["docker", "run"]
    if detach:
        if not name:
            raise RuntimeError("detached Rust probe command requires a container name")
        command.extend(["-d", "--name", name])
    else:
        command.append("--rm")
    command.extend([
        *docker_run_resource_options(),
        "--add-host",
        "host.docker.internal:host-gateway",
        "-v",
        docker_volume_spec(project_dir),
        "-w",
        "/app",
    ])
    if (cargo_home is None) != (cargo_target is None):
        raise RuntimeError("Rust dependency cache requires both Cargo home and target directories")
    if cargo_home is not None and cargo_target is not None:
        command.extend([
            "--mount",
            docker_bind_mount_spec(cargo_home, "/cache/cargo-home"),
            "--mount",
            docker_bind_mount_spec(cargo_target, "/cache/target"),
            "-e",
            "CARGO_HOME=/cache/cargo-home",
            "-e",
            "CARGO_TARGET_DIR=/cache/target",
        ])
    for key, value in sorted((env or {}).items()):
        command.extend(["-e", f"{key}={value}"])
    command.append(rust_probe_image())
    command.extend(args)
    return command


class RustCrateArtifactError(RuntimeError):
    def __init__(
        self,
        code: str,
        message: str,
        *,
        version: str,
        phase: str,
        package: str = "durable-workflow",
        command_result: subprocess.CompletedProcess[str] | None = None,
        details: dict[str, Any] | None = None,
    ):
        super().__init__(message)
        self.code = code
        self.version = version
        self.phase = phase
        self.package = package
        self.command_result = command_result
        self.details = details or {}


def rust_crate_version_from_artifact_tuple(versions: dict[str, Any]) -> str:
    version = artifact_version_value(versions, "sdk-rust")
    exact_semver = re.fullmatch(
        r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
        r"(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?"
        r"(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?",
        version,
    )
    if is_placeholder_version(version) or exact_semver is None:
        raise RustCrateArtifactError(
            "rust_crate_version_not_exact",
            "DW_RUST_SDK_VERSION must be an exact semantic version from the published artifact tuple",
            version=version,
            phase="validate_artifact_tuple",
        )
    return version


def official_crates_io_registry_source(source: str) -> bool:
    return source in {
        "registry+https://github.com/rust-lang/crates.io-index",
        "registry+https://index.crates.io",
        "registry+https://index.crates.io/",
        "registry+sparse+https://index.crates.io",
        "registry+sparse+https://index.crates.io/",
        "sparse+https://index.crates.io",
        "sparse+https://index.crates.io/",
    }


def prepare_rust_probe(run_root: Path, log_file: Path) -> tuple[Path, dict[str, Any], dict[str, Any]]:
    version = rust_crate_version_from_artifact_tuple(artifact_versions)
    if not command_available("docker"):
        raise RuntimeError("docker is required to install the published Rust SDK crate")

    project_dir = run_root / "sdk-rust"
    source_dir = project_dir / "src"
    source_dir.mkdir(parents=True, exist_ok=True)
    probe_source = Path(os.environ["REPO_ROOT"]) / "scripts" / "conformance" / "signals-queries-rust-probe.rs"
    shutil.copyfile(probe_source, source_dir / "main.rs")
    (project_dir / "Cargo.toml").write_text(
        "\n".join([
            "[package]",
            'name = "signals-queries-published-probe"',
            'version = "0.0.0"',
            'edition = "2021"',
            "",
            "[dependencies]",
            f'durable-workflow = "={version}"',
            'serde_json = "1"',
            'tokio = { version = "1", features = ["macros", "rt-multi-thread"] }',
            "",
        ]),
        encoding="utf-8",
    )

    generate = run_command(
        rust_probe_docker_command(project_dir, ["cargo", "generate-lockfile"]),
        log_file=log_file,
        timeout=600,
    )
    if generate.returncode != 0:
        raise RustCrateArtifactError(
            "rust_crate_resolution_failed",
            "could not resolve the artifact-tuple Rust SDK from crates.io",
            version=version,
            phase="cargo_generate_lockfile",
            command_result=generate,
        )

    cargo_lock_path = project_dir / "Cargo.lock"
    cargo_lock_bytes = cargo_lock_path.read_bytes()
    lock = __import__("tomllib").loads(cargo_lock_bytes.decode("utf-8"))
    packages = lock.get("package") if isinstance(lock, dict) else None
    if not isinstance(packages, list):
        raise RuntimeError("Cargo.lock did not contain resolved package provenance")

    registry_package_count = 0
    for package in packages:
        if not isinstance(package, dict):
            raise RustCrateArtifactError(
                "rust_dependency_registry_provenance_invalid",
                "Cargo.lock contained a dependency without structured registry provenance",
                version=version,
                phase="inspect_cargo_lock",
            )
        package_name = str(package.get("name") or "")
        source = str(package.get("source") or "")
        checksum = str(package.get("checksum") or "")
        if package_name == "signals-queries-published-probe" and source == "":
            continue
        if not official_crates_io_registry_source(source) or re.fullmatch(r"[0-9a-f]{64}", checksum) is None:
            raise RustCrateArtifactError(
                "rust_dependency_registry_provenance_invalid",
                "Cargo.lock resolved a dependency outside the official crates.io registry",
                version=version,
                phase="inspect_cargo_lock",
                package=package_name or "unknown",
                details={
                    "registry_source": source,
                    "checksum": checksum,
                },
            )
        registry_package_count += 1

    def package_provenance(name: str, expected_version: str | None = None) -> dict[str, Any]:
        for package in packages:
            if not isinstance(package, dict) or package.get("name") != name:
                continue
            resolved_version = str(package.get("version") or "")
            if expected_version is not None and resolved_version != expected_version:
                continue
            source = str(package.get("source") or "")
            checksum = str(package.get("checksum") or "")
            if not official_crates_io_registry_source(source) or not re.fullmatch(r"[0-9a-f]{64}", checksum):
                raise RustCrateArtifactError(
                    "rust_crate_registry_provenance_invalid"
                    if name == "durable-workflow"
                    else "apache_avro_registry_provenance_invalid",
                    f"{name} was not resolved with official crates.io registry checksum provenance",
                    version=version,
                    phase="inspect_cargo_lock",
                    package=name,
                    details={
                        "resolved_version": resolved_version,
                        "registry_source": source,
                        "checksum": checksum,
                    },
                )
            return {
                "package": name,
                "resolved_version": resolved_version,
                "source": source,
                "checksum": checksum,
            }
        raise RustCrateArtifactError(
            "rust_crate_not_resolved" if name == "durable-workflow" else "apache_avro_not_resolved",
            f"Cargo.lock did not resolve {name} {expected_version or ''}".strip(),
            version=version,
            phase="inspect_cargo_lock",
            package=name,
        )

    rust_provenance = package_provenance("durable-workflow", version)
    avro_provenance = package_provenance("apache-avro")
    dependency_manifest = (project_dir / "Cargo.toml").read_bytes()
    toolchain = rust_probe_toolchain_identity(log_file)
    cache_identity = rust_dependency_cache_identity(
        toolchain=toolchain,
        sdk_version=version,
        cargo_lock=cargo_lock_bytes,
        dependency_manifest=dependency_manifest,
    )
    cache_evidence: dict[str, Any]
    cache_operation = "open_cache_session"
    try:
        with rust_dependency_cache_session(run_root, cache_identity) as cache_session:
            cache_operation = "restore_dependency_artifacts"
            build_target, restore_timing = rust_dependency_build_target(run_root, cache_session)
            build_started = time.monotonic()
            try:
                cache_operation = "build_in_isolated_target"
                build = run_command(
                    rust_probe_docker_command(
                        project_dir,
                        ["cargo", "build", "--locked", "--release"],
                        cargo_home=cache_session["cargo_home"],
                        cargo_target=build_target,
                    ),
                    log_file=log_file,
                    timeout=1200,
                )
                elapsed_seconds = round(time.monotonic() - build_started, 3)
                compiled_packages = re.findall(
                    r"^\s*Compiling\s+([^\s]+)(?:\s+v[^\s]+)?",
                    build.stderr,
                    re.MULTILINE,
                )
                compiled_dependencies = [
                    package for package in compiled_packages if package != "signals-queries-published-probe"
                ]
                timing = {
                    "captured_at": now(),
                    "elapsed_seconds": elapsed_seconds,
                    "compiled_package_count": len(compiled_packages),
                    "compiled_dependency_package_count": len(compiled_dependencies),
                    "resolved_registry_package_count": registry_package_count,
                    **restore_timing,
                }
                if build.returncode != 0:
                    raise RustCrateArtifactError(
                        "rust_crate_build_failed",
                        "the artifact-tuple Rust SDK could not build in the published conformance probe",
                        version=version,
                        phase="cargo_build_locked_release",
                        command_result=build,
                        details={
                            "build_diagnostics": rust_build_failure_diagnostics(
                                build=build,
                                session=cache_session,
                                build_target=build_target,
                                run_root=run_root,
                                elapsed_seconds=elapsed_seconds,
                                compiled_packages=compiled_packages,
                            ),
                        },
                    )
                built_binary = build_target / "release" / "signals-queries-published-probe"
                if not built_binary.is_file():
                    raise RuntimeError("Cargo completed without producing the Rust conformance probe binary")
                record_unique_distribution_file(
                    "sdk-rust",
                    version,
                    cache_session["cargo_home"] / "registry" / "cache",
                    f"**/durable-workflow-{version}.crate",
                )
                isolated_binary = project_dir / "target" / "release" / "signals-queries-published-probe"
                isolated_binary.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(built_binary, isolated_binary)
                isolated_binary.chmod(0o700)
                purge_cached_rust_probe_outputs(build_target)
                cache_operation = "persist_dependency_artifacts"
                timing["persist_elapsed_seconds"] = persist_rust_dependency_build_target(
                    build_target,
                    cache_session,
                )
            finally:
                if build_target.exists() and build_target.is_dir() and not build_target.is_symlink():
                    shutil.rmtree(build_target)
                elif build_target.exists() or build_target.is_symlink():
                    build_target.unlink()
            cache_evidence = complete_rust_dependency_cache_session(cache_session, timing)
            cache_evidence["resolved_registry_package_count"] = registry_package_count
            log_line(
                log_file,
                "Rust dependency cache "
                f"state={cache_evidence['state_before_build']} "
                f"restore_seconds={timing['restore_elapsed_seconds']} "
                f"build_seconds={timing['elapsed_seconds']} "
                f"persist_seconds={timing['persist_elapsed_seconds']} "
                f"compiled_dependencies={timing['compiled_dependency_package_count']} "
                f"resolved_registry_packages={registry_package_count}",
            )
    except RustCrateArtifactError:
        raise
    except Exception as exc:
        raise RustCrateArtifactError(
            "rust_dependency_cache_failed",
            "the isolated Rust dependency cache could not prepare or retain build artifacts",
            version=version,
            phase="rust_dependency_cache",
            details={
                "cache_operation": cache_operation,
                "cache_error": {
                    "type": type(exc).__name__,
                    "message": str(exc),
                },
            },
        ) from exc

    install_entry = installed_public_artifact_entry(
        "sdk-rust",
        version,
        EXPECTED_ARTIFACT_SOURCES["sdk-rust"],
        "cargo_exact_registry_install",
    )
    install_entry.update({
        "cargo_requirement": f"={version}",
        "registry_source": rust_provenance["source"],
        "registry_checksum": rust_provenance["checksum"],
        "runtime_image": rust_probe_image(),
        "cargo_lock_sha256": cache_identity["identity"]["cargo_lock_sha256"],
        "dependency_cache": cache_evidence,
    })
    return project_dir, rust_provenance, {
        "package": avro_provenance,
        "install_entry": install_entry,
        "dependency_cache": cache_evidence,
    }


def rust_probe_env(base_url: str, token: str, namespace: str, task_queue: str = "") -> dict[str, str]:
    return {
        "DURABLE_WORKFLOW_SERVER_URL": docker_host_base_url(base_url),
        "DURABLE_WORKFLOW_TOKEN": token,
        "DURABLE_WORKFLOW_NAMESPACE": namespace,
        "TASK_QUEUE": task_queue,
    }


def rust_client_sample(
    project_dir: Path,
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
    operation: str,
    workflow_id: str,
    name: str,
    args: list[Any],
    log_file: Path,
) -> dict[str, Any]:
    command = rust_probe_docker_command(
        project_dir,
        [
            "/app/target/release/signals-queries-published-probe",
            "client",
            operation,
            workflow_id,
            name,
            json.dumps(args),
        ],
        env=rust_probe_env(base_url, token, namespace, task_queue),
    )
    completed = run_command(command, log_file=log_file, timeout=120)
    sample = json_sample_from_stdout(completed.stdout)
    sample.setdefault("client", "sdk-rust")
    sample.setdefault("operation", operation)
    sample.setdefault("operation_name", name)
    sample.setdefault("exit_code", completed.returncode)
    sample.setdefault("ok", completed.returncode == 0)
    if completed.returncode != 0 and completed.stderr.strip():
        sample.setdefault("stderr", completed.stderr.strip())
    return sample


def start_rust_probe_worker(
    project_dir: Path,
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
    worker_id: str,
    model: str,
    container_name: str,
    log_file: Path,
) -> dict[str, Any]:
    run_command(["docker", "rm", "-f", container_name], log_file=log_file, timeout=30)
    register_container(container_name, log_file)
    command = rust_probe_docker_command(
        project_dir,
        ["/app/target/release/signals-queries-published-probe", "worker", model],
        env={
            **rust_probe_env(base_url, token, namespace, task_queue),
            "WORKER_ID": worker_id,
        },
        name=container_name,
        detach=True,
    )
    started = run_command(command, log_file=log_file, timeout=60)
    if started.returncode != 0:
        raise RuntimeError(f"Rust {model} worker container failed to start")
    try:
        registration = wait_for_docker_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=worker_id,
            container_name=container_name,
            log_file=log_file,
            timeout_seconds=90,
        )
    except Exception:
        capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=30)
        cleanup_container(container_name, log_file)
        raise
    inspect = run_command(
        ["docker", "inspect", "-f", "{{.Id}}:{{.State.Pid}}", container_name],
        log_file=log_file,
        timeout=30,
    )
    return {
        "registration": registration,
        "process_id": inspect.stdout.strip(),
        "container_name": container_name,
    }


def stop_rust_probe_worker(container_name: str, log_file: Path) -> None:
    capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=30)
    cleanup_container(container_name, log_file)


def wait_for_history_signals(
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    run_id: str,
    expected: int,
    log_file: Path,
) -> dict[str, Any]:
    deadline = time.time() + 60
    last: dict[str, Any] = {}
    stable_observations = 0
    last_counts: tuple[Any, Any] | None = None
    while time.time() < deadline:
        last = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
        event_types = last.get("history_event_types")
        if isinstance(event_types, list) and event_types.count("SignalReceived") >= expected:
            counts = (last.get("history_event_count"), last.get("workflow_command_count"))
            stable_observations = stable_observations + 1 if counts == last_counts else 1
            last_counts = counts
            if stable_observations >= 3:
                return last
        time.sleep(0.5)
    log_line(log_file, f"Rust workflow history wait last snapshot: {last}")
    raise RuntimeError(f"{workflow_id} did not commit {expected} signals")


def wait_for_terminal_snapshot(
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    run_id: str,
) -> dict[str, Any]:
    deadline = time.time() + 60
    last: dict[str, Any] = {}
    while time.time() < deadline:
        last = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
        if str(last.get("status") or "").lower() in {
            "completed", "failed", "cancelled", "terminated", "timed_out"
        }:
            return last
        time.sleep(0.5)
    raise RuntimeError(f"{workflow_id} did not reach a terminal state: {last}")


def rust_query_sample_value(sample: dict[str, Any]) -> Any:
    return sample_result_value(sample)


def rust_provenance_outputs(
    rust_version: str,
    rust_provenance: dict[str, Any],
) -> dict[str, Any]:
    return {
        "rust_sdk_version": rust_version,
        "rust_crate_provenance": rust_provenance,
    }


def incompatible_query_protocol_sample(
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
) -> dict[str, Any]:
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Namespace": namespace,
        "X-Durable-Workflow-Protocol-Version": "2.0",
    }
    request = urllib.request.Request(
        url_join(base_url, "/api/worker/query-tasks/poll"),
        data=json.dumps({
            "worker_id": "rust-incompatible-query-protocol",
            "task_queue": task_queue,
            "timeout_seconds": 1,
            "poll_request_id": f"rust-incompatible-{time.time_ns()}",
        }).encode("utf-8"),
        headers=headers,
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            raw = response.read().decode("utf-8")
            body = json.loads(raw) if raw.strip() else {}
            return {"status_code": response.status, **(body if isinstance(body, dict) else {})}
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            body = json.loads(raw) if raw.strip() else {}
        except json.JSONDecodeError:
            body = {"raw": raw}
        return {"status_code": exc.code, **(body if isinstance(body, dict) else {})}


def counts_unchanged(before: dict[str, Any], after: dict[str, Any]) -> bool:
    return all(
        before.get(key) == after.get(key)
        and isinstance(before.get(key), int)
        for key in ("history_event_count", "workflow_command_count")
    )


def snapshot_count_unchanged(before: dict[str, Any], after: dict[str, Any], key: str) -> bool:
    return (
        isinstance(before.get(key), int)
        and not isinstance(before.get(key), bool)
        and not isinstance(after.get(key), bool)
        and before.get(key) == after.get(key)
    )


def query_all_published_clients(
    *,
    project_dir: Path,
    sdk_php_project: Path,
    python_bin: str,
    base_url: str,
    token: str,
    namespace: str,
    task_queue: str,
    workflow_type: str,
    workflow_id: str,
    expected: Any,
    log_file: Path,
    diagnostic_samples: dict[str, Any] | None = None,
) -> dict[str, Any]:
    rust = wait_for_query_result(
        label=f"Rust query for {workflow_id}",
        expected=expected,
        log_file=log_file,
        sample_factory=lambda: rust_client_sample(
            project_dir, base_url, token, namespace, task_queue,
            "query", workflow_id, "current", [], log_file,
        ),
        last_sample_holder=diagnostic_samples,
        last_sample_key="sdk_rust" if diagnostic_samples is not None else None,
    )
    php = wait_for_query_result(
        label=f"PHP query for Rust workflow {workflow_id}",
        expected=expected,
        log_file=log_file,
        sample_factory=lambda: php_workflow_client_sample(
            sdk_php_project, base_url, token, namespace,
            "query", workflow_type, workflow_id, task_queue, "current", log_file,
        ),
        last_sample_holder=diagnostic_samples,
        last_sample_key="sdk_php_sdk" if diagnostic_samples is not None else None,
    )
    python = wait_for_query_result(
        label=f"Python query for Rust workflow {workflow_id}",
        expected=expected,
        log_file=log_file,
        sample_factory=lambda: sdk_success_sample(
            python_bin, base_url, token, namespace, workflow_id,
            "query", "current", log_file,
        ),
        last_sample_holder=diagnostic_samples,
        last_sample_key="sdk_python" if diagnostic_samples is not None else None,
    )
    return {
        "sdk_rust": rust_query_sample_value(rust),
        "sdk_php_sdk": sample_result_value(php),
        "sdk_python": sample_result_value(python),
        "samples": {"sdk_rust": rust, "sdk_php_sdk": php, "sdk_python": python},
    }


RUST_MATRIX_FAILURE_ROUTES = {
    "rust_worker_rust_php_python_clients": {
        "type": "signal_query_rust_worker_client_matrix_failed",
        "owner": "sdk-rust, workflow, sdk-python, server",
        "title": "Rust worker and published client matrix failed",
    },
    "python_worker_rust_client": {
        "type": "signal_query_python_worker_rust_client_failed",
        "owner": "sdk-rust, sdk-python, server",
        "title": "Rust client against the Python worker failed",
    },
    "php_worker_rust_client": {
        "type": "signal_query_php_worker_rust_client_failed",
        "owner": "sdk-rust, workflow, server",
        "title": "Rust client against the PHP worker failed",
    },
    "rust_query_error_and_immutability": {
        "type": "signal_query_rust_error_immutability_failed",
        "owner": "sdk-rust, server",
        "title": "Rust query error and immutability checks failed",
    },
    "rust_replayed_instance_state_query_after_cold_restart": {
        "type": "signal_query_rust_replayed_state_cold_restart_failed",
        "owner": "sdk-rust, workflow, sdk-python, server",
        "title": "Rust replayed instance-state query after cold restart failed",
    },
}


def atomic_write_json(path: Path, value: Any) -> None:
    temporary = path.with_name(f".{path.name}.tmp")
    write_json(temporary, value)
    temporary.replace(path)


@contextmanager
def preserve_rust_matrix_cell(
    *,
    scenarios: dict[str, dict[str, Any]],
    descriptor: dict[str, Any],
    scenario_ids: tuple[str, ...],
    base_outputs: dict[str, Any],
    partial_outputs: dict[str, dict[str, Any]],
    failure_diagnostics: Any,
    checkpoint: Any,
    log_file: Path,
) -> Any:
    try:
        yield
    except Exception as exc:  # noqa: BLE001 - one product failure must not erase or stop sibling cells.
        error = probe_error_payload(exc)
        try:
            diagnostics = failure_diagnostics()
        except Exception as diagnostics_exc:  # noqa: BLE001 - retain the original product failure.
            diagnostics = {
                "capture_error": probe_error_payload(diagnostics_exc),
            }

        for scenario_id in scenario_ids:
            if scenario_id in scenarios:
                continue
            route = RUST_MATRIX_FAILURE_ROUTES[scenario_id]
            outputs = {
                **base_outputs,
                **partial_outputs.get(scenario_id, {}),
                "probe_error": error,
                "route_and_lease_diagnostics": diagnostics,
            }
            finding = {
                "id": route["type"],
                "type": route["type"],
                "scenario_id": scenario_id,
                "owner": route["owner"],
                "title": route["title"],
                "observed_behavior": "published artifacts produced a Rust signals/query cell failure",
                "current_evidence": outputs,
                "acceptance": [
                    "rerun this cell against the same published artifact tuple",
                    "complete the cell without a query, route, worker, or lease failure",
                    "retain the exact public response and worker/process diagnostics",
                ],
            }
            scenarios[scenario_id] = {
                "scenario_id": scenario_id,
                "status": "fail",
                "observed_outputs": outputs,
                "linked_findings": [finding],
            }
        log_line(
            log_file,
            "Rust matrix cell failed without stopping sibling cells: "
            f"{','.join(scenario_ids)}: {type(exc).__name__}: {exc}",
        )
    finally:
        for scenario_id in scenario_ids:
            result = scenarios.get(scenario_id)
            descriptor.setdefault("cell_verdicts", {})[scenario_id] = {
                "status": result.get("status") if isinstance(result, dict) else "not_covered",
                "checkpointed": True,
            }
        checkpoint()


def rust_matrix_route_and_lease_diagnostics(
    *,
    base_url: str,
    token: str,
    namespace: str,
    context: dict[str, Any],
    log_file: Path,
) -> dict[str, Any]:
    diagnostics: dict[str, Any] = {
        "probe_phase": context.get("probe_phase"),
        "task_queue": context.get("task_queue"),
        "workflow_id": context.get("workflow_id"),
        "run_id": context.get("run_id"),
        "worker_id": context.get("worker_id"),
        "worker_process_identities": context.get("worker_process_identities", []),
        "last_client_samples": context.get("last_client_samples", {}),
    }
    workflow_id = context.get("workflow_id")
    run_id = context.get("run_id")
    if isinstance(workflow_id, str) and workflow_id:
        try:
            diagnostics["workflow_route"] = workflow_public_snapshot(
                base_url, token, namespace, workflow_id,
                run_id if isinstance(run_id, str) and run_id else None,
            )
        except Exception as exc:  # noqa: BLE001 - diagnostics are best effort.
            diagnostics["workflow_route_error"] = probe_error_payload(exc)

    worker_id = context.get("worker_id")
    if isinstance(worker_id, str) and worker_id:
        try:
            diagnostics["worker_route"] = http_json(
                base_url,
                api_path("workers", worker_id),
                token=token,
                namespace=namespace,
                timeout=10,
            )
        except Exception as exc:  # noqa: BLE001 - diagnostics are best effort.
            diagnostics["worker_route_error"] = probe_error_payload(exc)

    containers = context.get("containers")
    if isinstance(containers, list):
        diagnostics["container_processes"] = {}
        for container in containers:
            if not isinstance(container, str) or not container:
                continue
            inspected = run_command(
                ["docker", "inspect", "-f", "{{json .State}}", container],
                log_file=log_file,
                timeout=30,
            )
            diagnostics["container_processes"][container] = command_summary(
                ["docker", "inspect", "-f", "{{json .State}}", container],
                inspected,
            )
    return diagnostics


def run_rust_matrix_probe(
    *,
    base_url: str,
    token: str,
    namespace: str,
    python_bin: str,
    sdk_php_project: Path,
    run_root: Path,
    log_file: Path,
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    project_dir, rust_provenance, rust_install = prepare_rust_probe(run_root, log_file)
    rust_version = artifact_version_value(artifact_versions, "sdk-rust")
    avro_provenance = rust_install["package"]
    rust_dependency_cache = rust_install.get("dependency_cache")
    suffix = hashlib.sha1(f"{time.time()}-rust-signals-queries".encode()).hexdigest()[:10]
    snapshot_queue = f"signals-queries-rust-snapshot-{suffix}"
    snapshot_worker_id = f"signals-queries-rust-snapshot-worker-{suffix}"
    snapshot_container = f"dw-sq-rust-snapshot-{suffix}"
    replay_queue = f"signals-queries-rust-replay-{suffix}"
    replay_container = f"dw-sq-rust-replay-{suffix}"
    replay_container_fresh = f"dw-sq-rust-replay-fresh-{suffix}"
    provenance = {
        **rust_provenance_outputs(rust_version, rust_provenance),
        "apache_avro_provenance": avro_provenance,
    }
    if isinstance(rust_dependency_cache, dict):
        provenance["rust_dependency_cache"] = rust_dependency_cache
    scenarios: dict[str, dict[str, Any]] = {}
    partial_outputs: dict[str, dict[str, Any]] = {
        scenario_id: dict(provenance)
        for scenario_id in (
            "rust_worker_rust_php_python_clients",
            "python_worker_rust_client",
            "php_worker_rust_client",
            "rust_query_error_and_immutability",
            "rust_replayed_instance_state_query_after_cold_restart",
        )
    }
    descriptor: dict[str, Any] = {
        "rust_project": project_dir.name,
        "cargo_requirement": f"={rust_version}",
        "rust_crate_provenance": rust_provenance,
        "apache_avro_provenance": avro_provenance,
        "generated_scenarios": [
            "rust_worker_rust_php_python_clients",
            "python_worker_rust_client",
            "php_worker_rust_client",
            "rust_query_error_and_immutability",
            "rust_replayed_instance_state_query_after_cold_restart",
        ],
    }
    if isinstance(rust_dependency_cache, dict):
        descriptor["rust_dependency_cache"] = rust_dependency_cache
    checkpoint_path = log_file.parent / "signals-queries-rust-cell-results.json"
    descriptor["cell_checkpoint_file"] = checkpoint_path.name

    def checkpoint() -> None:
        atomic_write_json(checkpoint_path, {
            "artifact_versions": probe_artifact_versions(),
            "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
            "rust_install": rust_install["install_entry"],
            "scenario_results": scenarios,
            "partial_observed_outputs": partial_outputs,
        })

    def record_phase(scenario_id: str, phase: str, **observations: Any) -> None:
        outputs = partial_outputs[scenario_id]
        outputs.update(observations)
        completed_phases = outputs.setdefault("completed_probe_phases", [])
        if phase not in completed_phases:
            completed_phases.append(phase)
        checkpoint()

    def failure_diagnostics(context: dict[str, Any]) -> dict[str, Any]:
        return rust_matrix_route_and_lease_diagnostics(
            base_url=base_url,
            token=token,
            namespace=namespace,
            context=context,
            log_file=log_file,
        )

    snapshot_context: dict[str, Any] = {
        "probe_phase": "rust_snapshot_worker_and_query_immutability",
        "task_queue": snapshot_queue,
        "worker_id": snapshot_worker_id,
        "containers": [snapshot_container],
        "worker_process_identities": [],
        "last_client_samples": {},
    }
    with preserve_rust_matrix_cell(
        scenarios=scenarios,
        descriptor=descriptor,
        scenario_ids=(
            "rust_worker_rust_php_python_clients",
            "rust_query_error_and_immutability",
        ),
        base_outputs=provenance,
        partial_outputs=partial_outputs,
        failure_diagnostics=lambda: failure_diagnostics(snapshot_context),
        checkpoint=checkpoint,
        log_file=log_file,
    ):
        snapshot_worker: dict[str, Any] | None = None
        try:
            snapshot_worker = start_rust_probe_worker(
                project_dir, base_url, token, namespace, snapshot_queue,
                snapshot_worker_id, "snapshot", snapshot_container, log_file,
            )
            snapshot_context["worker_process_identities"].append({
                "worker_id": snapshot_worker_id,
                "container_name": snapshot_container,
                "process_id": snapshot_worker["process_id"],
                "registration": snapshot_worker["registration"],
            })
            record_phase(
                "rust_worker_rust_php_python_clients",
                "worker_registered",
                worker_runtime="sdk-rust",
                rust_worker_registration=snapshot_worker["registration"],
                rust_worker_process_id=snapshot_worker["process_id"],
                task_queue=snapshot_queue,
                query_state_model="snapshot_derived_transport_state",
            )
            workflow_id = f"wf-sq-rust-snapshot-{suffix}"
            snapshot_context["workflow_id"] = workflow_id
            start = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "start", workflow_id, "conformance.counter.rust.snapshot", [], log_file,
            )
            run_id = workflow_start_run_id(start)
            if not public_sample_ok(start) or not run_id:
                raise RuntimeError(f"Rust snapshot workflow start failed: {start}")
            snapshot_context["run_id"] = run_id
            for scenario_id in (
                "rust_worker_rust_php_python_clients",
                "rust_query_error_and_immutability",
            ):
                record_phase(
                    scenario_id,
                    "workflow_started",
                    task_queue=snapshot_queue,
                    workflow_id=workflow_id,
                    run_id=run_id,
                    workflow_start_sample=start,
                    query_state_model="snapshot_derived_transport_state",
                )

            signal_samples: list[dict[str, Any]] = []
            applied_signal_values: list[int] = []
            for amount in (3, 5):
                signal = rust_client_sample(
                    project_dir, base_url, token, namespace, snapshot_queue,
                    "signal", workflow_id, "increment", [amount], log_file,
                )
                if not public_sample_ok(signal):
                    raise RuntimeError(f"Rust Avro increment signal failed: {signal}")
                signal_samples.append(signal)
                applied_signal_values.append(amount)
                record_phase(
                    "rust_worker_rust_php_python_clients",
                    f"ordered_signal_{len(applied_signal_values)}_applied",
                    ordered_signal_values=list(applied_signal_values),
                    ordered_signal_samples=list(signal_samples),
                )
            before_first_query = wait_for_history_signals(
                base_url, token, namespace, workflow_id, run_id, 2, log_file,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "history_before_first_query_observed",
                history_and_commands_before_first_successful_query=before_first_query,
            )
            running = query_all_published_clients(
                project_dir=project_dir,
                sdk_php_project=sdk_php_project,
                python_bin=python_bin,
                base_url=base_url,
                token=token,
                namespace=namespace,
                task_queue=snapshot_queue,
                workflow_type="conformance.counter.rust.snapshot",
                workflow_id=workflow_id,
                expected=8,
                log_file=log_file,
                diagnostic_samples=snapshot_context["last_client_samples"],
            )
            record_phase(
                "rust_worker_rust_php_python_clients",
                "running_queries_observed",
                rust_query_results={"running": running["sdk_rust"]},
                sdk_php_query_results={"running": running["sdk_php_sdk"]},
                sdk_python_query_results={"running": running["sdk_python"]},
                running_client_samples=running["samples"],
            )
            repeat = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "query", workflow_id, "current", [], log_file,
            )
            after_success = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            answer_before_failures = rust_query_sample_value(repeat)
            record_phase(
                "rust_worker_rust_php_python_clients",
                "repeat_query_observed",
                valid_avro_signal_and_query={
                    "default_codec": repeat.get("default_codec"),
                    "payload_codec": repeat.get("payload_codec"),
                    "observed_value": answer_before_failures,
                },
                repeat_query_consistency=answer_before_failures == running["sdk_rust"],
                repeat_query_sample=repeat,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "successful_query_immutability_observed",
                history_and_commands_after_successful_queries=after_success,
                answer_before_failures=answer_before_failures,
                successful_queries_appended_no_history=(
                    before_first_query.get("history_event_count") == after_success.get("history_event_count")
                ),
                successful_queries_emitted_no_workflow_commands=(
                    before_first_query.get("workflow_command_count") == after_success.get("workflow_command_count")
                ),
            )

            unknown = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "query", workflow_id, "does-not-exist", [], log_file,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "unknown_query_observed",
                unknown_query=unknown,
            )
            malformed_response = http_json(
                base_url,
                api_path("workflows", workflow_id, "query", "current"),
                method="POST",
                body={"input": {"codec": "avro", "blob": "not-valid-avro"}},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            malformed = response_sample(malformed_response)
            record_phase(
                "rust_query_error_and_immutability",
                "malformed_query_observed",
                malformed_query_payload=malformed,
            )
            missing = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "query", f"missing-{suffix}", "current", [], log_file,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "missing_workflow_observed",
                missing_workflow=missing,
            )
            unavailable_id = f"wf-sq-rust-unavailable-{suffix}"
            unavailable_start = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "start", unavailable_id, "conformance.counter.rust.unavailable", [], log_file,
            )
            if not public_sample_ok(unavailable_start):
                raise RuntimeError(f"Rust unavailable-handler workflow start failed: {unavailable_start}")
            unavailable = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "query", unavailable_id, "current", [], log_file,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "unavailable_handler_observed",
                unavailable_query_handler=unavailable,
            )
            incompatible = incompatible_query_protocol_sample(
                base_url, token, namespace, snapshot_queue,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "incompatible_protocol_observed",
                incompatible_query_protocol=incompatible,
            )
            answer_after_sample = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "query", workflow_id, "current", [], log_file,
            )
            answer_after_failures = rust_query_sample_value(answer_after_sample)
            after_failures = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            record_phase(
                "rust_query_error_and_immutability",
                "failed_query_immutability_observed",
                answer_after_failures=answer_after_failures,
                answer_after_failures_sample=answer_after_sample,
                history_and_commands_after_failure_queries=after_failures,
                failed_queries_appended_no_history=(
                    after_success.get("history_event_count") == after_failures.get("history_event_count")
                ),
                failed_queries_emitted_no_workflow_commands=(
                    after_success.get("workflow_command_count") == after_failures.get("workflow_command_count")
                ),
                failed_query_did_not_change_later_answer=answer_before_failures == answer_after_failures,
            )

            finish = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "signal", workflow_id, "finish", [], log_file,
            )
            if not public_sample_ok(finish):
                raise RuntimeError(f"Rust snapshot finish signal failed: {finish}")
            terminal_snapshot = wait_for_terminal_snapshot(
                base_url, token, namespace, workflow_id, run_id,
            )
            record_phase(
                "rust_worker_rust_php_python_clients",
                "workflow_completed",
                finish_signal_sample=finish,
                terminal_snapshot=terminal_snapshot,
            )
            completed = query_all_published_clients(
                project_dir=project_dir,
                sdk_php_project=sdk_php_project,
                python_bin=python_bin,
                base_url=base_url,
                token=token,
                namespace=namespace,
                task_queue=snapshot_queue,
                workflow_type="conformance.counter.rust.snapshot",
                workflow_id=workflow_id,
                expected=8,
                log_file=log_file,
                diagnostic_samples=snapshot_context["last_client_samples"],
            )
            record_phase(
                "rust_worker_rust_php_python_clients",
                "completed_queries_observed",
                rust_query_results={"running": running["sdk_rust"], "completed": completed["sdk_rust"]},
                sdk_php_query_results={
                    "running": running["sdk_php_sdk"],
                    "completed": completed["sdk_php_sdk"],
                },
                sdk_python_query_results={
                    "running": running["sdk_python"],
                    "completed": completed["sdk_python"],
                },
                completed_client_samples=completed["samples"],
            )

            registration = snapshot_worker["registration"]
            snapshot_outputs = {
                **partial_outputs["rust_worker_rust_php_python_clients"],
                "worker_runtime": "sdk-rust",
                "rust_worker_registration": registration,
                "rust_worker_process_id": snapshot_worker["process_id"],
                "task_queue": snapshot_queue,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "apache_avro_provenance": avro_provenance,
                "query_state_model": "snapshot_derived_transport_state",
                "ordered_signal_values": [3, 5],
                "rust_query_results": {"running": running["sdk_rust"], "completed": completed["sdk_rust"]},
                "sdk_php_query_results": {"running": running["sdk_php_sdk"], "completed": completed["sdk_php_sdk"]},
                "sdk_python_query_results": {"running": running["sdk_python"], "completed": completed["sdk_python"]},
                "valid_avro_signal_and_query": {
                    "default_codec": repeat.get("default_codec"),
                    "payload_codec": repeat.get("payload_codec"),
                    "observed_value": rust_query_sample_value(repeat),
                },
                "repeat_query_consistency": rust_query_sample_value(repeat) == running["sdk_rust"],
                "running_client_samples": running["samples"],
                "completed_client_samples": completed["samples"],
                "terminal_snapshot": terminal_snapshot,
            }
            scenarios["rust_worker_rust_php_python_clients"] = {
                "scenario_id": "rust_worker_rust_php_python_clients",
                "status": "pass" if has_required_evidence("rust_worker_rust_php_python_clients", snapshot_outputs) else "fail",
                "observed_outputs": snapshot_outputs,
            }
            checkpoint()

            snapshot_context["probe_phase"] = "rust_snapshot_terminal_signal"
            terminal_signal = rust_client_sample(
                project_dir, base_url, token, namespace, snapshot_queue,
                "signal", workflow_id, "increment", [1], log_file,
            )
            record_phase(
                "rust_query_error_and_immutability",
                "terminal_signal_observed",
                terminal_signal=terminal_signal,
            )

            error_outputs = {
                **partial_outputs["rust_query_error_and_immutability"],
                "query_state_model": "snapshot_derived_transport_state",
                "unknown_query": unknown,
                "malformed_query_payload": malformed,
                "unavailable_query_handler": unavailable,
                "incompatible_query_protocol": incompatible,
                "missing_workflow": missing,
                "terminal_signal": terminal_signal,
                "history_and_commands_before_first_successful_query": before_first_query,
                "history_and_commands_after_successful_queries": after_success,
                "history_and_commands_after_failure_queries": after_failures,
                "successful_queries_appended_no_history": before_first_query.get("history_event_count") == after_success.get("history_event_count"),
                "successful_queries_emitted_no_workflow_commands": before_first_query.get("workflow_command_count") == after_success.get("workflow_command_count"),
                "failed_queries_appended_no_history": after_success.get("history_event_count") == after_failures.get("history_event_count"),
                "failed_queries_emitted_no_workflow_commands": after_success.get("workflow_command_count") == after_failures.get("workflow_command_count"),
                "answer_before_failures": answer_before_failures,
                "answer_after_failures": answer_after_failures,
                "failed_query_did_not_change_later_answer": answer_before_failures == answer_after_failures,
            }
            scenarios["rust_query_error_and_immutability"] = {
                "scenario_id": "rust_query_error_and_immutability",
                "status": "pass" if has_required_evidence("rust_query_error_and_immutability", error_outputs) else "fail",
                "observed_outputs": error_outputs,
            }
        finally:
            if snapshot_worker is not None:
                stop_rust_probe_worker(snapshot_container, log_file)

    python_queue = f"signals-queries-python-rust-client-{suffix}"
    python_worker_id = f"signals-queries-python-rust-client-worker-{suffix}"
    python_context: dict[str, Any] = {
        "probe_phase": "python_worker_rust_client",
        "task_queue": python_queue,
        "worker_id": python_worker_id,
        "worker_process_identities": [],
        "last_client_samples": {},
    }
    with preserve_rust_matrix_cell(
        scenarios=scenarios,
        descriptor=descriptor,
        scenario_ids=("python_worker_rust_client",),
        base_outputs=provenance,
        partial_outputs=partial_outputs,
        failure_diagnostics=lambda: failure_diagnostics(python_context),
        checkpoint=checkpoint,
        log_file=log_file,
    ):
        python_process = start_python_sdk_counter_worker(
            python_bin=python_bin,
            base_url=base_url,
            token=token,
            namespace=namespace,
            task_queue=python_queue,
            worker_id=python_worker_id,
            query_route_evidence_path=run_root / f"{python_worker_id}.jsonl",
            run_root=run_root,
            log_file=log_file,
        )
        try:
            registration = wait_for_worker_registered(
                base_url=base_url, token=token, namespace=namespace,
                worker_id=python_worker_id, process=python_process, log_file=log_file,
            )
            python_context["worker_process_identities"].append({
                "worker_id": python_worker_id,
                "process_id": python_process.pid,
                "registration": registration,
            })
            record_phase(
                "python_worker_rust_client",
                "worker_registered",
                worker_runtime="sdk-python",
                worker_id=python_worker_id,
                worker_process_id=python_process.pid,
                worker_registration=registration,
                task_queue=python_queue,
            )
            workflow_id = f"wf-sq-python-rust-client-{suffix}"
            python_context["workflow_id"] = workflow_id
            start = rust_client_sample(
                project_dir, base_url, token, namespace, python_queue,
                "start", workflow_id, "conformance.counter", [], log_file,
            )
            if not public_sample_ok(start):
                raise RuntimeError(f"Rust client could not start Python workflow: {start}")
            run_id = workflow_start_run_id(start)
            python_context["run_id"] = run_id
            record_phase(
                "python_worker_rust_client",
                "workflow_started",
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_start_sample=start,
            )
            values: list[int] = []
            signal_samples: list[dict[str, Any]] = []
            for amount in (4, 6):
                signal = rust_client_sample(
                    project_dir, base_url, token, namespace, python_queue,
                    "signal", workflow_id, "increment", [amount], log_file,
                )
                if not public_sample_ok(signal):
                    raise RuntimeError(f"Rust client could not signal Python workflow: {signal}")
                values.append(amount)
                signal_samples.append(signal)
                record_phase(
                    "python_worker_rust_client",
                    f"ordered_signal_{len(values)}_applied",
                    ordered_signal_values=list(values),
                    ordered_signal_samples=list(signal_samples),
                )
            query = wait_for_query_result(
                label="Rust client query against Python worker", expected=sum(values), log_file=log_file,
                sample_factory=lambda: rust_client_sample(
                    project_dir, base_url, token, namespace, python_queue,
                    "query", workflow_id, "current", [], log_file,
                ),
                last_sample_holder=python_context["last_client_samples"],
                last_sample_key="sdk_rust",
            )
            record_phase(
                "python_worker_rust_client",
                "query_observed",
                default_codec=query.get("default_codec"),
                payload_codec=query.get("payload_codec"),
                rust_query_results=[sample_result_value(query)],
                rust_query_samples=[query],
            )
            repeat = rust_client_sample(
                project_dir, base_url, token, namespace, python_queue,
                "query", workflow_id, "current", [], log_file,
            )
            record_phase(
                "python_worker_rust_client",
                "repeat_query_observed",
                rust_query_results=[sample_result_value(query), sample_result_value(repeat)],
                rust_query_samples=[query, repeat],
                repeat_query_consistency=sample_result_value(query) == sample_result_value(repeat),
            )
            outputs = {
                **partial_outputs["python_worker_rust_client"],
                "worker_runtime": "sdk-python",
                "worker_id": python_worker_id,
                "worker_process_id": python_process.pid,
                "worker_registration": registration,
                "task_queue": python_queue,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "ordered_signal_values": values,
                "default_codec": query.get("default_codec"),
                "payload_codec": query.get("payload_codec"),
                "rust_query_results": [sample_result_value(query), sample_result_value(repeat)],
                "repeat_query_consistency": sample_result_value(query) == sample_result_value(repeat),
            }
            scenarios["python_worker_rust_client"] = {
                "scenario_id": "python_worker_rust_client",
                "status": "pass" if has_required_evidence("python_worker_rust_client", outputs) else "fail",
                "observed_outputs": outputs,
            }
        finally:
            stop_python_sdk_counter_worker(python_process, log_file)

    php_queue = f"signals-queries-php-rust-client-{suffix}"
    php_worker_id = f"signals-queries-php-rust-client-worker-{suffix}"
    php_container = f"dw-sq-php-rust-client-{suffix}"
    php_evidence = run_root / f"{php_worker_id}.jsonl"
    php_context: dict[str, Any] = {
        "probe_phase": "php_worker_rust_client",
        "task_queue": php_queue,
        "worker_id": php_worker_id,
        "containers": [php_container],
        "worker_process_identities": [],
        "last_client_samples": {},
    }
    with preserve_rust_matrix_cell(
        scenarios=scenarios,
        descriptor=descriptor,
        scenario_ids=("php_worker_rust_client",),
        base_outputs=provenance,
        partial_outputs=partial_outputs,
        failure_diagnostics=lambda: failure_diagnostics(php_context),
        checkpoint=checkpoint,
        log_file=log_file,
    ):
        register_container(php_container, log_file)
        php_start = run_command(
            php_docker_command(
                sdk_php_project,
                [
                    "php", "php-counter-worker.php", docker_host_base_url(base_url), token,
                    namespace, php_queue, php_worker_id, f"/app/{php_evidence.name}", "600",
                ],
                name=php_container,
                detach=True,
            ),
            log_file=log_file,
            timeout=60,
        )
        try:
            if php_start.returncode != 0:
                raise RuntimeError("PHP worker for Rust client matrix failed to start")
            registration = wait_for_docker_worker_registered(
                base_url=base_url, token=token, namespace=namespace,
                worker_id=php_worker_id, container_name=php_container, log_file=log_file,
            )
            php_inspect = run_command(
                ["docker", "inspect", "-f", "{{.Id}}:{{.State.Pid}}", php_container],
                log_file=log_file,
                timeout=30,
            )
            php_process_id = php_inspect.stdout.strip()
            php_context["worker_process_identities"].append({
                "worker_id": php_worker_id,
                "container_name": php_container,
                "process_id": php_process_id,
                "registration": registration,
            })
            record_phase(
                "php_worker_rust_client",
                "worker_registered",
                worker_runtime="sdk-php",
                worker_id=php_worker_id,
                worker_process_id=php_process_id,
                worker_registration=registration,
                task_queue=php_queue,
            )
            workflow_id = f"wf-sq-php-rust-client-{suffix}"
            php_context["workflow_id"] = workflow_id
            start = capture_published_client_invocation(
                partial_outputs["php_worker_rust_client"],
                "workflow_start",
                rust_client_sample(
                    project_dir, base_url, token, namespace, php_queue,
                    "start", workflow_id, "conformance.counter.php", [], log_file,
                ),
            )
            if not public_sample_ok(start):
                raise RuntimeError(f"Rust client could not start PHP workflow: {start}")
            run_id = workflow_start_run_id(start)
            php_context["run_id"] = run_id
            record_phase(
                "php_worker_rust_client",
                "workflow_started",
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_start_sample=start,
            )
            values: list[int] = []
            signal_samples: list[dict[str, Any]] = []
            for amount in (4, 6):
                signal = capture_published_client_invocation(
                    partial_outputs["php_worker_rust_client"],
                    f"ordered_signal_{len(values) + 1}",
                    rust_client_sample(
                        project_dir, base_url, token, namespace, php_queue,
                        "signal", workflow_id, "increment", [amount], log_file,
                    ),
                )
                if not public_sample_ok(signal):
                    raise RuntimeError(f"Rust client could not signal PHP workflow: {signal}")
                values.append(amount)
                signal_samples.append(signal)
                record_phase(
                    "php_worker_rust_client",
                    f"ordered_signal_{len(values)}_applied",
                    ordered_signal_values=list(values),
                    ordered_signal_samples=list(signal_samples),
                )
            query_samples: list[dict[str, Any]] = []
            query = wait_for_query_result(
                label="Rust client query against PHP worker", expected=sum(values), log_file=log_file,
                sample_factory=lambda: capture_published_client_invocation(
                    partial_outputs["php_worker_rust_client"],
                    "query",
                    rust_client_sample(
                        project_dir, base_url, token, namespace, php_queue,
                        "query", workflow_id, "current", [], log_file,
                    ),
                ),
                observed_samples=query_samples,
                last_sample_holder=php_context["last_client_samples"],
                last_sample_key="sdk_rust",
            )
            record_phase(
                "php_worker_rust_client",
                "query_observed",
                default_codec=query.get("default_codec"),
                payload_codec=query.get("payload_codec"),
                rust_query_results=[sample_result_value(query)],
                rust_query_observed_values=integer_query_observations(query_samples),
                rust_query_samples=list(query_samples),
            )
            repeat = capture_published_client_invocation(
                partial_outputs["php_worker_rust_client"],
                "repeat_query",
                rust_client_sample(
                    project_dir, base_url, token, namespace, php_queue,
                    "query", workflow_id, "current", [], log_file,
                ),
            )
            query_samples.append(repeat)
            observed_values = integer_query_observations(query_samples)
            record_phase(
                "php_worker_rust_client",
                "repeat_query_observed",
                rust_query_results=[sample_result_value(query), sample_result_value(repeat)],
                rust_query_observed_values=observed_values,
                rust_query_samples=list(query_samples),
                prefix_consistent_query_results=increment_query_observations_are_prefix_consistent(
                    observed_values,
                    values,
                ),
                query_result_rollback_free=increment_query_observations_are_rollback_free(observed_values),
                repeat_query_consistency=sample_result_value(query) == sample_result_value(repeat),
            )
            outputs = {
                **partial_outputs["php_worker_rust_client"],
                "worker_runtime": "sdk-php",
                "worker_id": php_worker_id,
                "worker_process_id": php_process_id,
                "worker_registration": registration,
                "task_queue": php_queue,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "ordered_signal_values": values,
                "default_codec": query.get("default_codec"),
                "payload_codec": query.get("payload_codec"),
                "rust_query_results": [sample_result_value(query), sample_result_value(repeat)],
                "rust_query_observed_values": observed_values,
                "prefix_consistent_query_results": increment_query_observations_are_prefix_consistent(
                    observed_values,
                    values,
                ),
                "query_result_rollback_free": increment_query_observations_are_rollback_free(observed_values),
                "repeat_query_consistency": sample_result_value(query) == sample_result_value(repeat),
            }
            scenarios["php_worker_rust_client"] = {
                "scenario_id": "php_worker_rust_client",
                "status": "pass" if has_required_evidence("php_worker_rust_client", outputs) else "fail",
                "observed_outputs": outputs,
            }
        finally:
            stop_rust_probe_worker(php_container, log_file)

    replay_worker_id = f"signals-queries-rust-replay-worker-{suffix}"
    replay_fresh_worker_id = f"signals-queries-rust-replay-fresh-worker-{suffix}"
    replay_context: dict[str, Any] = {
        "probe_phase": "rust_replayed_instance_state_running",
        "task_queue": replay_queue,
        "worker_id": replay_worker_id,
        "containers": [replay_container, replay_container_fresh],
        "worker_process_identities": [],
        "last_client_samples": {},
    }
    with preserve_rust_matrix_cell(
        scenarios=scenarios,
        descriptor=descriptor,
        scenario_ids=("rust_replayed_instance_state_query_after_cold_restart",),
        base_outputs=provenance,
        partial_outputs=partial_outputs,
        failure_diagnostics=lambda: failure_diagnostics(replay_context),
        checkpoint=checkpoint,
        log_file=log_file,
    ):
        replay_worker = start_rust_probe_worker(
            project_dir, base_url, token, namespace, replay_queue,
            replay_worker_id, "replay", replay_container, log_file,
        )
        replay_context["worker_process_identities"].append({
            "worker_id": replay_worker_id,
            "container_name": replay_container,
            "process_id": replay_worker["process_id"],
            "registration": replay_worker["registration"],
        })
        record_phase(
            "rust_replayed_instance_state_query_after_cold_restart",
            "initial_worker_registered",
            worker_runtime="sdk-rust",
            query_state_model="replayed_workflow_instance_state",
            task_queue=replay_queue,
            worker_process_identities=replay_context["worker_process_identities"],
            initial_worker_process_id=replay_worker["process_id"],
            last_client_samples=replay_context["last_client_samples"],
        )
        try:
            workflow_id = f"wf-sq-rust-replay-{suffix}"
            replay_context["workflow_id"] = workflow_id
            start = rust_client_sample(
                project_dir, base_url, token, namespace, replay_queue,
                "start", workflow_id, "conformance.counter.rust.replayed", [], log_file,
            )
            run_id = workflow_start_run_id(start)
            if not public_sample_ok(start) or not run_id:
                raise RuntimeError(f"Rust replay workflow start failed: {start}")
            replay_context["run_id"] = run_id
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "workflow_started",
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_start_sample=start,
            )
            applied_signal_values: list[int] = []
            signal_samples: list[dict[str, Any]] = []
            for amount in (2, 3):
                signal = rust_client_sample(
                    project_dir, base_url, token, namespace, replay_queue,
                    "signal", workflow_id, "increment", [amount], log_file,
                )
                if not public_sample_ok(signal):
                    raise RuntimeError(f"Rust replay increment failed: {signal}")
                applied_signal_values.append(amount)
                signal_samples.append(signal)
                record_phase(
                    "rust_replayed_instance_state_query_after_cold_restart",
                    f"ordered_signal_{len(applied_signal_values)}_applied",
                    ordered_signal_values=list(applied_signal_values),
                    ordered_signal_samples=list(signal_samples),
                )
            running_before = wait_for_history_signals(
                base_url, token, namespace, workflow_id, run_id, 2, log_file,
            )
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "running_history_before_queries_observed",
                immutability_checkpoints={
                    "running": {"before_first_successful_query": running_before},
                },
            )
            running = query_all_published_clients(
                project_dir=project_dir, sdk_php_project=sdk_php_project,
                python_bin=python_bin, base_url=base_url, token=token, namespace=namespace,
                task_queue=replay_queue, workflow_type="conformance.counter.rust.replayed",
                workflow_id=workflow_id, expected=5, log_file=log_file,
                diagnostic_samples=replay_context["last_client_samples"],
            )
            running_checkpoint = partial_outputs[
                "rust_replayed_instance_state_query_after_cold_restart"
            ]["immutability_checkpoints"]["running"]
            running_checkpoint["answer_before_failed_query"] = running["sdk_rust"]
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "running_queries_observed",
                running_query_results={
                    key: running[key]
                    for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")
                },
                running_client_samples=running["samples"],
            )
            running_failure = rust_client_sample(
                project_dir, base_url, token, namespace, replay_queue,
                "query", workflow_id, "unknown", [], log_file,
            )
            running_checkpoint["failed_query"] = running_failure
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "running_failed_query_observed",
                failure_samples={"running": running_failure},
            )
            running_after_failure = wait_for_query_result(
                label="Rust replay running query after failed query", expected=5, log_file=log_file,
                sample_factory=lambda: rust_client_sample(
                    project_dir, base_url, token, namespace, replay_queue,
                    "query", workflow_id, "current", [], log_file,
                ),
                last_sample_holder=replay_context["last_client_samples"],
                last_sample_key="sdk_rust_after_failed_query",
            )
            running_checkpoint["answer_after_failed_query"] = rust_query_sample_value(running_after_failure)
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "running_answer_after_failed_query_observed",
                running_answer_after_failed_query_sample=running_after_failure,
            )
            running_after = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            initial_process_id = replay_worker["process_id"]
            running_checkpoint["after_successful_and_failed_queries"] = running_after
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "running_query_immutability_observed",
                running_queries_preserved_history_and_commands=counts_unchanged(
                    running_before,
                    running_after,
                ),
            )
        finally:
            stop_rust_probe_worker(replay_container, log_file)

        replay_context["probe_phase"] = "rust_replayed_instance_state_cold_restart"
        replay_context["worker_id"] = replay_fresh_worker_id
        cold_before = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
        immutability_checkpoints = partial_outputs[
            "rust_replayed_instance_state_query_after_cold_restart"
        ]["immutability_checkpoints"]
        immutability_checkpoints["cold_restarted"] = {
            "before_first_successful_query": cold_before,
        }
        record_phase(
            "rust_replayed_instance_state_query_after_cold_restart",
            "cold_restart_history_before_queries_observed",
        )
        fresh_worker = start_rust_probe_worker(
            project_dir, base_url, token, namespace, replay_queue,
            replay_fresh_worker_id, "replay",
            replay_container_fresh, log_file,
        )
        replay_context["worker_process_identities"].append({
            "worker_id": replay_fresh_worker_id,
            "container_name": replay_container_fresh,
            "process_id": fresh_worker["process_id"],
            "registration": fresh_worker["registration"],
        })
        record_phase(
            "rust_replayed_instance_state_query_after_cold_restart",
            "fresh_worker_registered",
            worker_process_identities=replay_context["worker_process_identities"],
            cold_restart={"fresh_worker_process_id": fresh_worker["process_id"]},
        )
        try:
            restored = query_all_published_clients(
                project_dir=project_dir, sdk_php_project=sdk_php_project,
                python_bin=python_bin, base_url=base_url, token=token, namespace=namespace,
                task_queue=replay_queue, workflow_type="conformance.counter.rust.replayed",
                workflow_id=workflow_id, expected=5, log_file=log_file,
                diagnostic_samples=replay_context["last_client_samples"],
            )
            immutability_checkpoints["cold_restarted"]["answer_before_failed_query"] = restored["sdk_rust"]
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "cold_restart_queries_observed",
                restored_query_results={
                    key: restored[key]
                    for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")
                },
                restored_client_samples=restored["samples"],
                cold_restart={
                    "fresh_worker_process_id": fresh_worker["process_id"],
                    "durable_history_restored": restored["sdk_rust"] == running["sdk_rust"] == 5,
                },
            )
            restored_failure = rust_client_sample(
                project_dir, base_url, token, namespace, replay_queue,
                "query", workflow_id, "unknown", [], log_file,
            )
            failure_samples = partial_outputs[
                "rust_replayed_instance_state_query_after_cold_restart"
            ]["failure_samples"]
            failure_samples["cold_restarted"] = restored_failure
            immutability_checkpoints["cold_restarted"]["failed_query"] = restored_failure
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "cold_restart_failed_query_observed",
            )
            restored_after_failure = wait_for_query_result(
                label="Rust replay cold-restarted query after failed query", expected=5, log_file=log_file,
                sample_factory=lambda: rust_client_sample(
                    project_dir, base_url, token, namespace, replay_queue,
                    "query", workflow_id, "current", [], log_file,
                ),
                last_sample_holder=replay_context["last_client_samples"],
                last_sample_key="sdk_rust_cold_after_failed_query",
            )
            immutability_checkpoints["cold_restarted"]["answer_after_failed_query"] = (
                rust_query_sample_value(restored_after_failure)
            )
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "cold_restart_answer_after_failed_query_observed",
                restored_answer_after_failed_query_sample=restored_after_failure,
            )
            cold_after = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            immutability_checkpoints["cold_restarted"]["after_successful_and_failed_queries"] = cold_after
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "cold_restart_query_immutability_observed",
                cold_restart_queries_preserved_history_and_commands=counts_unchanged(
                    cold_before,
                    cold_after,
                ),
            )
            complete_signal = rust_client_sample(
                project_dir, base_url, token, namespace, replay_queue,
                "signal", workflow_id, "increment", [0], log_file,
            )
            if not public_sample_ok(complete_signal):
                raise RuntimeError(f"Rust replay completion signal failed: {complete_signal}")
            wait_for_terminal_snapshot(base_url, token, namespace, workflow_id, run_id)
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "workflow_completed",
                completion_signal_sample=complete_signal,
            )
            replay_context["probe_phase"] = "rust_replayed_instance_state_completed"
            completed_before = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            immutability_checkpoints["completed"] = {
                "before_first_successful_query": completed_before,
            }
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "completed_history_before_queries_observed",
            )
            completed = query_all_published_clients(
                project_dir=project_dir, sdk_php_project=sdk_php_project,
                python_bin=python_bin, base_url=base_url, token=token, namespace=namespace,
                task_queue=replay_queue, workflow_type="conformance.counter.rust.replayed",
                workflow_id=workflow_id, expected=5, log_file=log_file,
                diagnostic_samples=replay_context["last_client_samples"],
            )
            immutability_checkpoints["completed"]["answer_before_failed_query"] = completed["sdk_rust"]
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "completed_queries_observed",
                completed_query_results={
                    key: completed[key]
                    for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")
                },
                completed_client_samples=completed["samples"],
            )
            completed_failure = rust_client_sample(
                project_dir, base_url, token, namespace, replay_queue,
                "query", workflow_id, "unknown", [], log_file,
            )
            failure_samples["completed"] = completed_failure
            immutability_checkpoints["completed"]["failed_query"] = completed_failure
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "completed_failed_query_observed",
            )
            completed_after_failure = wait_for_query_result(
                label="Rust replay completed query after failed query", expected=5, log_file=log_file,
                sample_factory=lambda: rust_client_sample(
                    project_dir, base_url, token, namespace, replay_queue,
                    "query", workflow_id, "current", [], log_file,
                ),
                last_sample_holder=replay_context["last_client_samples"],
                last_sample_key="sdk_rust_completed_after_failed_query",
            )
            immutability_checkpoints["completed"]["answer_after_failed_query"] = (
                rust_query_sample_value(completed_after_failure)
            )
            record_phase(
                "rust_replayed_instance_state_query_after_cold_restart",
                "completed_answer_after_failed_query_observed",
                completed_answer_after_failed_query_sample=completed_after_failure,
            )
            completed_after = workflow_public_snapshot(base_url, token, namespace, workflow_id, run_id)
            immutability_checkpoints["completed"]["after_successful_and_failed_queries"] = completed_after
            immutable = all([
                counts_unchanged(running_before, running_after),
                counts_unchanged(cold_before, cold_after),
                counts_unchanged(completed_before, completed_after),
            ])
            replay_outputs = {
                **partial_outputs["rust_replayed_instance_state_query_after_cold_restart"],
                "worker_runtime": "sdk-rust",
                "query_state_model": "replayed_workflow_instance_state",
                "task_queue": replay_queue,
                "workflow_id": workflow_id,
                "run_id": run_id,
                "worker_process_identities": replay_context["worker_process_identities"],
                "initial_worker_process_id": initial_process_id,
                "cold_restart": {
                    "fresh_worker_process_id": fresh_worker["process_id"],
                    "durable_history_restored": restored["sdk_rust"] == running["sdk_rust"] == 5,
                },
                "running_query_results": {key: running[key] for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")},
                "restored_query_results": {key: restored[key] for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")},
                "completed_query_results": {key: completed[key] for key in ("sdk_rust", "sdk_php_sdk", "sdk_python")},
                "failure_samples": {
                    "running": running_failure,
                    "cold_restarted": restored_failure,
                    "completed": completed_failure,
                },
                "immutability_checkpoints": {
                    "running": {
                        "before_first_successful_query": running_before,
                        "answer_before_failed_query": running["sdk_rust"],
                        "failed_query": running_failure,
                        "answer_after_failed_query": rust_query_sample_value(running_after_failure),
                        "after_successful_and_failed_queries": running_after,
                    },
                    "cold_restarted": {
                        "before_first_successful_query": cold_before,
                        "answer_before_failed_query": restored["sdk_rust"],
                        "failed_query": restored_failure,
                        "answer_after_failed_query": rust_query_sample_value(restored_after_failure),
                        "after_successful_and_failed_queries": cold_after,
                    },
                    "completed": {
                        "before_first_successful_query": completed_before,
                        "answer_before_failed_query": completed["sdk_rust"],
                        "failed_query": completed_failure,
                        "answer_after_failed_query": rust_query_sample_value(completed_after_failure),
                        "after_successful_and_failed_queries": completed_after,
                    },
                },
                "successful_and_failed_queries_appended_no_history": immutable,
                "successful_and_failed_queries_emitted_no_workflow_commands": immutable,
                "failed_query_did_not_change_later_answer": all([
                    running["sdk_rust"] == rust_query_sample_value(running_after_failure) == 5,
                    restored["sdk_rust"] == rust_query_sample_value(restored_after_failure) == 5,
                    completed["sdk_rust"] == rust_query_sample_value(completed_after_failure) == 5,
                ]),
            }
            scenarios["rust_replayed_instance_state_query_after_cold_restart"] = {
                "scenario_id": "rust_replayed_instance_state_query_after_cold_restart",
                "status": "pass" if has_required_evidence(
                    "rust_replayed_instance_state_query_after_cold_restart", replay_outputs
                ) else "fail",
                "observed_outputs": replay_outputs,
            }
        finally:
            stop_rust_probe_worker(replay_container_fresh, log_file)

    return {
        "artifact_versions": probe_artifact_versions(),
        "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
        "scenario_results": scenarios,
    }, rust_install["install_entry"], descriptor


def wait_for_worker_registered(
    *,
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
    process: subprocess.Popen[str],
    log_file: Path,
    task_queue: str | None = None,
    expected_sdk_version: str | None = None,
    timeout_seconds: float = 45.0,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_response: dict[str, Any] | None = None
    while time.time() < deadline:
        return_code = process.poll()
        if return_code is not None:
            raise RuntimeError(
                f"Python SDK worker exited before registration with code {return_code}; "
                f"see {log_file.name}"
            )

        response = http_json(
            base_url,
            api_path("workers", worker_id),
            token=token,
            namespace=namespace,
            timeout=5,
        )
        last_response = response
        if int(response.get("status_code") or 0) == 200 and isinstance(response.get("body"), dict):
            registration = response["body"]
            if task_queue is not None and registration.get("task_queue") != task_queue:
                time.sleep(0.5)
                continue
            capabilities = registration.get("capabilities")
            if not isinstance(capabilities, list) or "query_tasks" not in capabilities:
                time.sleep(0.5)
                continue
            registered_sdk_version = python_registration_release_version(
                registration.get("sdk_version")
            )
            if (
                expected_sdk_version is not None
                and registered_sdk_version != ""
                and not same_python_release(expected_sdk_version, registered_sdk_version)
            ):
                time.sleep(0.5)
                continue
            return registration
        time.sleep(0.5)

    log_line(log_file, f"last worker registration probe response: {last_response}")
    raise RuntimeError(f"Python SDK worker {worker_id} did not register within {timeout_seconds}s")


def wait_for_query_result(
    *,
    sample_factory: Any,
    expected: Any,
    label: str,
    log_file: Path,
    timeout_seconds: float = 60.0,
    last_sample_holder: dict[str, Any] | None = None,
    last_sample_key: str | None = None,
    observed_samples: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    deadline = time.time() + timeout_seconds
    last_sample: dict[str, Any] | None = None
    while time.time() < deadline:
        sample = sample_factory()
        last_sample = sample
        if observed_samples is not None:
            observed_samples.append(sample)
        if last_sample_holder is not None and last_sample_key is not None:
            last_sample_holder[last_sample_key] = sample
        if public_sample_ok(sample) and sample_result_value(sample) == expected:
            return sample
        time.sleep(0.5)

    log_line(log_file, f"{label} last sample: {last_sample}")
    raise RuntimeError(f"{label} did not return {expected!r} within {timeout_seconds}s")


def capture_published_client_invocation(
    outputs: dict[str, Any],
    phase: str,
    sample: dict[str, Any],
) -> dict[str, Any]:
    invocations = outputs.setdefault("published_client_invocations", [])
    invocations.append({
        "sequence": len(invocations) + 1,
        "phase": phase,
        "workflow_id": sample.get("workflow_id") or outputs.get("workflow_id"),
        "run_id": sample.get("run_id") or outputs.get("run_id"),
        "worker_id": outputs.get("worker_id"),
        "task_queue": outputs.get("task_queue"),
        "sample": sample,
    })
    return sample


def integer_query_observations(samples: list[dict[str, Any]]) -> list[int]:
    values: list[int] = []
    for sample in samples:
        if not public_sample_ok(sample):
            continue
        value = sample_result_value(sample)
        if isinstance(value, int) and not isinstance(value, bool):
            values.append(value)
    return values


def increment_query_observations_are_prefix_consistent(
    observed_values: list[int],
    increments: list[int],
) -> bool:
    prefixes = [0]
    for amount in increments:
        prefixes.append(prefixes[-1] + amount)
    return bool(observed_values) and all(value in prefixes for value in observed_values)


def increment_query_observations_are_rollback_free(observed_values: list[int]) -> bool:
    return bool(observed_values) and all(
        current >= previous
        for previous, current in zip(observed_values, observed_values[1:])
    )


def install_evidence_for_artifacts(
    versions: dict[str, str],
    sources: dict[str, str],
    artifacts: tuple[str, ...],
    install_entries: dict[str, dict[str, Any]] | None = None,
) -> dict[str, Any]:
    entries = install_entries or {}
    return {
        "local_product_source_checkouts_used": False,
        "artifacts": [
            dict(entries[artifact])
            if artifact in entries
            else {
                "artifact": artifact,
                "status": "pass",
                "version": artifact_version_value(versions, artifact),
                "source": artifact_source_value(sources, artifact),
                "local_product_source_checkouts_used": False,
            }
            for artifact in artifacts
        ],
    }


def query_result_is(sample: dict[str, Any], expected: Any) -> bool:
    return public_sample_ok(sample) and sample_result_value(sample) == expected


def run_python_worker_php_facing_and_cli_clients(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    sdk_php_project: Path,
    task_queue: str,
    worker_id: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> dict[str, Any]:
    suffix = hashlib.sha1(f"{time.time()}-python-worker-php-clients".encode("utf-8")).hexdigest()[:10]
    workflow_id = f"wf-sq-python-php-clients-{suffix}"
    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-python",
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "workflow_type": workflow_type,
        "public_clients": ["sdk-php", "cli"],
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }

    try:
        start_sample = php_workflow_client_sample(
            sdk_php_project,
            base_url,
            token,
            namespace,
            "start",
            workflow_type,
            workflow_id,
            task_queue,
            "start",
            log_file,
        )
        outputs["sdk_php_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"PHP client start against Python worker failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"PHP client start against Python worker did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id

        initial_php_query = wait_for_query_result(
            label="Python worker PHP client initial query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                sdk_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["sdk_php_initial_query_sample"] = initial_php_query

        php_signal = php_workflow_client_sample(
            sdk_php_project,
            base_url,
            token,
            namespace,
            "signal",
            workflow_type,
            workflow_id,
            task_queue,
            "increment",
            log_file,
            args=[4],
        )
        outputs["sdk_php_signal_sample"] = php_signal
        if not public_sample_ok(php_signal):
            raise RuntimeError(f"PHP client signal against Python worker failed: {php_signal}")

        php_query = wait_for_query_result(
            label="Python worker PHP client query after PHP signal",
            expected=4,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                sdk_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["sdk_php_query_sample"] = php_query

        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[6]",
                "--output=json",
            ],
            log_file,
        )
        outputs["cli_signal_sample"] = cli_signal
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"CLI signal against Python worker failed: {cli_signal}")

        cli_query = wait_for_query_result(
            label="Python worker CLI query after PHP and CLI signals",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query

        repeat_php_query = wait_for_query_result(
            label="Python worker PHP client repeat query after CLI signal",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: php_workflow_client_sample(
                sdk_php_project,
                base_url,
                token,
                namespace,
                "query",
                workflow_type,
                workflow_id,
                task_queue,
                "current",
                log_file,
            ),
        )
        outputs["sdk_php_repeat_query_sample"] = repeat_php_query

        outputs["php_client_signal_and_query"] = (
            query_result_is(initial_php_query, 0)
            and public_sample_ok(php_signal)
            and query_result_is(php_query, 4)
        )
        outputs["cli_signal_and_query"] = public_sample_ok(cli_signal) and query_result_is(cli_query, 10)
        outputs["cross_language_query_consistency"] = (
            sample_result_value(cli_query) == sample_result_value(repeat_php_query) == 10
        )
        outputs["wire_envelope_compatibility"] = (
            public_sample_ok(start_sample)
            and public_sample_ok(php_signal)
            and public_sample_ok(php_query)
            and public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
        )
        outputs["observed_values"] = {
            "initial_php_query": sample_result_value(initial_php_query),
            "after_php_signal_php_query": sample_result_value(php_query),
            "after_cli_signal_cli_query": sample_result_value(cli_query),
            "after_cli_signal_php_repeat_query": sample_result_value(repeat_php_query),
        }
        outputs["commands_and_api_samples"] = {
            "sdk_php_start": start_sample.get("api_sample"),
            "sdk_php_signal": php_signal.get("api_sample"),
            "sdk_php_query": php_query.get("api_sample"),
            "cli_signal": cli_signal.get("command"),
            "cli_query": cli_query.get("command"),
        }
    except Exception as exc:  # noqa: BLE001 - partial public samples are the failure evidence.
        outputs["probe_error"] = probe_error_payload(exc)
        log_line(
            log_file,
            "Python worker PHP-facing/CLI client matrix probe failed: "
            f"{type(exc).__name__}: {exc}",
        )

    return outputs


def run_php_worker_python_and_cli_clients(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str,
    task_queue: str,
    worker_id: str,
    workflow_type: str,
    versions: dict[str, str],
    sources: dict[str, str],
    log_file: Path,
) -> dict[str, Any]:
    suffix = hashlib.sha1(f"{time.time()}-php-worker-python-clients".encode("utf-8")).hexdigest()[:10]
    workflow_id = f"wf-sq-php-python-clients-{suffix}"
    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-php",
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "workflow_type": workflow_type,
        "public_clients": ["sdk-python", "cli"],
        "published_artifact_versions": versions,
        "artifact_sources": sources,
    }
    phase = "python_sdk_workflow_start"
    outputs["probe_phase"] = phase

    try:
        start_sample = capture_published_client_invocation(
            outputs,
            phase,
            sdk_start_workflow_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                workflow_type,
                task_queue,
                log_file,
            ),
        )
        outputs["sdk_python_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"Python SDK start against PHP worker failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"Python SDK start against PHP worker did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id

        phase = "cli_initial_query"
        outputs["probe_phase"] = phase
        initial_cli_query = wait_for_query_result(
            label="PHP worker CLI initial query after Python SDK start",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "current",
                        "--output=json",
                    ],
                    log_file,
                ),
            ),
        )
        outputs["cli_initial_query_sample"] = initial_cli_query

        phase = "python_sdk_signal"
        outputs["probe_phase"] = phase
        sdk_signal = capture_published_client_invocation(
            outputs,
            phase,
            sdk_success_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                "signal",
                "increment",
                log_file,
                args=[4],
            ),
        )
        outputs["sdk_python_signal_sample"] = sdk_signal
        if not public_sample_ok(sdk_signal):
            raise RuntimeError(f"Python SDK signal against PHP worker failed: {sdk_signal}")

        phase = "python_sdk_query"
        outputs["probe_phase"] = phase
        sdk_query = wait_for_query_result(
            label="PHP worker Python SDK query after Python SDK signal",
            expected=4,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                sdk_success_sample(
                    python_bin,
                    base_url,
                    token,
                    namespace,
                    workflow_id,
                    "query",
                    "current",
                    log_file,
                ),
            ),
        )
        outputs["sdk_python_query_sample"] = sdk_query

        phase = "cli_signal"
        outputs["probe_phase"] = phase
        cli_signal = capture_published_client_invocation(
            outputs,
            phase,
            cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:signal",
                    workflow_id,
                    "increment",
                    "--input",
                    "[6]",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_signal_sample"] = cli_signal
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"CLI signal against PHP worker failed: {cli_signal}")

        phase = "cli_query"
        outputs["probe_phase"] = phase
        cli_query = wait_for_query_result(
            label="PHP worker CLI query after Python SDK and CLI signals",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "current",
                        "--output=json",
                    ],
                    log_file,
                ),
            ),
        )
        outputs["cli_query_sample"] = cli_query

        phase = "python_sdk_repeat_query"
        outputs["probe_phase"] = phase
        repeat_sdk_query = wait_for_query_result(
            label="PHP worker Python SDK repeat query after CLI signal",
            expected=10,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                sdk_success_sample(
                    python_bin,
                    base_url,
                    token,
                    namespace,
                    workflow_id,
                    "query",
                    "current",
                    log_file,
                ),
            ),
        )
        outputs["sdk_python_repeat_query_sample"] = repeat_sdk_query

        outputs["sdk_python_signal_and_query"] = (
            query_result_is(initial_cli_query, 0)
            and public_sample_ok(sdk_signal)
            and query_result_is(sdk_query, 4)
        )
        outputs["cli_signal_and_query"] = public_sample_ok(cli_signal) and query_result_is(cli_query, 10)
        outputs["cross_language_query_consistency"] = (
            sample_result_value(cli_query) == sample_result_value(repeat_sdk_query) == 10
        )
        outputs["wire_envelope_compatibility"] = (
            public_sample_ok(start_sample)
            and public_sample_ok(sdk_signal)
            and public_sample_ok(sdk_query)
            and public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
        )
        outputs["observed_values"] = {
            "initial_cli_query": sample_result_value(initial_cli_query),
            "after_python_signal_python_query": sample_result_value(sdk_query),
            "after_cli_signal_cli_query": sample_result_value(cli_query),
            "after_cli_signal_python_repeat_query": sample_result_value(repeat_sdk_query),
        }
        outputs["commands_and_api_samples"] = {
            "sdk_python_start": start_sample.get("api_sample"),
            "sdk_python_signal": {
                "client": "sdk-python",
                "operation": "signal",
                "workflow_id": workflow_id,
                "operation_name": "increment",
                "arguments": [4],
                "wire_input_shape": "durable-workflow.v2.payload-envelope",
            },
            "sdk_python_query": {
                "client": "sdk-python",
                "operation": "query",
                "workflow_id": workflow_id,
                "operation_name": "current",
                "wire_input_shape": "durable-workflow.v2.payload-envelope",
            },
            "cli_signal": cli_signal.get("command"),
            "cli_query": cli_query.get("command"),
        }
        outputs["probe_phase"] = "complete"
    except Exception as exc:  # noqa: BLE001 - partial public samples are the failure evidence.
        error = probe_error_payload(exc)
        error.setdefault("phase", phase)
        outputs["probe_error"] = error
        log_line(
            log_file,
            "PHP worker Python/CLI client matrix probe failed: "
            f"{type(exc).__name__}: {exc}",
        )

    return outputs


def python_baseline_failure_scope(phase: str) -> str:
    if phase in {
        "installed_package_version",
        "worker_start",
        "restart_worker_stop",
        "restart_worker_start",
    }:
        return "runner_setup"
    if phase in {
        "worker_registration",
        "routed_current_query",
        "restart_worker_registration",
        "restart_routed_current_query",
    }:
        return "server_routing"
    if phase in {"sdk_signal", "sdk_query"}:
        return "sdk_execution"
    return "workflow_state"


def run_python_sdk_baseline(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str,
    versions: dict[str, str],
    sources: dict[str, str],
    run_root: Path,
    log_file: Path,
    sdk_php_project: Path | None = None,
) -> tuple[dict[str, Any], dict[str, Any]]:
    suffix = hashlib.sha1(f"{time.time()}-python-sdk-baseline".encode("utf-8")).hexdigest()[:10]
    task_queue = f"signals-queries-python-sdk-{suffix}"
    worker_id = f"signals-queries-python-sdk-worker-{suffix}"
    workflow_type = "conformance.counter"
    workflow_id = f"wf-sq-python-sdk-{suffix}"
    query_route_evidence_path = run_root / f"{worker_id}-query-route-evidence.jsonl"
    expected_sdk_version = versions["sdk-python"]
    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-python",
        "python_worker_artifact_source": sources["sdk-python"],
        "python_worker_expected_sdk_version": expected_sdk_version,
        "workflow_id": workflow_id,
        "task_queue": task_queue,
        "worker_id": worker_id,
        "published_artifact_versions": versions,
        "artifact_sources": sources,
        "probe_phase": "installed_package_version",
    }
    descriptor: dict[str, Any] = {
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "worker_runtime": "sdk-python",
        "worker_source": sources["sdk-python"],
        "expected_worker_sdk_version": expected_sdk_version,
        "log_file": log_file.name,
    }
    phase = "installed_package_version"
    worker_process: subprocess.Popen[str] | None = None
    readiness_boundary: dict[str, Any] = {
        "status": "pending",
        "worker_id": worker_id,
        "task_queue": task_queue,
        "expected_sdk_version": expected_sdk_version,
    }
    outputs["readiness_boundary"] = readiness_boundary

    try:
        installed_sdk_version = python_sdk_distribution_version(python_bin, log_file)
        if installed_sdk_version == "":
            raise RuntimeError("Python SDK worker environment did not report an installed durable-workflow version")
        if not same_python_release(expected_sdk_version, installed_sdk_version):
            raise RuntimeError(
                "Python SDK worker installed package version "
                f"{installed_sdk_version!r} does not match exact candidate {expected_sdk_version!r}"
            )
        outputs["python_worker_sdk_version"] = installed_sdk_version
        outputs["python_worker_sdk_release_identity"] = python_release_identity(installed_sdk_version)
        descriptor["worker_sdk_version"] = installed_sdk_version
        readiness_boundary["installed_package_version"] = installed_sdk_version
        readiness_boundary["installed_package_version_verified_at"] = now()

        phase = "worker_start"
        outputs["probe_phase"] = phase
        worker_process = start_python_sdk_counter_worker(
            python_bin=python_bin,
            base_url=base_url,
            token=token,
            namespace=namespace,
            task_queue=task_queue,
            worker_id=worker_id,
            query_route_evidence_path=query_route_evidence_path,
            run_root=run_root,
            log_file=log_file,
        )
        readiness_boundary["worker_started_at"] = now()

        phase = "worker_registration"
        outputs["probe_phase"] = phase
        worker_registration = wait_for_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=worker_id,
            process=worker_process,
            log_file=log_file,
            task_queue=task_queue,
            expected_sdk_version=expected_sdk_version,
        )
        outputs["worker_registration"] = worker_registration
        capabilities = worker_registration.get("capabilities") if isinstance(worker_registration, dict) else None
        if isinstance(capabilities, list) and "query_tasks" in capabilities:
            outputs["python_worker_query_task_routing"] = True
            readiness_boundary["registered_query_task_capability"] = True
        readiness_boundary["worker_registered_at"] = now()

        phase = "workflow_start"
        outputs["probe_phase"] = phase
        start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(start["status_code"]) >= 400:
            raise RuntimeError(f"Python SDK baseline workflow start failed: {start}")

        run_id = str(start["body"].get("run_id", ""))
        outputs["run_id"] = run_id
        descriptor["run_id"] = run_id
        readiness_boundary["run_id"] = run_id

        phase = "initial_state_restoration"
        outputs["probe_phase"] = phase
        initial_query = wait_for_query_result(
            label="initial Python SDK worker CLI query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "state",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["initial_query_sample"] = initial_query
        readiness_boundary["initial_state_restored"] = sample_result_value(initial_query) == 0
        readiness_boundary["initial_state_restored_at"] = now()
        readiness_boundary["query_handler_ready"] = True
        readiness_boundary["query_handler_ready_at"] = readiness_boundary[
            "initial_state_restored_at"
        ]

        phase = "cli_signal"
        outputs["probe_phase"] = phase
        cli_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                "[3]",
                "--output=json",
            ],
            log_file,
        )
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"Python SDK baseline CLI signal failed: {cli_signal}")
        outputs["cli_signal_sample"] = cli_signal

        phase = "cli_current_query"
        outputs["probe_phase"] = phase
        cli_query = wait_for_query_result(
            label="Python SDK worker CLI query after CLI signal",
            expected=3,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_query_sample"] = cli_query
        outputs["cli_signal_and_query"] = (
            public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
            and sample_result_value(cli_query) == 3
        )
        routed_current_query_task: dict[str, Any] | None = None
        routed_current_query_task_error: dict[str, str] | None = None
        phase = "routed_current_query"
        outputs["probe_phase"] = phase
        try:
            routed_current_query_task = wait_for_routed_current_query_task(
                evidence_path=query_route_evidence_path,
                workflow_id=workflow_id,
                run_id=run_id,
                workflow_type=workflow_type,
                task_queue=task_queue,
                worker_id=worker_id,
                public_query_surface="cli",
                log_file=log_file,
                expected_sdk_version=expected_sdk_version,
            )
        except Exception as exc:  # noqa: BLE001 - retain public client proof for focused missing-route evidence.
            routed_current_query_task_error = probe_error_payload(exc)
            routed_current_query_task_error.setdefault("phase", phase)
            routed_current_query_task_error.setdefault(
                "failure_scope",
                python_baseline_failure_scope(phase),
            )
            outputs["routed_current_query_task_error"] = routed_current_query_task_error
            descriptor["routed_current_query_task_error"] = routed_current_query_task_error
            log_line(
                log_file,
                "Python SDK baseline routed current query task proof missing: "
                f"{type(exc).__name__}: {exc}",
            )
        if routed_current_query_task is not None:
            outputs["routed_current_query_task"] = routed_current_query_task

        phase = "sdk_signal"
        outputs["probe_phase"] = phase
        try:
            sdk_signal = sdk_success_sample(
                python_bin,
                base_url,
                token,
                namespace,
                workflow_id,
                "signal",
                "increment",
                log_file,
                args=[5],
            )
        except Exception as exc:  # noqa: BLE001 - preserve SDK failure as product behavior evidence.
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(exc)
            raise
        outputs["sdk_python_signal_sample"] = sdk_signal
        if not public_sample_ok(sdk_signal):
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(
                RuntimeError(f"Python SDK baseline SDK signal failed: {sdk_signal}")
            )
            raise RuntimeError(f"Python SDK baseline SDK signal failed: {sdk_signal}")

        phase = "sdk_query"
        outputs["probe_phase"] = phase
        try:
            sdk_query = wait_for_query_result(
                label="Python SDK worker SDK query after SDK signal",
                expected=8,
                log_file=log_file,
                sample_factory=lambda: sdk_success_sample(
                    python_bin,
                    base_url,
                    token,
                    namespace,
                    workflow_id,
                    "query",
                    "current",
                    log_file,
                ),
                last_sample_holder=outputs,
                last_sample_key="sdk_python_query_sample",
            )
        except Exception as exc:  # noqa: BLE001 - preserve public SDK evidence for product-failure routing.
            outputs["sdk_python_signal_and_query"] = False
            outputs["sdk_python_signal_and_query_error"] = probe_error_payload(exc)
            raise
        outputs["sdk_python_query_sample"] = sdk_query
        outputs["sdk_python_signal_and_query"] = (
            public_sample_ok(sdk_signal)
            and public_sample_ok(sdk_query)
            and sample_result_value(sdk_query) == 8
        )

        phase = "immediate_repeat_query"
        outputs["probe_phase"] = phase
        try:
            repeat_query = wait_for_query_result(
                label="Python SDK worker repeat CLI query",
                expected=8,
                log_file=log_file,
                sample_factory=lambda: cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "current",
                        "--output=json",
                    ],
                    log_file,
                ),
                last_sample_holder=outputs,
                last_sample_key="repeat_query_sample",
            )
        except Exception as exc:  # noqa: BLE001 - preserve repeat-query evidence for product-failure routing.
            outputs["immediate_repeat_query_consistency"] = False
            outputs["immediate_repeat_query_consistency_error"] = probe_error_payload(exc)
            raise
        outputs["repeat_query_sample"] = repeat_query
        outputs["immediate_repeat_query_consistency"] = (
            sample_result_value(repeat_query) == sample_result_value(sdk_query)
        )

        if sdk_php_project is not None:
            phase = "cross_language_clients"
            outputs["probe_phase"] = phase
            cross_language_outputs = run_python_worker_php_facing_and_cli_clients(
                base_url=base_url,
                token=token,
                namespace=namespace,
                cli_bin=cli_bin,
                sdk_php_project=sdk_php_project,
                task_queue=task_queue,
                worker_id=worker_id,
                workflow_type=workflow_type,
                versions=versions,
                sources=sources,
                log_file=log_file,
            )
            descriptor["python_worker_php_facing_and_cli_clients"] = {
                "workflow_id": cross_language_outputs.get("workflow_id"),
                "run_id": cross_language_outputs.get("run_id"),
                "worker_id": worker_id,
                "task_queue": task_queue,
                "status": baseline_scenario_result(
                    "python_worker_php_facing_and_cli_clients",
                    cross_language_outputs,
                )["status"],
            }
            descriptor.setdefault("cross_language_scenario_results", {})[
                "python_worker_php_facing_and_cli_clients"
            ] = baseline_scenario_result(
                "python_worker_php_facing_and_cli_clients",
                cross_language_outputs,
            )

        phase = "restart_worker_stop"
        outputs["probe_phase"] = phase
        original_worker_stopped_at = now()
        stop_python_sdk_counter_worker(worker_process, log_file)
        worker_process = None

        restart_worker_id = f"{worker_id}-restart"
        restart_query_route_evidence_path = (
            run_root / f"{restart_worker_id}-query-route-evidence.jsonl"
        )
        restart_started_at = now()
        phase = "restart_worker_start"
        outputs["probe_phase"] = phase
        worker_process = start_python_sdk_counter_worker(
            python_bin=python_bin,
            base_url=base_url,
            token=token,
            namespace=namespace,
            task_queue=task_queue,
            worker_id=restart_worker_id,
            query_route_evidence_path=restart_query_route_evidence_path,
            run_root=run_root,
            log_file=log_file,
        )

        phase = "restart_worker_registration"
        outputs["probe_phase"] = phase
        restart_registration = wait_for_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=restart_worker_id,
            process=worker_process,
            log_file=log_file,
            task_queue=task_queue,
            expected_sdk_version=expected_sdk_version,
        )
        restart_registered_at = now()

        phase = "restart_state_restoration"
        outputs["probe_phase"] = phase
        restart_query_sent_at = now()
        restart_query = wait_for_query_result(
            label="Python SDK worker CLI query after controlled restart",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )
        restart_query_completed_at = now()
        restart_repeat_query = wait_for_query_result(
            label="Python SDK worker immediate repeat CLI query after controlled restart",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:query",
                    workflow_id,
                    "current",
                    "--output=json",
                ],
                log_file,
            ),
        )

        phase = "restart_routed_current_query"
        outputs["probe_phase"] = phase
        restart_routed_query = wait_for_routed_current_query_task(
            evidence_path=restart_query_route_evidence_path,
            workflow_id=workflow_id,
            run_id=run_id,
            workflow_type=workflow_type,
            task_queue=task_queue,
            worker_id=restart_worker_id,
            public_query_surface="cli",
            log_file=log_file,
            expected_sdk_version=expected_sdk_version,
        )
        restart_repeat_query_completed_at = now()
        controlled_restart = {
            "status": "pass",
            "previous_worker_id": worker_id,
            "worker_id": restart_worker_id,
            "task_queue": task_queue,
            "run_id": run_id,
            "worker_stopped_at": original_worker_stopped_at,
            "worker_restart_at": restart_started_at,
            "worker_registered_at": restart_registered_at,
            "query_sent_at": restart_query_sent_at,
            "query_completed_at": restart_query_completed_at,
            "repeat_query_completed_at": restart_repeat_query_completed_at,
            "expected_replayed_state": 8,
            "query_result": sample_result_value(restart_query),
            "repeat_query_result": sample_result_value(restart_repeat_query),
            "repeat_query_consistency": (
                sample_result_value(restart_query)
                == sample_result_value(restart_repeat_query)
                == 8
            ),
            "worker_registration": restart_registration,
            "routed_current_query_task": restart_routed_query,
        }
        outputs["controlled_restart"] = controlled_restart
        readiness_boundary.setdefault("query_handler_ready", True)
        readiness_boundary.setdefault(
            "query_handler_ready_at",
            restart_routed_query.get("observed_at") or now(),
        )
        readiness_boundary["restart_worker_id"] = restart_worker_id
        readiness_boundary["restart_worker_registered_at"] = restart_registered_at
        readiness_boundary["restart_state_restored"] = controlled_restart[
            "repeat_query_consistency"
        ]
        readiness_boundary["evidence_captured_at"] = now()
        readiness_boundary["status"] = "pass"
        descriptor["controlled_restart"] = {
            "worker_id": restart_worker_id,
            "task_queue": task_queue,
            "run_id": run_id,
            "status": "pass",
        }
        outputs["probe_phase"] = "complete"
        return outputs, descriptor
    except Exception as exc:  # noqa: BLE001 - retain any public evidence collected before the probe stopped.
        error = probe_error_payload(exc)
        error.setdefault("phase", phase)
        error.setdefault("failure_scope", python_baseline_failure_scope(phase))
        outputs["probe_error"] = error
        outputs["probe_phase"] = phase
        descriptor["error"] = f"{type(exc).__name__}: {exc}"
        descriptor["failure_phase"] = phase
        descriptor["failure_scope"] = error["failure_scope"]
        log_line(log_file, f"Python SDK baseline probe stopped after partial evidence: {type(exc).__name__}: {exc}")
        return outputs, descriptor
    finally:
        if worker_process is not None:
            stop_python_sdk_counter_worker(worker_process, log_file)


def baseline_scenario_result(scenario: str, observed: dict[str, Any]) -> dict[str, Any]:
    if current_behavior_failures_for(scenario, observed):
        status = "fail"
    elif has_required_evidence(scenario, observed):
        status = "pass"
    else:
        status = "not_covered"

    return {
        "status": status,
        "observed_outputs": observed,
    }


def worker_public_snapshot(
    base_url: str,
    token: str,
    namespace: str,
    worker_id: str,
) -> dict[str, Any]:
    response = http_json(
        base_url,
        api_path("workers", worker_id),
        method="GET",
        token=token,
        namespace=namespace,
        timeout=10,
    )
    body = response.get("body")
    if not isinstance(body, dict):
        body = {}

    return {
        "status_code": response.get("status_code"),
        **{
            key: body[key]
            for key in (
                "worker_id",
                "task_queue",
                "runtime",
                "sdk_version",
                "status",
                "capabilities",
                "supported_workflow_types",
                "last_heartbeat_at",
                "registered_at",
                "process_metrics",
            )
            if key in body
        },
    }


def php_worker_process_snapshot(container_name: str, log_file: Path) -> dict[str, Any]:
    inspect = capture_command_summary(
        [
            "docker",
            "inspect",
            "--format",
            "{{json .State}}",
            container_name,
        ],
        log_file=log_file,
        timeout=20,
    )
    logs = capture_command_summary(
        ["docker", "logs", "--tail", "40", container_name],
        log_file=log_file,
        timeout=20,
    )

    return {
        "container_name": container_name,
        "inspect": {
            key: inspect[key]
            for key in ("command", "exit_code", "error")
            if key in inspect
        }
        | {
            "stdout_tail": diagnostic_output_tail(str(inspect.get("stdout") or "")),
            "stderr_tail": diagnostic_output_tail(str(inspect.get("stderr") or "")),
        },
        "logs": {
            key: logs[key]
            for key in ("command", "exit_code", "error")
            if key in logs
        }
        | {
            "stdout_tail": diagnostic_output_tail(str(logs.get("stdout") or "")),
            "stderr_tail": diagnostic_output_tail(str(logs.get("stderr") or "")),
        },
    }


def capture_php_cli_signal_post_attempt_state(
    *,
    base_url: str,
    token: str,
    namespace: str,
    workflow_id: str,
    run_id: str,
    worker_id: str,
    container_name: str,
    log_file: Path,
) -> dict[str, Any]:
    state: dict[str, Any] = {
        "captured_at": now(),
        "workflow_id": workflow_id,
        "run_id": run_id,
        "worker_id": worker_id,
    }
    for field, capture in (
        (
            "workflow",
            lambda: workflow_public_snapshot(
                base_url,
                token,
                namespace,
                workflow_id,
                run_id,
            ),
        ),
        (
            "worker",
            lambda: worker_public_snapshot(
                base_url,
                token,
                namespace,
                worker_id,
            ),
        ),
        (
            "worker_process",
            lambda: php_worker_process_snapshot(container_name, log_file),
        ),
    ):
        try:
            state[field] = capture()
        except Exception as exc:  # noqa: BLE001 - every diagnostic surface is independent.
            state[f"{field}_capture_error"] = {
                "type": type(exc).__name__,
                "message": str(exc),
            }
    return state


def php_cli_signal_attempt_classification(
    sample: dict[str, Any],
    post_attempt_state: dict[str, Any],
) -> dict[str, Any]:
    status_code = client_sample_field(
        sample,
        "status_code",
        "statusCode",
        "http_status",
        "httpStatus",
    )
    reason = client_sample_field(
        sample,
        "reason",
        "public_reason",
        "publicReason",
        "rejection_reason",
        "rejectionReason",
    )
    exit_code = sample.get("exit_code")
    workflow = post_attempt_state.get("workflow")
    worker = post_attempt_state.get("worker")
    workflow_status = workflow.get("status") if isinstance(workflow, dict) else None
    worker_status = worker.get("status") if isinstance(worker, dict) else None
    command_states = workflow.get("workflow_commands") if isinstance(workflow, dict) else None
    signal_commands = [
        command
        for command in (command_states if isinstance(command_states, list) else [])
        if isinstance(command, dict)
        and command.get("type") == "signal"
        and command.get("target_name") == "increment"
    ]

    if sample.get("ok") is True and exit_code == 0:
        return {
            "category": "php_cli_signal_path_passed",
            "owner": None,
            "product_reached": True,
            "summary": "The CLI signal command exited successfully against the running PHP workflow.",
        }
    if any(command.get("status") in {"accepted", "applied"} for command in signal_commands):
        return {
            "category": "cli_failed_after_signal_admission",
            "owner": "cli",
            "product_reached": True,
            "summary": "The server retained an accepted signal command although the CLI process reported failure.",
        }
    if workflow_status in {"completed", "failed", "cancelled", "terminated", "timed_out"}:
        return {
            "category": "fixture_workflow_not_running",
            "owner": "conformance_harness",
            "product_reached": True,
            "workflow_status": workflow_status,
            "summary": "The fixture workflow was terminal immediately after the failed CLI signal attempt.",
        }
    if worker_status in {"stale", "offline", "failed", "stopped"}:
        return {
            "category": "fixture_worker_unavailable",
            "owner": "conformance_harness",
            "product_reached": True,
            "worker_status": worker_status,
            "summary": "The published PHP worker was unavailable immediately after the CLI signal attempt.",
        }
    if isinstance(status_code, int) and status_code >= 400:
        return {
            "category": "signal_admission_rejected",
            "owner": "server_or_fixture_contract",
            "product_reached": True,
            "http_status": status_code,
            "public_reason": reason,
            "summary": "The CLI reached the server and the signal admission path returned a public error.",
        }
    if isinstance(exit_code, int) and exit_code != 0 and status_code is None:
        return {
            "category": "cli_transport_or_output_failure",
            "owner": "cli_or_conformance_harness",
            "product_reached": False,
            "summary": "The CLI exited unsuccessfully without retaining an HTTP response classification.",
        }
    return {
        "category": "unclassified_cli_signal_failure",
        "owner": "conformance_harness",
        "product_reached": None,
        "summary": "The retained fields did not establish where the CLI signal attempt failed.",
    }


def run_sdk_php_baseline(
    *,
    base_url: str,
    token: str,
    namespace: str,
    cli_bin: str,
    python_bin: str | None = None,
    sdk_php_project: Path,
    versions: dict[str, str],
    sources: dict[str, str],
    install_entry: dict[str, Any],
    run_root: Path,
    log_file: Path,
) -> tuple[dict[str, Any], dict[str, Any]]:
    suffix = hashlib.sha1(f"{time.time()}-sdk-php-baseline".encode("utf-8")).hexdigest()[:10]
    task_queue = f"signals-queries-sdk-php-{suffix}"
    worker_id = f"signals-queries-sdk-php-worker-{suffix}"
    workflow_type = "conformance.counter.php"
    workflow_id = f"wf-sq-sdk-php-{suffix}"
    container_name = f"dw-sq-php-{suffix}"
    query_route_evidence_path = sdk_php_project / f"{worker_id}-query-route-evidence.jsonl"
    container_evidence_path = f"/app/{query_route_evidence_path.name}"

    outputs: dict[str, Any] = {
        "worker_runtime": "sdk-php",
        "sdk_php_artifact_source": sources["sdk-php"],
        "sdk_php_sdk_version": versions["sdk-php"],
        "workflow_id": workflow_id,
        "workflow_type": workflow_type,
        "task_queue": task_queue,
        "worker_id": worker_id,
        "published_artifact_versions": versions,
        "artifact_sources": sources,
        "sdk_php_install_evidence": install_entry,
    }
    descriptor: dict[str, Any] = {
        "worker_id": worker_id,
        "task_queue": task_queue,
        "workflow_id": workflow_id,
        "worker_runtime": "sdk-php",
        "worker_source": sources["sdk-php"],
        "worker_sdk_version": outputs["sdk_php_sdk_version"],
        "runtime_image": sdk_php_docker_image(),
        "log_file": log_file.name,
    }
    phase = "worker_start"
    outputs["probe_phase"] = phase

    register_container(container_name, log_file)
    try:
        worker_command = php_docker_command(
            sdk_php_project,
            [
                "php",
                "php-counter-worker.php",
                docker_host_base_url(base_url),
                token,
                namespace,
                task_queue,
                worker_id,
                container_evidence_path,
                "600",
            ],
            name=container_name,
            detach=True,
        )
        start = run_command(
            worker_command,
            log_file=log_file,
            timeout=90,
        )
        if start.returncode != 0:
            raise RuntimeError("PHP worker container failed to start")
        descriptor["container_name"] = container_name
        descriptor["container_id"] = start.stdout.strip()

        phase = "worker_registration"
        outputs["probe_phase"] = phase
        worker_registration = wait_for_docker_worker_registered(
            base_url=base_url,
            token=token,
            namespace=namespace,
            worker_id=worker_id,
            container_name=container_name,
            log_file=log_file,
        )
        outputs["worker_registration"] = worker_registration
        capabilities = worker_registration.get("capabilities") if isinstance(worker_registration, dict) else None
        if isinstance(capabilities, list) and "query_tasks" in capabilities:
            outputs["registered_query_task_capability"] = True

        phase = "workflow_start"
        outputs["probe_phase"] = phase
        start_sample = capture_published_client_invocation(
            outputs,
            phase,
            php_workflow_client_sample(
                sdk_php_project,
                base_url,
                token,
                namespace,
                "start",
                workflow_type,
                workflow_id,
                task_queue,
                "start",
                log_file,
            ),
        )
        outputs["sdk_php_start_sample"] = start_sample
        if not public_sample_ok(start_sample):
            raise RuntimeError(f"PHP baseline workflow start failed: {start_sample}")

        run_id = workflow_start_run_id(start_sample)
        if not run_id:
            raise RuntimeError(f"PHP baseline workflow start did not return a run_id: {start_sample}")
        outputs["run_id"] = run_id
        descriptor["run_id"] = run_id

        phase = "initial_query"
        outputs["probe_phase"] = phase
        initial_query = wait_for_query_result(
            label="PHP worker initial CLI query",
            expected=0,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "state",
                        "--output=json",
                    ],
                    log_file,
                ),
            ),
        )
        outputs["initial_query_sample"] = initial_query

        phase = "cli_signal"
        outputs["probe_phase"] = phase
        cli_signal = capture_published_client_invocation(
            outputs,
            phase,
            cli_json_sample(
                cli_bin,
                base_url,
                token,
                namespace,
                [
                    "workflow:signal",
                    workflow_id,
                    "increment",
                    "--input",
                    "[3]",
                    "--output=json",
                ],
                log_file,
            ),
        )
        outputs["cli_signal_sample"] = cli_signal
        post_cli_signal_state = capture_php_cli_signal_post_attempt_state(
            base_url=base_url,
            token=token,
            namespace=namespace,
            workflow_id=workflow_id,
            run_id=run_id,
            worker_id=worker_id,
            container_name=container_name,
            log_file=log_file,
        )
        outputs["post_cli_signal_state"] = post_cli_signal_state
        outputs["cli_signal_attempt_classification"] = php_cli_signal_attempt_classification(
            cli_signal,
            post_cli_signal_state,
        )
        if not public_sample_ok(cli_signal):
            raise RuntimeError(f"PHP worker CLI signal failed: {cli_signal}")

        phase = "cli_query"
        outputs["probe_phase"] = phase
        cli_query = wait_for_query_result(
            label="PHP worker CLI query after CLI signal",
            expected=3,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                cli_json_sample(
                    cli_bin,
                    base_url,
                    token,
                    namespace,
                    [
                        "workflow:query",
                        workflow_id,
                        "current",
                        "--output=json",
                    ],
                    log_file,
                ),
            ),
        )
        outputs["cli_query_sample"] = cli_query
        outputs["cli_signal_and_query"] = (
            public_sample_ok(cli_signal)
            and public_sample_ok(cli_query)
            and sample_result_value(cli_query) == 3
        )

        phase = "routed_current_query"
        outputs["probe_phase"] = phase
        routed_query = wait_for_php_query_route_evidence(
            query_route_evidence_path,
            workflow_id=workflow_id,
            worker_id=worker_id,
        )
        routed_query = {
            **routed_query,
            "public_query_surface": "cli",
        }
        outputs["routed_current_query_task"] = routed_query
        outputs["php_routed_current_query_task"] = routed_query
        outputs["php_worker_query_task_routing"] = True

        phase = "sdk_php_signal"
        outputs["probe_phase"] = phase
        php_signal = capture_published_client_invocation(
            outputs,
            phase,
            php_workflow_client_sample(
                sdk_php_project,
                base_url,
                token,
                namespace,
                "signal",
                workflow_type,
                workflow_id,
                task_queue,
                "increment",
                log_file,
                args=[5],
            ),
        )
        outputs["sdk_php_signal_sample"] = php_signal
        if not public_sample_ok(php_signal):
            raise RuntimeError(f"PHP SDK signal failed: {php_signal}")

        phase = "sdk_php_query"
        outputs["probe_phase"] = phase
        php_query = wait_for_query_result(
            label="PHP worker PHP SDK query after PHP SDK signal",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                php_workflow_client_sample(
                    sdk_php_project,
                    base_url,
                    token,
                    namespace,
                    "query",
                    workflow_type,
                    workflow_id,
                    task_queue,
                    "current",
                    log_file,
                ),
            ),
        )
        outputs["sdk_php_query_sample"] = php_query
        outputs["sdk_php_signal_and_query"] = (
            public_sample_ok(php_signal)
            and public_sample_ok(php_query)
            and sample_result_value(php_query) == 8
        )

        phase = "sdk_php_repeat_query"
        outputs["probe_phase"] = phase
        repeat_query = wait_for_query_result(
            label="PHP worker repeat PHP SDK query",
            expected=8,
            log_file=log_file,
            sample_factory=lambda: capture_published_client_invocation(
                outputs,
                phase,
                php_workflow_client_sample(
                    sdk_php_project,
                    base_url,
                    token,
                    namespace,
                    "query",
                    workflow_type,
                    workflow_id,
                    task_queue,
                    "current",
                    log_file,
                ),
            ),
        )
        outputs["repeat_query_sample"] = repeat_query
        outputs["immediate_repeat_query_consistency"] = (
            sample_result_value(repeat_query) == sample_result_value(php_query)
        )

        if python_bin is not None:
            phase = "python_and_cli_cross_language_clients"
            outputs["probe_phase"] = phase
            cross_language_outputs = run_php_worker_python_and_cli_clients(
                base_url=base_url,
                token=token,
                namespace=namespace,
                cli_bin=cli_bin,
                python_bin=python_bin,
                task_queue=task_queue,
                worker_id=worker_id,
                workflow_type=workflow_type,
                versions=versions,
                sources=sources,
                log_file=log_file,
            )
            descriptor["php_worker_python_and_cli_clients"] = {
                "workflow_id": cross_language_outputs.get("workflow_id"),
                "run_id": cross_language_outputs.get("run_id"),
                "worker_id": worker_id,
                "task_queue": task_queue,
                "status": baseline_scenario_result(
                    "php_worker_python_and_cli_clients",
                    cross_language_outputs,
                )["status"],
            }
            descriptor.setdefault("cross_language_scenario_results", {})[
                "php_worker_python_and_cli_clients"
            ] = baseline_scenario_result(
                "php_worker_python_and_cli_clients",
                cross_language_outputs,
            )

        outputs["probe_phase"] = "complete"
        return outputs, descriptor
    except Exception as exc:  # noqa: BLE001 - retain partial PHP evidence for a focused mirror finding.
        error = probe_error_payload(exc)
        error.setdefault("phase", phase)
        outputs["probe_error"] = error
        descriptor["error"] = f"{type(exc).__name__}: {exc}"
        log_line(log_file, f"PHP worker mirror probe stopped after partial evidence: {type(exc).__name__}: {exc}")
        return outputs, descriptor
    finally:
        capture_command_summary(["docker", "logs", container_name], log_file=log_file, timeout=60)
        cleanup_container(container_name, log_file)


def probe_error_payload(exc: Exception) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "type": type(exc).__name__,
        "message": str(exc),
    }
    if isinstance(exc, PhpSdkArtifactError):
        payload.update({
            "code": exc.code,
            "artifact": "sdk-php",
            "package": "durable-workflow/sdk",
            "requested_version": exc.version,
            "source": EXPECTED_ARTIFACT_SOURCES["sdk-php"],
            "phase": exc.phase,
        })
        payload.update(exc.details)
        if exc.command is not None and exc.command_result is not None:
            payload["command"] = command_summary(exc.command, exc.command_result)
    elif isinstance(exc, RustCrateArtifactError):
        payload.update({
            "code": exc.code,
            "artifact": "sdk-rust",
            "package": exc.package,
            "requested_version": exc.version,
            "source": EXPECTED_ARTIFACT_SOURCES["sdk-rust"],
            "phase": exc.phase,
        })
        payload.update(exc.details)
        if exc.command_result is not None:
            payload["command"] = command_summary(
                ["cargo", "generate-lockfile"]
                if exc.phase == "cargo_generate_lockfile"
                else ["cargo", "build", "--locked", "--release"],
                exc.command_result,
            )
    return payload


RUST_MATRIX_SCENARIOS = (
    "rust_worker_rust_php_python_clients",
    "python_worker_rust_client",
    "php_worker_rust_client",
    "rust_query_error_and_immutability",
    "rust_replayed_instance_state_query_after_cold_restart",
)


def classify_rust_setup_failure(error: dict[str, Any]) -> dict[str, Any]:
    command = error.get("command")
    command_output = ""
    return_code: int | None = None
    if isinstance(command, dict):
        command_output = "\n".join([
            str(command.get("stdout") or ""),
            str(command.get("stderr") or ""),
        ]).lower()
        candidate_return_code = command.get("exit_code")
        if isinstance(candidate_return_code, int):
            return_code = candidate_return_code

    code = str(error.get("code") or "")
    phase = str(error.get("phase") or "")
    runner_patterns = {
        "rust_cache_storage_unavailable": (
            "no space left on device",
            "disk quota exceeded",
            "read-only file system",
            "permission denied",
        ),
        "rust_registry_transport_unavailable": (
            "failed to download",
            "failed to get successful http response",
            "spurious network error",
            "could not resolve host",
            "connection timed out",
            "connection reset",
            "network is unreachable",
        ),
    }
    for blocker_kind, patterns in runner_patterns.items():
        if any(pattern in command_output for pattern in patterns):
            return {
                "runner_blocked": True,
                "blocker_kind": blocker_kind,
                "owner": "conformance_harness",
                "failure_scope": "rust_setup",
            }

    if return_code in {137, 143} or "signal: 9, sigkill" in command_output:
        return {
            "runner_blocked": True,
            "blocker_kind": "rust_build_runner_resource_exhausted",
            "owner": "conformance_harness",
            "failure_scope": "rust_setup",
        }

    failed_package_match = re.search(r"could not compile [`']([^`']+)[`']", command_output)
    if failed_package_match is not None or "error[" in command_output:
        failed_package = failed_package_match.group(1) if failed_package_match is not None else "unknown"
        owner = "conformance_harness" if failed_package == "signals-queries-published-probe" else "sdk-rust"
        return {
            "runner_blocked": False,
            "blocker_kind": "rust_crate_or_probe_compilation_failed",
            "owner": owner,
            "failure_scope": "published_artifact_compilation",
            "failed_package": failed_package,
        }

    if code == "rust_crate_resolution_failed" and "no matching package" in command_output:
        return {
            "runner_blocked": False,
            "blocker_kind": "rust_published_crate_unavailable",
            "owner": "sdk-rust",
            "failure_scope": "published_artifact_resolution",
        }

    if code == "rust_dependency_cache_failed" or phase == "rust_dependency_cache":
        return {
            "runner_blocked": True,
            "blocker_kind": "rust_dependency_cache_unavailable",
            "owner": "conformance_harness",
            "failure_scope": "rust_setup",
        }

    return {
        "runner_blocked": True,
        "blocker_kind": "rust_setup_failure_unclassified",
        "owner": "conformance_harness",
        "failure_scope": "rust_setup",
    }


def rust_setup_failure_evidence(
    error: dict[str, Any],
    versions: dict[str, Any],
) -> dict[str, Any]:
    classification = classify_rust_setup_failure(error)
    retained_error = dict(error)
    retained_error["classification"] = classification
    finding_id = "signal_query_rust_published_artifact_setup_failed"
    status = "runner_blocked" if classification["runner_blocked"] else "fail"
    finding = {
        "id": finding_id,
        "type": finding_id,
        "scenario_id": RUST_MATRIX_SCENARIOS[0],
        "owner": classification["owner"],
        "title": "Rust published-artifact matrix could not start",
        "current_evidence": {
            "published_artifact_evidence_present": True,
            "setup_failure": retained_error,
        },
        "acceptance": [
            "build the exact published Rust SDK and generated conformance probe",
            "retain bounded Cargo diagnostics and cache filesystem diagnostics on failure",
            "run the Rust same-language, cross-language, error, and replay cells",
        ],
    }
    if status == "runner_blocked":
        finding["blocker_kind"] = classification["blocker_kind"]
    else:
        finding["observed_behavior"] = (
            "the exact published Rust artifact or generated probe did not compile"
        )

    scenario_results: dict[str, dict[str, Any]] = {}
    for index, scenario in enumerate(RUST_MATRIX_SCENARIOS):
        scenario_results[scenario] = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": {
                "published_artifact_versions": dict(versions),
                "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
                "setup_failure": retained_error,
            },
            "linked_findings": [finding if index == 0 else finding_id],
        }
    return {
        "artifact_versions": dict(versions),
        "scenario_results": scenario_results,
        "setup_failure": retained_error,
    }


def run_baseline_probe(result_dir: Path) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_BASELINE_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    run_root = Path(
        env_text("DW_SIGNALS_QUERIES_BASELINE_RUN_ROOT")
        or tempfile.mkdtemp(prefix="dw-signals-queries-baseline.", dir=str(result_dir))
    )
    run_root.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "signals-queries-baseline-probe.log"
    register_scratch_root(run_root, log_file)
    cleanup_commands: list[list[str]] = []

    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or "default"
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")
    readiness_probe: dict[str, Any] | None = None
    partial_evidence: dict[str, Any] | None = None
    generated_scenarios: list[str] = []
    baseline_checkpoint_path = result_dir / "signals-queries-baseline-cell-results.json"
    heartbeat_guard: WorkerHeartbeatGuard | None = None
    focused_php_cli_signal = env_text("DW_SIGNALS_QUERIES_FOCUS") == "php-worker-cli-signal"

    try:
        if not isinstance(base_url, str) or base_url.strip() == "":
            base_url, cleanup_commands, readiness_probe = start_published_server(run_root, log_file)
        else:
            base_url = base_url.rstrip("/")
            readiness_probe = wait_for_ready(
                base_url,
                log_file,
                timeout_seconds=server_ready_timeout_seconds(30),
                diagnostics=configured_server_diagnostics(base_url),
            )

        server_install = server_install_entry(cleanup_commands)
        versions = probe_artifact_versions()
        install_entries = {
            "server": server_install,
        }
        cli_bin: str | None = None
        python_bin: str | None = None
        sdk_php_project: Path | None = None
        sdk_php_install_error: dict[str, Any] | None = None
        cli_install_error: dict[str, Any] | None = None
        python_install_error: dict[str, Any] | None = None
        install_descriptors: dict[str, Any] = {}
        try:
            cli_bin, cli_install = install_cli(run_root, log_file)
            install_entries["cli"] = cli_install
        except Exception as exc:  # noqa: BLE001 - install proof is reported separately from server baseline cells.
            log_line(log_file, f"CLI install probe failed: {type(exc).__name__}: {exc}")
            cli_install_error = {
                **probe_error_payload(exc),
                "phase": "cli_install",
                "failure_scope": "runner_setup",
                "artifact": "cli",
            }
            cli_install = configured_artifact_entry(
                "cli",
                artifact_version_value(versions, "cli"),
                "published_cli_release",
                "github_release_installer",
            )
            cli_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
            install_entries["cli"] = cli_install
            install_descriptors["cli"] = {
                "error": f"{type(exc).__name__}: {exc}",
                "probe_error": cli_install_error,
                "log_file": log_file.name,
            }
        if focused_php_cli_signal:
            python_install = configured_artifact_entry(
                "sdk-python",
                artifact_version_value(versions, "sdk-python"),
                "published_pypi_package",
                "pypi_package_install",
            )
            python_install["status"] = "not_covered"
            python_install["not_proved_reason"] = "not required by focused PHP CLI signal cell"
            install_entries["sdk-python"] = python_install
            install_descriptors["sdk-python"] = {
                "skipped": "not_required_by_focused_cell",
                "log_file": log_file.name,
            }
        else:
            try:
                python_bin, python_install = ensure_python_sdk(run_root, log_file)
                install_entries["sdk-python"] = python_install
            except Exception as exc:  # noqa: BLE001 - install proof is reported separately from server baseline cells.
                log_line(log_file, f"Python SDK install probe failed: {type(exc).__name__}: {exc}")
                python_install_error = {
                    **probe_error_payload(exc),
                    "phase": "python_sdk_install",
                    "failure_scope": "runner_setup",
                    "artifact": "sdk-python",
                }
                python_install = configured_artifact_entry(
                    "sdk-python",
                    artifact_version_value(versions, "sdk-python"),
                    "published_pypi_package",
                    "pypi_package_install",
                )
                python_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
                install_entries["sdk-python"] = python_install
                install_descriptors["sdk-python"] = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "probe_error": python_install_error,
                    "log_file": log_file.name,
                }
        try:
            sdk_php_project, sdk_php_install = ensure_sdk_php_sdk(run_root, log_file)
            install_entries["sdk-php"] = sdk_php_install
        except Exception as exc:  # noqa: BLE001 - PHP mirror evidence is reported as its own baseline cell.
            sdk_php_install_error = probe_error_payload(exc)
            log_line(log_file, f"PHP SDK package install probe failed: {type(exc).__name__}: {exc}")
            sdk_php_install = configured_artifact_entry(
                "sdk-php",
                artifact_version_value(versions, "sdk-php"),
                "published_composer_package",
                "composer_package_install",
            )
            sdk_php_install["not_proved_reason"] = f"{type(exc).__name__}: {exc}"
            install_entries["sdk-php"] = sdk_php_install
            install_descriptors["sdk-php"] = {
                "error": f"{type(exc).__name__}: {exc}",
                "log_file": log_file.name,
            }

        rust_matrix_evidence: dict[str, Any] | None = None
        rust_matrix_descriptor: dict[str, Any] | None = None
        rust_install_error: dict[str, Any] | None = None
        if not env_flag("DW_SIGNALS_QUERIES_RUN_RUST_MATRIX_PROBE", True):
            rust_matrix_descriptor = {"skipped": "disabled_by_env", "log_file": log_file.name}
        elif python_bin is None or sdk_php_project is None:
            rust_matrix_descriptor = {
                "skipped": "python_or_php_published_client_unavailable",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }
        else:
            try:
                rust_matrix_evidence, rust_install, rust_matrix_descriptor = run_rust_matrix_probe(
                    base_url=base_url,
                    token=token,
                    namespace=namespace,
                    python_bin=python_bin,
                    sdk_php_project=sdk_php_project,
                    run_root=run_root,
                    log_file=log_file,
                )
                install_entries["sdk-rust"] = rust_install
            except Exception as exc:  # noqa: BLE001 - preserve the non-Rust shards and focused Rust finding.
                rust_install_error = probe_error_payload(exc)
                rust_matrix_evidence = rust_setup_failure_evidence(rust_install_error, versions)
                rust_install_error = dict(rust_matrix_evidence["setup_failure"])
                log_line(log_file, f"Rust published-artifact matrix failed: {type(exc).__name__}: {exc}")
                rust_matrix_descriptor = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "artifact_error": rust_install_error,
                    "log_file": log_file.name,
                    "generated_scenarios": list(RUST_MATRIX_SCENARIOS),
                }
        if "sdk-rust" not in install_entries:
            rust_install = configured_artifact_entry(
                "sdk-rust",
                artifact_version_value(versions, "sdk-rust"),
                EXPECTED_ARTIFACT_SOURCES["sdk-rust"],
                "cargo_exact_registry_install",
            )
            rust_install["status"] = "not_covered"
            if rust_install_error is not None:
                rust_install["not_proved_reason"] = (
                    f"{rust_install_error['type']}: {rust_install_error['message']}"
                )
                rust_install["artifact_error"] = rust_install_error
            install_entries["sdk-rust"] = rust_install
        sources = probe_artifact_sources(cleanup_commands, install_entries)
        install_outputs = {
            "published_artifact_versions": versions,
            "artifact_sources": sources,
            "artifact_install_evidence": install_evidence_for_artifacts(
                versions,
                sources,
                REQUIRED_INSTALL_PROOF_ARTIFACTS,
                install_entries,
            ),
            "local_product_source_checkouts_used": False,
        }
        rust_setup_classification = (
            rust_install_error.get("classification")
            if isinstance(rust_install_error, dict)
            else None
        )
        if isinstance(rust_install_error, dict):
            install_outputs["rust_setup_failure"] = rust_install_error
        if install_outputs_cover_required_artifacts(install_outputs):
            install_status = "pass"
        elif (
            isinstance(rust_setup_classification, dict)
            and rust_setup_classification.get("runner_blocked") is True
        ):
            install_status = "runner_blocked"
        elif isinstance(rust_install_error, dict):
            install_status = "fail"
        else:
            install_status = "not_covered"
        scenario_results: dict[str, dict[str, Any]] = {
            "published_artifact_install_only": {
                "status": install_status,
                "observed_outputs": install_outputs,
            },
        }
        if isinstance(rust_install_error, dict):
            scenario_results["published_artifact_install_only"]["linked_findings"] = [
                "signal_query_rust_published_artifact_setup_failed"
            ]
        generated_scenarios.append("published_artifact_install_only")
        if isinstance(rust_matrix_evidence, dict):
            rust_scenarios = rust_matrix_evidence.get("scenario_results")
            if isinstance(rust_scenarios, dict):
                for rust_scenario_id, rust_result in rust_scenarios.items():
                    if not isinstance(rust_scenario_id, str) or not isinstance(rust_result, dict):
                        continue
                    scenario_results[rust_scenario_id] = rust_result
                    generated_scenarios.append(rust_scenario_id)

        def checkpoint_baseline_cells() -> None:
            nonlocal partial_evidence
            partial_evidence = {
                "artifact_versions": versions,
                "scenario_results": scenario_results,
            }
            atomic_write_json(baseline_checkpoint_path, partial_evidence)

        checkpoint_baseline_cells()
        python_sdk_outputs: dict[str, Any] | None = None
        python_sdk_descriptor: dict[str, Any] | None = None
        python_worker_php_cross_result: dict[str, Any] | None = None
        python_sdk_status = "not_covered"
        if focused_php_cli_signal:
            python_sdk_descriptor = {
                "skipped": "not_required_by_focused_cell",
                "log_file": log_file.name,
            }
        elif cli_bin is not None and python_bin is not None:
            try:
                python_sdk_outputs, python_sdk_descriptor = run_python_sdk_baseline(
                    base_url=base_url,
                    token=token,
                    namespace=namespace,
                    cli_bin=cli_bin,
                    python_bin=python_bin,
                    versions=versions,
                    sources=sources,
                    run_root=run_root,
                    log_file=log_file,
                    sdk_php_project=sdk_php_project,
                )
                if isinstance(python_sdk_descriptor, dict):
                    cross_results = python_sdk_descriptor.get("cross_language_scenario_results")
                    if isinstance(cross_results, dict):
                        candidate = cross_results.get("python_worker_php_facing_and_cli_clients")
                        if isinstance(candidate, dict):
                            python_worker_php_cross_result = candidate
                python_sdk_status = baseline_scenario_result(
                    "python_worker_cli_and_sdk_baseline",
                    python_sdk_outputs,
                )["status"]
            except Exception as exc:  # noqa: BLE001 - keep the older shards routed when the SDK baseline is missing.
                log_line(log_file, f"Python SDK baseline probe failed: {type(exc).__name__}: {exc}")
                python_sdk_descriptor = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "log_file": log_file.name,
                }
        else:
            setup_error = python_install_error or cli_install_error or {
                "type": "RuntimeError",
                "message": "CLI or Python SDK install was unavailable",
                "phase": "runner_setup",
                "failure_scope": "runner_setup",
            }
            python_sdk_outputs = {
                "worker_runtime": "sdk-python",
                "python_worker_artifact_source": sources["sdk-python"],
                "python_worker_expected_sdk_version": versions["sdk-python"],
                "published_artifact_versions": versions,
                "artifact_sources": sources,
                "probe_phase": setup_error["phase"],
                "probe_error": setup_error,
                "readiness_boundary": {
                    "status": "failed",
                    "failed_phase": setup_error["phase"],
                    "failure_scope": setup_error["failure_scope"],
                },
            }
            if python_bin is not None:
                try:
                    observed_python_version = python_sdk_distribution_version(python_bin, log_file)
                    if observed_python_version != "":
                        python_sdk_outputs["python_worker_sdk_version"] = observed_python_version
                except Exception as exc:  # noqa: BLE001 - the retained setup failure remains authoritative.
                    log_line(
                        log_file,
                        "Python SDK setup failure version capture also failed: "
                        f"{type(exc).__name__}: {exc}",
                    )
            python_sdk_descriptor = {
                "skipped": "cli_or_python_sdk_install_unavailable",
                "install_probes": install_descriptors,
                "failure_phase": setup_error["phase"],
                "failure_scope": setup_error["failure_scope"],
                "log_file": log_file.name,
            }
            python_sdk_status = baseline_scenario_result(
                "python_worker_cli_and_sdk_baseline",
                python_sdk_outputs,
            )["status"]
        if python_sdk_outputs is not None:
            scenario_results["python_worker_cli_and_sdk_baseline"] = {
                "status": python_sdk_status,
                "observed_outputs": python_sdk_outputs,
            }
            generated_scenarios.append("python_worker_cli_and_sdk_baseline")
        if python_worker_php_cross_result is not None:
            scenario_results["python_worker_php_facing_and_cli_clients"] = python_worker_php_cross_result
            generated_scenarios.append("python_worker_php_facing_and_cli_clients")
        checkpoint_baseline_cells()

        sdk_php_outputs: dict[str, Any] | None = None
        sdk_php_descriptor: dict[str, Any] | None = None
        php_worker_python_cross_result: dict[str, Any] | None = None
        sdk_php_status = "not_covered"
        if not env_flag("DW_SIGNALS_QUERIES_RUN_PHP_BASELINE_PROBE", True):
            sdk_php_descriptor = {
                "skipped": "disabled_by_env",
                "log_file": log_file.name,
            }
        elif cli_bin is not None and sdk_php_project is not None:
            try:
                sdk_php_outputs, sdk_php_descriptor = run_sdk_php_baseline(
                    base_url=base_url,
                    token=token,
                    namespace=namespace,
                    cli_bin=cli_bin,
                    python_bin=python_bin,
                    sdk_php_project=sdk_php_project,
                    versions=versions,
                    sources=sources,
                    install_entry=install_entries["sdk-php"],
                    run_root=run_root,
                    log_file=log_file,
                )
                if isinstance(sdk_php_descriptor, dict):
                    cross_results = sdk_php_descriptor.get("cross_language_scenario_results")
                    if isinstance(cross_results, dict):
                        candidate = cross_results.get("php_worker_python_and_cli_clients")
                        if isinstance(candidate, dict):
                            php_worker_python_cross_result = candidate
                if has_required_evidence("php_worker_cli_and_sdk_baseline", sdk_php_outputs):
                    sdk_php_status = "pass"
            except Exception as exc:  # noqa: BLE001 - leave a focused mirror finding and preserve sibling cells.
                log_line(log_file, f"PHP worker mirror probe failed: {type(exc).__name__}: {exc}")
                sdk_php_descriptor = {
                    "error": f"{type(exc).__name__}: {exc}",
                    "log_file": log_file.name,
                }
        elif sdk_php_install_error is not None:
            sdk_php_outputs = {
                "worker_runtime": "sdk-php",
                "sdk_php_artifact_source": sources["sdk-php"],
                "sdk_php_sdk_version": versions["sdk-php"],
                "published_artifact_versions": versions,
                "artifact_sources": sources,
                "sdk_php_install_evidence": install_entries["sdk-php"],
                "probe_error": sdk_php_install_error,
            }
            sdk_php_descriptor = {
                "error": f"{sdk_php_install_error['type']}: {sdk_php_install_error['message']}",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }
        else:
            sdk_php_descriptor = {
                "skipped": "cli_or_php_sdk_install_unavailable",
                "install_probes": install_descriptors,
                "log_file": log_file.name,
            }
        if sdk_php_outputs is not None:
            scenario_results["php_worker_cli_and_sdk_baseline"] = {
                "status": sdk_php_status,
                "observed_outputs": sdk_php_outputs,
            }
            generated_scenarios.append("php_worker_cli_and_sdk_baseline")
        if php_worker_python_cross_result is not None:
            scenario_results["php_worker_python_and_cli_clients"] = php_worker_python_cross_result
            generated_scenarios.append("php_worker_python_and_cli_clients")
        checkpoint_baseline_cells()

        if focused_php_cli_signal:
            evidence = {
                "artifact_versions": versions,
                "scenario_results": scenario_results,
            }
            return evidence, {
                "file": log_file.name,
                "cell_checkpoint_file": baseline_checkpoint_path.name,
                "server_base_url": base_url,
                "focused_cell": "php-worker-cli-signal",
                "broad_property_claimed": False,
                "php_worker_cli_and_sdk_baseline": sdk_php_descriptor,
                "install_probes": install_descriptors,
                "server_readiness": readiness_probe,
                "generated_scenarios": generated_scenarios,
            }

        suffix = hashlib.sha1(f"{time.time()}-baseline".encode("utf-8")).hexdigest()[:10]
        task_queue = f"signals-queries-baseline-{suffix}"
        worker_id = f"signals-queries-baseline-worker-{suffix}"
        workflow_type = "conformance.counter"
        worker_process_started_at = now()

        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-baseline-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "process_metrics": {
                    "process_started_at": worker_process_started_at,
                },
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise RuntimeError(f"baseline worker registration failed: {register}")

        heartbeat_guard = WorkerHeartbeatGuard(
            base_url,
            token,
            namespace,
            worker_id,
            log_file,
        )
        heartbeat_guard.start()
        if not heartbeat_guard.wait_until_eligible():
            raise RuntimeError(
                f"baseline worker heartbeat guard did not establish eligibility: {heartbeat_guard.snapshot()}"
            )

        def optional_sample(field: str, callback: Any) -> Any:
            try:
                return callback()
            except Exception as exc:  # noqa: BLE001 - optional client samples must not erase server evidence.
                log_line(log_file, f"{field} optional public client sample failed: {type(exc).__name__}: {exc}")
                return MISSING

        baseline_workflow_id = f"wf-sq-baseline-{suffix}"
        baseline_run_id: str | None = None
        ordered_workflow_id = f"wf-sq-ordered-{suffix}"
        ordered_run_id: str | None = None
        dedup_workflow_id = f"wf-sq-dedup-{suffix}"
        dedup_run_id: str | None = None
        optional_unknown_outputs: dict[str, Any] = {}
        server_baseline_outputs: dict[str, Any] = {
            "worker_runtime": "external-http",
            "workflow_id": baseline_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }

        try:
            baseline_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                baseline_workflow_id,
                workflow_type,
                f"{baseline_workflow_id}-initial",
            )
            counter = 0
            history_and_commands_before_rejected_requests = workflow_public_snapshot(
                base_url,
                token,
                namespace,
                baseline_workflow_id,
                baseline_run_id,
            )

            unknown_signal = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id, "signal", "missing"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            query_not_found = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id, "query", "missing"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            missing_workflow_signal = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id + "-missing", "signal", "increment"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            missing_workflow_query = http_json(
                base_url,
                api_path("workflows", baseline_workflow_id + "-missing", "query", "state"),
                method="POST",
                body={},
                token=token,
                namespace=namespace,
                timeout=30,
            )
            history_and_commands_after_rejected_requests = workflow_public_snapshot(
                base_url,
                token,
                namespace,
                baseline_workflow_id,
                baseline_run_id,
            )
            known_query_after_unknown_errors = query_with_worker_result(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                baseline_workflow_id,
                "state",
                counter,
                log_file,
                lambda: http_json(
                    base_url,
                    api_path("workflows", baseline_workflow_id, "query", "state"),
                    method="POST",
                    body={},
                    token=token,
                    namespace=namespace,
                    timeout=60,
                ),
                workflow_condition_key=f"{baseline_workflow_id}-initial",
            )
            known_query_after_unknown_result = sample_result_value(known_query_after_unknown_errors)
            history_and_commands_after_recovery_query = workflow_public_snapshot(
                base_url,
                token,
                namespace,
                baseline_workflow_id,
                baseline_run_id,
            )
            if cli_bin is not None:
                optional_unknown_outputs.update(
                    {
                        "cli_unknown_signal_sample": optional_sample(
                            "cli_unknown_signal_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:signal",
                                    baseline_workflow_id,
                                    "missing",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_unknown_query_sample": optional_sample(
                            "cli_unknown_query_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:query",
                                    baseline_workflow_id,
                                    "missing",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_missing_workflow_signal_sample": optional_sample(
                            "cli_missing_workflow_signal_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:signal",
                                    baseline_workflow_id + "-missing",
                                    "increment",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                        "cli_missing_workflow_query_sample": optional_sample(
                            "cli_missing_workflow_query_sample",
                            lambda: cli_json_sample(
                                cli_bin,
                                base_url,
                                token,
                                namespace,
                                [
                                    "workflow:query",
                                    baseline_workflow_id + "-missing",
                                    "state",
                                    "--output=json",
                                ],
                                log_file,
                            ),
                        ),
                    }
                )
            if python_bin is not None:
                optional_unknown_outputs.update(
                    {
                        "sdk_python_unknown_signal_sample": optional_sample(
                            "sdk_python_unknown_signal_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id,
                                "signal",
                                "missing",
                                log_file,
                            ),
                        ),
                        "sdk_python_unknown_query_sample": optional_sample(
                            "sdk_python_unknown_query_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id,
                                "query",
                                "missing",
                                log_file,
                            ),
                        ),
                        "sdk_python_missing_workflow_signal_sample": optional_sample(
                            "sdk_python_missing_workflow_signal_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id + "-missing",
                                "signal",
                                "increment",
                                log_file,
                            ),
                        ),
                        "sdk_python_missing_workflow_query_sample": optional_sample(
                            "sdk_python_missing_workflow_query_sample",
                            lambda: sdk_error_sample(
                                python_bin,
                                base_url,
                                token,
                                namespace,
                                baseline_workflow_id + "-missing",
                                "query",
                                "state",
                                log_file,
                            ),
                        ),
                    }
                )
            optional_unknown_outputs = {
                field: sample
                for field, sample in optional_unknown_outputs.items()
                if sample is not MISSING
            }
            expected_rejected_signal_audits = [
                rejected_signal_audit_spec(baseline_run_id, "missing", "unknown_signal")
            ]
            if "cli_unknown_signal_sample" in optional_unknown_outputs:
                expected_rejected_signal_audits.append(
                    rejected_signal_audit_spec(baseline_run_id, "missing", "unknown_signal")
                )
            if "sdk_python_unknown_signal_sample" in optional_unknown_outputs:
                expected_rejected_signal_audits.append(
                    rejected_signal_audit_spec(baseline_run_id, "missing", "unknown_signal")
                )
            history_and_commands_after_all_requests = workflow_public_snapshot(
                base_url,
                token,
                namespace,
                baseline_workflow_id,
                baseline_run_id,
            )
            rejected_requests_and_recovery_appended_no_history = (
                snapshot_count_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_rejected_requests,
                    "history_event_count",
                )
                and snapshot_count_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_recovery_query,
                    "history_event_count",
                )
                and snapshot_count_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_all_requests,
                    "history_event_count",
                )
            )
            rejected_audit_evidence = rejected_signal_audit_evidence(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_all_requests,
                expected_rejected_signal_audits,
            )
            rejected_requests_created_no_executable_or_ready_work = (
                rejected_audit_evidence["exact_match"] is True
                and rejected_audit_evidence["executable_or_ready_command_count"] == 0
                and ready_or_leased_workflow_tasks_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_rejected_requests,
                )
                and ready_or_leased_workflow_tasks_unchanged(
                    history_and_commands_after_rejected_requests,
                    history_and_commands_after_recovery_query,
                )
                and ready_or_leased_workflow_tasks_unchanged(
                    history_and_commands_after_recovery_query,
                    history_and_commands_after_all_requests,
                )
            )
            rejected_handler_invocations = rejected_signal_handler_invocation_count(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_all_requests,
            )
            rejected_requests_mutated_no_workflow_state = (
                workflow_state_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_rejected_requests,
                )
                and workflow_state_unchanged(
                    history_and_commands_before_rejected_requests,
                    history_and_commands_after_all_requests,
                )
            )

            unknown_outputs = {
                "worker_id": worker_id,
                "task_queue": task_queue,
                "unknown_signal": response_sample(unknown_signal),
                "missing_workflow_signal": response_sample(missing_workflow_signal),
                "missing_workflow_query": response_sample(missing_workflow_query),
                "query_not_found": response_sample(query_not_found),
                "rejected_unknown_query": response_sample(query_not_found),
                "known_query_after_unknown_errors": known_query_after_unknown_errors,
                "known_query_after_unknown_result": known_query_after_unknown_result,
                "known_query_after_unknown_expected": counter,
                "post_error_query_responder": known_query_after_unknown_errors.get("query_task"),
                "history_and_commands_before_rejected_requests": (
                    history_and_commands_before_rejected_requests
                ),
                "history_and_commands_after_rejected_requests": (
                    history_and_commands_after_rejected_requests
                ),
                "history_and_commands_after_recovery_query": history_and_commands_after_recovery_query,
                "history_and_commands_after_all_requests": history_and_commands_after_all_requests,
                "rejected_signal_audit_rows": rejected_audit_evidence,
                "rejected_signal_audit_rows_match_expected": rejected_audit_evidence["exact_match"],
                "rejected_requests_and_recovery_appended_no_history": (
                    rejected_requests_and_recovery_appended_no_history
                ),
                "rejected_requests_created_no_executable_or_ready_work": (
                    rejected_requests_created_no_executable_or_ready_work
                ),
                "rejected_signal_handler_invocation_count": rejected_handler_invocations,
                "rejected_requests_mutated_no_workflow_state": (
                    rejected_requests_mutated_no_workflow_state
                ),
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
            unknown_outputs.update(optional_unknown_outputs)
            server_baseline_outputs = {
                "worker_runtime": "external-http",
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "known_query_after_unknown_errors": response_sample(known_query_after_unknown_errors),
                "known_query_after_unknown_result": known_query_after_unknown_result,
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
        except Exception as exc:  # noqa: BLE001 - record the missing proof without dropping sibling cells.
            log_line(log_file, f"unknown-handler baseline probe failed: {type(exc).__name__}: {exc}")
            unknown_outputs = {
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "probe_error": probe_error_payload(exc),
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
            server_baseline_outputs = {
                "worker_runtime": "external-http",
                "workflow_id": baseline_workflow_id,
                "run_id": baseline_run_id,
                "probe_error": probe_error_payload(exc),
                "published_artifact_versions": versions,
                "artifact_sources": sources,
            }
        scenario_results["unknown_signal_and_query_errors"] = baseline_scenario_result(
            "unknown_signal_and_query_errors",
            unknown_outputs,
        )
        generated_scenarios.append("unknown_signal_and_query_errors")
        checkpoint_baseline_cells()

        ordered_outputs: dict[str, Any] = {
            "workflow_id": ordered_workflow_id,
            "server_base_url": base_url,
            "namespace": namespace,
            "worker_id": worker_id,
            "task_queue": task_queue,
            "worker_process_started_at": worker_process_started_at,
            "worker_registration": register,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        try:
            ordered_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                ordered_workflow_id,
                workflow_type,
                f"{ordered_workflow_id}-initial",
            )
            ordered_outputs["run_id"] = ordered_run_id
            rapid_inputs = list(range(1, 11))
            ordered_outputs["rapid_increment_inputs"] = rapid_inputs
            ordered_signal_responses = []
            ordered_signal_failures = []
            accepted_signal_inputs: list[int] = []
            history_signal_order: list[int] = []
            ordered_outputs["history_signal_order"] = history_signal_order
            ordered_signal_tasks: list[dict[str, Any]] = []
            for amount in rapid_inputs:
                response = http_json(
                    base_url,
                    api_path("workflows", ordered_workflow_id, "signal", "increment"),
                    method="POST",
                    body={"input": [amount], "request_id": f"{ordered_workflow_id}-{amount}"},
                    token=token,
                    namespace=namespace,
                    timeout=30,
                )
                signal_sample = response_sample(response)
                signal_sample["amount"] = amount
                signal_sample["accepted"] = (
                    isinstance(signal_sample.get("status_code"), int)
                    and int(signal_sample["status_code"]) < 400
                )
                ordered_signal_responses.append(signal_sample)
                if signal_sample["accepted"]:
                    accepted_signal_inputs.append(amount)
                if int(response["status_code"]) >= 400:
                    ordered_signal_failures.append({
                        "amount": amount,
                        "response": signal_sample,
                    })

            ordered_outputs["signal_api_samples"] = ordered_signal_responses
            ordered_outputs["accepted_signal_inputs"] = accepted_signal_inputs
            ordered_outputs["accepted_signal_total"] = sum(accepted_signal_inputs)
            ordered_outputs["signal_status_codes"] = [
                sample.get("status_code")
                for sample in ordered_signal_responses
            ]
            if ordered_signal_failures:
                ordered_outputs["signal_api_failures"] = ordered_signal_failures

            accepted_signal_count = len(accepted_signal_inputs)
            if accepted_signal_count > 0:
                ordered_seen_signals: set[str] = set()
                try:
                    collect_increment_signal_observations(
                        base_url,
                        token,
                        namespace,
                        worker_id,
                        task_queue,
                        ordered_seen_signals,
                        history_signal_order,
                        ordered_signal_tasks,
                        f"{ordered_workflow_id}-after",
                        "ordered",
                        accepted_signal_count,
                        log_file,
                    )
                except Exception as exc:  # noqa: BLE001 - keep partial public ordered evidence.
                    log_line(log_file, f"ordered delivery history collection failed: {type(exc).__name__}: {exc}")
                    ordered_outputs["history_collection_error"] = probe_error_payload(exc)

            ordered_outputs["history_signal_order"] = history_signal_order
            ordered_outputs["signal_tasks"] = ordered_signal_tasks
            delivered_signal_total = sum(history_signal_order)
            ordered_outputs["delivered_signal_total"] = delivered_signal_total
            ordered_outputs["contract_expected_total"] = sum(rapid_inputs)
            ordered_outputs["expected_total"] = sum(accepted_signal_inputs)
            ordered_query_holder: dict[str, Any] = {}
            ordered_query_ready = threading.Event()

            def ordered_query_result_from_task(task: dict[str, Any], holder: dict[str, Any]) -> int:
                query_history_order = increment_signal_amounts_from_history_events(task.get("history_events"))
                if query_history_order:
                    holder["history_signal_order"] = query_history_order
                    return sum(query_history_order)

                return delivered_signal_total

            ordered_responder = threading.Thread(
                target=answer_next_query_task,
                args=(
                    base_url,
                    token,
                    namespace,
                    worker_id,
                    task_queue,
                    ordered_query_result_from_task,
                    log_file,
                    ordered_query_holder,
                    f"{ordered_workflow_id}-query",
                ),
                kwargs={
                    "ready_event": ordered_query_ready,
                    "capture_claim_eligibility": True,
                },
                daemon=True,
            )
            ordered_responder.start()
            ordered_query: dict[str, Any] | None = None
            ordered_query_error: Exception | None = None
            if not ordered_query_ready.wait(timeout=15):
                ordered_query_error = RuntimeError(
                    "ordered query responder did not acknowledge a heartbeat before the query request"
                )
            else:
                try:
                    ordered_query = http_json(
                        base_url,
                        api_path("workflows", ordered_workflow_id, "query", "state"),
                        method="POST",
                        body={},
                        token=token,
                        namespace=namespace,
                        timeout=60,
                    )
                except Exception as exc:  # noqa: BLE001 - record the exact public query failure.
                    ordered_query_error = exc
            ordered_responder.join(timeout=20)
            if ordered_responder.is_alive() or ordered_query_holder.get("error"):
                responder_error = ordered_query_holder.get("error", "timeout")
                log_line(log_file, f"ordered query responder failed: {responder_error}")
                ordered_outputs["query_responder_error"] = {"message": str(responder_error)}
            if ordered_query_error is not None:
                log_line(
                    log_file,
                    f"ordered query request failed: {type(ordered_query_error).__name__}: {ordered_query_error}",
                )
                ordered_outputs["query_error"] = probe_error_payload(ordered_query_error)
                ordered_query_result = None
            else:
                ordered_query_result = sample_result_value(ordered_query or {})
            claimed_query_task = ordered_query_holder.get("query_task")
            if not isinstance(claimed_query_task, dict):
                claimed_query_task = {}
            claim_eligibility = ordered_query_holder.get("worker_eligibility_when_claimed")
            if not isinstance(claim_eligibility, dict):
                claim_eligibility = {"eligible": False}
            completed_query = ordered_query_holder.get("complete")
            if not isinstance(completed_query, dict):
                completed_query = {}
            ordered_responder_evidence = {
                "worker_id": worker_id,
                "task_queue": task_queue,
                "process_started_at": worker_process_started_at,
                "heartbeat_guard": heartbeat_guard.snapshot() if heartbeat_guard is not None else None,
                "heartbeat_before_poll": ordered_query_holder.get("heartbeat"),
                "heartbeat_acknowledged_at": ordered_query_holder.get("heartbeat_acknowledged_at"),
                "query_poll_started_at": ordered_query_holder.get("query_poll_started_at"),
                "query_poll_ready_at": ordered_query_holder.get("query_poll_ready_at"),
                "query_poll_attempt_count": ordered_query_holder.get("query_poll_attempt_count"),
                "query_poll_transport_errors": ordered_query_holder.get("poll_transport_errors", []),
                "query_claimed_at": ordered_query_holder.get("query_handler_invoked_at"),
                "claim_eligibility": claim_eligibility,
                "claimed_query_task": {
                    "query_task_id": claimed_query_task.get("query_task_id"),
                    "query_task_attempt": claimed_query_task.get("query_task_attempt"),
                    "workflow_id": claimed_query_task.get("workflow_id"),
                    "run_id": claimed_query_task.get("run_id"),
                    "query_name": claimed_query_task.get("query_name"),
                    "task_queue": claimed_query_task.get("task_queue"),
                    "lease_owner": claimed_query_task.get("lease_owner"),
                },
                "query_task_completion": response_sample(completed_query) if completed_query else None,
            }
            ordered_responder_evidence["eligible_when_claimed"] = (
                claim_eligibility.get("eligible") is True
                and isinstance(claimed_query_task.get("query_task_id"), str)
                and claimed_query_task.get("workflow_id") == ordered_workflow_id
                and claimed_query_task.get("run_id") == ordered_run_id
                and claimed_query_task.get("query_name") == "state"
                and claimed_query_task.get("task_queue") == task_queue
                and claimed_query_task.get("lease_owner") == worker_id
                and isinstance(completed_query.get("status_code"), int)
                and int(completed_query["status_code"]) < 400
            )
            ordered_outputs["ordered_query_responder"] = ordered_responder_evidence
            query_task_history_order = ordered_query_holder.get("history_signal_order")
            if (
                isinstance(query_task_history_order, list)
                and all(isinstance(item, int) and not isinstance(item, bool) for item in query_task_history_order)
            ):
                ordered_outputs["workflow_task_history_signal_order"] = list(history_signal_order)
                ordered_outputs["query_task_history_signal_order"] = query_task_history_order
                history_signal_order[:] = query_task_history_order
                ordered_outputs["history_signal_order"] = history_signal_order
                delivered_signal_total = sum(history_signal_order)
                ordered_outputs["delivered_signal_total"] = delivered_signal_total
            ordered_outputs["queried_total"] = ordered_query_result
            ordered_outputs["ten_signal_ordered_delivery_total"] = ordered_query_result
            if ordered_query is not None:
                ordered_outputs["query_api_sample"] = response_sample(ordered_query)
            if ordered_signal_failures:
                raise RuntimeError(f"ordered signal API failures: {ordered_signal_failures}")
            if accepted_signal_inputs != rapid_inputs:
                raise RuntimeError(
                    f"ordered signal accepted inputs {accepted_signal_inputs}, expected {rapid_inputs}"
                )
            if history_signal_order != accepted_signal_inputs:
                raise RuntimeError(
                    f"ordered signal history order {history_signal_order}, expected {accepted_signal_inputs}"
                )
            if ordered_responder_evidence["eligible_when_claimed"] is not True:
                raise RuntimeError(
                    f"ordered query responder eligibility was not proved: {ordered_responder_evidence}"
                )
            if ordered_query_result != sum(accepted_signal_inputs):
                raise RuntimeError(
                    f"ordered query returned {ordered_query_result}, expected {sum(accepted_signal_inputs)}"
                )
        except Exception as exc:  # noqa: BLE001 - retain partial order proof for focused findings.
            log_line(log_file, f"ordered delivery baseline probe failed: {type(exc).__name__}: {exc}")
            ordered_outputs["run_id"] = ordered_run_id
            ordered_outputs["probe_error"] = probe_error_payload(exc)
        finally:
            try:
                ordered_outputs["final_run_status"] = run_status(base_url, token, namespace, ordered_workflow_id)
            except Exception as exc:  # noqa: BLE001 - retain ordered evidence even if status readout fails.
                ordered_outputs["final_run_status_error"] = probe_error_payload(exc)
        scenario_results["ordered_signal_delivery"] = baseline_scenario_result(
            "ordered_signal_delivery",
            ordered_outputs,
        )
        generated_scenarios.append("ordered_signal_delivery")
        checkpoint_baseline_cells()

        dedup_outputs: dict[str, Any] = {
            "workflow_id": dedup_workflow_id,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        try:
            dedup_run_id = start_waiting_workflow(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                dedup_workflow_id,
                workflow_type,
                f"{dedup_workflow_id}-initial",
            )
            dedup_outputs["run_id"] = dedup_run_id
            duplicate_request_id = f"{dedup_workflow_id}-duplicate-key"
            duplicate_signal_responses = []
            for index in range(2):
                response = http_json(
                    base_url,
                    api_path("workflows", dedup_workflow_id, "signal", "increment"),
                    method="POST",
                    body={"input": [7], "request_id": duplicate_request_id},
                    token=token,
                    namespace=namespace,
                    timeout=30,
                )
                duplicate_signal_responses.append(response_sample(response))
                if int(response["status_code"]) >= 400:
                    raise RuntimeError(f"duplicate signal {index + 1} failed: {response}")

            duplicate_observations: list[int] = []
            duplicate_tasks: list[dict[str, Any]] = []
            duplicate_seen_signals: set[str] = set()
            collect_increment_signal_observations(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                duplicate_seen_signals,
                duplicate_observations,
                duplicate_tasks,
                f"{dedup_workflow_id}-after",
                "duplicate",
                2,
                log_file,
                poll_timeout=5,
                allow_exhausted_after_observation=True,
            )

            handler_observation_count = len([amount for amount in duplicate_observations if amount == 7])
            client_side_key_support = handler_observation_count == 1
            documented_contract = (
                "SignalQueryRuntimeContract dedup_contract_observation: the public control-plane signal "
                "request_id behaved as an idempotency key for duplicate signal calls"
                if client_side_key_support
                else (
                    "SignalQueryRuntimeContract dedup_contract_observation: no public signal "
                    "idempotency key is documented; repeated accepted control-plane signal calls "
                    "are delivered independently"
                    if handler_observation_count > 1
                    else "SignalQueryRuntimeContract dedup_contract_observation: duplicate accepted "
                    "signal calls were not observed by the external handler"
                )
            )
            dedup_outputs.update(
                {
                    "client_side_key_support": client_side_key_support,
                    "documented_contract": documented_contract,
                    "documented_contract_source": (
                        "SignalQueryRuntimeContract manifest scenario "
                        "dedup_contract_observation"
                    ),
                    "handler_observation_count": handler_observation_count,
                    "duplicate_signal_contract": "public control-plane repeated signal behavior",
                    "duplicate_signal_payload_shape": "positional input array",
                    "duplicate_request_id_used": duplicate_request_id,
                    "duplicate_signal_api_samples": duplicate_signal_responses,
                    "handler_observed_amounts": duplicate_observations,
                    "signal_tasks": duplicate_tasks,
                }
            )
            if handler_observation_count == 0:
                raise RuntimeError("duplicate signal probe did not observe any delivered increment signals")
        except Exception as exc:  # noqa: BLE001 - retain partial dedup proof for focused findings.
            log_line(log_file, f"dedup baseline probe failed: {type(exc).__name__}: {exc}")
            dedup_outputs["run_id"] = dedup_run_id
            dedup_outputs["probe_error"] = probe_error_payload(exc)
        scenario_results["dedup_contract_observation"] = baseline_scenario_result(
            "dedup_contract_observation",
            dedup_outputs,
        )
        generated_scenarios.append("dedup_contract_observation")
        checkpoint_baseline_cells()

        evidence = {
            "artifact_versions": versions,
            "scenario_results": scenario_results,
        }
        not_claimed_as_pass = (
            ([] if install_status == "pass" else ["published_artifact_install_only"])
            + ([] if python_sdk_status == "pass" else ["python_worker_cli_and_sdk_baseline"])
            + ([] if sdk_php_status == "pass" else ["php_worker_cli_and_sdk_baseline"])
            + (
                []
                if (
                    isinstance(python_worker_php_cross_result, dict)
                    and python_worker_php_cross_result.get("status") == "pass"
                )
                else ["python_worker_php_facing_and_cli_clients"]
            )
            + (
                []
                if (
                    isinstance(php_worker_python_cross_result, dict)
                    and php_worker_python_cross_result.get("status") == "pass"
                )
                else ["php_worker_python_and_cli_clients"]
            )
            + [
                scenario
                for scenario in (
                    "rust_worker_rust_php_python_clients",
                    "python_worker_rust_client",
                    "php_worker_rust_client",
                    "rust_query_error_and_immutability",
                    "rust_replayed_instance_state_query_after_cold_restart",
                )
                if scenario_results.get(scenario, {}).get("status") != "pass"
            ]
            + [
                scenario
                for scenario in (
                    "ordered_signal_delivery",
                    "dedup_contract_observation",
                    "unknown_signal_and_query_errors",
                )
                if scenario_results.get(scenario, {}).get("status") != "pass"
            ]
        )
        descriptor = {
            "file": log_file.name,
            "cell_checkpoint_file": baseline_checkpoint_path.name,
            "server_base_url": base_url,
            "worker_id": worker_id,
            "task_queue": task_queue,
            "workflow_ids": {
                "baseline": baseline_workflow_id,
                "ordered": ordered_workflow_id,
                "dedup": dedup_workflow_id,
            },
            "partial_baseline_observations": {
                "external_worker_server_control_plane_observation": server_baseline_outputs,
                "optional_public_client_error_samples": sorted(optional_unknown_outputs),
                "python_worker_cli_and_sdk_baseline": python_sdk_descriptor,
                "python_worker_php_facing_and_cli_clients": (
                    python_worker_php_cross_result
                    if python_worker_php_cross_result is not None
                    else {"skipped": "python_worker_or_php_client_unavailable"}
                ),
                "php_worker_cli_and_sdk_baseline": sdk_php_descriptor,
                "php_worker_python_and_cli_clients": (
                    php_worker_python_cross_result
                    if php_worker_python_cross_result is not None
                    else {"skipped": "php_worker_or_python_client_unavailable"}
                ),
                "rust_published_artifact_matrix": rust_matrix_descriptor,
                "install_probes": install_descriptors,
                "server_readiness": readiness_probe,
                "published_artifact_sources_observed": sorted(sources),
                "not_claimed_as_pass": not_claimed_as_pass,
            },
            "generated_scenarios": generated_scenarios,
        }
        try:
            waterline_evidence, waterline_descriptor = run_waterline_observer_probe(
                result_dir,
                evidence,
                server_topology=readiness_probe,
            )
        except Exception as exc:  # noqa: BLE001 - keep baseline evidence if the Waterline shard crashes.
            waterline_evidence = waterline_observer_setup_result(
                status="fail",
                reason=f"Waterline observer shard failed before producing evidence: {type(exc).__name__}: {exc}",
                blocker_kind="waterline_observer_probe_exception",
            )
            waterline_descriptor = {
                "error": f"{type(exc).__name__}: {exc}",
                "generated_scenarios": ["waterline_operator_visibility"],
            }
        if waterline_evidence is not None:
            evidence = merge_probe_evidence(evidence, waterline_evidence)
            generated_scenarios.append("waterline_operator_visibility")
        if waterline_descriptor is not None:
            descriptor["waterline_observer_probe"] = waterline_descriptor
        try:
            service_evidence, service_descriptor = run_waterline_service_probe(
                result_dir,
                evidence,
                server_topology=readiness_probe,
            )
        except Exception as exc:  # noqa: BLE001 - retain the embedded observer and sibling evidence.
            service_evidence = waterline_service_setup_result(
                status="fail",
                reason=f"Waterline service shard failed before producing evidence: {type(exc).__name__}: {exc}",
                blocker_kind="waterline_service_probe_exception",
            )
            service_descriptor = {
                "error": f"{type(exc).__name__}: {exc}",
                "generated_scenarios": [WATERLINE_SERVICE_SCENARIO],
            }
        if service_evidence is not None:
            evidence = merge_probe_evidence(evidence, service_evidence)
            generated_scenarios.append(WATERLINE_SERVICE_SCENARIO)
        if service_descriptor is not None:
            descriptor["waterline_service_probe"] = service_descriptor
        for waterline_scenario in ("waterline_operator_visibility", WATERLINE_SERVICE_SCENARIO):
            if evidence.get("scenario_results", {}).get(waterline_scenario, {}).get("status") != "pass":
                descriptor["partial_baseline_observations"]["not_claimed_as_pass"].append(
                    waterline_scenario
                )
        return evidence, descriptor
    except ServerReadinessTopologyError as exc:
        details = dict(exc.details)
        details.setdefault("kind", "server_readiness_topology")
        cleanup_commands = cleanup_commands_from_blocker(details)
        log_line(log_file, f"baseline readiness topology blocked: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
            "runner_blocker": details,
        }
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"baseline probe failed: {type(exc).__name__}: {exc}")
        return partial_evidence, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
            "cell_checkpoint_file": baseline_checkpoint_path.name,
            "generated_scenarios": generated_scenarios,
            "partial_cell_evidence_preserved": partial_evidence is not None,
        }
    finally:
        if heartbeat_guard is not None:
            heartbeat_guard.stop()
        cleanup_labeled_docker_runs(log_file)
        cleanup_commands_deterministically(cleanup_commands)
        if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
            remove_scratch_root(run_root)


def run_adversarial_probe(result_dir: Path, current_evidence: Any) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    run_root = Path(
        env_text("DW_SIGNALS_QUERIES_RUN_ROOT")
        or tempfile.mkdtemp(prefix="dw-signals-queries-adversarial.", dir=str(result_dir))
    )
    run_root.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "signals-queries-adversarial-probe.log"
    register_scratch_root(run_root, log_file)
    cleanup_commands: list[list[str]] = []

    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or "default"
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    base_url = env_text("DW_SIGNALS_QUERIES_SERVER_URL") or env_text("DURABLE_WORKFLOW_SERVER_URL")
    readiness_probe: dict[str, Any] | None = None
    heartbeat_guard: WorkerHeartbeatGuard | None = None

    try:
        if not isinstance(base_url, str) or base_url.strip() == "":
            base_url, cleanup_commands, readiness_probe = start_published_server(run_root, log_file)
        else:
            base_url = base_url.rstrip("/")
            readiness_probe = wait_for_ready(
                base_url,
                log_file,
                timeout_seconds=server_ready_timeout_seconds(30),
                diagnostics=configured_server_diagnostics(base_url),
            )

        server_install = server_install_entry(cleanup_commands)
        cli_bin, cli_install = install_cli(run_root, log_file)
        python_bin, python_install = ensure_python_sdk(run_root, log_file)
        install_entries = {
            "server": server_install,
            "cli": cli_install,
            "sdk-python": python_install,
        }

        suffix = hashlib.sha1(str(time.time()).encode("utf-8")).hexdigest()[:10]
        workflow_id = f"wf-sq-adversarial-{suffix}"
        task_queue = f"signals-queries-adversarial-{suffix}"
        worker_id = f"signals-queries-adversarial-worker-{suffix}"
        workflow_type = "conformance.counter"
        worker_process_started_at = now()

        register = http_json(
            base_url,
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-adversarial-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "process_metrics": {
                    "process_started_at": worker_process_started_at,
                },
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=token,
            namespace=namespace,
            worker=True,
            timeout=30,
        )
        if int(register["status_code"]) >= 400:
            raise RuntimeError(f"worker registration failed: {register}")

        heartbeat_guard = WorkerHeartbeatGuard(
            base_url,
            token,
            namespace,
            worker_id,
            log_file,
        )
        heartbeat_guard.start()
        if not heartbeat_guard.wait_until_eligible():
            raise RuntimeError(
                "adversarial worker heartbeat guard did not establish eligibility: "
                f"{heartbeat_guard.snapshot()}"
            )

        start = http_json(
            base_url,
            api_path("workflows"),
            method="POST",
            body={
                "workflow_id": workflow_id,
                "workflow_type": workflow_type,
                "task_queue": task_queue,
            },
            token=token,
            namespace=namespace,
            timeout=30,
        )
        if int(start["status_code"]) >= 400:
            raise RuntimeError(f"workflow start failed: {start}")
        run_id = str(start["body"]["run_id"])

        initial_poll = poll_workflow_task(
            base_url,
            token,
            namespace,
            worker_id,
            task_queue,
        )
        initial_task = task_from_poll(initial_poll, "adversarial initial state")
        initial_complete = complete_open_wait(
            base_url,
            token,
            namespace,
            initial_task,
            f"{workflow_id}-committed-state",
        )
        if int(initial_complete.get("status_code") or 0) >= 400:
            raise RuntimeError(f"adversarial initial state commit failed: {initial_complete}")

        committed_state = 0
        history_and_commands_before_rejected_requests = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            workflow_id,
            run_id,
        )

        invalid_signal = http_json(
            base_url,
            api_path("workflows", workflow_id, "signal", "increment"),
            method="POST",
            body={"input": {"amount": "bad"}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        invalid_query = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "count-at-least"),
            method="POST",
            body={"input": {"minimum": "bad"}},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        unknown_signal = http_json(
            base_url,
            api_path("workflows", workflow_id, "signal", "missing"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        query_not_found = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "missing"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        missing_workflow_signal = http_json(
            base_url,
            api_path("workflows", workflow_id + "-missing", "signal", "increment"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        missing_workflow_query = http_json(
            base_url,
            api_path("workflows", workflow_id + "-missing", "query", "state"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=30,
        )
        history_and_commands_after_rejected_requests = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            workflow_id,
            run_id,
        )

        holder: dict[str, Any] = {}
        responder_ready = threading.Event()
        responder = threading.Thread(
            target=answer_next_query_task,
            args=(
                base_url,
                token,
                namespace,
                worker_id,
                task_queue,
                committed_state,
                log_file,
                holder,
            ),
            kwargs={
                "ready_event": responder_ready,
                "capture_claim_eligibility": True,
            },
            daemon=True,
        )
        responder.start()
        if not responder_ready.wait(timeout=15):
            raise RuntimeError(
                "post-error query responder did not acknowledge a heartbeat before the recovery query"
            )
        post_error_query = http_json(
            base_url,
            api_path("workflows", workflow_id, "query", "state"),
            method="POST",
            body={},
            token=token,
            namespace=namespace,
            timeout=45,
        )
        responder.join(timeout=20)
        responder_timed_out = responder.is_alive()
        if responder_timed_out or holder.get("error"):
            raise RuntimeError(f"query responder failed: {holder.get('error', 'timeout')}")

        post_error_result = (
            post_error_query.get("body", {}).get("result")
            if isinstance(post_error_query.get("body"), dict)
            else None
        )
        claimed_query_task = holder.get("query_task")
        if not isinstance(claimed_query_task, dict):
            claimed_query_task = {}
        claim_eligibility = holder.get("worker_eligibility_when_claimed")
        if not isinstance(claim_eligibility, dict):
            claim_eligibility = {"eligible": False}
        completed_query = holder.get("complete")
        if not isinstance(completed_query, dict):
            completed_query = {}
        post_error_query_responder = {
            "worker_id": worker_id,
            "task_queue": task_queue,
            "process_started_at": worker_process_started_at,
            "heartbeat_guard": heartbeat_guard.snapshot(),
            "heartbeat_before_poll": holder.get("heartbeat"),
            "heartbeat_acknowledged_at": holder.get("heartbeat_acknowledged_at"),
            "query_poll_ready_at": holder.get("query_poll_ready_at"),
            "query_claimed_at": holder.get("query_handler_invoked_at"),
            "claim_eligibility": claim_eligibility,
            "claimed_query_task": {
                "query_task_id": claimed_query_task.get("query_task_id"),
                "query_task_attempt": claimed_query_task.get("query_task_attempt"),
                "workflow_id": claimed_query_task.get("workflow_id"),
                "run_id": claimed_query_task.get("run_id"),
                "query_name": claimed_query_task.get("query_name"),
                "task_queue": claimed_query_task.get("task_queue"),
                "lease_owner": claimed_query_task.get("lease_owner"),
            },
            "query_task_completion": response_sample(completed_query) if completed_query else None,
            "responder_error": holder.get("error"),
            "responder_timed_out": responder_timed_out,
        }
        post_error_query_responder["eligible_when_claimed"] = (
            claim_eligibility.get("eligible") is True
            and isinstance(claimed_query_task.get("query_task_id"), str)
            and claimed_query_task.get("workflow_id") == workflow_id
            and claimed_query_task.get("run_id") == run_id
            and claimed_query_task.get("query_name") == "state"
            and claimed_query_task.get("task_queue") == task_queue
            and claimed_query_task.get("lease_owner") == worker_id
            and isinstance(completed_query.get("status_code"), int)
            and int(completed_query["status_code"]) < 400
        )
        if post_error_query_responder["eligible_when_claimed"] is not True:
            raise RuntimeError(
                "post-error query responder eligibility was not proved: "
                f"{post_error_query_responder}"
            )
        if int(post_error_query.get("status_code") or 0) >= 400:
            raise RuntimeError(f"post-error recovery query failed: {post_error_query}")
        if post_error_result != committed_state:
            raise RuntimeError(
                f"post-error recovery query returned {post_error_result}, expected {committed_state}"
            )

        history_and_commands_after_recovery_query = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            workflow_id,
            run_id,
        )

        cli_invalid_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "increment",
                "--input",
                '["bad"]',
                "--output=json",
            ],
            log_file,
        )
        cli_invalid_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id,
                "count-at-least",
                "--input",
                '["bad"]',
                "--output=json",
            ],
            log_file,
        )
        cli_unknown_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id,
                "missing",
                "--output=json",
            ],
            log_file,
        )
        cli_unknown_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id,
                "missing",
                "--output=json",
            ],
            log_file,
        )
        cli_missing_workflow_signal = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:signal",
                workflow_id + "-missing",
                "increment",
                "--output=json",
            ],
            log_file,
        )
        cli_missing_workflow_query = cli_json_sample(
            cli_bin,
            base_url,
            token,
            namespace,
            [
                "workflow:query",
                workflow_id + "-missing",
                "state",
                "--output=json",
            ],
            log_file,
        )
        sdk_invalid_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "signal",
            "increment",
            log_file,
            args=["bad"],
        )
        sdk_invalid_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "query",
            "count-at-least",
            log_file,
            args=["bad"],
        )
        sdk_unknown_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "signal",
            "missing",
            log_file,
        )
        sdk_unknown_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id,
            "query",
            "missing",
            log_file,
        )
        sdk_missing_workflow_signal = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id + "-missing",
            "signal",
            "increment",
            log_file,
        )
        sdk_missing_workflow_query = sdk_error_sample(
            python_bin,
            base_url,
            token,
            namespace,
            workflow_id + "-missing",
            "query",
            "state",
            log_file,
        )

        history = http_json(
            base_url,
            api_path("workflows", workflow_id, "runs", run_id, "history") + "?page_size=1000",
            method="GET",
            token=token,
            namespace=namespace,
            timeout=30,
        )
        signal_count = count_signal_received(history, "increment")
        history_and_commands_after_all_requests = workflow_public_snapshot(
            base_url,
            token,
            namespace,
            workflow_id,
            run_id,
        )
        rejected_requests_and_recovery_appended_no_history = (
            snapshot_count_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_rejected_requests,
                "history_event_count",
            )
            and snapshot_count_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_recovery_query,
                "history_event_count",
            )
            and snapshot_count_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_all_requests,
                "history_event_count",
            )
        )
        expected_rejected_signal_audits = [
            rejected_signal_audit_spec(run_id, "increment", "invalid_signal_arguments"),
            rejected_signal_audit_spec(run_id, "missing", "unknown_signal"),
            rejected_signal_audit_spec(run_id, "increment", "invalid_signal_arguments"),
            rejected_signal_audit_spec(run_id, "missing", "unknown_signal"),
            rejected_signal_audit_spec(run_id, "increment", "invalid_signal_arguments"),
            rejected_signal_audit_spec(run_id, "missing", "unknown_signal"),
        ]
        rejected_audit_evidence = rejected_signal_audit_evidence(
            history_and_commands_before_rejected_requests,
            history_and_commands_after_all_requests,
            expected_rejected_signal_audits,
        )
        rejected_requests_created_no_executable_or_ready_work = (
            rejected_audit_evidence["exact_match"] is True
            and rejected_audit_evidence["executable_or_ready_command_count"] == 0
            and ready_or_leased_workflow_tasks_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_rejected_requests,
            )
            and ready_or_leased_workflow_tasks_unchanged(
                history_and_commands_after_rejected_requests,
                history_and_commands_after_recovery_query,
            )
            and ready_or_leased_workflow_tasks_unchanged(
                history_and_commands_after_recovery_query,
                history_and_commands_after_all_requests,
            )
        )
        rejected_handler_invocations = rejected_signal_handler_invocation_count(
            history_and_commands_before_rejected_requests,
            history_and_commands_after_all_requests,
        )
        rejected_requests_mutated_no_workflow_state = (
            workflow_state_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_rejected_requests,
            )
            and workflow_state_unchanged(
                history_and_commands_before_rejected_requests,
                history_and_commands_after_all_requests,
            )
        )
        query_state_mutations = 0 if post_error_result == committed_state else 1

        versions = {
            "server": artifact_version_value(artifact_versions, "server"),
            "cli": artifact_version_value(artifact_versions, "cli"),
            "sdk-python": artifact_version_value(artifact_versions, "sdk-python"),
            "sdk-php": artifact_version_value(artifact_versions, "sdk-php"),
            "waterline": artifact_version_value(artifact_versions, "waterline"),
        }
        sources = probe_artifact_sources(cleanup_commands, install_entries)
        replay_terminal_evidence, replay_terminal_descriptor = run_replay_terminal_probe(
            base_url,
            token,
            namespace,
            worker_id,
            task_queue,
            workflow_type,
            versions,
            sources,
            log_file,
        )

        malformed_outputs = {
            "invalid_signal_arguments": response_sample(invalid_signal),
            "invalid_query_arguments": response_sample(invalid_query),
            "invalid_signal_arguments_context": {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "signal_name": "increment",
                "field": "amount",
                "artifact_versions": versions,
                "artifact_sources": sources,
            },
            "invalid_query_arguments_context": {
                "workflow_id": workflow_id,
                "run_id": run_id,
                "query_name": "count-at-least",
                "field": "minimum",
                "artifact_versions": versions,
                "artifact_sources": sources,
            },
            "signal_handler_invocation_count_after_invalid_payload": signal_count,
            "query_state_mutation_count_after_invalid_payload": query_state_mutations,
            "post_error_valid_query_result": post_error_result,
            "cli_invalid_signal_arguments_sample": cli_invalid_signal,
            "cli_invalid_query_arguments_sample": cli_invalid_query,
            "sdk_python_invalid_signal_arguments_sample": sdk_invalid_signal,
            "sdk_python_invalid_query_arguments_sample": sdk_invalid_query,
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        unknown_outputs = {
            "workflow_id": workflow_id,
            "run_id": run_id,
            "worker_id": worker_id,
            "task_queue": task_queue,
            "unknown_signal": response_sample(unknown_signal),
            "missing_workflow_signal": response_sample(missing_workflow_signal),
            "missing_workflow_query": response_sample(missing_workflow_query),
            "query_not_found": response_sample(query_not_found),
            "rejected_unknown_query": response_sample(query_not_found),
            "cli_unknown_signal_sample": cli_unknown_signal,
            "cli_unknown_query_sample": cli_unknown_query,
            "cli_missing_workflow_signal_sample": cli_missing_workflow_signal,
            "cli_missing_workflow_query_sample": cli_missing_workflow_query,
            "sdk_python_unknown_signal_sample": sdk_unknown_signal,
            "sdk_python_unknown_query_sample": sdk_unknown_query,
            "sdk_python_missing_workflow_signal_sample": sdk_missing_workflow_signal,
            "sdk_python_missing_workflow_query_sample": sdk_missing_workflow_query,
            "known_query_after_unknown_errors": post_error_query,
            "known_query_after_unknown_expected": committed_state,
            "known_query_after_unknown_result": post_error_result,
            "post_error_query_responder": post_error_query_responder,
            "history_and_commands_before_rejected_requests": history_and_commands_before_rejected_requests,
            "history_and_commands_after_rejected_requests": history_and_commands_after_rejected_requests,
            "history_and_commands_after_recovery_query": history_and_commands_after_recovery_query,
            "history_and_commands_after_all_requests": history_and_commands_after_all_requests,
            "rejected_signal_audit_rows": rejected_audit_evidence,
            "rejected_signal_audit_rows_match_expected": rejected_audit_evidence["exact_match"],
            "rejected_requests_and_recovery_appended_no_history": (
                rejected_requests_and_recovery_appended_no_history
            ),
            "rejected_requests_created_no_executable_or_ready_work": (
                rejected_requests_created_no_executable_or_ready_work
            ),
            "rejected_signal_handler_invocation_count": rejected_handler_invocations,
            "rejected_requests_mutated_no_workflow_state": (
                rejected_requests_mutated_no_workflow_state
            ),
            "published_artifact_versions": versions,
            "artifact_sources": sources,
        }
        scenario_results = {
            "unknown_signal_and_query_errors": {
                "status": "pass",
                "observed_outputs": unknown_outputs,
            },
            "malformed_signal_and_query_payloads": {
                "status": "pass",
                "observed_outputs": malformed_outputs,
            },
        }
        generated_scenarios = [
            "unknown_signal_and_query_errors",
            "malformed_signal_and_query_payloads",
        ]
        if replay_terminal_evidence is not None:
            replay_results = replay_terminal_evidence.get("scenario_results")
            if isinstance(replay_results, dict):
                scenario_results.update(replay_results)
                if replay_terminal_descriptor is not None:
                    generated = replay_terminal_descriptor.get("generated_scenarios")
                    if isinstance(generated, list):
                        generated_scenarios.extend(
                            str(scenario) for scenario in generated if isinstance(scenario, str)
                        )

        evidence = {
            "artifact_versions": versions,
            "scenario_results": scenario_results,
        }
        descriptor = {
            "file": log_file.name,
            "workflow_id": workflow_id,
            "run_id": run_id,
            "server_base_url": base_url,
            "generated_scenarios": generated_scenarios,
            "server_readiness": readiness_probe,
            "replay_terminal_probe": replay_terminal_descriptor,
        }
        return evidence, descriptor
    except ServerReadinessTopologyError as exc:
        details = dict(exc.details)
        details.setdefault("kind", "server_readiness_topology")
        cleanup_commands = cleanup_commands_from_blocker(details)
        log_line(log_file, f"adversarial readiness topology blocked: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
            "runner_blocker": details,
        }
    except Exception as exc:  # noqa: BLE001 - failed probe becomes uncovered evidence.
        log_line(log_file, f"adversarial probe failed: {type(exc).__name__}: {exc}")
        return None, {
            "file": log_file.name,
            "error": f"{type(exc).__name__}: {exc}",
        }
    finally:
        if heartbeat_guard is not None:
            heartbeat_guard.stop()
        cleanup_labeled_docker_runs(log_file)
        cleanup_commands_deterministically(cleanup_commands)
        if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
            remove_scratch_root(run_root)


def merge_probe_evidence(base: Any, probe: dict[str, Any]) -> dict[str, Any]:
    if not isinstance(base, dict):
        return dict(probe)

    merged = dict(base)
    for field in ("artifact_versions", "artifactVersions", "published_artifact_versions", "publishedArtifactVersions"):
        if field not in merged and field in probe:
            merged[field] = probe[field]

    probe_results = probe.get("scenario_results")
    if not isinstance(probe_results, dict):
        return merged

    existing = merged.get("scenario_results")
    if isinstance(existing, dict):
        existing = dict(existing)
        existing.update(probe_results)
        merged["scenario_results"] = existing
    elif isinstance(existing, list):
        existing = list(existing)
        for scenario_id, scenario_result in probe_results.items():
            item = dict(scenario_result)
            item.setdefault("scenario_id", scenario_id)
            replaced = False
            for index, existing_item in enumerate(existing):
                if not isinstance(existing_item, dict):
                    continue
                existing_scenario = (
                    existing_item.get("scenario_id")
                    or existing_item.get("scenario")
                    or existing_item.get("id")
                )
                if existing_scenario != scenario_id:
                    continue
                existing[index] = item
                replaced = True
                break
            if replaced:
                continue
            existing.append(item)
        merged["scenario_results"] = existing
    else:
        merged["scenario_results"] = probe_results

    return merged


def without_scenario_evidence(evidence: Any, scenario_id: str) -> tuple[Any, bool]:
    if not isinstance(evidence, dict):
        return evidence, False

    retained = dict(evidence)
    removed = retained.pop(scenario_id, None) is not None
    for field in ("scenario_results", "scenarioResults"):
        scenario_results = retained.get(field)
        if isinstance(scenario_results, dict):
            retained_results = dict(scenario_results)
            if retained_results.pop(scenario_id, None) is not None:
                removed = True
            retained[field] = retained_results
            continue

        if isinstance(scenario_results, list):
            retained_results = []
            for item in scenario_results:
                item_scenario = None
                if isinstance(item, dict):
                    item_scenario = (
                        item.get("scenario_id")
                        or item.get("scenario")
                        or item.get("id")
                    )
                if item_scenario == scenario_id:
                    removed = True
                    continue
                retained_results.append(item)
            retained[field] = retained_results

    for section in (
        "replay_timing",
        "terminal_run_behavior",
        "adversarial_errors",
        "waterline_observer_comparison",
    ):
        section_value = retained.get(section)
        if not isinstance(section_value, dict):
            continue
        retained_section = dict(section_value)
        if retained_section.pop(scenario_id, None) is not None:
            removed = True
        retained[section] = retained_section

    return retained, removed


def waterline_service_probe_requires_fresh_evidence() -> bool:
    return env_flag("DW_SIGNALS_QUERIES_RUN_WATERLINE_SERVICE_PROBE", True)


def reset_current_run_files(result_dir: Path) -> list[str]:
    generated_files = (
        "executed-distribution-identities.json",
        "pins.json",
        "run-metadata.json",
        "signals-queries-result.json",
        "signals-queries-record.json",
        "signals-queries-findings.json",
        "signals-queries-php-cli-signal-result.json",
        "signals-queries-php-cli-signal-record.json",
        "signals-queries-rust-cell-results.json",
        "signals-queries-baseline-cell-results.json",
        "signals-queries-baseline-probe.log",
        "signals-queries-adversarial-probe.log",
        "signals-queries-replay-terminal-probe.log",
        "waterline-signals-queries-service.log",
        "waterline-signals-queries-observer.log",
    )
    removed: list[str] = []
    for filename in generated_files:
        path = result_dir / filename
        if not path.is_file() and not path.is_symlink():
            continue
        path.unlink()
        removed.append(filename)
    return removed


def string_from_evidence(value: Any, keys: tuple[str, ...]) -> str | None:
    for key in keys:
        found = evidence_value(value, key)
        if isinstance(found, str) and found.strip():
            return found.strip()
    return None


def integer_from_evidence(value: Any, keys: tuple[str, ...]) -> int | None:
    for key in keys:
        found = evidence_value(value, key)
        if isinstance(found, bool):
            continue
        if isinstance(found, int):
            return found
        if isinstance(found, str) and found.strip().lstrip("-").isdigit():
            return int(found.strip())
    return None


def list_of_ints(value: Any) -> list[int]:
    if not isinstance(value, list):
        return []

    values: list[int] = []
    for item in value:
        if isinstance(item, bool) or not isinstance(item, int):
            return []
        values.append(item)
    return values


def waterline_status_bucket(status: str | None) -> str:
    normalized = (status or "").strip().lower()
    if normalized in {"completed", "failed", "cancelled", "canceled", "terminated", "timed_out"}:
        return "terminal"
    return "running"


def waterline_observer_public_evidence(current_evidence: Any) -> dict[str, Any] | None:
    if not isinstance(current_evidence, dict):
        return None

    ordered_candidate = scenario_evidence_candidate_from(current_evidence, "ordered_signal_delivery")
    if ordered_candidate is None:
        return None

    ordered = scenario_observed_outputs(ordered_candidate)
    if not has_required_evidence("ordered_signal_delivery", ordered):
        return None

    workflow_id = string_from_evidence(ordered, ("workflow_id", "workflow_instance_id", "instance_id"))
    run_id = string_from_evidence(ordered, ("run_id", "workflow_run_id", "selected_run_id"))
    if workflow_id is None or run_id is None:
        return None

    counter = integer_from_evidence(ordered, ("queried_total", "ten_signal_ordered_delivery_total", "delivered_signal_total"))
    signal_inputs = list_of_ints(ordered.get("history_signal_order")) or list_of_ints(ordered.get("accepted_signal_inputs"))
    if counter is None and signal_inputs:
        counter = sum(signal_inputs)
    if counter is None:
        return None

    public_evidence = dict(current_evidence)
    public_evidence["workflow_instance_id"] = workflow_id
    public_evidence["workflow_run_id"] = run_id
    public_evidence["run_status"] = string_from_evidence(ordered, ("final_run_status", "run_status")) or "waiting"
    public_evidence["query_name"] = "state"
    public_evidence["current_counter"] = counter

    return public_evidence


def waterline_observer_setup_result(
    *,
    status: str,
    reason: str,
    blocker_kind: str,
    failed_command: list[str] | None = None,
    command_result: subprocess.CompletedProcess[str] | None = None,
) -> dict[str, Any]:
    setup_failure: dict[str, Any] = {
        "blocker_kind": blocker_kind,
        "reason": reason,
    }
    if failed_command is not None and command_result is not None:
        setup_failure["command"] = command_summary(failed_command, command_result)

    current_evidence: dict[str, Any] = {
        "published_artifact_evidence_present": True,
        "blocker_kind": blocker_kind,
        "reason": reason,
    }
    if "command" in setup_failure:
        current_evidence["command"] = setup_failure["command"]

    finding = {
        "id": "signal_query_waterline_observer_probe_unavailable",
        "type": "signal_query_waterline_observer_probe_unavailable",
        "scenario_id": "waterline_operator_visibility",
        "owner": "conformance_harness" if status == "runner_blocked" else "waterline",
        "title": "Signals/queries Waterline observer probe could not produce comparison evidence",
        "current_evidence": current_evidence,
        "acceptance": [
            "install the pinned published Waterline artifact",
            "run waterline:signals-queries-conformance against the selected signal/query run",
            "import a passing waterline_operator_visibility scenario result",
        ],
    }
    if status == "runner_blocked":
        finding["blocker_kind"] = blocker_kind

    return {
        "artifact_versions": dict(artifact_versions),
        "scenario_results": {
            "waterline_operator_visibility": {
                "scenario_id": "waterline_operator_visibility",
                "status": status,
                "observed_outputs": {
                    "artifact_versions": dict(artifact_versions),
                    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
                    "setup_failure": setup_failure,
                },
                "linked_findings": [finding],
            },
        },
    }


WATERLINE_SERVICE_SCENARIO = "waterline_service_operator_visibility"
WATERLINE_SERVICE_IMAGE_PATTERN = (
    r"^docker\.io/durableworkflow/waterline@sha256:[0-9a-f]{64}$"
)
WATERLINE_SERVICE_SOURCE_LABELS = (
    "org.opencontainers.image.revision",
    "dev.durable-workflow.release.tag",
)
WATERLINE_SERVICE_QUERY_COMPLETION_BUDGET_SECONDS = 110.0
WATERLINE_SERVICE_QUERY_POLL_TIMEOUT_SECONDS = 90.0
WATERLINE_SERVICE_QUERY_COMPLETION_REQUEST_TIMEOUT_SECONDS = 15.0
WATERLINE_SERVICE_QUERY_COMPLETION_SETTLE_SECONDS = 1.0


class WaterlineServiceProbeError(RuntimeError):
    def __init__(self, message: str, *, blocker_kind: str, details: dict[str, Any] | None = None) -> None:
        super().__init__(message)
        self.blocker_kind = blocker_kind
        self.details = details or {}


def create_waterline_service_query_target(
    responder_inputs: dict[str, str],
    log_file: Path,
    *,
    suffix: str | None = None,
) -> dict[str, Any]:
    identity_suffix = suffix or hashlib.sha1(
        f"{os.getpid()}-{time.time_ns()}-waterline-service".encode("utf-8")
    ).hexdigest()[:10]
    worker_id = f"signals-queries-waterline-service-worker-{identity_suffix}"
    task_queue = f"signals-queries-waterline-service-{identity_suffix}"
    workflow_id = f"wf-sq-waterline-service-{identity_suffix}"
    workflow_type = "conformance.counter"
    process_started_at = now()
    worker_registration_started_at = now()
    try:
        registration = http_json(
            responder_inputs["base_url"],
            api_path("worker", "register"),
            method="POST",
            body={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "runtime": "external",
                "sdk_version": "signals-queries-waterline-service-probe",
                "supported_workflow_types": [workflow_type],
                "capabilities": ["query_tasks"],
                "process_metrics": {
                    "process_started_at": process_started_at,
                },
                "workflow_command_contracts": {
                    workflow_type: command_contract(),
                },
            },
            token=responder_inputs["token"],
            namespace=responder_inputs["namespace"],
            worker=True,
            timeout=30,
        )
    except (TimeoutError, socket.timeout) as exc:
        raise WaterlineServiceProbeError(
            "The designated Waterline service query responder registration timed out.",
            blocker_kind="waterline_service_query_worker_registration_timeout",
            details={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "worker_registration_started_at": worker_registration_started_at,
                "worker_registration_failed_at": now(),
                "registration_error": f"{type(exc).__name__}: {exc}",
            },
        ) from exc
    except (urllib.error.URLError, OSError) as exc:
        raise WaterlineServiceProbeError(
            "The designated Waterline service query responder registration transport failed.",
            blocker_kind="waterline_service_query_worker_registration_transport_failed",
            details={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "worker_registration_started_at": worker_registration_started_at,
                "worker_registration_failed_at": now(),
                "registration_error": f"{type(exc).__name__}: {exc}",
            },
        ) from exc
    worker_registration_finished_at = now()
    registration_status = registration.get("status_code")
    if (
        not isinstance(registration_status, int)
        or not 200 <= registration_status < 300
    ):
        raise WaterlineServiceProbeError(
            "The designated Waterline service query responder could not register.",
            blocker_kind="waterline_service_query_worker_registration_failed",
            details={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "worker_registration": response_sample(registration),
                "worker_registration_started_at": worker_registration_started_at,
                "worker_registration_finished_at": worker_registration_finished_at,
            },
        )

    heartbeat_guard = WorkerHeartbeatGuard(
        responder_inputs["base_url"],
        responder_inputs["token"],
        responder_inputs["namespace"],
        worker_id,
        log_file,
    )
    heartbeat_guard.start()
    if not heartbeat_guard.wait_until_eligible():
        liveness = heartbeat_guard.snapshot()
        heartbeat_guard.stop()
        raise WaterlineServiceProbeError(
            "The designated Waterline service query responder did not become live.",
            blocker_kind="waterline_service_query_worker_unavailable",
            details={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "responder_liveness": liveness,
            },
        )

    workflow_started_at = now()
    try:
        run_id = start_waiting_workflow(
            responder_inputs["base_url"],
            responder_inputs["token"],
            responder_inputs["namespace"],
            worker_id,
            task_queue,
            workflow_id,
            workflow_type,
            f"{workflow_id}-initial",
        )
    except Exception as exc:  # noqa: BLE001 - target setup failure becomes bounded runner evidence.
        liveness = heartbeat_guard.snapshot()
        heartbeat_guard.stop()
        raise WaterlineServiceProbeError(
            "The isolated Waterline service query workflow could not start.",
            blocker_kind="waterline_service_query_workflow_start_failed",
            details={
                "worker_id": worker_id,
                "task_queue": task_queue,
                "workflow_id": workflow_id,
                "responder_liveness": liveness,
                "workflow_started_at": workflow_started_at,
                "workflow_start_error": f"{type(exc).__name__}: {exc}",
            },
        ) from exc

    return {
        "responder_inputs": {
            **responder_inputs,
            "worker_id": worker_id,
            "task_queue": task_queue,
        },
        "workflow_id": workflow_id,
        "run_id": run_id,
        "workflow_type": workflow_type,
        "process_started_at": process_started_at,
        "worker_registration": response_sample(registration),
        "worker_registration_started_at": worker_registration_started_at,
        "worker_registration_finished_at": worker_registration_finished_at,
        "workflow_started_at": workflow_started_at,
        "workflow_ready_at": now(),
        "heartbeat_guard": heartbeat_guard,
    }


def waterline_service_query_target_evidence(target: dict[str, Any]) -> dict[str, Any]:
    responder_inputs = target["responder_inputs"]
    heartbeat_guard = target["heartbeat_guard"]
    return {
        "workflow_id": target["workflow_id"],
        "run_id": target["run_id"],
        "workflow_type": target["workflow_type"],
        "worker_id": responder_inputs["worker_id"],
        "task_queue": responder_inputs["task_queue"],
        "process_started_at": target["process_started_at"],
        "worker_registration": target["worker_registration"],
        "worker_registration_started_at": target["worker_registration_started_at"],
        "worker_registration_finished_at": target["worker_registration_finished_at"],
        "workflow_started_at": target["workflow_started_at"],
        "workflow_ready_at": target["workflow_ready_at"],
        "responder_liveness": heartbeat_guard.snapshot(),
        "captured_at": now(),
    }


def await_query_responder_completion(
    responder: threading.Thread,
    holder: dict[str, Any],
    done_event: threading.Event,
    completion_deadline_monotonic: float,
) -> dict[str, Any]:
    wait_started_at = now()
    alive_before_wait = responder.is_alive()
    remaining = max(0.0, completion_deadline_monotonic - time.monotonic())
    finished_within_budget = done_event.wait(timeout=remaining)
    responder.join(timeout=0)
    alive_after_wait = responder.is_alive()

    completion = holder.get("complete")
    completion_status_code = (
        completion.get("status_code")
        if isinstance(completion, dict)
        else None
    )
    completion_state = holder.get("completion_state")
    if not finished_within_budget:
        completion_state = "timeout"
    elif completion_state not in {
        "successful",
        "timeout",
        "transport_failure",
        "non_success_response",
        "responder_failure",
    }:
        if isinstance(completion_status_code, int) and 200 <= completion_status_code < 300:
            completion_state = "successful"
        elif isinstance(completion_status_code, int):
            completion_state = "non_success_response"
        elif holder.get("error"):
            completion_state = "responder_failure"
        else:
            completion_state = "incomplete"

    responder_error = holder.get("error")
    if not finished_within_budget and not responder_error:
        responder_error = "query responder did not finish within its completion budget"
    claim_binding = holder.get("query_claim_binding")
    completion_binding = holder.get("query_completion_binding")
    authoritative_completion = (
        completion_state == "successful"
        and isinstance(claim_binding, dict)
        and claim_binding.get("matches_expected") is True
        and isinstance(completion_binding, dict)
        and completion_binding.get("authoritative") is True
    )

    return {
        "completion_state": completion_state,
        "completion_response": (
            response_sample(completion)
            if isinstance(completion, dict)
            else None
        ),
        "completion_status_code": completion_status_code,
        "responder_error": responder_error,
        "responder_alive_before_wait": alive_before_wait,
        "responder_alive_after_wait": alive_after_wait,
        "finished_within_budget": finished_within_budget,
        "heartbeat_status_code": (
            holder.get("heartbeat", {}).get("status_code")
            if isinstance(holder.get("heartbeat"), dict)
            else None
        ),
        "heartbeat_acknowledged_at": holder.get("heartbeat_acknowledged_at"),
        "responder_started_at": holder.get("responder_started_at"),
        "responder_ready_at": holder.get("query_poll_ready_at"),
        "query_claimed_at": holder.get("query_handler_invoked_at"),
        "completion_request_started_at": holder.get("completion_request_started_at"),
        "completion_recorded_at": holder.get("query_completed_at"),
        "responder_finished_at": holder.get("responder_finished_at"),
        "completion_budget_seconds": holder.get("completion_budget_seconds"),
        "completion_budget_deadline_at": holder.get("completion_budget_deadline_at"),
        "claim_binding": claim_binding,
        "completion_binding": completion_binding,
        "authoritative_completion": authoritative_completion,
        "responder_liveness_at_claim": holder.get("worker_eligibility_when_claimed"),
        "wait_started_at": wait_started_at,
        "wait_finished_at": now(),
    }


def waterline_service_query_evidence(
    *,
    workflow_id: str,
    run_id: str,
    query_name: str,
    expected_result: int,
    query: dict[str, Any] | None,
    query_started_at: str,
    query_finished_at: str,
    responder_inputs: dict[str, str],
    holder: dict[str, Any],
    responder_wait: dict[str, Any],
    designated_target: dict[str, Any] | None = None,
) -> dict[str, Any]:
    query_body = (
        query.get("body")
        if isinstance(query, dict) and isinstance(query.get("body"), dict)
        else {}
    )
    claimed_task = holder.get("query_task")
    claimed_identity = {
        "workflow_id": (
            claimed_task.get("workflow_id")
            if isinstance(claimed_task, dict)
            else None
        ),
        "run_id": (
            claimed_task.get("run_id")
            if isinstance(claimed_task, dict)
            else None
        ),
        "query_name": (
            claimed_task.get("query_name")
            if isinstance(claimed_task, dict)
            else None
        ),
        "query_task_id": (
            claimed_task.get("query_task_id")
            if isinstance(claimed_task, dict)
            else None
        ),
        "query_task_attempt": (
            claimed_task.get("query_task_attempt")
            if isinstance(claimed_task, dict)
            else None
        ),
        "worker_id": (
            claimed_task.get("lease_owner")
            if isinstance(claimed_task, dict)
            else None
        ),
        "task_queue": (
            claimed_task.get("task_queue")
            if isinstance(claimed_task, dict)
            else None
        ),
    }
    return {
        "captured_at": now(),
        "expected_query_identity": {
            "workflow_id": workflow_id,
            "run_id": run_id,
            "query_name": query_name,
            "worker_id": responder_inputs["worker_id"],
            "task_queue": responder_inputs["task_queue"],
        },
        "query_identity": claimed_identity,
        "query_status_code": query.get("status_code") if isinstance(query, dict) else None,
        "query_result": query_body.get("result"),
        "expected_result": expected_result,
        "query_started_at": query_started_at,
        "query_finished_at": query_finished_at,
        "designated_target": designated_target,
        "poll_status_code": (
            holder.get("poll", {}).get("status_code")
            if isinstance(holder.get("poll"), dict)
            else None
        ),
        **responder_wait,
    }


def waterline_service_image_reference() -> str:
    image = env_text("DW_WATERLINE_SERVICE_IMAGE")
    if image is None:
        raise WaterlineServiceProbeError(
            "DW_WATERLINE_SERVICE_IMAGE is required and must identify the published Waterline manifest by digest.",
            blocker_kind="waterline_service_image_missing",
        )
    if re.fullmatch(WATERLINE_SERVICE_IMAGE_PATTERN, image) is None:
        raise WaterlineServiceProbeError(
            "DW_WATERLINE_SERVICE_IMAGE must be "
            "docker.io/durableworkflow/waterline@sha256:<64 lowercase hex characters>; "
            "tag-only and local image references are not conformance artifacts.",
            blocker_kind="waterline_service_image_not_immutable",
            details={"image_reference": image},
        )
    return image


def waterline_service_manifest_digest(image: str) -> str:
    if re.fullmatch(WATERLINE_SERVICE_IMAGE_PATTERN, image) is None:
        raise WaterlineServiceProbeError(
            "Waterline service manifest digest requested for a non-published image reference.",
            blocker_kind="waterline_service_image_not_immutable",
            details={"image_reference": image},
        )
    return image.rsplit("@", 1)[1]


def waterline_service_repo_digest_matches(candidate: Any, expected_digest: str) -> bool:
    if not isinstance(candidate, str) or "@" not in candidate:
        return False
    repository, digest = candidate.rsplit("@", 1)
    repository = repository.removeprefix("docker.io/").removeprefix("index.docker.io/")
    return repository == "durableworkflow/waterline" and digest.lower() == expected_digest


def inspect_waterline_service_image(
    image: str,
    waterline_version: str,
    log_file: Path,
) -> dict[str, Any]:
    pull_command = ["docker", "pull", image]
    pull = run_command(pull_command, log_file=log_file, timeout=300)
    if pull.returncode != 0:
        raise WaterlineServiceProbeError(
            "Docker could not pull the exact published Waterline service manifest.",
            blocker_kind="waterline_service_image_pull_failed",
            details={"command": command_summary(pull_command, pull)},
        )

    labels_command = ["docker", "image", "inspect", "--format", "{{json .Config.Labels}}", image]
    labels_result = run_command(labels_command, log_file=log_file, timeout=60)
    digests_command = ["docker", "image", "inspect", "--format", "{{json .RepoDigests}}", image]
    digests_result = run_command(digests_command, log_file=log_file, timeout=60)
    if labels_result.returncode != 0 or digests_result.returncode != 0:
        failed_command = labels_command if labels_result.returncode != 0 else digests_command
        failed_result = labels_result if labels_result.returncode != 0 else digests_result
        raise WaterlineServiceProbeError(
            "Docker could not inspect the pulled Waterline service image.",
            blocker_kind="waterline_service_image_inspect_failed",
            details={"command": command_summary(failed_command, failed_result)},
        )

    try:
        labels = json.loads(labels_result.stdout) if labels_result.stdout.strip() else {}
        repo_digests = json.loads(digests_result.stdout) if digests_result.stdout.strip() else []
    except json.JSONDecodeError as exc:
        raise WaterlineServiceProbeError(
            "Docker returned malformed Waterline image metadata.",
            blocker_kind="waterline_service_image_metadata_invalid",
        ) from exc
    if not isinstance(labels, dict) or not isinstance(repo_digests, list):
        raise WaterlineServiceProbeError(
            "Docker returned an unexpected Waterline image metadata shape.",
            blocker_kind="waterline_service_image_metadata_invalid",
        )

    expected_digest = waterline_service_manifest_digest(image)
    if not any(waterline_service_repo_digest_matches(candidate, expected_digest) for candidate in repo_digests):
        raise WaterlineServiceProbeError(
            "The pulled Waterline image does not retain the requested top-level manifest digest.",
            blocker_kind="waterline_service_manifest_digest_mismatch",
            details={"expected_manifest_digest": expected_digest},
        )

    revision = labels.get("org.opencontainers.image.revision")
    release_tag = labels.get("dev.durable-workflow.release.tag")
    if not isinstance(revision, str) or re.fullmatch(r"[0-9a-fA-F]{7,64}", revision.strip()) is None:
        raise WaterlineServiceProbeError(
            "The Waterline service image is missing a valid org.opencontainers.image.revision label.",
            blocker_kind="waterline_service_source_revision_missing",
        )
    if not isinstance(release_tag, str) or release_tag.strip() != waterline_version:
        raise WaterlineServiceProbeError(
            "The Waterline service image release label does not match DW_WATERLINE_VERSION.",
            blocker_kind="waterline_service_release_tag_mismatch",
            details={
                "expected_release_tag": waterline_version,
                "observed_release_tag": release_tag,
            },
        )

    return {
        "image_reference": image,
        "manifest_digest": expected_digest,
        "source_revision_labels": {
            "oci_revision": revision.strip(),
            "release_tag": release_tag.strip(),
            "labels": {
                label: str(labels[label])
                for label in WATERLINE_SERVICE_SOURCE_LABELS
            },
        },
    }


def waterline_service_container_command(
    *,
    image: str,
    container_name: str,
    network: str,
    server_endpoint: str,
    namespace: str,
    bind_host: str,
) -> list[str]:
    global DOCKER_RUN_COMMAND_BUILT
    DOCKER_RUN_COMMAND_BUILT = True
    normalized_bind_host = normalize_host(bind_host)
    if is_wildcard_host(normalized_bind_host):
        raise WaterlineServiceProbeError(
            "The Waterline service probe cannot publish its unauthenticated HTTP port on a wildcard interface.",
            blocker_kind="waterline_service_bind_host_insecure",
            details={"bind_host": bind_host},
        )
    publish_binding = (
        f"[{normalized_bind_host}]::8080"
        if ":" in normalized_bind_host
        else f"{normalized_bind_host}::8080"
    )
    return [
        "docker",
        "run",
        "--rm",
        "--detach",
        "--name",
        container_name,
        "--label",
        DOCKER_RUN_RESOURCE_LABEL,
        "--network",
        network,
        "--publish",
        publish_binding,
        "--env",
        "WATERLINE_SERVER_TOKEN",
        "--env",
        f"WATERLINE_SERVER_ENDPOINT={server_endpoint}",
        "--env",
        f"WATERLINE_NAMESPACE={namespace}",
        "--env",
        "WATERLINE_ACCESS_MODE=operator",
        "--env",
        "WATERLINE_ALLOW_UNAUTHENTICATED=true",
        image,
    ]


def waterline_service_bind_host() -> str:
    return (
        env_text("DW_SIGNALS_QUERIES_WATERLINE_SERVICE_BIND_HOST")
        or env_text("DW_SIGNALS_QUERIES_DOCKER_HOST_GATEWAY")
        or "127.0.0.1"
    )


def waterline_service_connect_host() -> str:
    return env_text("DW_SIGNALS_QUERIES_WATERLINE_SERVICE_CONNECT_HOST") or "127.0.0.1"


def waterline_service_host_urls(
    container_name: str,
    log_file: Path,
    *,
    env: dict[str, str],
) -> list[str]:
    command = ["docker", "port", container_name, "8080/tcp"]
    completed = run_command(command, log_file=log_file, timeout=30)
    if completed.returncode != 0:
        raise WaterlineServiceProbeError(
            "Docker did not publish the Waterline service HTTP port.",
            blocker_kind="waterline_service_port_unavailable",
            details={"command": command_summary(command, completed)},
        )
    candidates = server_url_candidates_from_published_port(
        completed.stdout,
        fallback_port=0,
        preferred_host=waterline_service_connect_host(),
        log_file=log_file,
        env=env,
    )
    if candidates:
        return candidates
    raise WaterlineServiceProbeError(
        "Docker returned no usable Waterline service HTTP port.",
        blocker_kind="waterline_service_port_unavailable",
        details={"published_port_output": completed.stdout[:DIAGNOSTIC_OUTPUT_LIMIT]},
    )


def waterline_service_http_json(
    base_url: str,
    path: str,
    *,
    method: str = "GET",
    body: Any = None,
    timeout: float = 30.0,
) -> dict[str, Any]:
    data = json.dumps(body).encode("utf-8") if body is not None else None
    request = urllib.request.Request(
        url_join(base_url, path),
        data=data,
        headers={"Accept": "application/json", "Content-Type": "application/json"},
        method=method,
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
            return {
                "status_code": response.status,
                "body": json.loads(raw) if raw.strip() else {},
            }
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            response_body = json.loads(raw) if raw.strip() else {}
        except json.JSONDecodeError:
            response_body = {"raw": raw[:DIAGNOSTIC_OUTPUT_LIMIT]}
        return {"status_code": exc.code, "body": response_body}


def wait_for_waterline_service(
    base_url: str | list[str],
    container_name: str,
    log_file: Path,
    timeout_seconds: float = 90.0,
) -> dict[str, Any]:
    candidates = ordered_unique([base_url] if isinstance(base_url, str) else base_url)
    if not candidates:
        raise WaterlineServiceProbeError(
            "The Waterline service probe has no runner-reachable endpoint candidates.",
            blocker_kind="waterline_service_port_unavailable",
        )
    deadline = time.time() + timeout_seconds
    attempts = 0
    last_error: str | None = None
    candidate_errors: dict[str, str] = {}
    while time.time() < deadline:
        if not docker_container_running(container_name, log_file):
            diagnostics = capture_command_summary(
                ["docker", "logs", container_name],
                log_file=log_file,
                timeout=30,
            )
            raise WaterlineServiceProbeError(
                "The Waterline service container exited before /up became ready.",
                blocker_kind="waterline_service_container_exited",
                details={
                    "container_logs": diagnostics,
                    "service_url_candidates": candidates,
                    "candidate_readiness_errors": candidate_errors,
                },
            )
        for candidate in candidates:
            if time.time() >= deadline:
                break
            attempts += 1
            try:
                request_timeout = min(2, max(0.2, deadline - time.time()))
                with urllib.request.urlopen(url_join(candidate, "/up"), timeout=request_timeout) as response:
                    if 200 <= response.status < 300:
                        return {
                            "path": "/up",
                            "status_code": response.status,
                            "attempts": attempts,
                            "ready_at": now(),
                            "base_url": candidate,
                            "service_url_candidates": candidates,
                            "candidate_readiness_errors": candidate_errors,
                        }
                    last_error = f"HTTPStatus: {response.status}"
            except Exception as exc:  # noqa: BLE001 - retain bounded errors for every topology candidate.
                last_error = f"{type(exc).__name__}: {exc}"
            candidate_errors[candidate] = last_error
        time.sleep(min(1, max(0, deadline - time.time())))
    raise WaterlineServiceProbeError(
        "The Waterline service /up endpoint did not become ready.",
        blocker_kind="waterline_service_readiness_timeout",
        details={
            "attempts": attempts,
            "last_error": last_error,
            "service_url_candidates": candidates,
            "candidate_readiness_errors": candidate_errors,
        },
    )


def waterline_service_setup_result(
    *,
    status: str,
    reason: str,
    blocker_kind: str,
    details: dict[str, Any] | None = None,
) -> dict[str, Any]:
    failure_captured_at = now()
    setup_failure = {
        "blocker_kind": blocker_kind,
        "reason": reason,
        **(details or {}),
        "captured_at": failure_captured_at,
    }
    finding = {
        "id": "signal_query_waterline_service_probe_unavailable",
        "type": "signal_query_waterline_service_probe_unavailable",
        "scenario_id": WATERLINE_SERVICE_SCENARIO,
        "owner": (
            "conformance_harness"
            if status == "runner_blocked" or blocker_kind in {
                "waterline_service_image_missing",
                "waterline_service_image_not_immutable",
                "waterline_service_topology_unavailable",
            }
            else "waterline"
        ),
        "title": "Published Waterline service image did not complete the signals/queries operator shard",
        "current_evidence": setup_failure,
        "acceptance": [
            "supply the immutable published Waterline service manifest reference",
            "start it on the exact published server network in service mode",
            "exercise selected-run reads, query, and signal actions through its PHP SDK backend",
        ],
    }
    if status == "runner_blocked":
        finding["blocker_kind"] = blocker_kind
    return {
        "artifact_versions": dict(artifact_versions),
        "scenario_results": {
            WATERLINE_SERVICE_SCENARIO: {
                "scenario_id": WATERLINE_SERVICE_SCENARIO,
                "status": status,
                "observed_outputs": {
                    "artifact_versions": dict(artifact_versions),
                    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
                    "captured_at": setup_failure.get("captured_at", failure_captured_at),
                    "setup_failure": setup_failure,
                },
                "linked_findings": [finding],
            },
        },
    }


def run_waterline_service_probe(
    result_dir: Path,
    current_evidence: Any,
    server_topology: dict[str, Any] | None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    probe_started_at = now()
    if not env_flag("DW_SIGNALS_QUERIES_RUN_WATERLINE_SERVICE_PROBE", True):
        return None, {"skipped": "disabled_by_env"}
    if not command_available("docker"):
        return waterline_service_setup_result(
            status="runner_blocked",
            reason="Docker is required to execute the published Waterline service image.",
            blocker_kind="docker_unavailable",
        ), {"error": "docker_unavailable"}

    public_evidence = waterline_observer_public_evidence(current_evidence)
    responder_inputs = (
        waterline_query_responder_inputs(public_evidence)
        if isinstance(public_evidence, dict)
        else None
    )
    if public_evidence is None or responder_inputs is None:
        return waterline_service_setup_result(
            status="fail",
            reason="The Waterline service shard requires the selected ordered signal/query run and responder identity.",
            blocker_kind="ordered_signal_delivery_evidence_unavailable",
        ), {"error": "ordered_signal_delivery_evidence_unavailable"}

    storage = waterline_storage_from_topology(server_topology)
    network = storage.get("docker_network") if isinstance(storage.get("docker_network"), str) else None
    if network is None or not isinstance(server_topology, dict) or not server_topology.get("compose_project"):
        return waterline_service_setup_result(
            status="fail",
            reason="The Waterline service image requires the published server Compose network.",
            blocker_kind="waterline_service_topology_unavailable",
        ), {"error": "waterline_service_topology_unavailable"}

    waterline_version = artifact_version_value(artifact_versions, "waterline")
    if is_placeholder_version(waterline_version):
        return waterline_service_setup_result(
            status="fail",
            reason="DW_WATERLINE_VERSION must be an exact published version for the service image shard.",
            blocker_kind="missing_exact_artifact_version",
        ), {"error": "missing_exact_artifact_version"}

    log_file = result_dir / "waterline-signals-queries-service.log"
    container_name = f"dw-waterline-service-{os.getpid()}-{time.time_ns()}"
    container_registered = False
    image_metadata: dict[str, Any] | None = None
    query_target: dict[str, Any] | None = None
    try:
        image = waterline_service_image_reference()
        image_metadata = inspect_waterline_service_image(image, waterline_version, log_file)
        service_env = os.environ.copy()
        service_env["WATERLINE_SERVER_TOKEN"] = responder_inputs["token"]
        service_bind_host = waterline_service_bind_host()
        service_connect_host = waterline_service_connect_host()
        run_command_value = waterline_service_container_command(
            image=image,
            container_name=container_name,
            network=network,
            server_endpoint="http://server:8080",
            namespace=responder_inputs["namespace"],
            bind_host=service_bind_host,
        )
        register_container(container_name, log_file)
        container_registered = True
        started = run_command(run_command_value, log_file=log_file, env=service_env, timeout=120)
        if started.returncode != 0:
            raise WaterlineServiceProbeError(
                "Docker could not start the exact Waterline service image.",
                blocker_kind="waterline_service_container_start_failed",
                details={"command": command_summary(run_command_value, started)},
            )

        service_url_candidates = waterline_service_host_urls(
            container_name,
            log_file,
            env=service_env,
        )
        readiness = wait_for_waterline_service(
            service_url_candidates,
            container_name,
            log_file,
        )
        service_url = str(readiness["base_url"])
        record_distribution_digest(
            "waterline-service",
            waterline_version,
            "manifest",
            str(image_metadata["manifest_digest"]),
        )

        query_target = create_waterline_service_query_target(
            responder_inputs,
            log_file,
        )
        responder_inputs = query_target["responder_inputs"]
        designated_target = waterline_service_query_target_evidence(query_target)
        workflow_id = str(query_target["workflow_id"])
        run_id = str(query_target["run_id"])
        query_name = str(public_evidence["query_name"])
        expected_counter = 0
        service_run_status = run_status(
            responder_inputs["base_url"],
            responder_inputs["token"],
            responder_inputs["namespace"],
            workflow_id,
        )
        detail_path = api_path("instances", workflow_id, "runs", run_id).replace("/api/", "/waterline/api/")
        query_path = detail_path + "/queries/" + urllib.parse.quote(query_name, safe="._:-")
        signal_path = detail_path + "/signals/increment"
        list_path = "/waterline/api/flows/running"

        running_list = waterline_service_http_json(service_url, list_path, timeout=30)
        detail = waterline_service_http_json(service_url, detail_path, timeout=30)
        list_body = running_list.get("body") if isinstance(running_list.get("body"), dict) else {}
        detail_body = detail.get("body") if isinstance(detail.get("body"), dict) else {}
        backend = detail_body.get("backend") if isinstance(detail_body.get("backend"), dict) else {}
        running_items = list_body.get("data") if isinstance(list_body.get("data"), list) else []
        selected_in_list = any(
            isinstance(item, dict)
            and item.get("instance_id") == workflow_id
            and item.get("run_id") == run_id
            for item in running_items
        )
        detail_valid = (
            detail.get("status_code") == 200
            and detail_body.get("instance_id") == workflow_id
            and detail_body.get("run_id") == run_id
            and detail_body.get("engine_source") == "service"
            and detail_body.get("can_query") is True
            and detail_body.get("can_signal") is True
            and backend.get("mode") == "service"
            and backend.get("transport") == "durable-workflow/sdk"
            and backend.get("namespace") == responder_inputs["namespace"]
            and backend.get("access_mode") == "operator"
        )
        if running_list.get("status_code") != 200 or not selected_in_list or not detail_valid:
            raise WaterlineServiceProbeError(
                "Waterline service-mode list or selected-run detail did not expose the candidate run through the PHP SDK backend.",
                blocker_kind="waterline_service_reads_failed",
                details={
                    "running_list_status_code": running_list.get("status_code"),
                    "selected_run_in_list": selected_in_list,
                    "selected_run_detail_status_code": detail.get("status_code"),
                    "selected_run_detail_valid": detail_valid,
                },
            )

        responder_holder: dict[str, Any] = {}
        responder_ready = threading.Event()
        responder_done = threading.Event()
        completion_deadline_monotonic = (
            time.monotonic() + WATERLINE_SERVICE_QUERY_COMPLETION_BUDGET_SECONDS
        )
        responder = threading.Thread(
            target=answer_next_query_task,
            args=(
                responder_inputs["base_url"],
                responder_inputs["token"],
                responder_inputs["namespace"],
                responder_inputs["worker_id"],
                responder_inputs["task_queue"],
                expected_counter,
                log_file,
                responder_holder,
            ),
            kwargs={
                "poll_timeout": WATERLINE_SERVICE_QUERY_POLL_TIMEOUT_SECONDS,
                "ready_event": responder_ready,
                "completion_deadline_monotonic": completion_deadline_monotonic,
                "completion_request_timeout": (
                    WATERLINE_SERVICE_QUERY_COMPLETION_REQUEST_TIMEOUT_SECONDS
                ),
                "completion_settle_seconds": (
                    WATERLINE_SERVICE_QUERY_COMPLETION_SETTLE_SECONDS
                ),
                "completion_done_event": responder_done,
                "capture_claim_eligibility": True,
                "expected_query_identity": {
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "query_name": query_name,
                    "task_queue": responder_inputs["task_queue"],
                    "worker_id": responder_inputs["worker_id"],
                },
            },
            daemon=True,
        )
        responder.start()
        if not responder_ready.wait(
            timeout=min(
                15.0,
                max(0.0, completion_deadline_monotonic - time.monotonic()),
            )
        ):
            responder_wait = await_query_responder_completion(
                responder,
                responder_holder,
                responder_done,
                completion_deadline_monotonic,
            )
            unavailable_at = now()
            raise WaterlineServiceProbeError(
                "The service-mode query responder did not become eligible before the Waterline query action.",
                blocker_kind="waterline_service_query_responder_unavailable",
                details={
                    "service_query_evidence": waterline_service_query_evidence(
                        workflow_id=workflow_id,
                        run_id=run_id,
                        query_name=query_name,
                        expected_result=expected_counter,
                        query=None,
                        query_started_at=unavailable_at,
                        query_finished_at=unavailable_at,
                        responder_inputs=responder_inputs,
                        holder=responder_holder,
                        responder_wait=responder_wait,
                        designated_target=designated_target,
                    ),
                },
            )

        query_started_at = now()
        try:
            query = waterline_service_http_json(
                service_url,
                query_path,
                method="POST",
                body={"arguments": []},
                timeout=min(
                    90.0,
                    max(0.2, completion_deadline_monotonic - time.monotonic()),
                ),
            )
        except Exception as exc:
            query_finished_at = now()
            responder_wait = await_query_responder_completion(
                responder,
                responder_holder,
                responder_done,
                completion_deadline_monotonic,
            )
            raise WaterlineServiceProbeError(
                "Waterline service-mode query action transport failed.",
                blocker_kind="waterline_service_query_transport_failed",
                details={
                    "service_query_evidence": {
                        **waterline_service_query_evidence(
                            workflow_id=workflow_id,
                            run_id=run_id,
                            query_name=query_name,
                            expected_result=expected_counter,
                            query=None,
                            query_started_at=query_started_at,
                            query_finished_at=query_finished_at,
                            responder_inputs=responder_inputs,
                            holder=responder_holder,
                            responder_wait=responder_wait,
                            designated_target=designated_target,
                        ),
                        "query_transport_error": f"{type(exc).__name__}: {exc}",
                    },
                },
            ) from exc

        query_finished_at = now()
        responder_wait = await_query_responder_completion(
            responder,
            responder_holder,
            responder_done,
            completion_deadline_monotonic,
        )
        query_body = query.get("body") if isinstance(query.get("body"), dict) else {}
        service_query_evidence = waterline_service_query_evidence(
            workflow_id=workflow_id,
            run_id=run_id,
            query_name=query_name,
            expected_result=expected_counter,
            query=query,
            query_started_at=query_started_at,
            query_finished_at=query_finished_at,
            responder_inputs=responder_inputs,
            holder=responder_holder,
            responder_wait=responder_wait,
            designated_target=designated_target,
        )
        query_valid = (
            query.get("status_code") == 200
            and query_body.get("query") == query_name
            and query_body.get("result") == expected_counter
            and responder_wait["completion_state"] == "successful"
            and responder_wait["finished_within_budget"] is True
            and responder_wait["completion_status_code"] is not None
            and 200 <= int(responder_wait["completion_status_code"]) < 300
            and responder_wait["authoritative_completion"] is True
            and isinstance(responder_wait["responder_liveness_at_claim"], dict)
            and responder_wait["responder_liveness_at_claim"].get("eligible") is True
        )
        if not query_valid:
            raise WaterlineServiceProbeError(
                "Waterline service-mode query action did not match the public client observation.",
                blocker_kind="waterline_service_query_action_failed",
                details={
                    "service_query_evidence": service_query_evidence,
                },
            )

        signal = waterline_service_http_json(
            service_url,
            signal_path,
            method="POST",
            body={"arguments": [0]},
            timeout=30,
        )
        signal_body = signal.get("body") if isinstance(signal.get("body"), dict) else {}
        if (
            not isinstance(signal.get("status_code"), int)
            or int(signal["status_code"]) >= 300
            or str(signal_body.get("command_status") or "").lower() not in {"accepted", "completed"}
        ):
            raise WaterlineServiceProbeError(
                "Waterline service-mode signal action was not accepted through the PHP SDK backend.",
                blocker_kind="waterline_service_signal_action_failed",
                details={
                    "signal_status_code": signal.get("status_code"),
                    "signal_reason": signal_body.get("reason"),
                    "signal_command_status": signal_body.get("command_status"),
                },
            )

        observed = {
            "artifact_versions": dict(artifact_versions),
            "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
            "captured_at": now(),
            "probe_started_at": probe_started_at,
            "distribution_identity": "waterline-service",
            "image_reference": image_metadata["image_reference"],
            "manifest_digest": image_metadata["manifest_digest"],
            "source_revision_labels": image_metadata["source_revision_labels"],
            "service_mode": {
                "backend": "service",
                "transport": "durable-workflow/sdk",
                "server_endpoint": "http://server:8080",
                "namespace": responder_inputs["namespace"],
                "access_mode": "operator",
                "docker_network": network,
            },
            "host_topology": {
                "bind_host": service_bind_host,
                "connect_host": service_connect_host,
                "service_url_candidates": service_url_candidates,
                "effective_host_endpoint": service_url,
            },
            "api_paths": {
                "up": "/up",
                "running_runs": list_path,
                "selected_run_detail": detail_path,
                "selected_run_query_action": query_path,
                "selected_run_signal_action": signal_path,
            },
            "api_captures": {
                "up": readiness,
                "running_runs": {
                    "status_code": running_list.get("status_code"),
                    "selected_run_present": selected_in_list,
                },
                "selected_run_detail": {
                    "status_code": detail.get("status_code"),
                    "workflow_id": detail_body.get("instance_id"),
                    "run_id": detail_body.get("run_id"),
                    "status": detail_body.get("status"),
                    "engine_source": detail_body.get("engine_source"),
                    "backend": backend,
                    "can_query": detail_body.get("can_query"),
                    "can_signal": detail_body.get("can_signal"),
                },
                "selected_run_query_action": {
                    "status_code": query.get("status_code"),
                    "query": query_body.get("query"),
                    "result": query_body.get("result"),
                    "target_scope": query_body.get("target_scope"),
                },
                "selected_run_signal_action": {
                    "status_code": signal.get("status_code"),
                    "signal": "increment",
                    "arguments": [0],
                    "command_status": signal_body.get("command_status"),
                },
            },
            "comparison": {
                "run_identity_matches_public_clients": True,
                "counter_state_matches_public_clients": True,
                "service_mode_uses_public_php_sdk": True,
                "server_observation": {
                    "workflow_id": workflow_id,
                    "run_id": run_id,
                    "status": service_run_status,
                    "counter": expected_counter,
                },
                "waterline_service_observation": {
                    "workflow_id": detail_body.get("instance_id"),
                    "run_id": detail_body.get("run_id"),
                    "status": detail_body.get("status"),
                    "counter": query_body.get("result"),
                },
                "baseline_reference": {
                    "workflow_id": public_evidence["workflow_instance_id"],
                    "run_id": public_evidence["workflow_run_id"],
                    "status": public_evidence["run_status"],
                    "counter": public_evidence["current_counter"],
                },
            },
            "query_responder": service_query_evidence,
        }
        return {
            "artifact_versions": dict(artifact_versions),
            "scenario_results": {
                WATERLINE_SERVICE_SCENARIO: {
                    "scenario_id": WATERLINE_SERVICE_SCENARIO,
                    "status": "pass",
                    "observed_outputs": observed,
                },
            },
        }, {
            "log_file": log_file.name,
            "generated_scenarios": [WATERLINE_SERVICE_SCENARIO],
            "distribution_identity": "waterline-service",
            "image_reference": image_metadata["image_reference"],
            "manifest_digest": image_metadata["manifest_digest"],
            "source_revision_labels": image_metadata["source_revision_labels"],
            "workflow_id": workflow_id,
            "run_id": run_id,
            "worker_id": responder_inputs["worker_id"],
            "task_queue": responder_inputs["task_queue"],
            "docker_network": network,
            "bind_host": service_bind_host,
            "connect_host": service_connect_host,
            "service_url_candidates": service_url_candidates,
            "effective_host_endpoint": service_url,
        }
    except WaterlineServiceProbeError as exc:
        log_line(log_file, f"Waterline service probe failed: {exc.blocker_kind}: {exc}")
        failure_details = dict(exc.details)
        failure_details.setdefault("probe_started_at", probe_started_at)
        failure_details["failure_captured_at"] = now()
        if image_metadata is not None:
            failure_details.setdefault("image_reference", image_metadata["image_reference"])
            failure_details.setdefault("manifest_digest", image_metadata["manifest_digest"])
            failure_details.setdefault(
                "source_revision_labels",
                image_metadata["source_revision_labels"],
            )
        if query_target is not None:
            failure_details.setdefault(
                "designated_query_target",
                waterline_service_query_target_evidence(query_target),
            )
        return waterline_service_setup_result(
            status="fail",
            reason=str(exc),
            blocker_kind=exc.blocker_kind,
            details=failure_details,
        ), {
            "error": exc.blocker_kind,
            "reason": str(exc),
            "details": failure_details,
            "log_file": log_file.name,
            "generated_scenarios": [WATERLINE_SERVICE_SCENARIO],
        }
    except Exception as exc:  # noqa: BLE001 - preserve immutable evidence for unexpected shard failures.
        blocker_kind = "waterline_service_probe_exception"
        log_line(log_file, f"Waterline service probe failed: {blocker_kind}: {type(exc).__name__}: {exc}")
        failure_details = {
            "probe_started_at": probe_started_at,
            "failure_captured_at": now(),
            "probe_error": f"{type(exc).__name__}: {exc}",
        }
        if image_metadata is not None:
            failure_details.update({
                "image_reference": image_metadata["image_reference"],
                "manifest_digest": image_metadata["manifest_digest"],
                "source_revision_labels": image_metadata["source_revision_labels"],
            })
        if query_target is not None:
            failure_details["designated_query_target"] = (
                waterline_service_query_target_evidence(query_target)
            )
        return waterline_service_setup_result(
            status="fail",
            reason="The Waterline service shard failed before producing complete evidence.",
            blocker_kind=blocker_kind,
            details=failure_details,
        ), {
            "error": blocker_kind,
            "reason": failure_details["probe_error"],
            "details": failure_details,
            "log_file": log_file.name,
            "generated_scenarios": [WATERLINE_SERVICE_SCENARIO],
        }
    finally:
        if query_target is not None:
            query_target["heartbeat_guard"].stop()
        if container_registered:
            cleanup_container(container_name, log_file)
        cleanup_labeled_docker_runs(log_file)


WATERLINE_WORKFLOW_STORAGE_ENV_KEYS = (
    "WATERLINE_WORKFLOW_STORAGE_CONNECTION",
    "WORKFLOW_STORAGE_CONNECTION",
    "DW_STORAGE_CONNECTION",
    "WATERLINE_WORKFLOW_DB_CONNECTION",
    "WATERLINE_WORKFLOW_DB_DRIVER",
    "WATERLINE_WORKFLOW_DB_HOST",
    "WATERLINE_WORKFLOW_DB_PORT",
    "WATERLINE_WORKFLOW_DB_DATABASE",
    "WATERLINE_WORKFLOW_DB_USERNAME",
    "WATERLINE_WORKFLOW_DB_PASSWORD",
    "WATERLINE_WORKFLOW_DB_SOCKET",
    "WORKFLOW_DB_CONNECTION",
    "WORKFLOW_DB_DRIVER",
    "WORKFLOW_DB_HOST",
    "WORKFLOW_DB_PORT",
    "WORKFLOW_DB_DATABASE",
    "WORKFLOW_DB_USERNAME",
    "WORKFLOW_DB_PASSWORD",
    "WORKFLOW_DB_SOCKET",
    "DW_WV_WATERLINE_DB_CONNECTION",
    "DW_WV_WATERLINE_DB_DRIVER",
    "DW_WV_WATERLINE_DB_HOST",
    "DW_WV_WATERLINE_DB_PORT",
    "DW_WV_WATERLINE_DB_DATABASE",
    "DW_WV_WATERLINE_DB_USERNAME",
    "DW_WV_WATERLINE_DB_PASSWORD",
    "DW_WV_WATERLINE_DB_SOCKET",
    "DW_WATERLINE_DB_CONNECTION",
    "DW_WATERLINE_DB_DRIVER",
    "DW_WATERLINE_DB_HOST",
    "DW_WATERLINE_DB_PORT",
    "DW_WATERLINE_DB_DATABASE",
    "DW_WATERLINE_DB_USERNAME",
    "DW_WATERLINE_DB_PASSWORD",
    "DW_WATERLINE_DB_SOCKET",
    "WORKFLOW_V2_TASK_DISPATCH_MODE",
    "DW_V2_TASK_DISPATCH_MODE",
)


def redacted_environment(env: dict[str, str]) -> dict[str, str]:
    redacted: dict[str, str] = {}
    for key, value in env.items():
        if "PASSWORD" in key or "TOKEN" in key or "SECRET" in key:
            redacted[key] = "<redacted>"
        else:
            redacted[key] = value
    return redacted


def waterline_storage_from_topology(server_topology: dict[str, Any] | None) -> dict[str, Any]:
    compose_project = None
    if isinstance(server_topology, dict):
        candidate = server_topology.get("compose_project")
        if isinstance(candidate, str) and candidate.strip():
            compose_project = candidate.strip()

    if compose_project:
        env = {
            "WATERLINE_WORKFLOW_STORAGE_CONNECTION": "waterline_workflow",
            "WATERLINE_WORKFLOW_DB_CONNECTION": "mysql",
            "WATERLINE_WORKFLOW_DB_DRIVER": "mysql",
            "WATERLINE_WORKFLOW_DB_HOST": "mysql",
            "WATERLINE_WORKFLOW_DB_PORT": "3306",
            "WATERLINE_WORKFLOW_DB_DATABASE": env_text("DB_DATABASE") or "durable_workflow",
            "WATERLINE_WORKFLOW_DB_USERNAME": env_text("DB_USERNAME") or "workflow",
            "WATERLINE_WORKFLOW_DB_PASSWORD": env_text("DB_PASSWORD") or "workflow",
            "WORKFLOW_V2_TASK_DISPATCH_MODE": env_text("WORKFLOW_V2_TASK_DISPATCH_MODE") or "database",
            "DW_V2_TASK_DISPATCH_MODE": env_text("DW_V2_TASK_DISPATCH_MODE") or "database",
        }
        return {
            "env": env,
            "docker_network": f"{compose_project}_default",
            "source": "published_server_compose_workflow_storage",
            "redacted_env": redacted_environment(env),
        }

    env = {
        key: value
        for key in WATERLINE_WORKFLOW_STORAGE_ENV_KEYS
        if (value := os.environ.get(key)) is not None and (value != "" or key.endswith("_PASSWORD"))
    }
    if env:
        return {
            "env": env,
            "docker_network": env_text("DW_SIGNALS_QUERIES_WATERLINE_DOCKER_NETWORK"),
            "source": "worker_environment_workflow_storage",
            "redacted_env": redacted_environment(env),
        }

    return {
        "env": {},
        "docker_network": None,
        "source": "unavailable",
        "redacted_env": {},
    }


def docker_run_for_project(
    project_dir: Path,
    composer_cache_dir: Path,
    command: list[str],
    *,
    extra_env: dict[str, str] | None = None,
    network: str | None = None,
    include_app_env: bool = True,
    image: str | None = None,
    entrypoint: str | None = None,
) -> list[str]:
    docker_command = [
        "docker",
        "run",
        "--rm",
        *docker_run_resource_options(),
    ]
    if entrypoint is not None:
        docker_command.extend(["--entrypoint", entrypoint])
    if network:
        docker_command.extend(["--network", network])
    else:
        docker_command.extend(["--add-host", "host.docker.internal:host-gateway"])

    docker_command.extend([
        "-v",
        docker_volume_spec(project_dir),
        "-v",
        docker_volume_spec(composer_cache_dir, "/tmp/dw-composer-cache"),
        "-w",
        "/app",
        "-e",
        "COMPOSER_CACHE_DIR=/tmp/dw-composer-cache",
    ])
    if include_app_env:
        docker_command.extend([
            "-e",
            "APP_ENV=production",
            "-e",
            "APP_DEBUG=false",
            "-e",
            "DB_CONNECTION=sqlite",
            "-e",
            "DB_DATABASE=/app/database/database.sqlite",
            "-e",
            "QUEUE_CONNECTION=database",
            "-e",
            "CACHE_STORE=array",
            "-e",
            "SESSION_DRIVER=array",
            "-e",
            "WATERLINE_ENGINE_SOURCE=v2",
            "-e",
            "WATERLINE_ALLOW_UNAUTHENTICATED=true",
        ])
    for key, value in sorted((extra_env or {}).items()):
        docker_command.extend(["-e", f"{key}={value}"])

    docker_command.extend([
        image or sdk_php_docker_image(),
        *command,
    ])
    return docker_command


def waterline_create_project_command(
    project_dir: Path,
    composer_cache_dir: Path,
    network: str | None = None,
) -> list[str]:
    return docker_run_for_project(
        project_dir,
        composer_cache_dir,
        ["composer", "create-project", "laravel/laravel", ".", "--no-interaction", "--no-progress", "--prefer-dist"],
        network=network,
        include_app_env=False,
        image=waterline_php_docker_image(),
        entrypoint="",
    )


def waterline_observer_already_reported(current_evidence: Any) -> bool:
    candidate = scenario_evidence_candidate_from(current_evidence, "waterline_operator_visibility")
    if candidate is None:
        return False

    return isinstance(candidate.get("status"), str) and candidate["status"].strip() != ""


def waterline_query_responder_inputs(public_evidence: dict[str, Any]) -> dict[str, str] | None:
    ordered_candidate = scenario_evidence_candidate_from(public_evidence, "ordered_signal_delivery")
    if ordered_candidate is None:
        return None

    ordered = scenario_observed_outputs(ordered_candidate)
    base_url = (
        env_text("DW_SIGNALS_QUERIES_SERVER_URL")
        or env_text("DURABLE_WORKFLOW_SERVER_URL")
        or string_from_evidence(ordered, ("server_base_url", "server_url", "base_url"))
    )
    token = (
        env_text("DW_SIGNALS_QUERIES_AUTH_TOKEN")
        or env_text("DURABLE_WORKFLOW_AUTH_TOKEN")
        or env_text("DW_AUTH_TOKEN")
        or "dev-token"
    )
    namespace = (
        env_text("DW_SIGNALS_QUERIES_NAMESPACE")
        or env_text("DURABLE_WORKFLOW_NAMESPACE")
        or string_from_evidence(ordered, ("namespace",))
        or "default"
    )
    worker_id = string_from_evidence(ordered, ("worker_id",))
    task_queue = string_from_evidence(ordered, ("task_queue",))
    if base_url is None or worker_id is None or task_queue is None:
        return None

    return {
        "base_url": base_url.rstrip("/"),
        "token": token,
        "namespace": namespace,
        "worker_id": worker_id,
        "task_queue": task_queue,
    }


def _run_waterline_observer_probe(
    result_dir: Path,
    composer_cache_dir: Path,
    current_evidence: Any,
    server_topology: dict[str, Any] | None = None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    if not env_flag("DW_SIGNALS_QUERIES_RUN_WATERLINE_OBSERVER_PROBE", True):
        return None, {"skipped": "disabled_by_env"}

    if waterline_observer_already_reported(current_evidence):
        return None, {"skipped": "waterline_operator_visibility_already_reported"}

    public_evidence = waterline_observer_public_evidence(current_evidence)
    if public_evidence is None:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "Waterline observer comparison requires ordered_signal_delivery evidence "
                "identifying the workflow run and public counter state."
            ),
            blocker_kind="ordered_signal_delivery_evidence_unavailable",
        ), {"error": "ordered_signal_delivery_evidence_unavailable"}

    if not command_available("docker"):
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason="Docker is required to install and run the published Waterline artifact.",
            blocker_kind="docker_unavailable",
        ), {"error": "docker_unavailable"}

    sdk_php_version = artifact_version_value(artifact_versions, "sdk-php")
    workflow_version = artifact_version_value(artifact_versions, "workflow")
    waterline_version = artifact_version_value(artifact_versions, "waterline")
    if (
        is_placeholder_version(sdk_php_version)
        or is_placeholder_version(workflow_version)
        or is_placeholder_version(waterline_version)
    ):
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason=(
                "Exact PHP SDK, workflow, and waterline versions are required before "
                "installing the published Waterline observer shard."
            ),
            blocker_kind="missing_exact_artifact_version",
        ), {"error": "missing_exact_artifact_version"}

    storage = waterline_storage_from_topology(server_topology)
    waterline_env = storage["env"] if isinstance(storage.get("env"), dict) else {}
    waterline_network = storage.get("docker_network") if isinstance(storage.get("docker_network"), str) else None
    if not waterline_env:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "The runner did not expose workflow storage credentials for the published "
                "Waterline artifact, so the selected-run detail and query routes cannot be "
                "exercised against the observed signal/query workflow."
            ),
            blocker_kind="waterline_workflow_storage_unavailable",
        ), {"error": "waterline_workflow_storage_unavailable"}

    waterline_root = result_dir / "waterline-signals-queries-observer"
    waterline_root.mkdir(parents=True, exist_ok=True)
    composer_cache_dir.mkdir(parents=True, exist_ok=True)
    log_file = result_dir / "waterline-signals-queries-observer.log"
    create_command = waterline_create_project_command(
        waterline_root,
        composer_cache_dir,
        waterline_network,
    )
    create = run_command(
        create_command,
        log_file=log_file,
        timeout=300,
    )
    if create.returncode != 0:
        return waterline_observer_setup_result(
            status="runner_blocked",
            reason="Laravel app creation failed before Waterline observer shard execution.",
            blocker_kind="waterline_create_project",
            failed_command=create_command,
            command_result=create,
        ), {"error": "waterline_create_project", "log_file": log_file.name}

    termination_ready_file = env_text("DW_SIGNALS_QUERIES_CLEANUP_TERMINATION_READY_FILE")
    if termination_ready_file is not None:
        checkpoint = Path(termination_ready_file)
        checkpoint.parent.mkdir(parents=True, exist_ok=True)
        checkpoint.write_text(DOCKER_RUN_RESOURCE_LABEL + "\n", encoding="utf-8")
        log_line(log_file, f"cleanup termination regression ready at {checkpoint}")
        while checkpoint.exists():
            time.sleep(0.1)

    (waterline_root / "database").mkdir(parents=True, exist_ok=True)
    (waterline_root / "database" / "database.sqlite").touch()
    conformance_dir = waterline_root / "conformance"
    conformance_dir.mkdir(parents=True, exist_ok=True)

    public_evidence_path = conformance_dir / "public-evidence.json"
    waterline_report_path = conformance_dir / "waterline-signals-queries-result.json"
    write_json(public_evidence_path, public_evidence)

    require_command = docker_run_for_project(
        waterline_root,
        composer_cache_dir,
        [
            "composer",
            "require",
            "--no-interaction",
            "--no-progress",
            "--prefer-dist",
            f"durable-workflow/waterline:{waterline_version}@beta",
            f"durable-workflow/workflow:{workflow_version}@beta",
            f"durable-workflow/sdk:{sdk_php_version}@beta",
        ],
        extra_env=waterline_env,
        network=waterline_network,
        image=waterline_php_docker_image(),
        entrypoint="",
    )
    require = run_command(
        require_command,
        log_file=log_file,
        timeout=420,
    )
    if require.returncode != 0:
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "Composer could not install pinned Packagist packages "
                f"durable-workflow/waterline:{waterline_version}, "
                f"durable-workflow/workflow:{workflow_version}, and "
                f"durable-workflow/sdk:{sdk_php_version}."
            ),
            blocker_kind="waterline_composer_require",
            failed_command=require_command,
            command_result=require,
        ), {"error": "waterline_composer_require", "log_file": log_file.name}

    try:
        record_unique_distribution_file(
            "workflow",
            workflow_version,
            composer_cache_dir / "files" / "durable-workflow" / "workflow",
            "**/*",
            "durable-workflow/workflow",
        )
        record_unique_distribution_file(
            "waterline",
            waterline_version,
            composer_cache_dir / "files" / "durable-workflow" / "waterline",
            "**/*",
            "durable-workflow/waterline",
        )
    except Exception as exc:  # noqa: BLE001 - missing byte evidence is a focused product failure.
        return waterline_observer_setup_result(
            status="fail",
            reason=f"Composer-installed Waterline distribution identity is unavailable: {type(exc).__name__}: {exc}",
            blocker_kind="waterline_distribution_identity",
        ), {"error": "waterline_distribution_identity", "log_file": log_file.name}

    key_command = docker_run_for_project(
        waterline_root,
        composer_cache_dir,
        ["php", "artisan", "key:generate", "--force"],
        extra_env=waterline_env,
        network=waterline_network,
        image=waterline_php_docker_image(),
        entrypoint="",
    )
    key = run_command(
        key_command,
        log_file=log_file,
        timeout=120,
    )
    artisan_list_command = docker_run_for_project(
        waterline_root,
        composer_cache_dir,
        ["php", "artisan", "list", "--raw"],
        extra_env=waterline_env,
        network=waterline_network,
        image=waterline_php_docker_image(),
        entrypoint="",
    )
    artisan_list = run_command(
        artisan_list_command,
        log_file=log_file,
        timeout=120,
    )
    if key.returncode != 0 or artisan_list.returncode != 0:
        return waterline_observer_setup_result(
            status="fail",
            reason="The Composer-installed Waterline package could not boot its Laravel command surface.",
            blocker_kind="waterline_artisan_list",
            failed_command=key_command if key.returncode != 0 else artisan_list_command,
            command_result=key if key.returncode != 0 else artisan_list,
        ), {"error": "waterline_artisan_list", "log_file": log_file.name}

    if "waterline:signals-queries-conformance" not in artisan_list.stdout:
        return waterline_observer_setup_result(
            status="fail",
            reason="The Composer-installed Waterline package does not expose waterline:signals-queries-conformance.",
            blocker_kind="waterline_command_missing",
        ), {"error": "waterline_command_missing", "log_file": log_file.name}

    workflow_id = str(public_evidence["workflow_instance_id"])
    run_id = str(public_evidence["workflow_run_id"])
    run_status_value = str(public_evidence["run_status"])
    query_name = str(public_evidence["query_name"])
    counter = integer_from_evidence(public_evidence, ("current_counter", "counter", "queried_total"))
    responder_inputs = waterline_query_responder_inputs(public_evidence)
    responder_holder: dict[str, Any] = {}
    responder: threading.Thread | None = None
    if responder_inputs is not None and counter is not None:
        responder = threading.Thread(
            target=answer_next_query_task,
            args=(
                responder_inputs["base_url"],
                responder_inputs["token"],
                responder_inputs["namespace"],
                responder_inputs["worker_id"],
                responder_inputs["task_queue"],
                counter,
                log_file,
                responder_holder,
                210.0,
            ),
            daemon=True,
        )
        responder.start()

    waterline_command = docker_run_for_project(
        waterline_root,
        composer_cache_dir,
        [
            "php",
            "artisan",
            "waterline:signals-queries-conformance",
            "--input=/app/conformance/public-evidence.json",
            "--output=/app/conformance/waterline-signals-queries-result.json",
            f"--run-id=signals-queries-{run_id}",
            f"--instance-id={workflow_id}",
            f"--workflow-run-id={run_id}",
            f"--run-status={run_status_value}",
            f"--query={query_name}",
            f"--artifact-version=server={artifact_version_value(artifact_versions, 'server')}",
            f"--artifact-version=cli={artifact_version_value(artifact_versions, 'cli')}",
            f"--artifact-version=sdk-python={artifact_version_value(artifact_versions, 'sdk-python')}",
            f"--artifact-version=sdk-php={artifact_version_value(artifact_versions, 'sdk-php')}",
            f"--artifact-version=waterline={waterline_version}",
            "--artifact-source=server=docker_image",
            "--artifact-source=cli=official_install_script",
            "--artifact-source=sdk-python=pypi_package",
            "--artifact-source=sdk-php=packagist_package",
            "--artifact-source=waterline=packagist_package",
        ],
        extra_env=waterline_env,
        network=waterline_network,
        image=waterline_php_docker_image(),
        entrypoint="",
    )
    waterline_report_path.unlink(missing_ok=True)
    command = run_command(
        waterline_command,
        log_file=log_file,
        timeout=240,
    )
    if responder is not None:
        responder.join(timeout=5)
    if not waterline_report_path.is_file():
        return waterline_observer_setup_result(
            status="fail",
            reason=(
                "waterline:signals-queries-conformance exited without writing a report "
                f"(status {command.returncode})."
            ),
            blocker_kind="waterline_conformance_command",
            failed_command=waterline_command,
            command_result=command,
        ), {"error": "waterline_conformance_command", "log_file": log_file.name}

    try:
        waterline_report = json.loads(waterline_report_path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - malformed shard report becomes a focused finding.
        return waterline_observer_setup_result(
            status="fail",
            reason=f"Waterline observer shard report was not valid JSON: {type(exc).__name__}: {exc}",
            blocker_kind="waterline_report_decode",
        ), {"error": "waterline_report_decode", "log_file": log_file.name}

    if isinstance(waterline_report, dict):
        raw_scenarios = waterline_report.get("scenario_results")
        if isinstance(raw_scenarios, list):
            waterline_scenarios = {
                str(item["scenario_id"]): item
                for item in raw_scenarios
                if isinstance(item, dict) and isinstance(item.get("scenario_id"), str)
            }
        elif isinstance(raw_scenarios, dict):
            waterline_scenarios = raw_scenarios
        else:
            waterline_scenarios = {}

        waterline_visibility = waterline_scenarios.get("waterline_operator_visibility")
        if not isinstance(waterline_visibility, dict):
            return waterline_observer_setup_result(
                status="fail",
                reason="Waterline observer shard report did not include waterline_operator_visibility.",
                blocker_kind="waterline_visibility_scenario_missing",
            ), {"error": "waterline_visibility_scenario_missing", "log_file": log_file.name}

        waterline_report = {
            "artifact_versions": dict(artifact_versions),
            "scenario_results": {
                "waterline_operator_visibility": waterline_visibility,
            },
        }

    descriptor = {
        "file": waterline_report_path.name,
        "log_file": log_file.name,
        "workflow_id": workflow_id,
        "run_id": run_id,
        "query_name": query_name,
        "command_status": command.returncode,
        "generated_scenarios": ["waterline_operator_visibility"],
        "shard_command": "waterline:signals-queries-conformance",
        "real_capture_source": "published_waterline_artifact_http_kernel",
        "waterline_workflow_storage": {
            "source": storage.get("source"),
            "docker_network": waterline_network,
            "runtime_image": waterline_php_docker_image(),
            "env": storage.get("redacted_env"),
        },
        "query_responder": {
            "started": responder is not None,
            "poll_status_code": responder_holder.get("poll", {}).get("status_code")
            if isinstance(responder_holder.get("poll"), dict)
            else None,
            "complete_status_code": responder_holder.get("complete", {}).get("status_code")
            if isinstance(responder_holder.get("complete"), dict)
            else None,
            "error": responder_holder.get("error"),
        },
    }
    return waterline_report if isinstance(waterline_report, dict) else None, descriptor


def run_waterline_observer_probe(
    result_dir: Path,
    current_evidence: Any,
    server_topology: dict[str, Any] | None = None,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None]:
    waterline_root = result_dir / "waterline-signals-queries-observer"
    composer_cache_dir = result_dir / "waterline-signals-queries-composer-cache"
    log_file = result_dir / "waterline-signals-queries-observer.log"
    register_scratch_root(waterline_root, log_file)
    register_scratch_root(composer_cache_dir, log_file)
    try:
        return _run_waterline_observer_probe(
            result_dir,
            composer_cache_dir,
            current_evidence,
            server_topology,
        )
    finally:
        cleanup_labeled_docker_runs(log_file)
        if not env_flag("DW_SIGNALS_QUERIES_KEEP_RUN_ROOT", False):
            remove_scratch_root(waterline_root)
            remove_scratch_root(composer_cache_dir)


MISSING = object()
FORBIDDEN_ARTIFACT_SOURCES = (
    "local_product_source_checkout",
    "workspace_repo_as_artifact_under_test",
)
ARTIFACT_SOURCE_FIELDS = ("artifact_sources", "artifactSources")


def evidence_value(value: Any, key: str) -> Any:
    if isinstance(value, dict):
        if key in value:
            return value[key]
        for child in value.values():
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    if isinstance(value, list):
        for child in value:
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    return MISSING


def is_forbidden_artifact_source(source: Any) -> bool:
    if not isinstance(source, str):
        return False

    normalized = source.strip().lower()
    if normalized == "":
        return False

    return any(
        normalized == forbidden or forbidden in normalized
        for forbidden in FORBIDDEN_ARTIFACT_SOURCES
    )


def artifact_source_policy_violations(value: Any, path: str = "$") -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []

    if isinstance(value, dict):
        for field in ARTIFACT_SOURCE_FIELDS:
            sources = value.get(field)
            if not isinstance(sources, dict):
                continue

            for artifact, source in sources.items():
                if not is_forbidden_artifact_source(source):
                    continue

                violations.append(
                    {
                        "path": f"{path}.{field}",
                        "field": field,
                        "artifact": str(artifact),
                        "source": str(source),
                    }
                )

        for key, child in value.items():
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}.{key}"))

    if isinstance(value, list):
        for index, child in enumerate(value):
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}[{index}]"))

    return violations


def evidence_source_policy_violations(*values: Any) -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []
    for value in values:
        violations.extend(artifact_source_policy_violations(value))
    return violations


def flat_smoke_field(key: str) -> Any:
    if smoke_evidence is None:
        return MISSING
    if not isinstance(smoke_evidence, dict):
        return MISSING
    return smoke_evidence.get(key, MISSING)


def smoke_field(key: str, scenario: str | None = None) -> Any:
    value = flat_smoke_field(key)
    if value is not MISSING:
        return value

    if scenario is None:
        return MISSING

    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return MISSING

    observed = scenario_observed_outputs(candidate)
    found = evidence_lookup(observed, key)
    if found is not MISSING:
        return found

    if key == "ten_signal_ordered_delivery_total":
        return evidence_lookup(observed, "queried_total")

    return MISSING


def smoke_field_present(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    return value is not MISSING and value not in (None, "", [], {})


def smoke_field_true(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def is_placeholder_version(version: str) -> bool:
    normalized = version.strip().lower()
    if not normalized:
        return True
    placeholder_tokens = ("latest", "current", "head", "unresolved", "placeholder")
    return (
        normalized.startswith("<")
        or "${" in normalized
        or "{{" in normalized
        or any(token in normalized for token in placeholder_tokens)
    )


def artifact_versions_pinned() -> bool:
    return all(not is_placeholder_version(str(artifact_versions.get(artifact, ""))) for artifact in REQUIRED_INSTALL_ARTIFACTS)


REQUIRED_INSTALL_ARTIFACTS = ("server", "cli", "sdk-python", "sdk-rust", "sdk-php", "workflow", "waterline")
REQUIRED_INSTALL_PROOF_ARTIFACTS = ("server", "cli", "sdk-php", "sdk-python", "sdk-rust")
EXPECTED_ARTIFACT_SOURCES = {
    "server": "published_docker_image",
    "cli": "published_cli_release",
    "sdk-python": "published_pypi_package",
    "sdk-rust": "published_crates_io_package",
    "sdk-php": "published_composer_package",
    "workflow": "published_composer_package",
    "waterline": "published_waterline_artifact",
}

ARTIFACT_VERSION_ALIASES: dict[str, list[str]] = {
    "sdk-php": ["sdk-php", "sdk_php"],
    "sdk-python": ["sdk-python", "sdk_python", "python"],
    "sdk-rust": ["sdk-rust", "sdk_rust", "rust"],
    "waterline": ["waterline", "waterline-ui", "waterline_ui"],
}

ARTIFACT_VERSION_FIELDS = (
    "artifact_versions",
    "artifactVersions",
    "published_artifact_versions",
    "publishedArtifactVersions",
)


def artifact_version_value(versions: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        value = versions.get(key)
        if value is None:
            continue
        normalized = str(value).strip()
        if normalized:
            return normalized
    return ""


def artifact_source_value(sources: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        value = sources.get(key)
        if value is None:
            continue
        normalized = str(value).strip()
        if normalized:
            return normalized
    return ""


def published_source_matches_artifact(source: str, artifact: str) -> bool:
    return source.strip() == EXPECTED_ARTIFACT_SOURCES.get(artifact, "")


def declared_artifact_versions(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {}

    for field in ARTIFACT_VERSION_FIELDS:
        versions = value.get(field)
        if isinstance(versions, dict):
            return versions

    return {}


def declared_artifact_version_maps(value: Any) -> list[dict[str, Any]]:
    if isinstance(value, list):
        maps: list[dict[str, Any]] = []
        for child in value:
            maps.extend(declared_artifact_version_maps(child))
        return maps

    if not isinstance(value, dict):
        return []

    maps = []
    versions = declared_artifact_versions(value)
    if versions:
        maps.append(versions)

    for child in value.values():
        maps.extend(declared_artifact_version_maps(child))

    return maps


def artifact_version_mismatches(versions: dict[str, Any]) -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        expected = artifact_version_value(artifact_versions, artifact)
        actual = artifact_version_value(versions, artifact)
        if expected and actual and expected != actual:
            mismatched[artifact] = {"expected": expected, "actual": actual}
    return mismatched


def evidence_artifact_version_mismatches(value: Any) -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for versions in declared_artifact_version_maps(value):
        for artifact, mismatch in artifact_version_mismatches(versions).items():
            mismatched.setdefault(artifact, mismatch)
    return mismatched


def smoke_artifact_version_mismatches() -> dict[str, dict[str, str]]:
    return evidence_artifact_version_mismatches(smoke_evidence)


def evidence_matches_current_tuple(value: Any) -> bool:
    return evidence_artifact_version_mismatches(value) == {}


def smoke_evidence_matches_current_tuple() -> bool:
    return evidence_matches_current_tuple(smoke_evidence)


def candidate_artifact_versions(candidate: dict[str, Any], observed: dict[str, Any]) -> dict[str, Any]:
    for value in (candidate, observed):
        versions = declared_artifact_versions(value)
        if versions:
            return versions

    return {}


def candidate_matches_current_tuple(candidate: dict[str, Any], observed: dict[str, Any]) -> bool:
    if evidence_source_policy_violations(candidate, observed):
        return False

    versions = candidate_artifact_versions(candidate, observed)
    if versions:
        return artifact_version_mismatches(versions) == {}

    return smoke_evidence_matches_current_tuple()


def output_field(observed: dict[str, Any], *keys: str) -> Any:
    for key in keys:
        value = evidence_lookup(observed, key)
        if value is not MISSING:
            return value
    return MISSING


def is_python_worker_runtime(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return value.strip().lower() in {
        "sdk-python",
        "python",
        "sdk_python",
        "python_worker",
    }


def is_published_python_sdk_source(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return published_source_matches_artifact(value, "sdk-python")


def python_sdk_version_matches_current(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    actual = str(value).strip()
    expected = artifact_version_value(artifact_versions, "sdk-python")
    return (
        actual != ""
        and not is_placeholder_version(actual)
        and (expected == "" or same_python_release(expected, actual))
    )


def python_readiness_boundary_satisfied(value: Any) -> bool:
    if not isinstance(value, dict) or value.get("status") != "pass":
        return False
    if value.get("registered_query_task_capability") is not True:
        return False
    if value.get("initial_state_restored") is not True:
        return False
    if value.get("query_handler_ready") is not True:
        return False
    if value.get("restart_state_restored") is not True:
        return False
    if not python_sdk_version_matches_current(value.get("installed_package_version")):
        return False
    fields_present = all(
        isinstance(value.get(field), str) and str(value[field]).strip() != ""
        for field in (
            "worker_id",
            "restart_worker_id",
            "task_queue",
            "run_id",
            "installed_package_version",
            "installed_package_version_verified_at",
            "worker_started_at",
            "worker_registered_at",
            "initial_state_restored_at",
            "query_handler_ready_at",
            "restart_worker_registered_at",
            "evidence_captured_at",
        )
    )
    return fields_present and timestamps_in_order(value, [
        ("installed_package_version_verified_at", "<=", "worker_started_at"),
        ("worker_started_at", "<=", "worker_registered_at"),
        ("worker_registered_at", "<=", "initial_state_restored_at"),
        ("initial_state_restored_at", "<=", "query_handler_ready_at"),
        ("query_handler_ready_at", "<=", "restart_worker_registered_at"),
        ("restart_worker_registered_at", "<=", "evidence_captured_at"),
    ])


def python_controlled_restart_satisfied(value: Any) -> bool:
    if not isinstance(value, dict) or value.get("status") != "pass":
        return False
    previous_worker_id = str(value.get("previous_worker_id") or "").strip()
    worker_id = str(value.get("worker_id") or "").strip()
    if previous_worker_id == "" or worker_id == "" or previous_worker_id == worker_id:
        return False
    if value.get("expected_replayed_state") != value.get("query_result"):
        return False
    if value.get("query_result") != value.get("repeat_query_result"):
        return False
    if value.get("repeat_query_consistency") is not True:
        return False
    route = value.get("routed_current_query_task")
    if not routed_current_query_task_satisfied(route):
        return False
    if not isinstance(route, dict) or route.get("worker_id") != worker_id:
        return False
    registration = value.get("worker_registration")
    if not isinstance(registration, dict):
        return False
    if registration.get("worker_id") != worker_id:
        return False
    if registration.get("task_queue") != value.get("task_queue"):
        return False
    capabilities = registration.get("capabilities")
    if not isinstance(capabilities, list) or "query_tasks" not in capabilities:
        return False
    return (
        python_sdk_version_matches_current(route.get("worker_sdk_version"))
        and timestamps_in_order(value, [
            ("worker_stopped_at", "<=", "worker_restart_at"),
            ("worker_restart_at", "<=", "worker_registered_at"),
            ("worker_registered_at", "<=", "query_sent_at"),
            ("query_sent_at", "<=", "query_completed_at"),
            ("query_completed_at", "<=", "repeat_query_completed_at"),
        ])
    )


def python_worker_claim_satisfied(observed: dict[str, Any]) -> bool:
    return (
        is_python_worker_runtime(output_field(observed, "worker_runtime", "workerRuntime", "python_worker_runtime"))
        and is_published_python_sdk_source(
            output_field(
                observed,
                "python_worker_artifact_source",
                "pythonWorkerArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        )
        and python_sdk_version_matches_current(
            output_field(
                observed,
                "python_worker_sdk_version",
                "pythonWorkerSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        )
    )


def is_sdk_php_worker_runtime(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return value.strip().lower() in {
        "sdk-php",
        "sdk_php",
        "php",
        "php_worker",
    }


def is_published_sdk_php_source(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    return published_source_matches_artifact(value, "sdk-php")


def sdk_php_version_matches_current(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    actual = str(value).strip()
    expected = artifact_version_value(artifact_versions, "sdk-php")
    return actual != "" and not is_placeholder_version(actual) and (expected == "" or actual == expected)


def sdk_php_worker_claim_satisfied(observed: dict[str, Any]) -> bool:
    return (
        is_sdk_php_worker_runtime(output_field(observed, "worker_runtime", "workerRuntime", "php_worker_runtime"))
        and is_published_sdk_php_source(
            output_field(
                observed,
                "sdk_php_artifact_source",
                "sdkPhpArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        )
        and sdk_php_version_matches_current(
            output_field(
                observed,
                "sdk_php_sdk_version",
                "sdkPhpSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        )
    )


def exact_python_smoke_present() -> bool:
    candidate = scenario_evidence_candidate("python_worker_cli_and_sdk_baseline")
    observed = scenario_observed_outputs(candidate) if candidate is not None else smoke_evidence
    return (
        isinstance(observed, dict)
        and python_worker_claim_satisfied(observed)
        and routed_current_query_task_satisfied(
            evidence_lookup(observed, "routed_current_query_task")
        )
        and all(
            smoke_field_true(field, "python_worker_cli_and_sdk_baseline")
            for field in (
                "python_worker_query_task_routing",
                "cli_signal_and_query",
                "sdk_python_signal_and_query",
                "immediate_repeat_query_consistency",
            )
        )
    )


def exact_ordered_delivery_smoke_present() -> bool:
    observed = {
        "workflow_id": smoke_field("workflow_id", "ordered_signal_delivery"),
        "run_id": smoke_field("run_id", "ordered_signal_delivery"),
        "rapid_increment_inputs": smoke_field("rapid_increment_inputs", "ordered_signal_delivery"),
        "accepted_signal_inputs": smoke_field("accepted_signal_inputs", "ordered_signal_delivery"),
        "accepted_signal_total": smoke_field("accepted_signal_total", "ordered_signal_delivery"),
        "queried_total": smoke_field("queried_total", "ordered_signal_delivery"),
        "history_signal_order": smoke_field("history_signal_order", "ordered_signal_delivery"),
        "final_run_status": smoke_field("final_run_status", "ordered_signal_delivery"),
    }
    ordered_query_responder = smoke_field("ordered_query_responder", "ordered_signal_delivery")
    if ordered_query_responder is not MISSING:
        observed["ordered_query_responder"] = ordered_query_responder

    return ordered_delivery_observations_agree(observed)


ALLOWED_SCENARIO_STATUSES = {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}

SCENARIO_REQUIRED_EVIDENCE: dict[str, list[str]] = {
    "published_artifact_install_only": [
        "published_artifact_versions",
        "artifact_sources",
        "artifact_install_evidence",
    ],
    "python_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
        "python_worker_query_task_routing",
        "routed_current_query_task",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
        "readiness_boundary",
        "controlled_restart",
    ],
    "php_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "sdk_php_artifact_source",
        "sdk_php_sdk_version",
        "php_worker_query_task_routing",
        "routed_current_query_task",
        "cli_signal_and_query",
        "sdk_php_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "python_worker_php_facing_and_cli_clients": [
        "php_client_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "php_worker_python_and_cli_clients": [
        "sdk_python_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "rust_worker_rust_php_python_clients": [
        "worker_runtime",
        "rust_sdk_version",
        "rust_worker_registration.sdk_version",
        "rust_crate_provenance.source",
        "rust_crate_provenance.checksum",
        "rust_crate_provenance.resolved_version",
        "apache_avro_provenance.source",
        "apache_avro_provenance.checksum",
        "query_state_model",
        "ordered_signal_values",
        "rust_query_results.running",
        "sdk_php_query_results.running",
        "sdk_python_query_results.running",
        "rust_query_results.completed",
        "sdk_php_query_results.completed",
        "sdk_python_query_results.completed",
        "valid_avro_signal_and_query.default_codec",
        "valid_avro_signal_and_query.payload_codec",
        "valid_avro_signal_and_query.observed_value",
        "repeat_query_consistency",
    ],
    "python_worker_rust_client": [
        "worker_runtime",
        "rust_sdk_version",
        "rust_crate_provenance.source",
        "rust_crate_provenance.resolved_version",
        "rust_crate_provenance.checksum",
        "ordered_signal_values",
        "default_codec",
        "payload_codec",
        "rust_query_results",
        "repeat_query_consistency",
    ],
    "php_worker_rust_client": [
        "worker_runtime",
        "rust_sdk_version",
        "rust_crate_provenance.source",
        "rust_crate_provenance.resolved_version",
        "rust_crate_provenance.checksum",
        "ordered_signal_values",
        "default_codec",
        "payload_codec",
        "rust_query_results",
        "rust_query_observed_values",
        "prefix_consistent_query_results",
        "query_result_rollback_free",
        "repeat_query_consistency",
    ],
    "rust_query_error_and_immutability": [
        "rust_sdk_version",
        "rust_crate_provenance.source",
        "rust_crate_provenance.resolved_version",
        "rust_crate_provenance.checksum",
        "query_state_model",
        "unknown_query.reason",
        "malformed_query_payload.reason",
        "unavailable_query_handler.reason",
        "incompatible_query_protocol.reason",
        "missing_workflow.reason",
        "terminal_signal.reason",
        "terminal_signal.rejection_reason",
        "history_and_commands_before_first_successful_query.history_event_count",
        "history_and_commands_before_first_successful_query.workflow_command_count",
        "history_and_commands_after_successful_queries.history_event_count",
        "history_and_commands_after_successful_queries.workflow_command_count",
        "history_and_commands_after_failure_queries.history_event_count",
        "history_and_commands_after_failure_queries.workflow_command_count",
        "successful_queries_appended_no_history",
        "successful_queries_emitted_no_workflow_commands",
        "failed_queries_appended_no_history",
        "failed_queries_emitted_no_workflow_commands",
        "answer_before_failures",
        "answer_after_failures",
        "failed_query_did_not_change_later_answer",
    ],
    "ordered_signal_delivery": [
        "rapid_increment_inputs",
        "accepted_signal_inputs",
        "accepted_signal_total",
        "queried_total",
        "history_signal_order",
        "final_run_status",
    ],
    "dedup_contract_observation": [
        "client_side_key_support",
        "documented_contract",
        "handler_observation_count",
    ],
    "signal_during_replay": [
        "signal_api_sample",
        "signal_status_code",
        "worker_restart_at",
        "signal_sent_at",
        "replay_completed_at",
        "signal_applied_at",
    ],
    "query_during_replay": [
        "query_api_sample",
        "query_status_code",
        "worker_restart_at",
        "query_sent_at",
        "query_poll_started_at",
        "replay_completed_at",
        "query_handler_invoked_at",
        "query_completed_at",
        "query_answer",
        "expected_answer",
    ],
    "rust_replayed_instance_state_query_after_cold_restart": [
        "worker_runtime",
        "rust_sdk_version",
        "rust_crate_provenance.source",
        "rust_crate_provenance.resolved_version",
        "rust_crate_provenance.checksum",
        "query_state_model",
        "initial_worker_process_id",
        "cold_restart.fresh_worker_process_id",
        "cold_restart.durable_history_restored",
        "running_query_results.sdk_rust",
        "running_query_results.sdk_php_sdk",
        "running_query_results.sdk_python",
        "restored_query_results.sdk_rust",
        "restored_query_results.sdk_php_sdk",
        "restored_query_results.sdk_python",
        "completed_query_results.sdk_rust",
        "completed_query_results.sdk_php_sdk",
        "completed_query_results.sdk_python",
        "immutability_checkpoints.running.before_first_successful_query.history_event_count",
        "immutability_checkpoints.running.before_first_successful_query.workflow_command_count",
        "immutability_checkpoints.running.answer_before_failed_query",
        "immutability_checkpoints.running.failed_query.reason",
        "immutability_checkpoints.running.answer_after_failed_query",
        "immutability_checkpoints.running.after_successful_and_failed_queries.history_event_count",
        "immutability_checkpoints.running.after_successful_and_failed_queries.workflow_command_count",
        "immutability_checkpoints.cold_restarted.before_first_successful_query.history_event_count",
        "immutability_checkpoints.cold_restarted.before_first_successful_query.workflow_command_count",
        "immutability_checkpoints.cold_restarted.answer_before_failed_query",
        "immutability_checkpoints.cold_restarted.failed_query.reason",
        "immutability_checkpoints.cold_restarted.answer_after_failed_query",
        "immutability_checkpoints.cold_restarted.after_successful_and_failed_queries.history_event_count",
        "immutability_checkpoints.cold_restarted.after_successful_and_failed_queries.workflow_command_count",
        "immutability_checkpoints.completed.before_first_successful_query.history_event_count",
        "immutability_checkpoints.completed.before_first_successful_query.workflow_command_count",
        "immutability_checkpoints.completed.answer_before_failed_query",
        "immutability_checkpoints.completed.failed_query.reason",
        "immutability_checkpoints.completed.answer_after_failed_query",
        "immutability_checkpoints.completed.after_successful_and_failed_queries.history_event_count",
        "immutability_checkpoints.completed.after_successful_and_failed_queries.workflow_command_count",
        "successful_and_failed_queries_appended_no_history",
        "successful_and_failed_queries_emitted_no_workflow_commands",
        "failed_query_did_not_change_later_answer",
    ],
    "completed_run_signal_and_query": [
        "completed_run_id",
        "completed_at",
        "terminal_status",
        "signal_api_sample",
        "signal_error.status_code",
        "signal_error.reason",
        "signal_error.rejection_reason",
        "query_api_sample",
        "query_result_or_error.status_code",
        "query_result_or_error.outcome",
        "signal_error",
        "query_result_or_error",
        "public_query_surfaces",
        "terminal_state_before_operations.history_event_count",
        "terminal_state_after_operations.history_event_count",
        "terminal_result_changed_after_operations",
        "terminal_history_changed_after_operations",
        "run_status_after_operations",
    ],
    "unknown_signal_and_query_errors": [
        "workflow_id",
        "run_id",
        "worker_id",
        "task_queue",
        "unknown_signal",
        "missing_workflow_signal",
        "missing_workflow_query",
        "query_not_found",
        "rejected_unknown_query",
        "known_query_after_unknown_errors",
        "known_query_after_unknown_expected",
        "known_query_after_unknown_result",
        "post_error_query_responder",
        "history_and_commands_before_rejected_requests.history_event_count",
        "history_and_commands_before_rejected_requests.workflow_command_count",
        "history_and_commands_before_rejected_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_before_rejected_requests.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_rejected_requests.history_event_count",
        "history_and_commands_after_rejected_requests.workflow_command_count",
        "history_and_commands_after_rejected_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_after_rejected_requests.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_recovery_query.history_event_count",
        "history_and_commands_after_recovery_query.workflow_command_count",
        "history_and_commands_after_recovery_query.ready_or_leased_workflow_task_count",
        "history_and_commands_after_recovery_query.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_all_requests.history_event_count",
        "history_and_commands_after_all_requests.workflow_command_count",
        "history_and_commands_after_all_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_after_all_requests.ready_or_leased_workflow_task_set_sha256",
        "rejected_signal_audit_rows",
        "rejected_signal_audit_rows_match_expected",
        "rejected_requests_and_recovery_appended_no_history",
        "rejected_requests_created_no_executable_or_ready_work",
        "rejected_signal_handler_invocation_count",
        "rejected_requests_mutated_no_workflow_state",
    ],
    "malformed_signal_and_query_payloads": [
        "invalid_signal_arguments",
        "invalid_query_arguments",
        "invalid_signal_arguments.status_code",
        "invalid_signal_arguments.reason",
        "invalid_query_arguments.status_code",
        "invalid_query_arguments.reason",
        "invalid_signal_arguments_context",
        "invalid_query_arguments_context",
        "signal_handler_invocation_count_after_invalid_payload",
        "query_state_mutation_count_after_invalid_payload",
        "post_error_valid_query_result",
        "cli_invalid_signal_arguments_sample",
        "cli_invalid_query_arguments_sample",
        "sdk_python_invalid_signal_arguments_sample",
        "sdk_python_invalid_query_arguments_sample",
    ],
    "waterline_operator_visibility": [
        "artifact_versions",
        "artifact_sources",
        "captured_at",
        "observer_state.selected_run",
        "observer_state.signals",
        "observer_state.queries",
        "observer_state.paths.selected_run_query_template",
        "api_paths.selected_run_detail",
        "api_paths.selected_run_query_action",
        "dashboard_json_envelopes.selected_run_detail",
        "api_captures.selected_run_detail",
        "api_captures.selected_run_query_action",
        "comparison.run_status_matches_public_clients",
        "comparison.counter_state_matches_public_clients",
        "comparison.server_observation",
        "comparison.cli_observation",
        "comparison.sdk_observation",
    ],
    "waterline_service_operator_visibility": [
        "artifact_versions",
        "artifact_sources",
        "captured_at",
        "probe_started_at",
        "distribution_identity",
        "image_reference",
        "manifest_digest",
        "source_revision_labels.oci_revision",
        "source_revision_labels.release_tag",
        "source_revision_labels.labels",
        "service_mode.backend",
        "service_mode.transport",
        "service_mode.namespace",
        "service_mode.access_mode",
        "api_paths.up",
        "api_paths.running_runs",
        "api_paths.selected_run_detail",
        "api_paths.selected_run_query_action",
        "api_paths.selected_run_signal_action",
        "api_captures.up.status_code",
        "api_captures.running_runs.selected_run_present",
        "api_captures.selected_run_detail",
        "api_captures.selected_run_query_action",
        "api_captures.selected_run_signal_action",
        "comparison.run_identity_matches_public_clients",
        "comparison.counter_state_matches_public_clients",
        "comparison.service_mode_uses_public_php_sdk",
        "comparison.server_observation",
        "comparison.waterline_service_observation",
        "query_responder",
        "query_responder.captured_at",
        "query_responder.query_identity",
        "query_responder.query_status_code",
        "query_responder.query_result",
        "query_responder.expected_query_identity",
        "query_responder.designated_target",
        "query_responder.designated_target.responder_liveness.eligible",
        "query_responder.query_identity.workflow_id",
        "query_responder.query_identity.run_id",
        "query_responder.query_identity.query_name",
        "query_responder.query_identity.task_queue",
        "query_responder.query_identity.worker_id",
        "query_responder.query_identity.query_task_id",
        "query_responder.query_identity.query_task_attempt",
        "query_responder.claim_binding.matches_expected",
        "query_responder.completion_binding.request.query_task_id",
        "query_responder.completion_binding.request.query_task_attempt",
        "query_responder.completion_binding.request.lease_owner",
        "query_responder.completion_binding.response.query_task_id",
        "query_responder.completion_binding.response.query_task_attempt",
        "query_responder.completion_binding.response.outcome",
        "query_responder.completion_binding.authoritative",
        "query_responder.authoritative_completion",
        "query_responder.responder_liveness_at_claim.eligible",
        "query_responder.completion_state",
        "query_responder.completion_response",
        "query_responder.responder_alive_after_wait",
        "query_responder.finished_within_budget",
        "query_responder.query_started_at",
        "query_responder.query_finished_at",
        "query_responder.responder_started_at",
        "query_responder.query_claimed_at",
        "query_responder.completion_request_started_at",
        "query_responder.completion_recorded_at",
        "query_responder.responder_finished_at",
        "query_responder.wait_finished_at",
    ],
}

TRUTHY_REQUIRED_EVIDENCE = {
    "python_worker_query_task_routing",
    "cli_signal_and_query",
    "sdk_python_signal_and_query",
    "immediate_repeat_query_consistency",
    "php_worker_query_task_routing",
    "sdk_php_signal_and_query",
    "php_client_signal_and_query",
    "cross_language_query_consistency",
    "wire_envelope_compatibility",
    "comparison.run_status_matches_public_clients",
    "comparison.run_identity_matches_public_clients",
    "comparison.counter_state_matches_public_clients",
    "comparison.service_mode_uses_public_php_sdk",
    "api_captures.running_runs.selected_run_present",
    "query_responder.finished_within_budget",
    "query_responder.designated_target.responder_liveness.eligible",
    "query_responder.claim_binding.matches_expected",
    "query_responder.completion_binding.authoritative",
    "query_responder.authoritative_completion",
    "query_responder.responder_liveness_at_claim.eligible",
    "prefix_consistent_query_results",
    "query_result_rollback_free",
    "repeat_query_consistency",
    "successful_queries_appended_no_history",
    "successful_queries_emitted_no_workflow_commands",
    "failed_queries_appended_no_history",
    "failed_queries_emitted_no_workflow_commands",
    "failed_query_did_not_change_later_answer",
    "successful_and_failed_queries_appended_no_history",
    "successful_and_failed_queries_emitted_no_workflow_commands",
    "rejected_requests_and_recovery_appended_no_history",
    "rejected_signal_audit_rows_match_expected",
    "rejected_requests_created_no_executable_or_ready_work",
    "rejected_requests_mutated_no_workflow_state",
    "cold_restart.durable_history_restored",
}


def path_value(value: Any, path: list[str]) -> Any:
    current = value
    for segment in path:
        if not isinstance(current, dict) or segment not in current:
            return MISSING
        current = current[segment]
    return current


def evidence_present(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    if isinstance(value, str):
        return value.strip() != ""
    if isinstance(value, (list, dict)):
        return bool(value)
    return True


def evidence_true(value: Any) -> bool:
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def required_evidence_satisfied(evidence_key: str, value: Any) -> bool:
    if evidence_key in TRUTHY_REQUIRED_EVIDENCE:
        return evidence_true(value)

    return evidence_present(value)


def evidence_lookup(value: Any, key: str) -> Any:
    if "." in key and isinstance(value, dict):
        found = path_value(value, key.split("."))
        if found is not MISSING:
            return found

    return evidence_value(value, key)


def integer_value(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.strip().lstrip("-").isdigit():
        return int(value.strip())
    return None


def integer_sequence(value: Any) -> list[int] | None:
    if not isinstance(value, list):
        return None

    sequence: list[int] = []
    for item in value:
        if isinstance(item, bool) or not isinstance(item, int):
            return None
        sequence.append(item)
    return sequence


def expected_rapid_signal_inputs() -> list[int]:
    return list(range(1, 11))


def ordered_delivery_reference_inputs(observed: dict[str, Any]) -> list[int] | None:
    accepted_inputs = integer_sequence(evidence_lookup(observed, "accepted_signal_inputs"))
    if accepted_inputs is not None:
        return accepted_inputs

    return integer_sequence(evidence_lookup(observed, "rapid_increment_inputs"))


def ordered_query_responder_satisfied(observed: dict[str, Any]) -> bool:
    responder = evidence_lookup(observed, "ordered_query_responder")
    if not isinstance(responder, dict):
        return False

    worker_id = responder.get("worker_id")
    task_queue = responder.get("task_queue")
    workflow_id = evidence_lookup(observed, "workflow_id")
    run_id = evidence_lookup(observed, "run_id")
    claim_eligibility = responder.get("claim_eligibility")
    claimed_query_task = responder.get("claimed_query_task")
    completion = responder.get("query_task_completion")
    capabilities = (
        claim_eligibility.get("capabilities")
        if isinstance(claim_eligibility, dict)
        and isinstance(claim_eligibility.get("capabilities"), list)
        else []
    )
    completion_status = (
        integer_value(completion.get("status_code"))
        if isinstance(completion, dict)
        else None
    )

    return (
        isinstance(worker_id, str)
        and worker_id.strip() != ""
        and isinstance(task_queue, str)
        and task_queue.strip() != ""
        and isinstance(workflow_id, str)
        and workflow_id.strip() != ""
        and isinstance(run_id, str)
        and run_id.strip() != ""
        and evidence_true(responder.get("eligible_when_claimed"))
        and isinstance(responder.get("query_claimed_at"), str)
        and responder["query_claimed_at"].strip() != ""
        and isinstance(claim_eligibility, dict)
        and evidence_true(claim_eligibility.get("eligible"))
        and claim_eligibility.get("worker_id") == worker_id
        and claim_eligibility.get("task_queue") == task_queue
        and claim_eligibility.get("status") == "active"
        and "query_tasks" in capabilities
        and isinstance(claim_eligibility.get("last_heartbeat_at"), str)
        and claim_eligibility["last_heartbeat_at"].strip() != ""
        and isinstance(claimed_query_task, dict)
        and isinstance(claimed_query_task.get("query_task_id"), str)
        and claimed_query_task["query_task_id"].strip() != ""
        and claimed_query_task.get("workflow_id") == workflow_id
        and claimed_query_task.get("run_id") == run_id
        and claimed_query_task.get("query_name") == "state"
        and claimed_query_task.get("task_queue") == task_queue
        and claimed_query_task.get("lease_owner") == worker_id
        and completion_status is not None
        and completion_status < 400
    )


def post_error_query_responder_satisfied(observed: dict[str, Any]) -> bool:
    responder = evidence_lookup(observed, "post_error_query_responder")
    if not isinstance(responder, dict):
        return False

    workflow_id = evidence_lookup(observed, "workflow_id")
    run_id = evidence_lookup(observed, "run_id")
    worker_id = evidence_lookup(observed, "worker_id")
    task_queue = evidence_lookup(observed, "task_queue")
    claim_eligibility = responder.get("claim_eligibility")
    claimed_query_task = responder.get("claimed_query_task")
    completion = responder.get("query_task_completion")
    heartbeat = responder.get("heartbeat_before_poll")
    capabilities = (
        claim_eligibility.get("capabilities")
        if isinstance(claim_eligibility, dict)
        and isinstance(claim_eligibility.get("capabilities"), list)
        else []
    )

    return (
        isinstance(workflow_id, str)
        and workflow_id != ""
        and isinstance(run_id, str)
        and run_id != ""
        and isinstance(worker_id, str)
        and worker_id != ""
        and isinstance(task_queue, str)
        and task_queue != ""
        and responder.get("worker_id") == worker_id
        and responder.get("task_queue") == task_queue
        and evidence_true(responder.get("eligible_when_claimed"))
        and responder.get("responder_timed_out") is False
        and responder.get("responder_error") in (None, "")
        and isinstance(responder.get("heartbeat_acknowledged_at"), str)
        and responder["heartbeat_acknowledged_at"].strip() != ""
        and isinstance(responder.get("query_poll_ready_at"), str)
        and responder["query_poll_ready_at"].strip() != ""
        and isinstance(responder.get("query_claimed_at"), str)
        and responder["query_claimed_at"].strip() != ""
        and isinstance(heartbeat, dict)
        and integer_value(heartbeat.get("status_code")) is not None
        and int(heartbeat["status_code"]) < 400
        and isinstance(claim_eligibility, dict)
        and evidence_true(claim_eligibility.get("eligible"))
        and claim_eligibility.get("worker_id") == worker_id
        and claim_eligibility.get("task_queue") == task_queue
        and claim_eligibility.get("status") == "active"
        and "query_tasks" in capabilities
        and isinstance(claim_eligibility.get("last_heartbeat_at"), str)
        and claim_eligibility["last_heartbeat_at"].strip() != ""
        and isinstance(claimed_query_task, dict)
        and isinstance(claimed_query_task.get("query_task_id"), str)
        and claimed_query_task["query_task_id"].strip() != ""
        and claimed_query_task.get("workflow_id") == workflow_id
        and claimed_query_task.get("run_id") == run_id
        and claimed_query_task.get("query_name") == "state"
        and claimed_query_task.get("task_queue") == task_queue
        and claimed_query_task.get("lease_owner") == worker_id
        and isinstance(completion, dict)
        and status_code_in_range({"completion": completion}, "completion.status_code", 200, 299)
    )


def rejected_signal_audit_rows_satisfied(observed: dict[str, Any]) -> bool:
    audit = evidence_lookup(observed, "rejected_signal_audit_rows")
    if not isinstance(audit, dict):
        return False

    expected_rows = audit.get("expected_rows")
    observed_rows = audit.get("observed_rows")
    run_id = evidence_lookup(observed, "run_id")
    if (
        not isinstance(run_id, str)
        or not isinstance(expected_rows, list)
        or not expected_rows
        or expected_rows != observed_rows
        or audit.get("exact_match") is not True
        or integer_value(audit.get("executable_or_ready_command_count")) != 0
    ):
        return False

    allowed_targets = {
        ("missing", "unknown_signal", "rejected_unknown_signal"),
        ("increment", "invalid_signal_arguments", "rejected_invalid_arguments"),
    }
    for row in expected_rows:
        if not isinstance(row, dict):
            return False
        target = (row.get("target_name"), row.get("reason"), row.get("outcome"))
        if (
            row.get("type") != "signal"
            or row.get("target_scope") != "instance"
            or row.get("requested_run_id") is not None
            or row.get("resolved_run_id") != run_id
            or target not in allowed_targets
            or row.get("status") != "rejected"
            or row.get("rejection_reason") != row.get("reason")
            or row.get("accepted_at") is not None
            or row.get("applied_at") is not None
            or row.get("rejected_at_recorded") is not True
        ):
            return False

    return any(row.get("reason") == "unknown_signal" for row in expected_rows)


def post_error_recovery_immutability_satisfied(observed: dict[str, Any]) -> bool:
    before = evidence_lookup(observed, "history_and_commands_before_rejected_requests")
    after_rejected = evidence_lookup(observed, "history_and_commands_after_rejected_requests")
    after_recovery = evidence_lookup(observed, "history_and_commands_after_recovery_query")
    after_all = evidence_lookup(observed, "history_and_commands_after_all_requests")

    return (
        isinstance(before, dict)
        and isinstance(after_rejected, dict)
        and isinstance(after_recovery, dict)
        and isinstance(after_all, dict)
        and snapshot_count_unchanged(before, after_rejected, "history_event_count")
        and snapshot_count_unchanged(before, after_recovery, "history_event_count")
        and snapshot_count_unchanged(before, after_all, "history_event_count")
        and ready_or_leased_workflow_tasks_unchanged(before, after_rejected)
        and ready_or_leased_workflow_tasks_unchanged(after_rejected, after_recovery)
        and ready_or_leased_workflow_tasks_unchanged(after_recovery, after_all)
        and rejected_signal_audit_rows_satisfied(observed)
        and evidence_true(evidence_lookup(observed, "rejected_signal_audit_rows_match_expected"))
        and evidence_true(
            evidence_lookup(observed, "rejected_requests_and_recovery_appended_no_history")
        )
        and evidence_true(
            evidence_lookup(observed, "rejected_requests_created_no_executable_or_ready_work")
        )
        and integer_value(evidence_lookup(observed, "rejected_signal_handler_invocation_count")) == 0
        and evidence_true(evidence_lookup(observed, "rejected_requests_mutated_no_workflow_state"))
    )


def ordered_delivery_observations_agree(observed: dict[str, Any]) -> bool:
    rapid_inputs = integer_sequence(evidence_lookup(observed, "rapid_increment_inputs"))
    accepted_inputs = integer_sequence(evidence_lookup(observed, "accepted_signal_inputs"))
    accepted_signal_total = integer_value(evidence_lookup(observed, "accepted_signal_total"))
    queried_total = integer_value(evidence_lookup(observed, "queried_total"))
    history_signal_order = integer_sequence(evidence_lookup(observed, "history_signal_order"))
    final_run_status = evidence_lookup(observed, "final_run_status")

    return (
        rapid_inputs == expected_rapid_signal_inputs()
        and accepted_inputs == rapid_inputs
        and accepted_signal_total == sum(accepted_inputs)
        and queried_total == sum(accepted_inputs)
        and history_signal_order == accepted_inputs
        and required_evidence_satisfied("final_run_status", final_run_status)
        and ordered_query_responder_satisfied(observed)
    )


def status_code_in_range(observed: dict[str, Any], key: str, minimum: int, maximum: int) -> bool:
    status = integer_value(evidence_lookup(observed, key))
    return status is not None and minimum <= status <= maximum


def reason_in(observed: dict[str, Any], key: str, allowed: set[str]) -> bool:
    value = evidence_lookup(observed, key)
    return isinstance(value, str) and value in allowed


def artifact_sources_from_outputs(observed: dict[str, Any]) -> dict[str, Any]:
    for field in ARTIFACT_SOURCE_FIELDS:
        sources = observed.get(field)
        if isinstance(sources, dict):
            return sources

    return {}


def artifact_install_evidence_from_outputs(observed: dict[str, Any]) -> dict[str, Any]:
    for field in (
        "artifact_install_evidence",
        "artifactInstallEvidence",
        "install_evidence",
        "installEvidence",
    ):
        evidence = observed.get(field)
        if isinstance(evidence, dict):
            return evidence

    return {}


def install_evidence_entry(install_evidence: dict[str, Any], artifact: str) -> dict[str, Any] | None:
    artifacts = install_evidence.get("artifacts")
    if isinstance(artifacts, list):
        for entry in artifacts:
            if not isinstance(entry, dict):
                continue
            entry_artifact = str(
                entry.get("artifact")
                or entry.get("name")
                or entry.get("id")
                or entry.get("package")
                or ""
            )
            if entry_artifact == artifact:
                return entry

    direct = install_evidence.get(artifact)
    if isinstance(direct, dict):
        return direct

    return None


def entry_text(entry: dict[str, Any], *keys: str) -> str:
    for key in keys:
        value = entry.get(key)
        if value is None:
            continue
        text = str(value).strip()
        if text:
            return text
    return ""


def entry_has_local_checkout(entry: dict[str, Any]) -> bool:
    for key in ("local_product_source_checkouts_used", "localProductSourceCheckoutsUsed"):
        value = entry.get(key)
        if value is True:
            return True
        if isinstance(value, str) and value.strip().lower() in {"1", "true", "yes"}:
            return True
    return False


def explicit_false_local_checkout(value: dict[str, Any]) -> bool:
    return any(
        value.get(key) is False
        for key in ("local_product_source_checkouts_used", "localProductSourceCheckoutsUsed")
    )


def install_outputs_cover_required_artifacts(observed: dict[str, Any]) -> bool:
    versions = declared_artifact_versions(observed)
    sources = artifact_sources_from_outputs(observed)
    install_evidence = artifact_install_evidence_from_outputs(observed)
    if not versions or not sources:
        return False
    if not install_evidence:
        return False
    if not any(
        isinstance(observed.get(field), dict) and observed.get(field)
        for field in ("published_artifact_versions", "publishedArtifactVersions")
    ):
        return False

    if evidence_source_policy_violations({"artifact_sources": sources}):
        return False

    if entry_has_local_checkout(observed):
        return False
    if not explicit_false_local_checkout(install_evidence):
        return False

    for artifact in REQUIRED_INSTALL_PROOF_ARTIFACTS:
        version = artifact_version_value(versions, artifact)
        source = artifact_source_value(sources, artifact)
        if version == "" or is_placeholder_version(version):
            return False
        if source == "" or is_forbidden_artifact_source(source):
            return False
        if not published_source_matches_artifact(source, artifact):
            return False
        entry = install_evidence_entry(install_evidence, artifact)
        if entry is None:
            return False
        status = entry_text(entry, "status", "result", "outcome").lower()
        if status != "pass":
            return False
        entry_version = entry_text(
            entry,
            "version",
            "resolved_version",
            "resolvedVersion",
            "artifact_version",
            "artifactVersion",
        )
        if entry_version == "" or is_placeholder_version(entry_version) or entry_version != version:
            return False
        entry_source = entry_text(
            entry,
            "source",
            "install_source",
            "installSource",
            "artifact_source",
            "artifactSource",
        )
        if entry_source == "" or not published_source_matches_artifact(entry_source, artifact):
            return False
        if entry_has_local_checkout(entry):
            return False

    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        source = artifact_source_value(sources, artifact)
        if source == "" or is_forbidden_artifact_source(source):
            return False
        if not published_source_matches_artifact(source, artifact):
            return False

    return True


def timestamp_seconds(value: Any) -> float | None:
    if not isinstance(value, str) or value.strip() == "":
        return None
    normalized = value.strip()
    if normalized.endswith("Z"):
        normalized = f"{normalized[:-1]}+00:00"
    try:
        return datetime.fromisoformat(normalized).timestamp()
    except ValueError:
        return None


def timestamps_in_order(observed: dict[str, Any], orders: list[tuple[str, str, str]]) -> bool:
    for left_key, operator, right_key in orders:
        left = timestamp_seconds(evidence_lookup(observed, left_key))
        right = timestamp_seconds(evidence_lookup(observed, right_key))
        if left is None or right is None:
            return False
        if operator == "<" and not left < right:
            return False
        if operator == "<=" and not left <= right:
            return False
    return True


def has_required_evidence(scenario: str, observed: dict[str, Any]) -> bool:
    if scenario == "published_artifact_install_only":
        return artifact_versions_pinned() and install_outputs_cover_required_artifacts(observed)

    if scenario == "python_worker_cli_and_sdk_baseline":
        return (
            python_worker_claim_satisfied(observed)
            and routed_current_query_task_satisfied(
                observed.get("routed_current_query_task", MISSING)
            )
            and all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
        )

    if scenario == "php_worker_cli_and_sdk_baseline":
        return (
            sdk_php_worker_claim_satisfied(observed)
            and routed_current_query_task_satisfied(
                evidence_lookup(observed, "routed_current_query_task"),
                expected_runtime="sdk-php",
            )
            and all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
        )

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_observations_agree(observed)

    if scenario == "rust_query_error_and_immutability":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and evidence_lookup(observed, "terminal_signal.reason") == "run_not_active"
            and evidence_lookup(observed, "terminal_signal.rejection_reason") == "run_not_active"
        )

    if scenario == "signal_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_status_code", 200, 299)
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "signal_sent_at"),
                    ("signal_sent_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "signal_applied_at"),
                ],
            )
        )

    if scenario == "query_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "query_status_code", 200, 299)
            and evidence_lookup(observed, "query_answer") == evidence_lookup(observed, "expected_answer")
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "query_sent_at"),
                    ("query_sent_at", "<=", "query_poll_started_at"),
                    ("query_poll_started_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "query_handler_invoked_at"),
                    ("query_handler_invoked_at", "<=", "query_completed_at"),
                ],
            )
        )

    if scenario == "completed_run_signal_and_query":
        query_status = integer_value(evidence_lookup(observed, "query_result_or_error.status_code"))
        query_reason = evidence_lookup(observed, "query_result_or_error.reason")
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_error.status_code", 400, 499)
            and evidence_lookup(observed, "signal_error.reason") == "run_not_active"
            and evidence_lookup(observed, "signal_error.rejection_reason") == "run_not_active"
            and query_status is not None
            and 200 <= query_status <= 499
            and (query_status < 400 or required_evidence_satisfied("query_result_or_error.reason", query_reason))
            and evidence_lookup(observed, "terminal_result_changed_after_operations") is False
            and evidence_lookup(observed, "terminal_history_changed_after_operations") is False
        )

    if scenario == "unknown_signal_and_query_errors":
        query_reasons = {"query_not_found", "rejected_unknown_query"}
        required_passed = (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "unknown_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_signal.status_code", 404, 404)
            and status_code_in_range(observed, "missing_workflow_query.status_code", 404, 404)
            and status_code_in_range(observed, "query_not_found.status_code", 404, 404)
            and status_code_in_range(observed, "known_query_after_unknown_errors.status_code", 200, 299)
            and reason_in(observed, "unknown_signal.reason", {"unknown_signal"})
            and reason_in(observed, "missing_workflow_signal.reason", {"instance_not_found"})
            and reason_in(observed, "missing_workflow_query.reason", {"instance_not_found"})
            and reason_in(observed, "query_not_found.reason", query_reasons)
            and reason_in(observed, "rejected_unknown_query.reason", query_reasons)
            and evidence_lookup(observed, "known_query_after_unknown_result")
            == evidence_lookup(observed, "known_query_after_unknown_expected")
            and post_error_query_responder_satisfied(observed)
            and post_error_recovery_immutability_satisfied(observed)
        )
        if not required_passed:
            return False

        optional_checks = {
            "cli_unknown_signal_sample": ({"unknown_signal"}, None, True),
            "cli_unknown_query_sample": (query_reasons, None, True),
            "cli_missing_workflow_signal_sample": ({"instance_not_found"}, None, True),
            "cli_missing_workflow_query_sample": ({"instance_not_found"}, None, True),
            "sdk_python_unknown_signal_sample": ({"unknown_signal"}, "SignalFailed", True),
            "sdk_python_unknown_query_sample": (query_reasons, "QueryFailed", True),
            "sdk_python_missing_workflow_signal_sample": ({"instance_not_found"}, "WorkflowNotFound", False),
            "sdk_python_missing_workflow_query_sample": ({"instance_not_found"}, "WorkflowNotFound", False),
        }
        for field, (reasons, exception, require_status_code) in optional_checks.items():
            sample = evidence_lookup(observed, field)
            if sample is MISSING:
                continue
            if not isinstance(sample, dict):
                return False
            if require_status_code and not status_code_in_range(observed, f"{field}.status_code", 404, 404):
                return False
            if not reason_in(observed, f"{field}.reason", reasons):
                return False
            if exception is not None and evidence_lookup(observed, f"{field}.exception") != exception:
                return False
        return True

    if scenario == "malformed_signal_and_query_payloads":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "invalid_signal_arguments.status_code", 422, 422)
            and status_code_in_range(observed, "invalid_query_arguments.status_code", 422, 422)
            and evidence_lookup(observed, "invalid_signal_arguments.reason") == "invalid_signal_arguments"
            and evidence_lookup(observed, "invalid_query_arguments.reason") == "invalid_query_arguments"
            and status_code_in_range(observed, "cli_invalid_signal_arguments_sample.status_code", 422, 422)
            and status_code_in_range(observed, "cli_invalid_query_arguments_sample.status_code", 422, 422)
            and evidence_lookup(
                observed,
                "cli_invalid_signal_arguments_sample.reason",
            ) == "invalid_signal_arguments"
            and evidence_lookup(
                observed,
                "cli_invalid_query_arguments_sample.reason",
            ) == "invalid_query_arguments"
            and status_code_in_range(observed, "sdk_python_invalid_signal_arguments_sample.status_code", 422, 422)
            and status_code_in_range(observed, "sdk_python_invalid_query_arguments_sample.status_code", 422, 422)
            and evidence_lookup(
                observed,
                "sdk_python_invalid_signal_arguments_sample.reason",
            ) == "invalid_signal_arguments"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_query_arguments_sample.reason",
            ) == "invalid_query_arguments"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_signal_arguments_sample.exception",
            ) == "SignalFailed"
            and evidence_lookup(
                observed,
                "sdk_python_invalid_query_arguments_sample.exception",
            ) == "QueryFailed"
            and integer_value(evidence_lookup(
                observed,
                "signal_handler_invocation_count_after_invalid_payload",
            )) == 0
            and integer_value(evidence_lookup(
                observed,
                "query_state_mutation_count_after_invalid_payload",
            )) == 0
        )

    return all(
        required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, [])
    )


def scenario_result_items(raw: Any) -> list[dict[str, Any]]:
    if isinstance(raw, dict):
        items = []
        for scenario_id, value in raw.items():
            if not isinstance(value, dict):
                continue
            item = dict(value)
            item.setdefault("scenario_id", scenario_id)
            items.append(item)
        return items

    if isinstance(raw, list):
        return [item for item in raw if isinstance(item, dict)]

    return []


def scenario_evidence_candidate_from(evidence: Any, scenario: str) -> dict[str, Any] | None:
    if not isinstance(evidence, dict):
        return None

    for field in ("scenario_results", "scenarioResults"):
        for item in scenario_result_items(evidence.get(field)):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    direct = evidence.get(scenario)
    if isinstance(direct, dict):
        return direct

    for section in (
        "replay_timing",
        "terminal_run_behavior",
        "adversarial_errors",
        "waterline_observer_comparison",
    ):
        section_value = evidence.get(section)
        if not isinstance(section_value, dict):
            continue

        keyed = section_value.get(scenario)
        if isinstance(keyed, dict):
            return keyed

        for item in scenario_result_items(section_value):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    return None


def scenario_evidence_candidate(scenario: str) -> dict[str, Any] | None:
    return scenario_evidence_candidate_from(smoke_evidence, scenario)


def scenario_status(candidate: dict[str, Any]) -> str:
    for field in ("status", "outcome", "verdict"):
        status = candidate.get(field)
        if isinstance(status, str) and status in ALLOWED_SCENARIO_STATUSES:
            return status

    return ""


def scenario_observed_outputs(candidate: dict[str, Any]) -> dict[str, Any]:
    for field in ("observed_outputs", "observedOutputs", "evidence", "outputs"):
        value = candidate.get(field)
        if isinstance(value, dict):
            return dict(value)

    metadata_fields = {
        "scenario_id",
        "scenario",
        "id",
        "status",
        "outcome",
        "verdict",
        "linked_findings",
        "linkedFindings",
        "finding_links",
        "findingLinks",
    }
    return {
        key: value
        for key, value in candidate.items()
        if key not in metadata_fields
    }


def explicit_install_observed_outputs(evidence: Any) -> dict[str, Any]:
    candidate = scenario_evidence_candidate_from(evidence, "published_artifact_install_only")
    if candidate is not None:
        return scenario_observed_outputs(candidate)

    if not isinstance(evidence, dict):
        return {}

    outputs: dict[str, Any] = {}
    for field in ARTIFACT_VERSION_FIELDS:
        versions = evidence.get(field)
        if isinstance(versions, dict):
            outputs["published_artifact_versions"] = versions
            break

    for field in ARTIFACT_SOURCE_FIELDS:
        sources = evidence.get(field)
        if isinstance(sources, dict):
            outputs["artifact_sources"] = sources
            break

    for field in (
        "artifact_install_evidence",
        "artifactInstallEvidence",
        "artifact_source_verification",
        "artifactSourceVerification",
    ):
        install_evidence = evidence.get(field)
        if isinstance(install_evidence, dict):
            outputs["artifact_install_evidence"] = install_evidence
            break

    return outputs


def scenario_linked_findings(candidate: dict[str, Any]) -> list[Any]:
    for field in ("linked_findings", "linkedFindings", "finding_links", "findingLinks"):
        value = candidate.get(field)
        if isinstance(value, list) and value:
            return value
    return []


def imported_scenario_result(scenario: str) -> dict[str, Any] | None:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return None

    observed = scenario_observed_outputs(candidate)
    if smoke_descriptor is not None:
        observed.setdefault("external_smoke_evidence", smoke_descriptor)

    if not candidate_matches_current_tuple(candidate, observed):
        return None

    status = scenario_status(candidate)
    if status == "" and has_required_evidence(scenario, observed):
        status = "pass"

    if status == "pass":
        if current_behavior_failures_for(scenario, observed):
            return {
                "scenario_id": scenario,
                "status": "fail",
                "observed_outputs": observed,
            }
        if not has_required_evidence(scenario, observed):
            if scenario in {"signal_during_replay", "query_during_replay"}:
                return replay_timing_scenario_result(scenario, observed)
            return None
        return {
            "scenario_id": scenario,
            "status": "pass",
            "observed_outputs": observed,
        }

    if status in ALLOWED_SCENARIO_STATUSES:
        result: dict[str, Any] = {
            "scenario_id": scenario,
            "status": status,
        }
        if observed:
            result["observed_outputs"] = observed
        linked_findings = scenario_linked_findings(candidate)
        if linked_findings:
            result["linked_findings"] = linked_findings
        return result

    return None


SERVER_BASELINE_SCENARIOS = {
    "ordered_signal_delivery",
    "dedup_contract_observation",
    "unknown_signal_and_query_errors",
}

BASELINE_CURRENT_EVIDENCE_SCENARIOS = SERVER_BASELINE_SCENARIOS | {
    "python_worker_cli_and_sdk_baseline",
    "php_worker_cli_and_sdk_baseline",
    "python_worker_php_facing_and_cli_clients",
    "php_worker_python_and_cli_clients",
}

BASELINE_CURRENT_EVIDENCE_FIELDS = {
    "python_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
        "python_worker_query_task_routing",
        "routed_current_query_task",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
        "readiness_boundary",
        "controlled_restart",
    ],
    "php_worker_cli_and_sdk_baseline": [
        "worker_runtime",
        "sdk_php_artifact_source",
        "sdk_php_sdk_version",
        "php_worker_query_task_routing",
        "cli_signal_and_query",
        "sdk_php_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "python_worker_php_facing_and_cli_clients": [
        "php_client_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "php_worker_python_and_cli_clients": [
        "sdk_python_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "ordered_signal_delivery": [
        "workflow_id",
        "run_id",
        "rapid_increment_inputs",
        "accepted_signal_inputs",
        "accepted_signal_total",
        "queried_total",
        "history_signal_order",
        "final_run_status",
        "ordered_query_responder",
    ],
    "dedup_contract_observation": [
        "client_side_key_support",
        "documented_contract",
        "handler_observation_count",
    ],
    "unknown_signal_and_query_errors": [
        "workflow_id",
        "run_id",
        "worker_id",
        "task_queue",
        "unknown_signal",
        "missing_workflow_signal",
        "missing_workflow_query",
        "query_not_found",
        "rejected_unknown_query",
        "known_query_after_unknown_errors",
        "known_query_after_unknown_expected",
        "known_query_after_unknown_result",
        "post_error_query_responder",
        "history_and_commands_before_rejected_requests.history_event_count",
        "history_and_commands_before_rejected_requests.workflow_command_count",
        "history_and_commands_before_rejected_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_before_rejected_requests.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_rejected_requests.history_event_count",
        "history_and_commands_after_rejected_requests.workflow_command_count",
        "history_and_commands_after_rejected_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_after_rejected_requests.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_recovery_query.history_event_count",
        "history_and_commands_after_recovery_query.workflow_command_count",
        "history_and_commands_after_recovery_query.ready_or_leased_workflow_task_count",
        "history_and_commands_after_recovery_query.ready_or_leased_workflow_task_set_sha256",
        "history_and_commands_after_all_requests.history_event_count",
        "history_and_commands_after_all_requests.workflow_command_count",
        "history_and_commands_after_all_requests.ready_or_leased_workflow_task_count",
        "history_and_commands_after_all_requests.ready_or_leased_workflow_task_set_sha256",
        "rejected_signal_audit_rows",
        "rejected_signal_audit_rows_match_expected",
        "rejected_requests_and_recovery_appended_no_history",
        "rejected_requests_created_no_executable_or_ready_work",
        "rejected_signal_handler_invocation_count",
        "rejected_requests_mutated_no_workflow_state",
    ],
}

BASELINE_PRODUCT_FAILURE_ROUTES = {
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_baseline_failed",
        "title": "Signals/queries Python worker CLI and SDK baseline behavior failed",
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_failed",
        "title": "Signals/queries PHP worker mirror behavior failed",
    },
    "python_worker_php_facing_and_cli_clients": {
        "type": "signal_query_python_worker_php_facing_clients_failed",
        "title": "Signals/queries Python worker PHP-facing client behavior failed",
    },
    "php_worker_python_and_cli_clients": {
        "type": "signal_query_php_worker_python_clients_failed",
        "title": "Signals/queries PHP worker Python and CLI client behavior failed",
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_failed",
        "title": "Signals/queries ordered delivery behavior failed",
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_failed",
        "title": "Signals/queries duplicate signal contract behavior failed",
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_failed",
        "title": "Signals/queries unknown-handler error behavior failed",
    },
}

BASELINE_CURRENT_MISSING_ROUTES = {
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_routed_current_query_evidence_missing",
        "title": "Signals/queries Python baseline routed current-query evidence missing",
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_current_evidence_missing",
        "title": "Signals/queries PHP worker mirror current evidence missing",
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_current_evidence_missing",
        "title": "Signals/queries ordered delivery current evidence missing",
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_current_evidence_missing",
        "title": "Signals/queries duplicate signal contract current evidence missing",
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_current_evidence_missing",
        "title": "Signals/queries unknown-handler current evidence missing",
    },
}


def unique_strings(values: list[str]) -> list[str]:
    seen: set[str] = set()
    unique = []
    for value in values:
        if value in seen:
            continue
        seen.add(value)
        unique.append(value)
    return unique


def required_current_evidence_for(scenario: str) -> list[str]:
    return list(BASELINE_CURRENT_EVIDENCE_FIELDS.get(
        scenario,
        SCENARIO_REQUIRED_EVIDENCE.get(scenario, []),
    ))


def ordered_delivery_flat_current_observed() -> dict[str, Any]:
    if not isinstance(smoke_evidence, dict):
        return {}

    observed: dict[str, Any] = {}
    for evidence_key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]:
        value = flat_smoke_field(evidence_key)
        if value is not MISSING:
            observed[evidence_key] = value

    if "queried_total" not in observed:
        legacy_total = flat_smoke_field("ten_signal_ordered_delivery_total")
        if legacy_total is not MISSING:
            observed["queried_total"] = legacy_total

    if observed and smoke_descriptor is not None:
        observed.setdefault("external_smoke_evidence", smoke_descriptor)

    return observed


def flat_current_observed_for(scenario: str) -> dict[str, Any]:
    if scenario == "ordered_signal_delivery":
        return ordered_delivery_flat_current_observed()

    return {}


def current_candidate_and_observed(scenario: str) -> tuple[dict[str, Any] | None, dict[str, Any]]:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is not None:
        observed = scenario_observed_outputs(candidate)
        if candidate_matches_current_tuple(candidate, observed):
            return candidate, observed

    observed = flat_current_observed_for(scenario)
    if not observed:
        return None, {}

    if evidence_source_policy_violations(smoke_evidence):
        return None, {}

    if not smoke_evidence_matches_current_tuple():
        return None, {}

    return None, observed


def current_evidence_candidate_status(scenario: str) -> str:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        observed = flat_current_observed_for(scenario)
        if observed:
            if evidence_source_policy_violations(smoke_evidence):
                return "source_policy_violation"
            if not smoke_evidence_matches_current_tuple():
                return "not_current_tuple"
            return "current"
        return "missing"

    observed = scenario_observed_outputs(candidate)
    if evidence_source_policy_violations(candidate, observed):
        return "source_policy_violation"

    if not candidate_matches_current_tuple(candidate, observed):
        return "not_current_tuple"

    return "current"


def ordered_delivery_missing_current_evidence(observed: dict[str, Any]) -> list[str]:
    missing = []
    workflow_id = evidence_lookup(observed, "workflow_id")
    run_id = evidence_lookup(observed, "run_id")
    rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
    accepted_inputs = evidence_lookup(observed, "accepted_signal_inputs")
    accepted_signal_total = evidence_lookup(observed, "accepted_signal_total")
    queried_total = evidence_lookup(observed, "queried_total")
    history_signal_order = evidence_lookup(observed, "history_signal_order")
    final_run_status = evidence_lookup(observed, "final_run_status")
    ordered_query_responder = evidence_lookup(observed, "ordered_query_responder")

    if not required_evidence_satisfied("workflow_id", workflow_id):
        missing.append("workflow_id")
    if not required_evidence_satisfied("run_id", run_id):
        missing.append("run_id")
    if rapid_inputs is MISSING:
        missing.append("rapid_increment_inputs")
    if accepted_inputs is MISSING:
        missing.append("accepted_signal_inputs")
    if accepted_signal_total is MISSING:
        missing.append("accepted_signal_total")
    if queried_total is MISSING:
        missing.append("queried_total")
    if history_signal_order is MISSING:
        missing.append("history_signal_order")
    if not required_evidence_satisfied("final_run_status", final_run_status):
        missing.append("final_run_status")
    if ordered_query_responder is MISSING:
        missing.append("ordered_query_responder")
    elif not ordered_query_responder_satisfied(observed):
        missing.append("ordered_query_responder.eligible_when_claimed")

    return missing


def unknown_handler_missing_current_evidence(observed: dict[str, Any]) -> list[str]:
    missing = [
        evidence_key
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE["unknown_signal_and_query_errors"]
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
    ]
    query_reasons = {"query_not_found", "rejected_unknown_query"}
    for evidence_key, minimum, maximum in (
        ("unknown_signal.status_code", 404, 404),
        ("missing_workflow_signal.status_code", 404, 404),
        ("missing_workflow_query.status_code", 404, 404),
        ("query_not_found.status_code", 404, 404),
        ("known_query_after_unknown_errors.status_code", 200, 299),
    ):
        if evidence_lookup(observed, evidence_key) is MISSING:
            missing.append(evidence_key)

    for evidence_key, reasons in (
        ("unknown_signal.reason", {"unknown_signal"}),
        ("missing_workflow_signal.reason", {"instance_not_found"}),
        ("missing_workflow_query.reason", {"instance_not_found"}),
        ("query_not_found.reason", query_reasons),
        ("rejected_unknown_query.reason", query_reasons),
    ):
        if evidence_lookup(observed, evidence_key) is MISSING:
            missing.append(evidence_key)

    if not post_error_query_responder_satisfied(observed):
        missing.append("post_error_query_responder.eligible_when_claimed")
    if not post_error_recovery_immutability_satisfied(observed):
        missing.append("rejected_requests_and_recovery_history_and_commands_unchanged")

    return unique_strings(missing)


def missing_current_evidence_for(scenario: str, observed: dict[str, Any]) -> list[str]:
    if not observed:
        return required_current_evidence_for(scenario)

    if scenario == "python_worker_cli_and_sdk_baseline":
        missing = [
            evidence_key
            for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        ]
        if not is_python_worker_runtime(
            output_field(observed, "worker_runtime", "workerRuntime", "python_worker_runtime")
        ):
            missing.append("worker_runtime")
        if not is_published_python_sdk_source(
            output_field(
                observed,
                "python_worker_artifact_source",
                "pythonWorkerArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        ):
            missing.append("python_worker_artifact_source")
        if not python_sdk_version_matches_current(
            output_field(
                observed,
                "python_worker_sdk_version",
                "pythonWorkerSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        ):
            missing.append("python_worker_sdk_version")
        if not routed_current_query_task_satisfied(
            observed.get("routed_current_query_task", MISSING)
        ):
            missing.append("routed_current_query_task")
        if not python_readiness_boundary_satisfied(
            evidence_lookup(observed, "readiness_boundary")
        ):
            missing.append("readiness_boundary")
        if not python_controlled_restart_satisfied(
            evidence_lookup(observed, "controlled_restart")
        ):
            missing.append("controlled_restart")
        return unique_strings(missing)

    if scenario == "php_worker_cli_and_sdk_baseline":
        missing = [
            evidence_key
            for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        ]
        if not is_sdk_php_worker_runtime(
            output_field(observed, "worker_runtime", "workerRuntime", "php_worker_runtime")
        ):
            missing.append("worker_runtime")
        if not is_published_sdk_php_source(
            output_field(
                observed,
                "sdk_php_artifact_source",
                "sdkPhpArtifactSource",
                "worker_artifact_source",
                "workerArtifactSource",
            )
        ):
            missing.append("sdk_php_artifact_source")
        if not sdk_php_version_matches_current(
            output_field(
                observed,
                "sdk_php_sdk_version",
                "sdkPhpSdkVersion",
                "worker_sdk_version",
                "workerSdkVersion",
            )
        ):
            missing.append("sdk_php_sdk_version")
        if not routed_current_query_task_satisfied(
            evidence_lookup(observed, "routed_current_query_task"),
            expected_runtime="sdk-php",
        ):
            missing.append("routed_current_query_task")
        return unique_strings(missing)

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_missing_current_evidence(observed)

    if scenario == "unknown_signal_and_query_errors":
        return unknown_handler_missing_current_evidence(observed)

    return [
        evidence_key
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, [])
        if not required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
    ]


def python_worker_claim_observed_for_focused_route() -> dict[str, Any]:
    _, observed = current_candidate_and_observed("python_worker_cli_and_sdk_baseline")
    if observed:
        return observed

    if evidence_source_policy_violations(smoke_evidence):
        return {}

    if not smoke_evidence_matches_current_tuple():
        return {}

    observed = {}
    for evidence_key in (
        "worker_runtime",
        "python_worker_artifact_source",
        "python_worker_sdk_version",
    ):
        value = flat_smoke_field(evidence_key)
        if value is not MISSING:
            observed[evidence_key] = value

    return observed


def python_routed_current_missing_route_allowed(missing_current_evidence: list[str]) -> bool:
    return (
        "routed_current_query_task" in missing_current_evidence
        and python_worker_claim_satisfied(python_worker_claim_observed_for_focused_route())
    )


def current_evidence_gaps(scenario: str) -> list[str]:
    if scenario not in BASELINE_CURRENT_EVIDENCE_SCENARIOS:
        return []

    _, observed = current_candidate_and_observed(scenario)

    return missing_current_evidence_for(scenario, observed)


def behavior_failure(code: str, evidence_key: str, expected: Any, actual: Any) -> dict[str, Any]:
    return {
        "code": code,
        "evidence_key": evidence_key,
        "expected": expected,
        "actual": actual,
    }


def sample_readout(sample: Any) -> dict[str, Any] | None:
    if not isinstance(sample, dict):
        return None

    readout = {
        "ok": public_sample_ok(sample),
        "result": sample_result_value(sample),
        "status_code": sample.get("status_code"),
        "reason": sample.get("reason"),
        "exception": sample.get("exception"),
    }
    if isinstance(sample.get("raw_stdout"), str):
        readout["raw_stdout"] = sample["raw_stdout"]

    return readout


def python_baseline_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    installed_version = output_field(
        observed,
        "python_worker_sdk_version",
        "pythonWorkerSdkVersion",
        "worker_sdk_version",
        "workerSdkVersion",
    )
    if (
        installed_version is not MISSING
        and not python_sdk_version_matches_current(installed_version)
    ):
        failures.append(behavior_failure(
            "python_worker_sdk_version_mismatch",
            "python_worker_sdk_version",
            artifact_version_value(artifact_versions, "sdk-python"),
            installed_version,
        ))

    sdk_signal_and_query = evidence_lookup(observed, "sdk_python_signal_and_query")
    if sdk_signal_and_query is not MISSING and not evidence_true(sdk_signal_and_query):
        failures.append(behavior_failure(
            "python_sdk_signal_query_mismatch",
            "sdk_python_signal_and_query",
            "Python SDK signal increment(5) succeeds and Python SDK query current returns 8",
            {
                "sdk_signal": sample_readout(observed.get("sdk_python_signal_sample")),
                "sdk_query": sample_readout(observed.get("sdk_python_query_sample")),
                "error": observed.get("sdk_python_signal_and_query_error"),
            },
        ))

    repeat_consistency = evidence_lookup(observed, "immediate_repeat_query_consistency")
    if repeat_consistency is not MISSING and not evidence_true(repeat_consistency):
        failures.append(behavior_failure(
            "immediate_repeat_query_mismatch",
            "immediate_repeat_query_consistency",
            "immediate repeat query result equals the preceding Python SDK query result",
            {
                "sdk_query": sample_readout(observed.get("sdk_python_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
                "error": observed.get("immediate_repeat_query_consistency_error"),
            },
        ))

    probe_error = observed.get("probe_error")
    if isinstance(probe_error, dict) and probe_error:
        failure_scope = str(probe_error.get("failure_scope") or "workflow_state")
        failures.append(behavior_failure(
            f"python_baseline_{failure_scope}_failed",
            "probe_error",
            "the focused Python baseline completes every deterministic readiness phase",
            probe_error,
        ))

    return failures


def php_baseline_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    sdk_php_sdk_version = output_field(
        observed,
        "sdk_php_sdk_version",
        "sdkPhpSdkVersion",
        "worker_sdk_version",
        "workerSdkVersion",
    )
    actual_sdk_php_version = (
        str(sdk_php_sdk_version).strip()
        if sdk_php_sdk_version is not MISSING
        else ""
    )
    expected_sdk_php_version = artifact_version_value(artifact_versions, "sdk-php")
    if (
        actual_sdk_php_version != ""
        and not is_placeholder_version(actual_sdk_php_version)
        and expected_sdk_php_version != ""
        and not is_placeholder_version(expected_sdk_php_version)
        and actual_sdk_php_version != expected_sdk_php_version
    ):
        failures.append(behavior_failure(
            "sdk_php_sdk_version_mismatch",
            "sdk_php_sdk_version",
            expected_sdk_php_version,
            actual_sdk_php_version,
        ))

    cli_signal_and_query = evidence_lookup(observed, "cli_signal_and_query")
    if cli_signal_and_query is not MISSING and not evidence_true(cli_signal_and_query):
        failures.append(behavior_failure(
            "php_worker_cli_signal_query_mismatch",
            "cli_signal_and_query",
            "CLI signal increment(3) succeeds and CLI query current returns 3 against the PHP worker",
            {
                "cli_signal": sample_readout(observed.get("cli_signal_sample")),
                "cli_query": sample_readout(observed.get("cli_query_sample")),
                "error": observed.get("cli_signal_and_query_error"),
            },
        ))

    sdk_php_signal_and_query = evidence_lookup(observed, "sdk_php_signal_and_query")
    if sdk_php_signal_and_query is not MISSING and not evidence_true(sdk_php_signal_and_query):
        failures.append(behavior_failure(
            "php_sdk_signal_query_mismatch",
            "sdk_php_signal_and_query",
            "PHP SDK signal increment(5) succeeds and PHP SDK query current returns 8 against the PHP worker",
            {
                "sdk_php_signal": sample_readout(observed.get("sdk_php_signal_sample")),
                "sdk_php_query": sample_readout(observed.get("sdk_php_query_sample")),
                "error": observed.get("sdk_php_signal_and_query_error"),
            },
        ))

    repeat_consistency = evidence_lookup(observed, "immediate_repeat_query_consistency")
    if repeat_consistency is not MISSING and not evidence_true(repeat_consistency):
        failures.append(behavior_failure(
            "php_immediate_repeat_query_mismatch",
            "immediate_repeat_query_consistency",
            "immediate repeat query result equals the preceding PHP SDK query result",
            {
                "sdk_php_query": sample_readout(observed.get("sdk_php_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
                "error": observed.get("immediate_repeat_query_consistency_error"),
            },
        ))

    probe_error = observed.get("probe_error")
    if isinstance(probe_error, dict) and probe_error:
        failures.append(behavior_failure(
            "php_worker_mirror_probe_failed",
            "probe_error",
            "published PHP worker completes start, signal, query, and routed current-query evidence",
            {
                "probe_error": probe_error,
                "worker_registration": observed.get("worker_registration"),
                "sdk_php_start": sample_readout(observed.get("sdk_php_start_sample")),
                "initial_query": sample_readout(observed.get("initial_query_sample")),
                "cli_signal": sample_readout(observed.get("cli_signal_sample")),
                "cli_signal_attempt_classification": observed.get("cli_signal_attempt_classification"),
                "post_cli_signal_state": observed.get("post_cli_signal_state"),
                "cli_query": sample_readout(observed.get("cli_query_sample")),
                "sdk_php_signal": sample_readout(observed.get("sdk_php_signal_sample")),
                "sdk_php_query": sample_readout(observed.get("sdk_php_query_sample")),
                "repeat_query": sample_readout(observed.get("repeat_query_sample")),
            },
        ))

    return failures


def cross_language_client_behavior_failures(scenario: str, observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    if scenario == "python_worker_php_facing_and_cli_clients":
        client_field = "php_client_signal_and_query"
        client_expected = (
            "PHP-facing client starts a Python-authored Counter workflow, sends increment(4), "
            "and queries current=4"
        )
        client_actual = {
            "start": sample_readout(observed.get("sdk_php_start_sample")),
            "initial_query": sample_readout(observed.get("sdk_php_initial_query_sample")),
            "signal": sample_readout(observed.get("sdk_php_signal_sample")),
            "query": sample_readout(observed.get("sdk_php_query_sample")),
            "repeat_query": sample_readout(observed.get("sdk_php_repeat_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
        cli_expected = "CLI signal increment(6) succeeds and CLI query current returns 10 against the Python worker"
        cli_actual = {
            "cli_signal": sample_readout(observed.get("cli_signal_sample")),
            "cli_query": sample_readout(observed.get("cli_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
    else:
        client_field = "sdk_python_signal_and_query"
        client_expected = (
            "Python SDK starts a PHP-authored Counter workflow, sends increment(4), "
            "and queries current=4"
        )
        client_actual = {
            "start": sample_readout(observed.get("sdk_python_start_sample")),
            "initial_query": sample_readout(observed.get("cli_initial_query_sample")),
            "signal": sample_readout(observed.get("sdk_python_signal_sample")),
            "query": sample_readout(observed.get("sdk_python_query_sample")),
            "repeat_query": sample_readout(observed.get("sdk_python_repeat_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }
        cli_expected = "CLI signal increment(6) succeeds and CLI query current returns 10 against the PHP worker"
        cli_actual = {
            "cli_signal": sample_readout(observed.get("cli_signal_sample")),
            "cli_query": sample_readout(observed.get("cli_query_sample")),
            "observed_values": observed.get("observed_values"),
            "error": observed.get("probe_error"),
        }

    client_signal_and_query = evidence_lookup(observed, client_field)
    if client_signal_and_query is not MISSING and not evidence_true(client_signal_and_query):
        failures.append(behavior_failure(
            "cross_language_public_client_signal_query_mismatch",
            client_field,
            client_expected,
            client_actual,
        ))

    cli_signal_and_query = evidence_lookup(observed, "cli_signal_and_query")
    if cli_signal_and_query is not MISSING and not evidence_true(cli_signal_and_query):
        failures.append(behavior_failure(
            "cross_language_cli_signal_query_mismatch",
            "cli_signal_and_query",
            cli_expected,
            cli_actual,
        ))

    query_consistency = evidence_lookup(observed, "cross_language_query_consistency")
    if query_consistency is not MISSING and not evidence_true(query_consistency):
        failures.append(behavior_failure(
            "cross_language_query_consistency_mismatch",
            "cross_language_query_consistency",
            "public clients return the same final current query value after all signals",
            {
                "observed_values": observed.get("observed_values"),
                "error": observed.get("probe_error"),
            },
        ))

    wire_compatibility = evidence_lookup(observed, "wire_envelope_compatibility")
    if wire_compatibility is not MISSING and not evidence_true(wire_compatibility):
        failures.append(behavior_failure(
            "cross_language_wire_envelope_incompatible",
            "wire_envelope_compatibility",
            "public clients exchange language-neutral payload envelopes with the worker runtime",
            {
                "commands_and_api_samples": observed.get("commands_and_api_samples"),
                "error": observed.get("probe_error"),
            },
        ))

    probe_error = observed.get("probe_error")
    if isinstance(probe_error, dict) and probe_error:
        failures.append(behavior_failure(
            "cross_language_client_matrix_probe_failed",
            "probe_error",
            "focused cross-language public client matrix completes against published artifacts",
            {
                "probe_error": probe_error,
                "commands_and_api_samples": observed.get("commands_and_api_samples"),
                "observed_values": observed.get("observed_values"),
            },
        ))

    return failures


def ordered_delivery_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    expected_inputs = expected_rapid_signal_inputs()
    rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
    accepted_inputs = evidence_lookup(observed, "accepted_signal_inputs")
    accepted_signal_total = evidence_lookup(observed, "accepted_signal_total")
    queried_total = evidence_lookup(observed, "queried_total")
    history_signal_order = evidence_lookup(observed, "history_signal_order")
    rapid_sequence = integer_sequence(rapid_inputs)
    accepted_sequence = integer_sequence(accepted_inputs)
    reference_sequence = ordered_delivery_reference_inputs(observed)

    if rapid_inputs is not MISSING and rapid_sequence != expected_inputs:
        failures.append(behavior_failure(
            "unexpected_ordered_signal_inputs",
            "rapid_increment_inputs",
            expected_inputs,
            rapid_inputs,
        ))
    if (
        accepted_inputs is not MISSING
        and rapid_sequence is not None
        and accepted_sequence != rapid_sequence
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_acceptance",
            "accepted_signal_inputs",
            rapid_sequence,
            accepted_inputs,
        ))
    if (
        accepted_signal_total is not MISSING
        and reference_sequence is not None
        and integer_value(accepted_signal_total) != sum(reference_sequence)
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_accepted_total",
            "accepted_signal_total",
            sum(reference_sequence),
            accepted_signal_total,
        ))
    if (
        queried_total is not MISSING
        and reference_sequence is not None
        and integer_value(queried_total) != sum(reference_sequence)
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_total",
            "queried_total",
            sum(reference_sequence),
            queried_total,
        ))
    if (
        history_signal_order is not MISSING
        and reference_sequence is not None
        and integer_sequence(history_signal_order) != reference_sequence
    ):
        failures.append(behavior_failure(
            "unexpected_ordered_signal_history_order",
            "history_signal_order",
            reference_sequence,
            history_signal_order,
        ))

    return failures


def dedup_contract_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    handler_observation_count = evidence_lookup(observed, "handler_observation_count")
    count = integer_value(handler_observation_count)
    if handler_observation_count is not MISSING and (count is None or count < 1):
        failures.append(behavior_failure(
            "duplicate_signal_not_observed",
            "handler_observation_count",
            "at least one delivered duplicate/repeated signal observation",
            handler_observation_count,
        ))

    return failures


def unknown_handler_behavior_failures(observed: dict[str, Any]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    query_reasons = {"query_not_found", "rejected_unknown_query"}
    for evidence_key, minimum, maximum in (
        ("unknown_signal.status_code", 404, 404),
        ("missing_workflow_signal.status_code", 404, 404),
        ("missing_workflow_query.status_code", 404, 404),
        ("query_not_found.status_code", 404, 404),
        ("known_query_after_unknown_errors.status_code", 200, 299),
    ):
        actual = evidence_lookup(observed, evidence_key)
        if actual is not MISSING and not status_code_in_range(observed, evidence_key, minimum, maximum):
            failures.append(behavior_failure(
                "unexpected_unknown_handler_status_code",
                evidence_key,
                f"{minimum}..{maximum}",
                actual,
            ))

    for evidence_key, reasons in (
        ("unknown_signal.reason", {"unknown_signal"}),
        ("missing_workflow_signal.reason", {"instance_not_found"}),
        ("missing_workflow_query.reason", {"instance_not_found"}),
        ("query_not_found.reason", query_reasons),
        ("rejected_unknown_query.reason", query_reasons),
    ):
        actual = evidence_lookup(observed, evidence_key)
        if actual is not MISSING and not reason_in(observed, evidence_key, reasons):
            failures.append(behavior_failure(
                "unexpected_unknown_handler_reason",
                evidence_key,
                sorted(reasons),
                actual,
            ))

    expected_known_result = evidence_lookup(observed, "known_query_after_unknown_expected")
    actual_known_result = evidence_lookup(observed, "known_query_after_unknown_result")
    if (
        expected_known_result is not MISSING
        and actual_known_result is not MISSING
        and actual_known_result != expected_known_result
    ):
        failures.append(behavior_failure(
            "unexpected_known_query_after_unknown_result",
            "known_query_after_unknown_result",
            expected_known_result,
            actual_known_result,
        ))

    if evidence_lookup(observed, "rejected_requests_and_recovery_appended_no_history") is False:
        failures.append(behavior_failure(
            "rejected_requests_or_recovery_appended_history",
            "rejected_requests_and_recovery_appended_no_history",
            True,
            False,
        ))
    if evidence_lookup(observed, "rejected_signal_audit_rows_match_expected") is False:
        failures.append(behavior_failure(
            "rejected_signal_audit_rows_differed_from_expected",
            "rejected_signal_audit_rows_match_expected",
            True,
            False,
        ))
    if evidence_lookup(observed, "rejected_requests_created_no_executable_or_ready_work") is False:
        failures.append(behavior_failure(
            "rejected_request_created_executable_or_ready_work",
            "rejected_requests_created_no_executable_or_ready_work",
            True,
            False,
        ))
    handler_invocations = evidence_lookup(observed, "rejected_signal_handler_invocation_count")
    if handler_invocations is not MISSING and integer_value(handler_invocations) != 0:
        failures.append(behavior_failure(
            "rejected_signal_invoked_handler",
            "rejected_signal_handler_invocation_count",
            0,
            handler_invocations,
        ))
    if evidence_lookup(observed, "rejected_requests_mutated_no_workflow_state") is False:
        failures.append(behavior_failure(
            "rejected_request_mutated_workflow_state",
            "rejected_requests_mutated_no_workflow_state",
            True,
            False,
        ))

    return failures


def current_behavior_failures_for(scenario: str, observed: dict[str, Any]) -> list[dict[str, Any]]:
    if scenario == "python_worker_cli_and_sdk_baseline":
        return python_baseline_behavior_failures(observed)

    if scenario == "php_worker_cli_and_sdk_baseline":
        return php_baseline_behavior_failures(observed)

    if scenario in {
        "python_worker_php_facing_and_cli_clients",
        "php_worker_python_and_cli_clients",
    }:
        return cross_language_client_behavior_failures(scenario, observed)

    if scenario == "ordered_signal_delivery":
        return ordered_delivery_behavior_failures(observed)

    if scenario == "dedup_contract_observation":
        return dedup_contract_behavior_failures(observed)

    if scenario == "unknown_signal_and_query_errors":
        return unknown_handler_behavior_failures(observed)

    return []


def current_behavior_failures(scenario: str) -> list[dict[str, Any]]:
    if scenario not in BASELINE_CURRENT_EVIDENCE_SCENARIOS:
        return []

    _, observed = current_candidate_and_observed(scenario)
    if not observed:
        return []

    return current_behavior_failures_for(scenario, observed)


def runner_blocker_from_descriptor(descriptor: Any) -> dict[str, Any] | None:
    if not isinstance(descriptor, dict):
        return None

    blocker = descriptor.get("runner_blocker")
    if not isinstance(blocker, dict):
        return None

    if blocker.get("kind") != "server_readiness_topology":
        return None

    return blocker


result_dir = Path(os.environ["RESULT_DIR"])
started_at = os.environ["STARTED_AT"]
artifact_versions = {
    "server": os.environ["DW_SERVER_VERSION"],
    "cli": os.environ["DW_CLI_VERSION"],
    "sdk-python": os.environ["DW_PYTHON_SDK_VERSION"],
    "sdk-rust": os.environ["DW_RUST_SDK_VERSION"],
    "workflow": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "sdk-php": os.environ["DW_PHP_SDK_VERSION"],
    "waterline": os.environ["DW_WATERLINE_VERSION"],
}

smoke_path = os.environ.get("DW_SIGNALS_QUERIES_EVIDENCE", "") or os.environ.get(
    "DW_SIGNALS_QUERIES_SMOKE_EVIDENCE",
    "",
)
smoke_evidence: Any = None
external_smoke_evidence: Any = None
smoke_descriptor: dict[str, Any] | None = None
distribution_identity_handoff_failures: list[str] = []
if smoke_path:
    candidate = Path(smoke_path)
    if candidate.is_file():
        raw = candidate.read_bytes()
        smoke_descriptor = {
            "file": candidate.name,
            "sha256": hashlib.sha256(raw).hexdigest(),
        }
        try:
            smoke_evidence = json.loads(raw.decode("utf-8"))
            external_smoke_evidence = smoke_evidence
        except Exception as exc:
            smoke_descriptor["decode_error"] = f"{type(exc).__name__}: {exc}"

reset_files = reset_current_run_files(result_dir)
if smoke_descriptor is not None and reset_files:
    smoke_descriptor["reset_current_run_files"] = reset_files

fresh_service_probe_required = waterline_service_probe_requires_fresh_evidence()
if fresh_service_probe_required:
    smoke_evidence, prior_service_evidence_discarded = without_scenario_evidence(
        smoke_evidence,
        WATERLINE_SERVICE_SCENARIO,
    )
    if smoke_descriptor is not None:
        smoke_descriptor["prior_waterline_service_evidence_discarded"] = (
            prior_service_evidence_discarded
        )

if isinstance(external_smoke_evidence, dict):
    identity_handoff = external_smoke_evidence.get(
        "executed_distribution_identities",
        external_smoke_evidence.get("executedDistributionIdentities"),
    )
    if identity_handoff is not None:
        try:
            merge_distribution_identity_handoff(
                identity_handoff,
                executed_distribution_identities_path(),
                artifact_versions,
            )
        except Exception as exc:  # noqa: BLE001 - conflicting staged bytes are a product failure.
            distribution_identity_handoff_failures.append(
                f"executed distribution identity handoff was rejected: {type(exc).__name__}: {exc}"
            )

baseline_evidence, baseline_descriptor = run_baseline_probe(result_dir)
if baseline_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, baseline_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["baseline_probe"] = baseline_descriptor
elif baseline_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["baseline_probe"] = baseline_descriptor

probe_evidence, probe_descriptor = run_adversarial_probe(result_dir, smoke_evidence)
if probe_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, probe_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["adversarial_probe"] = probe_descriptor
elif probe_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["adversarial_probe"] = probe_descriptor

try:
    waterline_evidence, waterline_descriptor = run_waterline_observer_probe(result_dir, smoke_evidence)
except Exception as exc:  # noqa: BLE001 - the Waterline shard must not erase sibling evidence.
    waterline_evidence = waterline_observer_setup_result(
        status="fail",
        reason=f"Waterline observer shard failed before producing evidence: {type(exc).__name__}: {exc}",
        blocker_kind="waterline_observer_probe_exception",
    )
    waterline_descriptor = {
        "error": f"{type(exc).__name__}: {exc}",
        "generated_scenarios": ["waterline_operator_visibility"],
    }
if waterline_evidence is not None:
    smoke_evidence = merge_probe_evidence(smoke_evidence, waterline_evidence)
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["waterline_observer_probe"] = waterline_descriptor
elif waterline_descriptor is not None:
    if smoke_descriptor is None:
        smoke_descriptor = {}
    smoke_descriptor["waterline_observer_probe"] = waterline_descriptor

baseline_readiness_blocker = runner_blocker_from_descriptor(baseline_descriptor)

required_scenarios = [
    "published_artifact_install_only",
    "python_worker_cli_and_sdk_baseline",
    "php_worker_cli_and_sdk_baseline",
    "python_worker_php_facing_and_cli_clients",
    "php_worker_python_and_cli_clients",
    "rust_worker_rust_php_python_clients",
    "python_worker_rust_client",
    "php_worker_rust_client",
    "rust_query_error_and_immutability",
    "ordered_signal_delivery",
    "dedup_contract_observation",
    "signal_during_replay",
    "query_during_replay",
    "rust_replayed_instance_state_query_after_cold_restart",
    "completed_run_signal_and_query",
    "unknown_signal_and_query_errors",
    "malformed_signal_and_query_payloads",
    "waterline_operator_visibility",
    "waterline_service_operator_visibility",
]

scenario_routes = {
    "published_artifact_install_only": {
        "type": "signal_query_published_artifact_install_uncovered",
        "owner": "conformance_harness",
        "title": "Signals/queries published-artifact install evidence remains unproved",
        "acceptance": [
            "resolve concrete server, CLI, Python SDK, PHP workflow, and Waterline versions",
            "prove every actor starts from a published package, image, or release asset",
        ],
    },
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_smoke_uncovered",
        "owner": "sdk-python, cli, server",
        "title": "Signals/queries Python worker CLI and SDK baseline remains unproved",
        "acceptance": [
            "start Counter on the Python worker",
            "verify CLI and Python SDK signals update query-visible state",
            "record a routed current query task from the public CLI through the server to the Python SDK worker",
            "record immediate repeat-query consistency",
        ],
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_uncovered",
        "owner": "server",
        "title": "Signals/queries ordered delivery evidence remains unproved",
        "acceptance": [
            "send increment(1) through increment(10) rapidly",
            "record the accepted signal sequence",
            "record the accepted signal total",
            "query total matching the accepted signal sequence",
            "record history signal order matching the accepted signal sequence",
        ],
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_uncovered",
        "owner": "server, sdk-python, workflow, cli, docs",
        "title": "Signals/queries dedup contract remains unproved",
        "acceptance": [
            "send duplicate signals with the documented idempotency or dedup key when supported",
            "record whether the handler observes one transition or two",
            "link any docs/runtime mismatch to the owning surface",
        ],
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_uncovered",
        "owner": "workflow",
        "title": "Signals/queries PHP worker mirror remains unproved",
        "acceptance": [
            "start Counter on the PHP worker",
            "verify CLI and PHP SDK signals update query-visible state",
            "record PHP handler and query evidence using published artifacts",
            "record a routed current query task from the public CLI through the server to the PHP worker",
        ],
    },
    "python_worker_php_facing_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, cli, server",
        "title": "Signals/queries Python worker with PHP-facing clients remains unproved",
        "acceptance": [
            "start Counter on the Python worker from a PHP-facing client",
            "send signals from PHP and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "php_worker_python_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, sdk-python, cli, server",
        "title": "Signals/queries PHP worker with Python and CLI clients remains unproved",
        "acceptance": [
            "start Counter on the PHP worker from the Python SDK",
            "send signals from Python and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "rust_worker_rust_php_python_clients": {
        "type": "signal_query_rust_worker_client_matrix_uncovered",
        "owner": "sdk-rust, workflow, sdk-python, server",
        "title": "Signals/queries Rust worker and published client matrix remains unproved",
        "acceptance": [
            "install the exact durable-workflow crate from crates.io",
            "run the Rust snapshot-query worker and exercise Rust, PHP, and Python callers",
            "prove a valid Apache Avro signal/query round trip and completed-run reads",
        ],
    },
    "python_worker_rust_client": {
        "type": "signal_query_python_worker_rust_client_uncovered",
        "owner": "sdk-rust, sdk-python, server",
        "title": "Signals/queries Python worker with Rust client remains unproved",
        "acceptance": [
            "send ordered Avro signals from the exact crates.io Rust client to a Python workflow",
            "query repeatedly through Rust and observe the expected state",
        ],
    },
    "php_worker_rust_client": {
        "type": "signal_query_php_worker_rust_client_uncovered",
        "owner": "sdk-rust, workflow, server",
        "title": "Signals/queries PHP worker with Rust client remains unproved",
        "acceptance": [
            "send ordered Avro signals from the exact crates.io Rust client to a PHP workflow",
            "query repeatedly through Rust and observe the expected state",
        ],
    },
    "rust_query_error_and_immutability": {
        "type": "signal_query_rust_error_immutability_uncovered",
        "owner": "sdk-rust, server",
        "title": "Rust query error and immutability outcomes remain unproved",
        "acceptance": [
            "capture the history and workflow-command baseline before the first successful Rust query",
            "exercise every stable query failure and terminal signal outcome",
            "prove successful and failed queries append no history, emit no commands, and preserve later answers",
        ],
    },
    "signal_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Signals during replay timing remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "send a signal while replay is in progress",
            "prove the signal applies after replay reaches a consistent point",
        ],
    },
    "query_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Query during replay consistency remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "query while replay is in progress",
            "prove the answer matches the expected replay-consistent state",
        ],
    },
    "rust_replayed_instance_state_query_after_cold_restart": {
        "type": "signal_query_rust_replayed_state_cold_restart_uncovered",
        "owner": "sdk-rust, workflow, sdk-python, server",
        "title": "Rust replayed instance-state query after cold restart remains unproved",
        "acceptance": [
            "use register_replayed_workflow and register_replayed_query from the exact published crate",
            "cold-stop the first worker and query restored durable state from a fresh worker process",
            "verify running, restored, and completed state through Rust, PHP, and Python callers",
            "keep deterministic workflow replay evidence distinct from snapshot inspection",
        ],
    },
    "completed_run_signal_and_query": {
        "type": "signal_query_completed_run_handling_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries completed-run handling remains unproved",
        "acceptance": [
            "complete Counter cleanly with a replayable query handler",
            "prove signal-to-completed-run returns a typed terminal outcome",
            "prove every claimed query surface returns final state or a documented handler-unavailable error",
        ],
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries unknown-handler errors remain unproved",
        "acceptance": [
            "send an unknown signal and unknown query",
            "capture stable typed error envelopes",
            "prove known queries still work after the errors",
        ],
    },
    "malformed_signal_and_query_payloads": {
        "type": "signal_query_adversarial_error_shapes_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries malformed-payload errors remain unproved",
        "acceptance": [
            "send malformed signal and query payloads",
            "capture stable validation or decoding errors with argument context",
            "prove malformed attempts do not mutate workflow state",
            "record public CLI and Python SDK error samples for malformed signal and query calls",
        ],
    },
    "waterline_operator_visibility": {
        "type": "signal_query_waterline_observer_comparison_uncovered",
        "owner": "waterline",
        "title": "Signals/queries Waterline observer comparison remains unproved",
        "acceptance": [
            "compare Waterline selected-run detail against server, CLI, and SDK observations",
            "show applied, rejected, and terminal-run signal/query outcomes",
            "record any unsupported Waterline query-result materialization as an explicit finding",
        ],
    },
    "waterline_service_operator_visibility": {
        "type": "signal_query_waterline_service_observer_uncovered",
        "owner": "waterline",
        "title": "Signals/queries published Waterline service-image coverage remains unproved",
        "acceptance": [
            "execute the immutable published Waterline service manifest on the candidate server network",
            "retain its source revision and release labels plus top-level manifest digest",
            "exercise selected-run reads, query, and signal actions through the public PHP SDK backend",
        ],
    },
}

smoke_attached = smoke_evidence is not None
smoke_tuple_matches = smoke_evidence_matches_current_tuple()
smoke_tuple_mismatches = smoke_artifact_version_mismatches()
smoke_source_policy_violations = evidence_source_policy_violations(smoke_evidence)
smoke_source_policy_ok = smoke_source_policy_violations == []
external_smoke_attached = external_smoke_evidence is not None
external_smoke_tuple_matches = evidence_matches_current_tuple(external_smoke_evidence)
external_smoke_source_policy_violations = evidence_source_policy_violations(external_smoke_evidence)
external_smoke_source_policy_ok = external_smoke_source_policy_violations == []
install_evidence_outputs = explicit_install_observed_outputs(external_smoke_evidence)
if smoke_descriptor is not None and smoke_tuple_mismatches:
    smoke_descriptor["artifact_version_mismatches"] = smoke_tuple_mismatches
if smoke_descriptor is not None and smoke_source_policy_violations:
    smoke_descriptor["artifact_source_policy_violations"] = smoke_source_policy_violations
if smoke_descriptor is not None and external_smoke_source_policy_violations:
    smoke_descriptor["external_artifact_source_policy_violations"] = external_smoke_source_policy_violations
install_evidence_pass = (
    external_smoke_attached
    and external_smoke_tuple_matches
    and external_smoke_source_policy_ok
    and has_required_evidence("published_artifact_install_only", install_evidence_outputs)
)
python_smoke_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_python_smoke_present()
ordered_delivery_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_ordered_delivery_smoke_present()
scenario_results: dict[str, dict[str, Any]] = {}
findings: list[dict[str, Any]] = []
finding_links: dict[str, list[str]] = {}

for scenario in required_scenarios:
    observed: dict[str, Any] = {}
    status = "not_covered"
    imported_result = imported_scenario_result(scenario)

    if imported_result is not None:
        result = imported_result
        status = str(result["status"])
    elif install_evidence_pass and scenario == "published_artifact_install_only":
        status = "pass"
        observed = dict(install_evidence_outputs)
        observed.setdefault("published_artifact_versions", artifact_versions)
        observed.setdefault(
            "artifact_sources",
            dict(EXPECTED_ARTIFACT_SOURCES),
        )
        observed["external_smoke_evidence"] = smoke_descriptor
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif python_smoke_pass and scenario == "python_worker_cli_and_sdk_baseline":
        status = "pass"
        observed = {
            "worker_runtime": smoke_field("worker_runtime", scenario),
            "python_worker_artifact_source": smoke_field("python_worker_artifact_source", scenario),
            "python_worker_sdk_version": smoke_field("python_worker_sdk_version", scenario),
            "python_worker_query_task_routing": smoke_field(
                "python_worker_query_task_routing",
                scenario,
            ),
            "routed_current_query_task": smoke_field(
                "routed_current_query_task",
                scenario,
            ),
            "cli_signal_and_query": smoke_field("cli_signal_and_query", scenario),
            "sdk_python_signal_and_query": smoke_field("sdk_python_signal_and_query", scenario),
            "immediate_repeat_query_consistency": smoke_field(
                "immediate_repeat_query_consistency",
                scenario,
            ),
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif ordered_delivery_pass and scenario == "ordered_signal_delivery":
        status = "pass"
        observed = {
            "workflow_id": smoke_field("workflow_id", scenario),
            "run_id": smoke_field("run_id", scenario),
            "rapid_increment_inputs": smoke_field("rapid_increment_inputs", scenario),
            "accepted_signal_inputs": smoke_field("accepted_signal_inputs", scenario),
            "accepted_signal_total": smoke_field("accepted_signal_total", scenario),
            "queried_total": smoke_field("queried_total", scenario),
            "history_signal_order": smoke_field("history_signal_order", scenario),
            "final_run_status": smoke_field("final_run_status", scenario),
            "external_smoke_evidence": smoke_descriptor,
        }
        ordered_query_responder = smoke_field("ordered_query_responder", scenario)
        if ordered_query_responder is not MISSING:
            observed["ordered_query_responder"] = ordered_query_responder
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    else:
        result = {
            "scenario_id": scenario,
            "status": status,
        }

    if status != "pass":
        linked_findings = result.get("linked_findings")
        if isinstance(linked_findings, list) and linked_findings:
            finding_links[scenario] = linked_findings
            findings.extend([item for item in linked_findings if isinstance(item, dict)])
        else:
            route = scenario_routes[scenario]
            behavior_failures: list[dict[str, Any]] = []
            missing_current_evidence: list[str] = []
            candidate_status = current_evidence_candidate_status(scenario)

            if baseline_readiness_blocker is not None and scenario in SERVER_BASELINE_SCENARIOS:
                status = "runner_blocked"
                result["status"] = status
                result["observed_outputs"] = {
                    "server_readiness_topology": baseline_readiness_blocker,
                }
                route = {
                    **route,
                    "type": f"signal_query_{scenario}_server_readiness_topology",
                    "owner": "conformance_harness",
                    "title": "Signals/queries published server readiness topology blocked baseline evidence",
                    "acceptance": [
                        "make the published server /api/ready endpoint reachable from the host runner",
                        "record the effective host endpoint and compose port/container diagnostics",
                        "rerun the baseline signals/queries scenarios after readiness is reachable",
                    ],
                }
                finding_id = route["type"]
            else:
                behavior_failures = current_behavior_failures(scenario)

            if behavior_failures:
                failure_route = BASELINE_PRODUCT_FAILURE_ROUTES.get(scenario)
                if failure_route is not None:
                    route = {
                        **route,
                        "type": failure_route["type"],
                        "title": failure_route["title"],
                    }
                status = "fail"
                result["status"] = status

            finding_id = route["type"]
            if status != "runner_blocked":
                missing_current_evidence = current_evidence_gaps(scenario)
                if scenario == "ordered_signal_delivery":
                    _, current_observed = current_candidate_and_observed(scenario)
                    if current_observed:
                        result.setdefault("observed_outputs", current_observed)
            if missing_current_evidence and not behavior_failures:
                current_missing_route = BASELINE_CURRENT_MISSING_ROUTES.get(scenario)
                if (
                    scenario == "python_worker_cli_and_sdk_baseline"
                    and not python_routed_current_missing_route_allowed(missing_current_evidence)
                ):
                    current_missing_route = None
                if current_missing_route is not None:
                    if scenario == "python_worker_cli_and_sdk_baseline":
                        missing_current_evidence = ["routed_current_query_task"]
                    route = {
                        **route,
                        "type": current_missing_route["type"],
                        "title": current_missing_route["title"],
                    }
                    finding_id = route["type"]
            finding = {
                "id": finding_id,
                "type": route["type"],
                "scenario_id": scenario,
                "owner": route["owner"],
                "title": route["title"],
                "current_evidence": {
                    "published_artifact_evidence_present": smoke_attached,
                    "evidence": smoke_descriptor,
                },
                "acceptance": route["acceptance"],
            }
            if behavior_failures:
                finding["current_evidence"]["current_evidence_candidate_status"] = candidate_status
                finding["current_evidence"]["current_behavior_failures"] = behavior_failures
                if scenario == "ordered_signal_delivery":
                    _, ordered_observed = current_candidate_and_observed(scenario)
                    ordered_readout = {
                        key: ordered_observed[key]
                        for key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]
                        if key in ordered_observed
                    }
                    if ordered_readout:
                        finding["current_evidence"]["ordered_delivery_observed_outputs"] = ordered_readout
                finding["observed_behavior"] = "current published artifacts produced behavior outside the signals/queries contract"
            if status == "runner_blocked":
                finding["blocker_kind"] = "server_readiness_topology"
                finding["runner_blocker"] = baseline_readiness_blocker
                finding["current_evidence"]["server_readiness_topology"] = baseline_readiness_blocker
                finding["observed_behavior"] = (
                    "published server endpoint was not reachable from the host before baseline scenario generation"
                )
            if missing_current_evidence and not behavior_failures:
                finding["title"] = (
                    f"{route['title']}: missing current evidence "
                    f"{', '.join(missing_current_evidence)}"
                )
                finding["current_evidence"]["current_evidence_candidate_present"] = candidate_status == "current"
                finding["current_evidence"]["current_evidence_candidate_status"] = candidate_status
                finding["current_evidence"]["missing_current_evidence"] = missing_current_evidence
            result["linked_findings"] = [finding_id]
            findings.append(finding)
            finding_links[scenario] = [finding_id]

    scenario_results[scenario] = result

executed_distribution_identities, distribution_identity_validation_failures = (
    validate_required_distribution_identities(
        executed_distribution_identities_path(),
        artifact_versions,
    )
)
distribution_identity_failures = list(dict.fromkeys([
    *distribution_identity_handoff_failures,
    *distribution_identity_validation_failures,
]))
if distribution_identity_failures:
    identity_finding = {
        "id": "executed_distribution_identity_missing_or_conflicting",
        "type": "executed_distribution_identity_missing_or_conflicting",
        "scenario_id": "executed_distribution_identities",
        "owner": "conformance_harness",
        "title": "Signals/queries execution did not retain a complete, conflict-free distribution identity set",
        "current_evidence": {
            "failures": distribution_identity_failures,
            "observed_components": sorted(executed_distribution_identities),
        },
        "observed_behavior": "consumed distribution identity evidence was missing, malformed, or conflicting",
        "acceptance": [
            "retain the package, crate, release asset, and OCI manifest identities consumed by every passing shard",
            "reject a repeated component when the same artifact name has different consumed bytes",
        ],
    }
    findings.append(identity_finding)
    finding_links["executed_distribution_identities"] = [identity_finding["id"]]

pins = {
    "artifact_versions": artifact_versions,
    "artifact_sources": dict(EXPECTED_ARTIFACT_SOURCES),
}
write_json(result_dir / "pins.json", pins)


def retained_runner_blockers(
    results: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    blockers: list[dict[str, Any]] = []
    seen: set[str] = set()
    for scenario_id, scenario_result in results.items():
        if scenario_result.get("status") != "runner_blocked":
            continue
        observed = scenario_result.get("observed_outputs")
        if not isinstance(observed, dict):
            continue
        setup_failure = observed.get("setup_failure") or observed.get("rust_setup_failure")
        if not isinstance(setup_failure, dict):
            continue
        classification = setup_failure.get("classification")
        blocker_kind = (
            str(classification.get("blocker_kind") or "runner_setup_failure")
            if isinstance(classification, dict)
            else "runner_setup_failure"
        )
        identity = json.dumps(setup_failure, sort_keys=True, separators=(",", ":"))
        digest = hashlib.sha256(identity.encode("utf-8")).hexdigest()
        if digest in seen:
            continue
        seen.add(digest)
        blockers.append({
            "scenario_id": scenario_id,
            "blocker_kind": blocker_kind,
            "setup_failure": setup_failure,
        })
    return blockers


finished_at = now()
runner_blocked = any(item["status"] == "runner_blocked" for item in scenario_results.values())
runner_blockers = retained_runner_blockers(scenario_results)

run_metadata = {
    "schema": "durable-workflow.v2.signal-query-runtime.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner": "scripts/conformance/signals-queries-published-artifacts.sh",
    "local_product_source_checkouts_used": False,
    "smoke_evidence": smoke_descriptor,
    "executed_distribution_identity_failures": distribution_identity_failures,
    "executed_distribution_identity_observed_components": sorted(
        executed_distribution_identities
    ),
}
if runner_blocked and baseline_readiness_blocker is not None:
    run_metadata["runner_blocker"] = baseline_readiness_blocker
if runner_blockers:
    run_metadata["runner_blockers"] = runner_blockers
write_json(result_dir / "run-metadata.json", run_metadata)
write_json(result_dir / "signals-queries-findings.json", findings)

PORTABLE_COMMON_SCENARIO_EVIDENCE = (
    "published_artifact_versions",
    "artifact_versions",
    "artifactVersions",
    "artifact_sources",
    "local_product_source_checkouts_used",
)
PORTABLE_OPTIONAL_SCENARIO_EVIDENCE = {
    "python_worker_cli_and_sdk_baseline": (
        "workflow_id",
        "run_id",
        "task_queue",
        "worker_id",
        "python_worker_expected_sdk_version",
        "probe_phase",
        "probe_error",
        "routed_current_query_task_error",
    ),
    "php_worker_cli_and_sdk_baseline": (
        "workflow_id",
        "run_id",
        "task_queue",
        "worker_id",
        "worker_registration",
        "sdk_php_start_sample",
        "initial_query_sample",
        "cli_signal_sample",
        "post_cli_signal_state",
        "cli_signal_attempt_classification",
        "published_client_invocations",
    ),
    "php_worker_python_and_cli_clients": (
        "workflow_id",
        "run_id",
        "task_queue",
        "worker_id",
        "published_client_invocations",
    ),
    "php_worker_rust_client": (
        "workflow_id",
        "run_id",
        "task_queue",
        "worker_id",
        "published_client_invocations",
    ),
    "unknown_signal_and_query_errors": (
        "cli_unknown_signal_sample",
        "cli_unknown_query_sample",
        "cli_missing_workflow_signal_sample",
        "cli_missing_workflow_query_sample",
        "sdk_python_unknown_signal_sample",
        "sdk_python_unknown_query_sample",
        "sdk_python_missing_workflow_signal_sample",
        "sdk_python_missing_workflow_query_sample",
    ),
    "waterline_service_operator_visibility": (
        "setup_failure",
        "service_query_evidence",
    ),
}
PORTABLE_SENSITIVE_KEY_PARTS = (
    "authorization",
    "cookie",
    "credential",
    "password",
    "privatekey",
    "secret",
)
PORTABLE_SENSITIVE_KEY_SUFFIXES = (
    "apikey",
    "appkey",
    "passphrase",
    "token",
)
PORTABLE_SENSITIVE_KEY_STANDALONE_ATOMS = {
    "auth",
    "authentication",
    "authorization",
    "cookie",
    "credential",
    "credentials",
    "passphrase",
    "password",
    "secret",
    "token",
}
PORTABLE_UNBOUNDED_VALUE_KEYS = {
    "customerpayload",
    "debuglog",
    "eventhistory",
    "historyevents",
    "rawpayload",
    "stacktrace",
    "workflowpayload",
}
PORTABLE_PRIORITY_KEYS = {
    "artifact",
    "status",
    "status_code",
    "outcome",
    "reason",
    "rejection_reason",
    "version",
    "resolved_version",
    "source",
    "worker_runtime",
    "worker_id",
    "task_queue",
    "workflow_id",
    "run_id",
    "query_name",
    "signal_name",
    "sha256",
}
PORTABLE_FAILURE_SUMMARY_STRING_LIMIT = 1024
# A failed 60-second query wait can contribute at most 120 half-second attempts;
# the target PHP-worker cells perform no more than six invocations before that wait.
PORTABLE_CLIENT_INVOCATION_LIMIT = 128
PORTABLE_TEXT_FIELD_PATTERN = re.compile(
    r'''(?ix)
    (?P<prefix>
        (?<![A-Za-z0-9_-])
        (?P<key_quote>["']?)
        (?P<key>[A-Za-z][A-Za-z0-9_-]*)
        (?P=key_quote)
        \s*[:=]\s*
    )
    (?P<value>
        "(?:\\.|[^"\\])*"
        | '(?:\\.|[^'\\])*'
        | [^\s,;&}\]]+
    )
    ''',
)


def portable_key_token(value: Any) -> str:
    return re.sub(r"[^a-z0-9]", "", str(value).lower())


def portable_key_atoms(value: Any) -> list[str]:
    separated = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", str(value))
    separated = re.sub(r"([A-Z]+)([A-Z][a-z])", r"\1_\2", separated)
    return [part.lower() for part in re.split(r"[^A-Za-z0-9]+", separated) if part]


def portable_sensitive_key(value: Any) -> bool:
    token = portable_key_token(value)
    atoms = portable_key_atoms(value)
    atom_set = set(atoms)
    compound_key = (
        ({"api", "key"} <= atom_set)
        or ({"api", "keys"} <= atom_set)
        or ({"app", "key"} <= atom_set)
        or ({"app", "keys"} <= atom_set)
        or ({"private", "key"} <= atom_set)
        or ({"private", "keys"} <= atom_set)
    )
    return (
        compound_key
        or any(atom in PORTABLE_SENSITIVE_KEY_STANDALONE_ATOMS for atom in atoms)
        or any(part in token for part in PORTABLE_SENSITIVE_KEY_PARTS)
        or any(token.endswith(suffix) for suffix in PORTABLE_SENSITIVE_KEY_SUFFIXES)
    )


def portable_redact_field_value(match: re.Match[str]) -> str:
    if not portable_sensitive_key(match.group("key")):
        return match.group(0)
    raw_value = match.group("value")
    if len(raw_value) >= 2 and raw_value[0] in "\"'" and raw_value[-1] == raw_value[0]:
        replacement = f"{raw_value[0]}<redacted>{raw_value[0]}"
    else:
        replacement = "<redacted>"
    return match.group("prefix") + replacement


def portable_redact_query_value(match: re.Match[str]) -> str:
    if not portable_sensitive_key(match.group("key")):
        return match.group(0)
    return match.group("prefix") + "<redacted>"


def portable_redact_field_values(value: str) -> str:
    retained: list[str] = []
    retained_until = 0
    search_from = 0
    while match := PORTABLE_TEXT_FIELD_PATTERN.search(value, search_from):
        if not portable_sensitive_key(match.group("key")):
            search_from = match.start() + 1
            continue
        retained.append(value[retained_until:match.start()])
        retained.append(portable_redact_field_value(match))
        retained_until = match.end()
        search_from = match.end()
    retained.append(value[retained_until:])
    return "".join(retained)


def portable_sanitize_text(value: str) -> str:
    sanitized = value
    sanitized = re.sub(
        r"(?i)\b(bearer|basic)\s+[^\s,;]+",
        r"\1 <redacted>",
        sanitized,
    )
    sanitized = re.sub(
        r"(?i)([a-z][a-z0-9+.-]*://)[^/@\s:]+:[^/@\s]+@",
        r"\1<redacted>@",
        sanitized,
    )
    sanitized = re.sub(
        r"(?i)(?P<prefix>[?&](?P<key>[a-z][a-z0-9_-]*)=)(?P<value>[^&#\s]*)",
        portable_redact_query_value,
        sanitized,
    )
    sanitized = portable_redact_field_values(sanitized)
    for key, secret in os.environ.items():
        if not secret or len(secret) < 4:
            continue
        if portable_sensitive_key(key) or portable_key_token(key).endswith("appkey"):
            sanitized = sanitized.replace(secret, "<redacted>")
    return sanitized


def portable_unbounded_value_key(value: Any) -> bool:
    token = portable_key_token(value)
    return token in PORTABLE_UNBOUNDED_VALUE_KEYS or (
        token.endswith("payload") and token != "payloadcodec"
    )


def portable_json_bytes(value: Any) -> bytes:
    return json.dumps(
        value,
        default=str,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def portable_value_summary(value: Any, reason: str) -> dict[str, Any]:
    encoded = portable_json_bytes(value)
    return {
        "retained": False,
        "reason": reason,
        "original_bytes": len(encoded),
        "sha256": hashlib.sha256(encoded).hexdigest(),
    }


def portable_value(value: Any, *, depth: int = 0) -> Any:
    if value is None or isinstance(value, (bool, int, float)):
        return value
    if isinstance(value, str):
        sanitized = portable_sanitize_text(value)
        if len(sanitized.encode("utf-8")) <= PORTABLE_EVIDENCE_STRING_LIMIT:
            return sanitized
        return portable_value_summary(value, "string_limit")
    if depth >= 8:
        return portable_value_summary(value, "depth_limit")
    if isinstance(value, list):
        retained = [
            portable_value(item, depth=depth + 1)
            for item in value[:PORTABLE_EVIDENCE_COLLECTION_LIMIT]
        ]
        if len(value) > PORTABLE_EVIDENCE_COLLECTION_LIMIT:
            retained.append({
                "retained": False,
                "reason": "collection_limit",
                "omitted_items": len(value) - PORTABLE_EVIDENCE_COLLECTION_LIMIT,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            })
        return retained
    if isinstance(value, dict):
        keys = sorted(
            value,
            key=lambda key: (
                portable_key_token(key) not in {
                    portable_key_token(priority) for priority in PORTABLE_PRIORITY_KEYS
                },
                str(key),
            ),
        )
        retained: dict[str, Any] = {}
        omitted_keys = 0
        for key in keys:
            key_text = str(key)
            if portable_sensitive_key(key_text):
                continue
            if len(retained) >= PORTABLE_EVIDENCE_COLLECTION_LIMIT:
                omitted_keys += 1
                continue
            if portable_unbounded_value_key(key_text):
                retained[key_text] = portable_value_summary(value[key], "unbounded_payload")
                continue
            retained[key_text] = portable_value(value[key], depth=depth + 1)
        if omitted_keys:
            retained["_portable_evidence_omitted"] = {
                "reason": "collection_limit",
                "omitted_keys": omitted_keys,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            }
        return retained
    return portable_value(str(value), depth=depth)


def portable_failure_text_summary(
    value: str,
    limit: int = PORTABLE_FAILURE_SUMMARY_STRING_LIMIT,
) -> Any:
    sanitized = portable_sanitize_text(value)
    encoded = sanitized.encode("utf-8")
    if len(encoded) <= limit:
        return sanitized

    head_bytes = limit // 2
    tail_bytes = max(32, limit - head_bytes - 96)
    head = encoded[:head_bytes].decode("utf-8", errors="ignore")
    tail = encoded[-tail_bytes:].decode("utf-8", errors="ignore")
    omitted = max(0, len(encoded) - len(head.encode("utf-8")) - len(tail.encode("utf-8")))
    return {
        "retained": True,
        "reason": "bounded_sanitized_failure_summary",
        "original_bytes": len(value.encode("utf-8")),
        "sha256": hashlib.sha256(value.encode("utf-8")).hexdigest(),
        "summary": f"{head}\n... {omitted} bytes omitted ...\n{tail}",
    }


def portable_text_excerpt(value: Any, limit: int = 64) -> str:
    sanitized = portable_sanitize_text(
        value if isinstance(value, str) else portable_json_bytes(value).decode("utf-8", errors="replace")
    )
    encoded = sanitized.encode("utf-8")
    if len(encoded) <= limit:
        return sanitized
    head_bytes = limit // 2
    tail_bytes = limit - head_bytes - 3
    return (
        encoded[:head_bytes].decode("utf-8", errors="ignore")
        + "..."
        + encoded[-tail_bytes:].decode("utf-8", errors="ignore")
    )


def portable_failure_value(value: Any, *, depth: int = 0) -> Any:
    if value is None or isinstance(value, (bool, int, float)):
        return value
    if isinstance(value, str):
        return portable_failure_text_summary(value)
    if depth >= 8:
        return portable_value_summary(value, "depth_limit")
    if isinstance(value, list):
        retained = [
            portable_failure_value(item, depth=depth + 1)
            for item in value[:PORTABLE_EVIDENCE_COLLECTION_LIMIT]
        ]
        if len(value) > PORTABLE_EVIDENCE_COLLECTION_LIMIT:
            retained.append({
                "retained": False,
                "reason": "collection_limit",
                "omitted_items": len(value) - PORTABLE_EVIDENCE_COLLECTION_LIMIT,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            })
        return retained
    if isinstance(value, dict):
        retained: dict[str, Any] = {}
        omitted_keys = 0
        for key in sorted(value, key=str):
            key_text = str(key)
            if portable_sensitive_key(key_text):
                continue
            if len(retained) >= PORTABLE_EVIDENCE_COLLECTION_LIMIT:
                omitted_keys += 1
                continue
            if portable_unbounded_value_key(key_text):
                retained[key_text] = portable_value_summary(value[key], "unbounded_payload")
                continue
            retained[key_text] = portable_failure_value(value[key], depth=depth + 1)
        if omitted_keys:
            retained["_portable_evidence_omitted"] = {
                "reason": "collection_limit",
                "omitted_keys": omitted_keys,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            }
        return retained
    return portable_failure_text_summary(str(value))


def portable_client_response_or_error_summary(sample: dict[str, Any]) -> dict[str, Any]:
    summary = {
        key: portable_failure_value(sample[key])
        for key in (
            "body",
            "error",
            "exception",
            "message",
            "output",
            "raw_stdout",
            "server_response",
            "stderr",
        )
        if sample.get(key) is not None
    }
    if summary:
        return summary
    return {"sample": portable_failure_value(sample)}


def portable_client_invocation(value: Any, fallback_sequence: int) -> dict[str, Any]:
    wrapper = value if isinstance(value, dict) else {}
    sample_value = wrapper.get("sample", wrapper)
    sample = sample_value if isinstance(sample_value, dict) else {"raw_stdout": str(sample_value)}
    command = sample.get("command")
    operation = client_sample_field(sample, "operation")
    if operation is None and isinstance(command, str):
        command_parts = command.split()
        operation = command_parts[1] if len(command_parts) > 1 else command

    validation_details = client_sample_field(
        sample,
        "validation_errors",
        "validationErrors",
        "errors",
        "details",
    )
    record = {
        "sequence": wrapper.get("sequence", fallback_sequence),
        "phase": portable_value(wrapper.get("phase")),
        "client": portable_value(client_sample_field(sample, "client")),
        "operation_surface": portable_value(operation),
        "operation_name": portable_value(
            client_sample_field(sample, "operation_name", "operationName")
        ),
        "ok": public_sample_ok(sample),
        "exit_code": client_sample_field(sample, "exit_code", "exitCode"),
        "status": portable_value(client_sample_field(sample, "status")),
        "status_code": client_sample_field(sample, "status_code", "statusCode"),
        "reason": portable_failure_value(
            client_sample_field(sample, "reason", "rejection_reason", "rejectionReason")
        ),
        "validation_details": portable_failure_value(validation_details),
        "workflow_identity": {
            "workflow_id": portable_value(
                wrapper.get("workflow_id") or client_sample_field(sample, "workflow_id", "workflowId")
            ),
            "run_id": portable_value(
                wrapper.get("run_id") or client_sample_field(sample, "run_id", "runId")
            ),
            "worker_id": portable_value(wrapper.get("worker_id")),
            "task_queue": portable_value(wrapper.get("task_queue")),
        },
        "command": portable_value(sample.get("command_argv", sample.get("command"))),
        "stdout_tail": portable_failure_value(sample.get("stdout_tail")),
        "stderr_tail": portable_failure_value(sample.get("stderr_tail")),
        "sample": portable_value(sample),
    }
    if not record["ok"]:
        record["response_or_error_summary"] = portable_client_response_or_error_summary(sample)
    return record


def portable_client_invocations(value: Any) -> Any:
    if not isinstance(value, list):
        return []

    retained = [
        portable_client_invocation(invocation, index)
        for index, invocation in enumerate(
            value[:PORTABLE_CLIENT_INVOCATION_LIMIT],
            start=1,
        )
    ]
    if len(value) > PORTABLE_CLIENT_INVOCATION_LIMIT:
        retained.append({
            "retained": False,
            "reason": "client_invocation_limit",
            "omitted_invocations": len(value) - PORTABLE_CLIENT_INVOCATION_LIMIT,
            "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
        })
    if len(portable_json_bytes(retained)) <= PORTABLE_EVIDENCE_CELL_LIMIT_BYTES:
        return retained

    routing_only = []
    for record in retained:
        if record.get("retained") is False:
            routing_only.append(record)
            continue
        compact = {
            key: record.get(key)
            for key in (
                "sequence",
                "phase",
                "client",
                "operation_surface",
                "operation_name",
                "ok",
                "exit_code",
                "status",
                "status_code",
                "reason",
                "workflow_identity",
                "command",
                "stdout_tail",
                "stderr_tail",
            )
        }
        validation_details = record.get("validation_details")
        if validation_details is not None:
            encoded_validation = portable_json_bytes(validation_details)
            compact["validation_details"] = (
                validation_details
                if len(encoded_validation) <= 512
                else portable_failure_text_summary(
                    encoded_validation.decode("utf-8", errors="replace"),
                    256,
                )
            )
        if record.get("ok") is False:
            response_summary = portable_json_bytes(record.get("response_or_error_summary"))
            compact["response_or_error_summary"] = portable_failure_text_summary(
                response_summary.decode("utf-8", errors="replace"),
                256,
            )
        compact["sample_sha256"] = hashlib.sha256(
            portable_json_bytes(record.get("sample"))
        ).hexdigest()
        routing_only.append(compact)
    if len(portable_json_bytes(routing_only)) <= PORTABLE_EVIDENCE_CELL_LIMIT_BYTES:
        return routing_only

    minimal_routing = []
    for record in routing_only:
        if record.get("retained") is False:
            minimal_routing.append(record)
            continue
        identity = record.get("workflow_identity")
        retained_identity = (
            {
                key: identity.get(key)
                for key in ("workflow_id", "run_id", "worker_id", "task_queue")
                if identity.get(key) is not None
            }
            if isinstance(identity, dict)
            else None
        )
        minimal_record = {
            "sequence": record.get("sequence"),
            "client": portable_text_excerpt(record.get("client"), 32),
            "operation_surface": portable_text_excerpt(record.get("operation_surface"), 48),
            "operation_name": portable_text_excerpt(record.get("operation_name"), 48),
            "ok": record.get("ok"),
            "exit_code": record.get("exit_code"),
            "status_code": record.get("status_code"),
            "reason": portable_text_excerpt(record.get("reason"), 64),
            "validation_details": portable_text_excerpt(record.get("validation_details"), 64),
            "response_or_error_summary": portable_text_excerpt(
                record.get("response_or_error_summary"),
                80,
            ),
            "sample_sha256": record.get("sample_sha256"),
        }
        optional_failure_fields = {
            "workflow_identity": retained_identity,
            "command": record.get("command"),
            "stdout_tail": portable_text_excerpt(record.get("stdout_tail"), 96),
            "stderr_tail": portable_text_excerpt(record.get("stderr_tail"), 96),
        }
        minimal_record.update({
            key: value
            for key, value in optional_failure_fields.items()
            if value not in (None, {}, [])
        })
        minimal_routing.append(minimal_record)
    return minimal_routing


def bounded_portable_cell(value: Any) -> Any:
    retained = portable_value(value)
    if len(portable_json_bytes(retained)) <= PORTABLE_EVIDENCE_CELL_LIMIT_BYTES:
        return retained
    return portable_value_summary(value, "evidence_cell_limit")


def set_portable_evidence_path(target: dict[str, Any], path: str, value: Any) -> None:
    segments = path.split(".")
    current = target
    for segment in segments[:-1]:
        child = current.get(segment)
        if not isinstance(child, dict):
            child = {}
            current[segment] = child
        current = child
    current[segments[-1]] = bounded_portable_cell(value)


def portable_finding_ref(value: Any) -> Any:
    if not isinstance(value, dict):
        return bounded_portable_cell(value)
    return {
        key: bounded_portable_cell(value[key])
        for key in (
            "id",
            "type",
            "scenario_id",
            "owner",
            "owning_contract",
            "title",
            "blocker_kind",
        )
        if key in value
    }


def portable_scenario_result(scenario_id: str, value: dict[str, Any]) -> dict[str, Any]:
    retained: dict[str, Any] = {
        "scenario_id": scenario_id,
        "status": value.get("status"),
    }
    linked_findings = value.get("linked_findings")
    if isinstance(linked_findings, list) and linked_findings:
        retained["linked_findings"] = [
            portable_finding_ref(finding)
            for finding in linked_findings[:PORTABLE_FINDING_LIMIT]
        ]

    observed = value.get("observed_outputs")
    if not isinstance(observed, dict):
        return retained

    retained_observed: dict[str, Any] = {}
    evidence_keys = unique_strings(
        list(SCENARIO_REQUIRED_EVIDENCE.get(scenario_id, ()))
        + required_current_evidence_for(scenario_id)
        + list(PORTABLE_COMMON_SCENARIO_EVIDENCE)
        + list(PORTABLE_OPTIONAL_SCENARIO_EVIDENCE.get(scenario_id, ()))
    )
    for evidence_key in evidence_keys:
        if (
            evidence_key == "routed_current_query_task"
            and scenario_id in {
                "python_worker_cli_and_sdk_baseline",
                "php_worker_cli_and_sdk_baseline",
            }
        ):
            evidence = observed.get(evidence_key, MISSING)
        else:
            evidence = evidence_lookup(observed, evidence_key)
        if evidence is MISSING:
            continue
        if evidence_key == "published_client_invocations":
            retained_observed[evidence_key] = portable_client_invocations(evidence)
            continue
        set_portable_evidence_path(retained_observed, evidence_key, evidence)
    if retained_observed:
        retained["observed_outputs"] = retained_observed
    return retained


def portable_scenario_results(
    values: dict[str, dict[str, Any]],
) -> dict[str, dict[str, Any]]:
    return {
        scenario_id: portable_scenario_result(scenario_id, value)
        for scenario_id, value in values.items()
    }


def retained_php_worker_client_invocations(
    values: dict[str, dict[str, Any]],
) -> dict[str, Any]:
    retained: dict[str, Any] = {}
    for scenario_id in (
        "php_worker_cli_and_sdk_baseline",
        "php_worker_python_and_cli_clients",
        "php_worker_rust_client",
    ):
        scenario_result = values.get(scenario_id)
        if not isinstance(scenario_result, dict):
            continue
        observed = scenario_result.get("observed_outputs")
        if not isinstance(observed, dict):
            continue
        invocations = observed.get("published_client_invocations")
        if not isinstance(invocations, list) or not invocations:
            continue
        retained[scenario_id] = {
            "status": scenario_result.get("status"),
            "invocations": invocations,
        }
    return retained


def portable_finding(value: dict[str, Any]) -> dict[str, Any]:
    return {
        key: bounded_portable_cell(value[key])
        for key in (
            "id",
            "type",
            "scenario_id",
            "owner",
            "owning_contract",
            "title",
            "summary",
            "reason",
            "message",
            "blocker_kind",
            "current_evidence",
            "acceptance",
        )
        if key in value
    }


def portable_findings(values: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [portable_finding(value) for value in values[:PORTABLE_FINDING_LIMIT]]


def section_for(
    retained_scenario_results: dict[str, dict[str, Any]],
    *scenario_ids: str,
) -> dict[str, dict[str, Any]]:
    return {
        scenario_id: {
            "status": retained_scenario_results[scenario_id]["status"],
            "scenario_result_ref": f"$.scenario_results.{scenario_id}",
        }
        for scenario_id in scenario_ids
    }


def encoded_result_bytes(value: dict[str, Any]) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True) + "\n").encode("utf-8")


def portable_result_limit_fallback(
    value: dict[str, Any],
    original_bytes: int,
) -> dict[str, Any]:
    finding_id = "signal_query_portable_result_limit_exceeded"
    retained_scenarios = {
        scenario_id: {
            "scenario_id": scenario_id,
            "status": "runner_blocked",
            "linked_findings": [finding_id],
        }
        for scenario_id in required_scenarios
    }
    blocker = {
        "scenario_id": "portable_evidence",
        "blocker_kind": "portable_result_limit_exceeded",
        "setup_failure": {
            "code": "portable_result_limit_exceeded",
            "original_bytes": original_bytes,
            "limit_bytes": PORTABLE_RESULT_LIMIT_BYTES,
        },
    }
    fallback = {
        "schema": value["schema"],
        "started_at": value["started_at"],
        "finished_at": value["finished_at"],
        "outcome": "non_passing_runner_blocked",
        "runner_blocked": True,
        "artifactVersions": value["artifactVersions"],
        "executed_distribution_identities": value["executed_distribution_identities"],
        "executed_distribution_identity_failures": value[
            "executed_distribution_identity_failures"
        ],
        "executed_distribution_identity_observed_components": value[
            "executed_distribution_identity_observed_components"
        ],
        "artifact_sources": value["artifact_sources"],
        "runtime_matrix": value["runtime_matrix"],
        "replay_timing": section_for(
            retained_scenarios,
            "signal_during_replay",
            "query_during_replay",
        ),
        "terminal_run_behavior": section_for(
            retained_scenarios,
            "completed_run_signal_and_query",
        ),
        "adversarial_errors": section_for(
            retained_scenarios,
            "unknown_signal_and_query_errors",
            "malformed_signal_and_query_payloads",
        ),
        "waterline_observer_comparison": section_for(
            retained_scenarios,
            "waterline_operator_visibility",
            "waterline_service_operator_visibility",
        ),
        "scenario_results": retained_scenarios,
        "findings": [{
            "id": finding_id,
            "type": finding_id,
            "scenario_id": "portable_evidence",
            "owner": "conformance_harness",
            "title": "Signals/queries native evidence exceeded its portable result budget",
            "summary": (
                f"The projected native result required {original_bytes} bytes; "
                f"the portable contract allows {PORTABLE_RESULT_LIMIT_BYTES} bytes."
            ),
        }],
        "finding_links": {
            scenario_id: [finding_id]
            for scenario_id in required_scenarios
        },
        "runner_blockers": [blocker],
        "portable_evidence_contract": value["portable_evidence_contract"],
    }
    if value.get("published_client_invocations"):
        fallback["published_client_invocations"] = value["published_client_invocations"]
    if len(encoded_result_bytes(fallback)) > PORTABLE_RESULT_LIMIT_BYTES:
        raise RuntimeError("portable signals/queries infrastructure fallback exceeded its result budget")
    return fallback


def retainable_php_worker_mirror_failures(behavior_failures: Any) -> list[dict[str, Any]]:
    if not isinstance(behavior_failures, list):
        return []

    retained_failures: list[dict[str, Any]] = []
    for failure in behavior_failures:
        if not isinstance(failure, dict):
            continue
        if failure.get("code") != "php_worker_mirror_probe_failed":
            continue
        if failure.get("actual") is None:
            continue

        retained_failures.append(
            {
                "code": failure.get("code"),
                "evidence_key": failure.get("evidence_key"),
                "expected": failure.get("expected"),
                "actual": failure.get("actual"),
            }
        )

    return retained_failures


def compact_finding_ref(
    scenario_result: dict[str, Any],
) -> tuple[Any | None, Any | None]:
    linked_findings = scenario_result.get("linked_findings")
    if not isinstance(linked_findings, list):
        return None, None

    for finding in linked_findings:
        if isinstance(finding, dict):
            finding_type = finding.get("type")
            finding_id = finding.get("id") or finding_type
            if finding_type == "signal_query_php_worker_mirror_failed":
                return finding_id, finding_type
        elif finding == "signal_query_php_worker_mirror_failed":
            return finding, finding

    return None, None


def php_worker_mirror_diagnostics_from_scenario_result(
    scenario_results: dict[str, dict[str, Any]],
) -> dict[str, Any] | None:
    scenario_result = scenario_results.get("php_worker_cli_and_sdk_baseline")
    if not isinstance(scenario_result, dict):
        return None
    if scenario_result.get("status") != "fail":
        return None

    observed = scenario_result.get("observed_outputs")
    if not isinstance(observed, dict):
        return None

    retained_failures = retainable_php_worker_mirror_failures(
        php_baseline_behavior_failures(observed)
    )
    if not retained_failures:
        return None

    finding_id, finding_type = compact_finding_ref(scenario_result)
    finding_type = finding_type or "signal_query_php_worker_mirror_failed"
    finding_id = finding_id or finding_type

    return {
        "finding_id": finding_id,
        "finding_type": finding_type,
        "current_evidence_candidate_status": current_evidence_candidate_status(
            "php_worker_cli_and_sdk_baseline"
        ),
        "current_behavior_failures": retained_failures,
    }


def retained_behavior_failure_diagnostics(
    findings: list[dict[str, Any]],
    scenario_results: dict[str, dict[str, Any]],
) -> dict[str, Any]:
    diagnostics: dict[str, Any] = {}

    for finding in findings:
        if finding.get("scenario_id") != "php_worker_cli_and_sdk_baseline":
            continue
        if finding.get("type") != "signal_query_php_worker_mirror_failed":
            continue

        current_evidence = finding.get("current_evidence")
        if not isinstance(current_evidence, dict):
            continue

        retained_failures = retainable_php_worker_mirror_failures(
            current_evidence.get("current_behavior_failures")
        )
        if not retained_failures:
            continue

        diagnostics["php_worker_cli_and_sdk_baseline"] = {
            "finding_id": finding.get("id"),
            "finding_type": finding.get("type"),
            "current_evidence_candidate_status": current_evidence.get("current_evidence_candidate_status"),
            "current_behavior_failures": retained_failures,
        }

    if "php_worker_cli_and_sdk_baseline" not in diagnostics:
        raw_diagnostics = php_worker_mirror_diagnostics_from_scenario_result(scenario_results)
        if raw_diagnostics is not None:
            diagnostics["php_worker_cli_and_sdk_baseline"] = raw_diagnostics

    return diagnostics


if runner_blocked:
    outcome = "non_passing_runner_blocked"
elif (
    not findings
    and not distribution_identity_failures
    and all(item["status"] == "pass" for item in scenario_results.values())
):
    outcome = "pass"
else:
    outcome = "non_passing"

behavior_failure_diagnostics = retained_behavior_failure_diagnostics(findings, scenario_results)
retained_scenario_results = portable_scenario_results(scenario_results)
retained_findings = portable_findings(findings)
published_client_invocations = retained_php_worker_client_invocations(retained_scenario_results)
result = {
    "schema": "durable-workflow.v2.signal-query-runtime.result",
    "started_at": started_at,
    "finished_at": finished_at,
    "outcome": outcome,
    "runner_blocked": runner_blocked,
    "artifactVersions": artifact_versions,
    "executed_distribution_identities": executed_distribution_identities,
    "executed_distribution_identity_failures": distribution_identity_failures,
    "executed_distribution_identity_observed_components": sorted(
        executed_distribution_identities
    ),
    "artifact_sources": pins["artifact_sources"],
    "runtime_matrix": {
        "runtimes": ["sdk-php", "sdk-python", "sdk-rust"],
        "same_language_cells": [
            {
                "scenario": "python_worker_cli_and_sdk_baseline",
                "worker": "sdk-python",
                "clients": ["cli", "sdk-python"],
            },
            {
                "scenario": "php_worker_cli_and_sdk_baseline",
                "worker": "sdk-php",
                "clients": ["cli", "sdk-php"],
            },
            {
                "scenario": "rust_worker_rust_php_python_clients",
                "worker": "sdk-rust",
                "clients": ["sdk-rust", "sdk-php", "sdk-python"],
            },
        ],
        "cross_language_cells": [
            {
                "scenario": "python_worker_php_facing_and_cli_clients",
                "worker": "sdk-python",
                "clients": ["sdk-php", "cli"],
            },
            {
                "scenario": "php_worker_python_and_cli_clients",
                "worker": "sdk-php",
                "clients": ["sdk-python", "cli"],
            },
            {
                "scenario": "python_worker_rust_client",
                "worker": "sdk-python",
                "clients": ["sdk-rust"],
            },
            {
                "scenario": "php_worker_rust_client",
                "worker": "sdk-php",
                "clients": ["sdk-rust"],
            },
        ],
    },
    "replay_timing": section_for(
        retained_scenario_results,
        "signal_during_replay",
        "query_during_replay",
    ),
    "terminal_run_behavior": section_for(
        retained_scenario_results,
        "completed_run_signal_and_query",
    ),
    "adversarial_errors": section_for(
        retained_scenario_results,
        "unknown_signal_and_query_errors",
        "malformed_signal_and_query_payloads",
    ),
    "waterline_observer_comparison": section_for(
        retained_scenario_results,
        "waterline_operator_visibility",
        "waterline_service_operator_visibility",
    ),
    "scenario_results": retained_scenario_results,
    "findings": retained_findings,
    "finding_links": bounded_portable_cell(finding_links),
    "portable_evidence_contract": {
        "schema": "durable-workflow.v1.portable-native-evidence",
        "max_result_bytes": PORTABLE_RESULT_LIMIT_BYTES,
        "max_evidence_cell_bytes": PORTABLE_EVIDENCE_CELL_LIMIT_BYTES,
        "max_string_bytes": PORTABLE_EVIDENCE_STRING_LIMIT,
        "max_client_invocations_per_cell": PORTABLE_CLIENT_INVOCATION_LIMIT,
        "scenario_evidence": "required_behavior_cells_and_bounded_diagnostics",
        "sensitive_values": "omitted",
        "unbounded_values": "sha256_summary",
    },
}
if published_client_invocations:
    result["published_client_invocations"] = published_client_invocations
if behavior_failure_diagnostics:
    result["behavior_failure_diagnostics"] = bounded_portable_cell(
        behavior_failure_diagnostics
    )
if runner_blocked and baseline_readiness_blocker is not None:
    result["runner_blocker"] = bounded_portable_cell(baseline_readiness_blocker)
if runner_blockers:
    result["runner_blockers"] = bounded_portable_cell(runner_blockers)

projected_result_bytes = len(encoded_result_bytes(result))
if projected_result_bytes > PORTABLE_RESULT_LIMIT_BYTES:
    result = portable_result_limit_fallback(result, projected_result_bytes)
outcome = str(result["outcome"])
runner_blocked = result["runner_blocked"] is True
behavior_failure_diagnostics = result.get("behavior_failure_diagnostics", {})
published_client_invocations = result.get("published_client_invocations", {})
runner_blockers = result.get("runner_blockers", [])
write_json(result_dir / "signals-queries-result.json", result)

ordered_record_outputs = result.get("scenario_results", {}).get(
    "ordered_signal_delivery",
    {},
).get("observed_outputs", {})
ordered_signal_delivery_evidence: dict[str, Any] = {}
if isinstance(ordered_record_outputs, dict):
    ordered_signal_delivery_evidence = {
        key: ordered_record_outputs[key]
        for key in BASELINE_CURRENT_EVIDENCE_FIELDS["ordered_signal_delivery"]
        if key in ordered_record_outputs
    }

record = {
    "experiment": "signals-queries",
    "outcome": outcome,
    "runnerBlocked": runner_blocked,
    "artifactVersions": artifact_versions,
    "result_file": "signals-queries-result.json",
    "findings_file": "signals-queries-findings.json",
}
if ordered_signal_delivery_evidence:
    record["ordered_signal_delivery_evidence"] = ordered_signal_delivery_evidence
if behavior_failure_diagnostics:
    record["behavior_failure_diagnostics"] = behavior_failure_diagnostics
if published_client_invocations:
    record["published_client_invocations"] = published_client_invocations
if runner_blocked and result.get("runner_blocker") is not None:
    record["runner_blocker"] = result["runner_blocker"]
if runner_blockers:
    record["runner_blockers"] = runner_blockers
write_json(result_dir / "signals-queries-record.json", record)

focused_cell = env_text("DW_SIGNALS_QUERIES_FOCUS")
if focused_cell == "php-worker-cli-signal":
    raw_php_scenario = scenario_results.get("php_worker_cli_and_sdk_baseline", {})
    raw_php_observed = raw_php_scenario.get("observed_outputs", {})
    if not isinstance(raw_php_observed, dict):
        raw_php_observed = {}
    raw_invocations = raw_php_observed.get("published_client_invocations")
    if not isinstance(raw_invocations, list):
        raw_invocations = []

    def focused_invocation(phase: str) -> dict[str, Any] | None:
        for index, invocation in enumerate(raw_invocations, start=1):
            if not isinstance(invocation, dict) or invocation.get("phase") != phase:
                continue
            return portable_client_invocation(invocation, index)
        return None

    start_invocation = focused_invocation("workflow_start")
    initial_query_invocation = focused_invocation("initial_query")
    cli_signal_invocation = focused_invocation("cli_signal")
    raw_start = raw_php_observed.get("sdk_php_start_sample")
    raw_initial_query = raw_php_observed.get("initial_query_sample")
    raw_cli_signal = raw_php_observed.get("cli_signal_sample")
    initial_query_value = (
        sample_result_value(raw_initial_query)
        if isinstance(raw_initial_query, dict)
        else None
    )
    focused_pass = (
        isinstance(raw_php_observed.get("worker_registration"), dict)
        and isinstance(raw_start, dict)
        and public_sample_ok(raw_start)
        and isinstance(raw_initial_query, dict)
        and public_sample_ok(raw_initial_query)
        and initial_query_value == 0
        and isinstance(raw_cli_signal, dict)
        and public_sample_ok(raw_cli_signal)
    )
    classification = raw_php_observed.get("cli_signal_attempt_classification")
    if not isinstance(classification, dict):
        probe_error = raw_php_observed.get("probe_error")
        classification = {
            "category": "focused_path_stopped_before_cli_signal",
            "owner": "conformance_harness_or_fixture",
            "product_reached": False,
            "summary": "The focused cell stopped before it retained a CLI signal attempt.",
        }
        if isinstance(probe_error, dict):
            classification["failed_phase"] = probe_error.get("phase")

    focused_result = {
        "schema": "durable-workflow.v2.signal-query-runtime.php-cli-signal-focused-result",
        "started_at": started_at,
        "finished_at": finished_at,
        "cell": focused_cell,
        "outcome": "pass" if focused_pass else "non_passing",
        "runner_blocked": baseline_readiness_blocker is not None,
        "broad_property_claimed": False,
        "broad_confirmation_required": True,
        "artifactVersions": artifact_versions,
        "fixture_contract": {
            "workflow_type": raw_php_observed.get("workflow_type", "conformance.counter.php"),
            "workflow_handler_execution_model": "fiber",
            "signal": "increment",
            "initial_query": "state",
        },
        "path": {
            "worker_registration": bounded_portable_cell(
                raw_php_observed.get("worker_registration")
            ),
            "workflow_start": start_invocation,
            "initial_query": initial_query_invocation,
            "initial_query_result": initial_query_value,
            "cli_signal_attempt": cli_signal_invocation,
        },
        "classification": bounded_portable_cell(classification),
        "post_attempt_state": bounded_portable_cell(
            raw_php_observed.get("post_cli_signal_state")
        ),
        "probe_error": portable_failure_value(raw_php_observed.get("probe_error")),
        "evidence_limits": {
            "max_result_bytes": 256 * 1024,
            "max_evidence_cell_bytes": PORTABLE_EVIDENCE_CELL_LIMIT_BYTES,
            "max_failure_text_bytes": PORTABLE_FAILURE_SUMMARY_STRING_LIMIT,
            "stdout_stderr_policy": "bounded_sanitized_tails",
            "actionable_failure_fields": "retained_independently_of_unbounded_text",
        },
    }
    focused_result["result_bytes"] = 0
    focused_bytes = len(encoded_result_bytes(focused_result))
    focused_result["result_bytes"] = focused_bytes
    focused_bytes = len(encoded_result_bytes(focused_result))
    if focused_bytes > focused_result["evidence_limits"]["max_result_bytes"]:
        raise RuntimeError(
            f"focused PHP CLI signal evidence exceeded its result budget: {focused_bytes} bytes"
        )
    focused_result["result_bytes"] = focused_bytes
    focused_result_path = result_dir / "signals-queries-php-cli-signal-result.json"
    write_json(focused_result_path, focused_result)
    focused_record = {
        "experiment": "signals-queries",
        "cell": focused_cell,
        "outcome": focused_result["outcome"],
        "runnerBlocked": focused_result["runner_blocked"],
        "broadPropertyClaimed": False,
        "broadConfirmationRequired": True,
        "artifactVersions": artifact_versions,
        "classification": focused_result["classification"],
        "result_file": focused_result_path.name,
    }
    write_json(
        result_dir / "signals-queries-php-cli-signal-record.json",
        focused_record,
    )
    stdout_record = {
        "cell": focused_cell,
        "outcome": focused_result["outcome"],
        "result_dir": str(result_dir),
        "result_file": focused_result_path.name,
        "classification": focused_result["classification"],
        "broad_property_claimed": False,
    }
else:
    stdout_record = {"outcome": outcome, "result_dir": str(result_dir)}
    if behavior_failure_diagnostics:
        stdout_record["behavior_failure_diagnostics"] = behavior_failure_diagnostics
    if published_client_invocations:
        stdout_record["published_client_invocations"] = published_client_invocations
print(json.dumps(stdout_record, sort_keys=True))
PY
