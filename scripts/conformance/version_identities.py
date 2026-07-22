from __future__ import annotations

import re

CORE_IDENTIFIER = r"(?:0|[1-9]\d*)"
PRERELEASE_IDENTIFIER = r"(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
EXACT_SEMVER_RELEASE_PATTERN = re.compile(
    rf"^{CORE_IDENTIFIER}\.{CORE_IDENTIFIER}\.{CORE_IDENTIFIER}"
    rf"(?:-{PRERELEASE_IDENTIFIER}(?:\.{PRERELEASE_IDENTIFIER})*)?$",
)
PYTHON_SEMVER_PRERELEASE_PATTERN = re.compile(
    rf"^({CORE_IDENTIFIER})\.({CORE_IDENTIFIER})\.({CORE_IDENTIFIER})-(alpha|beta|rc)\.({CORE_IDENTIFIER})$",
    re.IGNORECASE,
)
PYTHON_PEP440_PRERELEASE_PATTERN = re.compile(
    rf"^({CORE_IDENTIFIER})\.({CORE_IDENTIFIER})\.({CORE_IDENTIFIER})(a|b|rc)({CORE_IDENTIFIER})$",
    re.IGNORECASE,
)
ROLLING_RELEASE_IDENTIFIERS = {
    "latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder",
}


def is_exact_semver_release(value: str) -> bool:
    if EXACT_SEMVER_RELEASE_PATTERN.fullmatch(value) is None:
        return False
    prerelease = value.partition("-")[2]
    return not prerelease or not any(
        identifier.lower() in ROLLING_RELEASE_IDENTIFIERS for identifier in prerelease.split(".")
    )


def python_release_identity(value: str) -> str | None:
    if re.fullmatch(rf"{CORE_IDENTIFIER}\.{CORE_IDENTIFIER}\.{CORE_IDENTIFIER}", value):
        return value

    semver = PYTHON_SEMVER_PRERELEASE_PATTERN.fullmatch(value)
    if semver:
        major, minor, patch, prerelease, ordinal = semver.groups()
        pep440_prerelease = {"alpha": "a", "beta": "b", "rc": "rc"}[prerelease.lower()]
        return f"{major}.{minor}.{patch}{pep440_prerelease}{ordinal}"

    pep440 = PYTHON_PEP440_PRERELEASE_PATTERN.fullmatch(value)
    if not pep440:
        return None
    major, minor, patch, prerelease, ordinal = pep440.groups()
    return f"{major}.{minor}.{patch}{prerelease.lower()}{ordinal}"


def same_python_release(expected: str, observed: str) -> bool:
    expected_identity = python_release_identity(expected)
    return expected_identity is not None and expected_identity == python_release_identity(observed)
