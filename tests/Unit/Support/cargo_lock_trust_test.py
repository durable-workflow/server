"""Every active Cargo.lock resolves the fixed TLS trust crates.

RUSTSEC-2026-0381 and RUSTSEC-2026-0382 (GHSA-6q48-6c7w-9gw2) fix the
withdrawn emSign roots in rustls-webpki 0.103.16 and webpki-roots 1.0.10.
Each Cargo.lock in the repository is an executable input: CI builds it with
`cargo build --locked`. So every lock found by walking the tree must resolve
at least those versions. Immutable historical evidence is the only exemption.
It is listed by path, and its bytes must stay exactly as recorded.

Locks are read as TOML, so the order of a package's fields does not matter.
Versions compare by SemVer precedence: a prerelease of the fixed release, such
as 0.103.16-alpha.1, is below the floor. A lock that is not valid TOML, or a
trust crate whose version is not SemVer, fails the check.

Run with: python3 -m unittest tests/Unit/Support/cargo_lock_trust_test.py
"""

from __future__ import annotations

import hashlib
import os
import re
import tomllib
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]

FLOORS = {
    "rustls-webpki": (0, 103, 16),
    "webpki-roots": (1, 0, 10),
}

# Archived evidence of a published Rust SDK run; it is never built again.
IMMUTABLE_EVIDENCE = {
    "docs/evidence/server-237-20260929-published-rust212/Cargo.lock":
        "ed31eb89b3ce1cd5a25a3a4b1751870cdc0a07cea0877751c101bc0a103c5475",
}

# Locks the walk must find, so a moved or renamed tree cannot empty the check.
REQUIRED = {
    "rust/Cargo.lock",
    "benchmarks/capacity/v1/bindings/rust/Cargo.lock",
    "tests/Fixtures/ActivityLeaseConsumers/rust/Cargo.lock",
    "scripts/conformance/server-parity/corrected-markers/rust/Cargo.lock",
    "scripts/conformance/server-parity/legacy-marker-aliases/rust/Cargo.lock",
    "scripts/conformance/server-parity/published-sdk-patch/rust/Cargo.lock",
    "scripts/conformance/server-parity/query-authority/rust/Cargo.lock",
    "scripts/conformance/server-parity/sdk-directions/rust/Cargo.lock",
}

SKIPPED_DIRECTORIES = {".git", "node_modules", "target"}

SEMVER = re.compile(
    r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
    r"(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?"
    r"(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?"
)


def find_locks(root: Path) -> list[str]:
    found = []
    for directory, subdirectories, files in os.walk(root):
        subdirectories[:] = sorted(d for d in subdirectories if d not in SKIPPED_DIRECTORIES)
        if "Cargo.lock" in files:
            found.append((Path(directory) / "Cargo.lock").relative_to(root).as_posix())
    return sorted(found)


def packages(lock_text: str) -> list[tuple[object, object]]:
    """Each package's name and version, read as TOML in any field order."""
    lock = tomllib.loads(lock_text)
    entries = lock.get("package", [])
    if not isinstance(entries, list):
        raise ValueError("the lock's package entry is not an array of tables")
    return [(entry.get("name"), entry.get("version")) if isinstance(entry, dict) else (None, None)
            for entry in entries]


def below_floor(resolved: object, floor: tuple[int, int, int]) -> bool:
    """SemVer precedence: a release below the floor, or a prerelease of it.

    Build metadata does not change precedence. A version that is not SemVer
    counts as below the floor.
    """
    if not isinstance(resolved, str):
        return True
    match = SEMVER.fullmatch(resolved)
    if match is None:
        return True
    core = tuple(int(part) for part in match.group(1, 2, 3))
    return core < floor or (core == floor and match.group(4) is not None)


def stale_packages(lock_text: str) -> list[str]:
    """The trust crates in one lock that resolve below their fixed floor."""
    stale = []
    for name, resolved in packages(lock_text):
        floor = FLOORS.get(name) if isinstance(name, str) else None
        if floor is not None and below_floor(resolved, floor):
            stale.append(f"{name} {resolved}")
    return stale


class CargoLockTrustTest(unittest.TestCase):
    maxDiff = None  # name every failing lock

    def test_every_active_lock_resolves_the_fixed_trust_crates(self) -> None:
        locks = find_locks(ROOT)
        missing = REQUIRED - set(locks)
        self.assertEqual(set(), missing, "known locks the walk did not find")
        failures = {}
        for path in locks:
            if path in IMMUTABLE_EVIDENCE:
                continue
            stale = stale_packages((ROOT / path).read_text(encoding="utf-8"))
            if stale:
                failures[path] = stale
        self.assertEqual({}, failures, "active locks below the RUSTSEC-2026-0381/0382 floors")

    def test_the_known_tls_locks_resolve_both_crates(self) -> None:
        for path in sorted(REQUIRED):
            with self.subTest(path=path):
                names = {name for name, _ in packages((ROOT / path).read_text(encoding="utf-8"))}
                self.assertTrue(set(FLOORS) <= names, f"{path} no longer resolves the trust crates")

    def test_immutable_evidence_keeps_its_recorded_bytes(self) -> None:
        for path, digest in IMMUTABLE_EVIDENCE.items():
            with self.subTest(path=path):
                self.assertEqual(digest, hashlib.sha256((ROOT / path).read_bytes()).hexdigest())

    def test_stale_versions_are_refused(self) -> None:
        def lock(webpki: str, roots: str) -> str:
            return (
                '[[package]]\nname = "rustls-webpki"\nversion = "' + webpki + '"\n'
                'source = "registry+https://github.com/rust-lang/crates.io-index"\n\n'
                '[[package]]\nname = "webpki-roots"\nversion = "' + roots + '"\n'
            )

        self.assertEqual([], stale_packages(lock("0.103.16", "1.0.10")))
        self.assertEqual([], stale_packages(lock("0.104.0", "1.1.0")))
        self.assertEqual(["rustls-webpki 0.103.15"], stale_packages(lock("0.103.15", "1.0.10")))
        self.assertEqual(["webpki-roots 1.0.9"], stale_packages(lock("0.103.16", "1.0.9")))
        self.assertEqual(["rustls-webpki 0.102.8", "webpki-roots 0.26.11"], stale_packages(lock("0.102.8", "0.26.11")))

    def test_field_order_does_not_hide_a_stale_crate(self) -> None:
        source = 'source = "registry+https://github.com/rust-lang/crates.io-index"\n'
        reordered = (
            '[[package]]\nname = "rustls-webpki"\n' + source + 'version = "0.103.15"\n\n'
            '[[package]]\nversion = "1.0.9"\nchecksum = "00"\nname = "webpki-roots"\n'
        )
        self.assertEqual(["rustls-webpki 0.103.15", "webpki-roots 1.0.9"], stale_packages(reordered))
        fixed = '[[package]]\n' + source + 'version = "0.103.16"\nname = "rustls-webpki"\n'
        self.assertEqual([], stale_packages(fixed))

    def test_a_prerelease_of_the_fixed_release_is_refused(self) -> None:
        def lock(webpki: str, roots: str) -> str:
            return (
                '[[package]]\nname = "rustls-webpki"\nversion = "' + webpki + '"\n\n'
                '[[package]]\nname = "webpki-roots"\nversion = "' + roots + '"\n'
            )

        self.assertEqual(["rustls-webpki 0.103.16-alpha.1", "webpki-roots 1.0.10-rc.1"],
                         stale_packages(lock("0.103.16-alpha.1", "1.0.10-rc.1")))
        # A later release's prerelease and build metadata stay at or above the floor.
        self.assertEqual([], stale_packages(lock("0.103.17-alpha.1", "1.0.10+build.5")))
        self.assertEqual([], stale_packages(lock("0.104.0-rc.1", "1.1.0-beta")))

    def test_unreadable_versions_and_locks_fail_closed(self) -> None:
        for resolved in ["0.103", "v0.103.16", "0.103.16.1", "0.103.016", "", "latest"]:
            with self.subTest(resolved=resolved):
                self.assertEqual([f"rustls-webpki {resolved}"],
                                 stale_packages(f'[[package]]\nname = "rustls-webpki"\nversion = "{resolved}"\n'))
        self.assertEqual(["rustls-webpki None"], stale_packages('[[package]]\nname = "rustls-webpki"\n'))
        self.assertEqual(["webpki-roots 1"], stale_packages('[[package]]\nname = "webpki-roots"\nversion = 1\n'))
        with self.assertRaises(tomllib.TOMLDecodeError):
            stale_packages('[[package]]\nname = "webpki-roots"\nversion = "1.0.9\n')
        with self.assertRaises(ValueError):
            stale_packages('package = "webpki-roots"\n')

    def test_a_new_lock_outside_evidence_is_checked(self) -> None:
        import tempfile

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "fixtures/new/rust").mkdir(parents=True)
            (root / "fixtures/new/rust/Cargo.lock").write_text(
                '[[package]]\nversion = "1.0.9"\nsource = "registry+https://github.com/rust-lang/crates.io-index"\n'
                'name = "webpki-roots"\n', encoding="utf-8")
            (root / "target").mkdir()
            (root / "target/Cargo.lock").write_text("", encoding="utf-8")
            self.assertEqual(["fixtures/new/rust/Cargo.lock"], find_locks(root))
            self.assertEqual(["webpki-roots 1.0.9"],
                             stale_packages((root / "fixtures/new/rust/Cargo.lock").read_text(encoding="utf-8")))


if __name__ == "__main__":
    unittest.main()
