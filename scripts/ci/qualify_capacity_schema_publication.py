#!/usr/bin/env python3
"""Gate live capacity schema checks while their canonical routes are first published."""

from __future__ import annotations

import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Callable, Mapping


ROOT = Path(__file__).resolve().parents[2]
PUBLICATION_PATH = "benchmarks/capacity/v1/schema-publication.json"
COMMIT_PATTERN = re.compile(r"[0-9a-f]{40}")
ZERO_COMMIT = "0" * 40


class PublicationGateError(RuntimeError):
    """Raised when workflow event metadata cannot be inspected safely."""


def publication_base_ref(environment: Mapping[str, str]) -> str | None:
    """Return the validated prior commit for PR and ordinary push events."""

    event_name = environment.get("GITHUB_EVENT_NAME", "")
    if event_name not in {"pull_request", "push"}:
        return None

    base_ref = environment.get("PUBLICATION_BASE_REF", "")
    if not COMMIT_PATTERN.fullmatch(base_ref):
        raise PublicationGateError(
            "PUBLICATION_BASE_REF must be an exact lowercase 40-character commit ID"
        )
    if base_ref == ZERO_COMMIT:
        return None
    return base_ref


def base_contains_publication(base_ref: str) -> bool:
    """Check the fixed publication path in a strictly bounded commit object."""

    if not COMMIT_PATTERN.fullmatch(base_ref) or base_ref == ZERO_COMMIT:
        raise PublicationGateError("refusing to inspect an unbounded publication base ref")

    commit = subprocess.run(
        ["git", "-C", str(ROOT), "cat-file", "-e", f"{base_ref}^{{commit}}"],
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    if commit.returncode != 0:
        raise PublicationGateError(
            "PUBLICATION_BASE_REF is not available in the checked-out repository"
        )

    publication = subprocess.run(
        ["git", "-C", str(ROOT), "cat-file", "-e", f"{base_ref}:{PUBLICATION_PATH}"],
        check=False,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return publication.returncode == 0


def should_verify_publication(
    environment: Mapping[str, str],
    contains_publication: Callable[[str], bool] = base_contains_publication,
) -> bool:
    """Require live routes unless this change introduces the inventory."""

    base_ref = publication_base_ref(environment)
    return base_ref is None or contains_publication(base_ref)


def main() -> int:
    try:
        verify = should_verify_publication(os.environ)
    except PublicationGateError as error:
        print(f"Capacity schema publication gate failed: {error}", file=sys.stderr)
        return 1

    if not verify:
        print("Deferring live route qualification for the initial publication.")
        return 0

    subprocess.run(
        [
            sys.executable,
            str(ROOT / "scripts" / "benchmark" / "capacity_suite.py"),
            "verify-publication",
        ],
        check=True,
        cwd=ROOT,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
