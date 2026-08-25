#!/usr/bin/env python3
"""Fail closed when canonical capacity schemas are not published exactly."""

from __future__ import annotations

import sys

import capacity_suite


def main() -> int:
    try:
        capacity_suite.verify_schema_publication()
    except capacity_suite.ContractError as exc:
        print(f"capacity schema publication audit error: {exc}", file=sys.stderr)
        return 1

    print(
        "capacity schema publication is live "
        f"({len(capacity_suite.REQUIRED_SCHEMAS)} schemas)"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
