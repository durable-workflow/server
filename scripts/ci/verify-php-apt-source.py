#!/usr/bin/env python3
"""Read-only source prerequisite; never builds, fetches or installs anything."""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re


INDEX = "sha256:3614314e50a9b17a0c317dfd100c6346d8881d6565e713556b7a4e8eb3ec4505"
PROOF_SHA256 = "09078d7166cd57d8781ef344f5f7b2f69712ace54aa867d320d99954f039cd60"
GUARD_SHA256 = "082e4bcf3aebaf8da74658c2f15c4b6df259e0cb8f2b09e4e16a737e223a94a3"
DOCKERFILE_SHA256 = "d70f15b575b98f0cfd6c5b6abc95a9d53b9560b03aeed9c1996fec46d4d9d41a"
GUARD = "/usr/local/lib/server-apt/require-fixed-apt.sh"
PREFIX = "/usr/bin/env -i PATH=/usr/bin:/bin LC_ALL=C /bin/sh " + GUARD


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise ValueError(reason)


def instructions(source: str):
    logical = ""
    for line in source.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        continued = line.endswith("\\")
        logical += (line[:-1].rstrip() if continued else line) + " "
        if not continued:
            verb, _, value = logical.strip().partition(" ")
            yield verb.upper(), value
            logical = ""
    require(not logical, "unterminated Dockerfile instruction")


def require_source_boundary(source: str, proof: bytes, guard: bytes) -> dict:
    # These hashes bind reviewed source inputs. They do not authenticate an
    # arbitrary downloaded inventory, installed image or runtime observation.
    require(hashlib.sha256(proof).hexdigest() == PROOF_SHA256, "unreviewed parent inventory")
    require(hashlib.sha256(guard).hexdigest() == GUARD_SHA256, "unreviewed effective-client guard")
    value = json.loads(proof)
    stage, seen, first_run, copied = "", set(), set(), False
    for verb, argument in instructions(source):
        if verb == "FROM":
            parts = argument.split()
            require(len(parts) in (1, 3), "unsupported stage declaration")
            if len(parts) == 3:
                require(parts[1] == "AS" and parts[2] not in seen, "ambiguous stage")
                stage = parts[2]
            else:
                stage = parts[0]
            seen.add(stage)
            if stage == "base":
                require(parts[0] == "php@" + INDEX, "unproved PHP parent")
            if stage == "production":
                require(parts[0] == "base", "production must inherit the guarded base")
        elif verb == "COPY" and argument == "docker/apt/require-fixed-apt.sh " + GUARD:
            require(stage == "base" and stage not in first_run and not copied, "late or duplicate guard copy")
            copied = True
        elif verb == "RUN":
            if stage not in first_run and stage in ("base", "production"):
                require(copied and argument.startswith(PREFIX + " && "), "first RUN lacks the closed guard")
            first_run.add(stage)
            if re.search(r"\b(?:apt|apt-get|docker-php-ext-install|install-php-extensions)\b", argument):
                require(stage in ("base", "production"), "unqualified package stage")
    require({"base", "production"} <= first_run and copied, "missing guarded runtime stages")
    require(hashlib.sha256(source.encode("utf-8")).hexdigest() == DOCKERFILE_SHA256,
            "unreviewed Dockerfile bytes")
    return value


def checked_file(root: Path, relative: str, limit: int) -> bytes:
    path = root / relative
    require(path.is_file() and not path.is_symlink() and path.resolve().is_relative_to(root),
            "source path escapes checkout or is not a regular file")
    with path.open("rb") as stream:
        data = stream.read(limit + 1)
    require(0 < len(data) <= limit, "source input bound")
    return data


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    args = parser.parse_args()
    root = args.root.resolve()
    proof = require_source_boundary(
        checked_file(root, "Dockerfile", 65536).decode("utf-8", errors="strict"),
        checked_file(root, "docker/apt/parent.v1.json", 16384),
        checked_file(root, "docker/apt/require-fixed-apt.sh", 8192))
    print(json.dumps({"source_prerequisite": "pass", "index": proof["index"],
                      "platforms": sorted(proof["platforms"]), "installed_observation": False}))


if __name__ == "__main__":
    main()
