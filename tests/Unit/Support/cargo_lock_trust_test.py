"""Every active Cargo.lock resolves the fixed TLS trust crates.

RUSTSEC-2026-0381 and RUSTSEC-2026-0382 (GHSA-6q48-6c7w-9gw2) fix the
withdrawn emSign roots in rustls-webpki 0.103.16 and webpki-roots 1.0.10.
Each Cargo.lock in the repository is an executable input: CI builds it with
`cargo build --locked`. So every lock found by walking the tree must resolve
at least those versions. Immutable historical evidence is the only exemption.
It is listed by path, and its bytes must stay exactly as recorded.

Run with: python3 -m unittest tests/Unit/Support/cargo_lock_trust_test.py
"""

from __future__ import annotations

import hashlib
import os
import re
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

PACKAGE = re.compile(r'^\[\[package\]\]\nname = "([^"]+)"\nversion = "([^"]+)"', re.MULTILINE)


def find_locks(root: Path) -> list[str]:
    found = []
    for directory, subdirectories, files in os.walk(root):
        subdirectories[:] = sorted(d for d in subdirectories if d not in SKIPPED_DIRECTORIES)
        if "Cargo.lock" in files:
            found.append((Path(directory) / "Cargo.lock").relative_to(root).as_posix())
    return sorted(found)


def version(text: str) -> tuple[int, ...]:
    core = text.split("+", 1)[0].split("-", 1)[0]
    return tuple(int(part) for part in core.split("."))


def stale_packages(lock_text: str) -> list[str]:
    """The trust crates in one lock that resolve below their fixed floor."""
    stale = []
    for name, resolved in PACKAGE.findall(lock_text):
        floor = FLOORS.get(name)
        if floor is not None and version(resolved) < floor:
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
                names = {name for name, _ in PACKAGE.findall((ROOT / path).read_text(encoding="utf-8"))}
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

    def test_a_new_lock_outside_evidence_is_checked(self) -> None:
        import tempfile

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "fixtures/new/rust").mkdir(parents=True)
            (root / "fixtures/new/rust/Cargo.lock").write_text(
                '[[package]]\nname = "webpki-roots"\nversion = "1.0.9"\n', encoding="utf-8")
            (root / "target").mkdir()
            (root / "target/Cargo.lock").write_text("", encoding="utf-8")
            self.assertEqual(["fixtures/new/rust/Cargo.lock"], find_locks(root))
            self.assertEqual(["webpki-roots 1.0.9"],
                             stale_packages((root / "fixtures/new/rust/Cargo.lock").read_text(encoding="utf-8")))


if __name__ == "__main__":
    unittest.main()
