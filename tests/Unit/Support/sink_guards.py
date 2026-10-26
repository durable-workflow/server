"""Machine-checked guards for reviewed network sinks (GHSA-6q48-6c7w-9gw2).

A sink manifest lists every line that can make a package request. For each
occurrence of that line it names the guard confining it: a guard type, the
exact guard line and the signed line distance from the sink (positive when
the guard comes first). The nearest line of that type, in the type's
direction, within the same file and, in a workflow, the same job, must be
that line at that distance. Deleting or moving a guard therefore fails the
inventory even when the sink line itself is unchanged.

Types without a guard line hold only for sinks that cannot run: comments,
documentation and assertion messages. A pinned reference is its own guard.
"""

from __future__ import annotations

import re
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

COMMENT = re.compile(r"^\s*(?:#|//)")
WORKFLOW_JOB = re.compile(r"^  [A-Za-z0-9_-]+:\s*$")


@dataclass(frozen=True)
class GuardType:
    pattern: re.Pattern[str]
    direction: int  # -1: the guard precedes the sink; +1: it follows in the same command
    contains: str | None = None  # text the guard line itself must hold


@dataclass(frozen=True)
class Occurrence:
    path: str
    line: str
    index: int
    column: int
    text: str


def occurrences(root: Path, files: list[Path], sink: re.Pattern[str]) -> list[Occurrence]:
    found = []
    for path in files:
        try:
            text = path.read_text()
        except (UnicodeDecodeError, OSError):
            continue
        starts = [0] + [index + 1 for index, character in enumerate(text) if character == "\n"]
        for match in sink.finditer(text):
            line_index = text.count("\n", 0, match.start())
            start = starts[line_index]
            end = text.find("\n", start)
            line = text[start:end if end >= 0 else None]
            found.append(Occurrence(path.relative_to(root).as_posix(), line.strip(), line_index,
                                    match.start() - start, match.group(0)))
    return found


def counts(found: list[Occurrence]) -> Counter[tuple[str, str]]:
    return Counter((occurrence.path, occurrence.line) for occurrence in found)


def find_guard(lines: list[str], occurrence: Occurrence, guard: GuardType) -> tuple[str, int] | None:
    workflow = occurrence.path.startswith(".github/workflows/")
    line = lines[occurrence.index]
    here = line[:occurrence.column] if guard.direction < 0 else line[occurrence.column + len(occurrence.text):]
    if guard.pattern.search(here):
        return line.strip(), 0
    step = guard.direction
    index = occurrence.index + step
    while 0 <= index < len(lines):
        candidate = lines[index]
        if workflow and WORKFLOW_JOB.match(candidate):
            return None
        if guard.direction > 0 and not lines[index - 1].rstrip().endswith("\\"):
            return None  # a following guard must belong to the same continued command
        if not COMMENT.match(candidate) and guard.pattern.search(candidate):
            return candidate.strip(), occurrence.index - index
        index += step
    return None


# Sinks that cannot run, checked from the sink itself. Inventories add their
# own self-confining kinds, such as a pinned reference.
SelfCheck = Callable[[Occurrence], bool]
SELF_CHECKS: dict[str, SelfCheck] = {
    "comment": lambda occurrence: COMMENT.match(occurrence.line) is not None,
    "documentation": lambda occurrence: occurrence.path.endswith(".md"),
    "assertion-message": lambda occurrence: re.match(r"assert\.\w+\(", occurrence.line) is not None,
}


def evidence_record(root: Path) -> SelfCheck:
    """A JS evidence field naming a command, in a file that never reads one back."""
    return lambda occurrence: (
        occurrence.path.endswith(".mjs")
        and re.match(r"command: `", occurrence.line) is not None
        and re.search(r"\.command\b", (root / occurrence.path).read_text()) is None
    )


def guard_problems(root: Path, found: list[Occurrence], entries: list[dict], types: dict[str, GuardType],
                   self_checks: dict[str, SelfCheck] | None = None) -> list[str]:
    """Every occurrence's recorded guard, checked against the source."""
    checks = {**SELF_CHECKS, **(self_checks or {})}
    reviewed = {(str(entry["path"]), str(entry["line"])): entry for entry in entries}
    seen: Counter[tuple[str, str]] = Counter()
    sources: dict[str, list[str]] = {}
    problems = []
    for occurrence in found:
        key = (occurrence.path, occurrence.line)
        entry = reviewed.get(key)
        if entry is None:
            continue  # reported by the inventory comparison
        position = seen[key]
        seen[key] += 1
        guards = entry.get("guards") or []
        label = f"{occurrence.path}:{occurrence.index + 1}: {occurrence.line[:80]}"
        if position >= len(guards):
            problems.append(f"{label}: no recorded guard for occurrence {position + 1}")
            continue
        recorded = guards[position]
        kind = recorded.get("type")
        if kind in checks:
            if recorded.get("line") is not None or recorded.get("distance") is not None or not checks[kind](occurrence):
                problems.append(f"{label}: not a {kind}")
            continue
        guard = types.get(kind)
        if guard is None:
            problems.append(f"{label}: unknown guard type {kind!r}")
            continue
        lines = sources.setdefault(occurrence.path, (root / occurrence.path).read_text().split("\n"))
        actual = find_guard(lines, occurrence, guard)
        expected = (recorded.get("line"), recorded.get("distance"))
        if actual != expected:
            problems.append(f"{label}: {kind} guard {expected!r} not found; nearest is {actual!r}")
        elif guard.contains is not None and guard.contains not in actual[0]:
            problems.append(f"{label}: {kind} guard does not hold {guard.contains!r}")
    return problems
