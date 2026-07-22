#!/usr/bin/env python3
"""Record normalized identities for distribution bytes consumed by conformance runners."""

from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import re
import sys
import tempfile
from contextlib import contextmanager
from pathlib import Path
from typing import Any

VERSION_PATTERN = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
PYTHON_VERSION_PATTERN = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:(?:a|b|rc)(?:0|[1-9]\d*)|-(?:alpha|beta|rc)\.(?:0|[1-9]\d*))?$",
    re.IGNORECASE,
)
DIGEST_PATTERN = re.compile(r"^[0-9a-f]{64}$")


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
    pep440 = re.fullmatch(
        r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)",
        version,
        re.IGNORECASE,
    )
    if not pep440:
        return None
    major, minor, patch, prerelease, ordinal = pep440.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"
COMPONENTS = {
    "workflow": ("composer", "durable-workflow/workflow"),
    "waterline": ("composer", "durable-workflow/waterline"),
    "server": ("oci", "docker.io/durableworkflow/server"),
    "cli": ("github-release", "durable-workflow/cli"),
    "sdk-php": ("composer", "durable-workflow/sdk"),
    "sdk-python": ("pypi", "durable-workflow"),
    "sdk-rust": ("crates.io", "durable-workflow"),
}


class IdentityEvidenceError(RuntimeError):
    """Executed distribution evidence is absent or malformed."""


def sha256_file(path: Path) -> str:
    if not path.is_file():
        raise IdentityEvidenceError(f"executed distribution artifact is missing: {path}")
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def identity(component: str, version: str, artifact_name: str, digest: str) -> dict[str, Any]:
    if component not in COMPONENTS:
        raise IdentityEvidenceError(f"unknown distribution component: {component}")
    valid_version = python_release_identity(version) is not None if component == "sdk-python" else bool(VERSION_PATTERN.fullmatch(version))
    if not valid_version:
        raise IdentityEvidenceError(f"invalid exact distribution version for {component}: {version}")
    if not artifact_name or len(artifact_name) > 256:
        raise IdentityEvidenceError(f"invalid distribution artifact name for {component}: {artifact_name}")
    # Composer artifact names are package locators and intentionally contain one slash.
    if "/" in artifact_name and component not in {"workflow", "waterline", "sdk-php"}:
        raise IdentityEvidenceError(f"invalid distribution artifact name for {component}: {artifact_name}")
    if not DIGEST_PATTERN.fullmatch(digest):
        raise IdentityEvidenceError(f"invalid SHA-256 evidence for {component}:{artifact_name}")
    kind, package = COMPONENTS[component]
    locator_version = python_release_identity(version) if component == "sdk-python" else version
    return {
        "kind": kind,
        "locator": f"{kind}:{package}@{locator_version}",
        "artifacts": [{"name": artifact_name, "sha256": digest}],
    }


def load(path: Path) -> dict[str, dict[str, Any]]:
    if not path.exists():
        return {}
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise IdentityEvidenceError(f"cannot read executed distribution evidence {path}: {error}") from error
    if not isinstance(value, dict) or not set(value).issubset(COMPONENTS):
        raise IdentityEvidenceError("executed distribution evidence must be a component map")
    for component, observed in value.items():
        if not isinstance(observed, dict) or set(observed) != {"kind", "locator", "artifacts"}:
            raise IdentityEvidenceError(f"invalid executed distribution identity for {component}")
        kind, package = COMPONENTS[component]
        component_version_pattern = PYTHON_VERSION_PATTERN if component == "sdk-python" else VERSION_PATTERN
        locator_pattern = re.compile(
            rf"^{re.escape(kind)}:{re.escape(package)}@{component_version_pattern.pattern[1:-1]}$",
            component_version_pattern.flags,
        )
        if observed["kind"] != kind or not locator_pattern.fullmatch(str(observed["locator"])):
            raise IdentityEvidenceError(f"invalid executed distribution locator for {component}")
        artifacts = observed.get("artifacts")
        if not isinstance(artifacts, list) or not artifacts:
            raise IdentityEvidenceError(f"executed distribution identity has no artifacts for {component}")
        names: list[str] = []
        for artifact in artifacts:
            if (
                not isinstance(artifact, dict)
                or set(artifact) != {"name", "sha256"}
                or not isinstance(artifact["name"], str)
                or not artifact["name"]
                or len(artifact["name"]) > 256
                or not DIGEST_PATTERN.fullmatch(str(artifact["sha256"]))
            ):
                raise IdentityEvidenceError(f"invalid executed distribution artifact for {component}")
            names.append(artifact["name"])
        if names != sorted(names) or len(names) != len(set(names)):
            raise IdentityEvidenceError(f"executed distribution artifacts are not normalized for {component}")
    return value


@contextmanager
def store_lock(path: Path) -> Any:
    path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = path.with_name(f".{path.name}.lock")
    with lock_path.open("a+b") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def write(path: Path, identities: dict[str, dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        dir=path.parent,
        prefix=f".{path.name}.",
        suffix=".tmp",
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        temporary.write_text(
            json.dumps(identities, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def record(path: Path, component: str, observed: dict[str, Any]) -> None:
    with store_lock(path):
        identities = load(path)
        current = identities.get(component)
        if current is not None:
            if current["kind"] != observed["kind"] or current["locator"] != observed["locator"]:
                raise IdentityEvidenceError(f"conflicting executed distribution locator for {component}")
            artifacts = {artifact["name"]: artifact["sha256"] for artifact in current["artifacts"]}
            for artifact in observed["artifacts"]:
                previous = artifacts.get(artifact["name"])
                if previous is not None and previous != artifact["sha256"]:
                    raise IdentityEvidenceError(
                        f"conflicting consumed bytes for {component}:{artifact['name']}"
                    )
                artifacts[artifact["name"]] = artifact["sha256"]
            observed["artifacts"] = [
                {"name": name, "sha256": artifacts[name]}
                for name in sorted(artifacts)
            ]
        identities[component] = observed
        write(path, identities)


def unique_file(root: Path, pattern: str) -> Path:
    matches = sorted(path for path in root.glob(pattern) if path.is_file())
    if len(matches) != 1:
        raise IdentityEvidenceError(
            f"expected exactly one consumed distribution artifact matching {pattern} under {root}, found {len(matches)}"
        )
    return matches[0]


def parser() -> argparse.ArgumentParser:
    value = argparse.ArgumentParser(description=__doc__)
    commands = value.add_subparsers(dest="command", required=True)

    record_file = commands.add_parser("record-file")
    record_file.add_argument("store", type=Path)
    record_file.add_argument("component", choices=COMPONENTS)
    record_file.add_argument("version")
    record_file.add_argument("file", type=Path)
    record_file.add_argument("--artifact-name")

    record_unique = commands.add_parser("record-unique")
    record_unique.add_argument("store", type=Path)
    record_unique.add_argument("component", choices=COMPONENTS)
    record_unique.add_argument("version")
    record_unique.add_argument("root", type=Path)
    record_unique.add_argument("pattern")
    record_unique.add_argument("--artifact-name")

    record_digest = commands.add_parser("record-digest")
    record_digest.add_argument("store", type=Path)
    record_digest.add_argument("component", choices=COMPONENTS)
    record_digest.add_argument("version")
    record_digest.add_argument("artifact_name")
    record_digest.add_argument("sha256")

    validate = commands.add_parser("validate")
    validate.add_argument("store", type=Path)
    validate.add_argument("components", nargs="+", choices=COMPONENTS)
    return value


def main() -> int:
    arguments = parser().parse_args()
    if arguments.command == "record-file":
        artifact = arguments.file
        artifact_name = arguments.artifact_name or artifact.name
        record(arguments.store, arguments.component, identity(
            arguments.component, arguments.version, artifact_name, sha256_file(artifact)
        ))
    elif arguments.command == "record-unique":
        artifact = unique_file(arguments.root, arguments.pattern)
        artifact_name = arguments.artifact_name or artifact.name
        record(arguments.store, arguments.component, identity(
            arguments.component, arguments.version, artifact_name, sha256_file(artifact)
        ))
    elif arguments.command == "record-digest":
        digest = arguments.sha256.removeprefix("sha256:").lower()
        record(arguments.store, arguments.component, identity(
            arguments.component, arguments.version, arguments.artifact_name, digest
        ))
    else:
        identities = load(arguments.store)
        missing = [component for component in arguments.components if component not in identities]
        if missing:
            raise IdentityEvidenceError(
                "missing executed distribution evidence for: " + ", ".join(missing)
            )
        print(json.dumps(identities, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except IdentityEvidenceError as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from error
