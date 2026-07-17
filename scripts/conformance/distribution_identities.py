#!/usr/bin/env python3
"""Record normalized identities for distribution bytes consumed by conformance runners."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


VERSION_PATTERN = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$")
DIGEST_PATTERN = re.compile(r"^[0-9a-f]{64}$")
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
    if not VERSION_PATTERN.fullmatch(version):
        raise IdentityEvidenceError(f"invalid exact distribution version for {component}: {version}")
    if not artifact_name or len(artifact_name) > 256:
        raise IdentityEvidenceError(f"invalid distribution artifact name for {component}: {artifact_name}")
    # Composer artifact names are package locators and intentionally contain one slash.
    if "/" in artifact_name and component not in {"workflow", "waterline", "sdk-php"}:
        raise IdentityEvidenceError(f"invalid distribution artifact name for {component}: {artifact_name}")
    if not DIGEST_PATTERN.fullmatch(digest):
        raise IdentityEvidenceError(f"invalid SHA-256 evidence for {component}:{artifact_name}")
    kind, package = COMPONENTS[component]
    return {
        "kind": kind,
        "locator": f"{kind}:{package}@{version}",
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
        locator_pattern = re.compile(
            rf"^{re.escape(kind)}:{re.escape(package)}@{VERSION_PATTERN.pattern[1:-1]}$"
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


def write(path: Path, identities: dict[str, dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    temporary.write_text(json.dumps(identities, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    temporary.replace(path)


def record(path: Path, component: str, observed: dict[str, Any]) -> None:
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
