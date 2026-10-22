"""Contracts for the patched-pip helpers (GHSA-6q48-6c7w-9gw2).

Run with: python3 -m unittest tests/Unit/Support/patched_pip_test.py
Needs the openssl command line tool to make throwaway test roots.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
CI = ROOT / "scripts" / "ci"
PIP_WHEEL = (
    "pip-26.2.2-py3-none-any.whl#sha256="
    "fd7f4dfa8190e014013b9f08f3e973f4e4c26ffd179a204895533a7254813a70"
)
WITHDRAWN = ("AAA Certificate Services", "emSign ECC Root CA - G3")

# A stand-in venv interpreter. It records each call, answers the bootstrap's
# questions from the environment and runs the real root counter.
FAKE_PYTHON = """#!/bin/sh
printf '%s\\n' "$*" >> "$FAKE_LOG"
case "$*" in
  *"-m pip "*) exit "${FAKE_INSTALL_STATUS:-0}" ;;
  *"import pip;"*) printf '%s\\n' "$FAKE_PIP_VERSION" ;;
  *"import certifi"*) printf '%s\\n' "$FAKE_BUNDLE" ;;
  *"sys.prefix"*) printf '%s\\n' "$FAKE_PREFIX" ;;
  *) exec "$REAL_PYTHON" "$@" ;;
esac
"""

# Files that mention a pip install without running one through the helpers.
NOT_PATCHED_PIP_INSTALLS = {
    # Assertion text about pip's install report, not a command.
    "scripts/conformance/server-parity/published-sdk-patch/identity.mjs",
    # Descriptive evidence strings; never executed.
    "scripts/conformance/worker-versioning-published-workers.mjs",
    # Debian's pip in the operator-chosen Server image uses that image's OS
    # store, which the Server image build checks for the withdrawn roots.
    "tests/Integration/StorageAdmission/sdk/Dockerfile",
}
SCANNED = (".github/workflows", "scripts", "tests/Fixtures", "tests/Integration", "benchmarks", "docker")
PIP_INSTALL = re.compile(r"\bpip3?\s+install\b|-m\s+pip\s+(?:--isolated\s+)?(?:install|download)\b")


def make_root(directory: Path, common_name: str) -> str:
    stem = directory / re.sub(r"\W+", "-", common_name)
    subprocess.run(
        [
            "openssl", "req", "-x509", "-nodes", "-days", "1",
            "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
            "-subj", f"/CN={common_name}",
            "-keyout", f"{stem}.key", "-out", f"{stem}.pem",
        ],
        check=True,
        capture_output=True,
    )
    return Path(f"{stem}.pem").read_text()


@unittest.skipUnless(shutil.which("openssl"), "openssl is required to make test roots")
class PatchedPipTest(unittest.TestCase):
    def setUp(self) -> None:
        self.work = Path(tempfile.mkdtemp(prefix="dw-patched-pip-"))
        self.addCleanup(shutil.rmtree, self.work, ignore_errors=True)
        self.clean = make_root(self.work, "Durable Workflow Test Root")
        self.withdrawn = {name: make_root(self.work, name) for name in WITHDRAWN}

    def bundle(self, *certificates: str) -> Path:
        path = Path(tempfile.mkstemp(suffix=".pem", dir=self.work)[1])
        path.write_text("".join(certificates))
        return path

    def count(self, bundle: Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, "-I", str(CI / "blocked_roots.py"), str(bundle)],
            capture_output=True,
            text=True,
        )

    def bootstrap(self, **overrides: str) -> tuple[subprocess.CompletedProcess[str], Path]:
        venv = self.work / "venv"
        venv.mkdir(exist_ok=True)
        python = self.work / "python"
        python.write_text(FAKE_PYTHON)
        python.chmod(0o755)
        log = self.work / "calls.log"
        environment = {
            **os.environ,
            "FAKE_LOG": str(log),
            "FAKE_PIP_VERSION": "26.2.2",
            "FAKE_BUNDLE": str(self.bundle(self.clean)),
            "FAKE_PREFIX": str(venv),
            "REAL_PYTHON": sys.executable,
            **overrides,
        }
        result = subprocess.run(
            ["sh", str(CI / "bootstrap-patched-pip.sh"), str(python)],
            capture_output=True,
            text=True,
            env=environment,
        )
        return result, venv

    def test_root_counter_accepts_a_bundle_without_withdrawn_roots(self) -> None:
        result = self.count(self.bundle(self.clean, self.clean))

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("2 roots", result.stdout)

    def test_root_counter_refuses_each_withdrawn_root(self) -> None:
        for name, certificate in self.withdrawn.items():
            with self.subTest(name):
                result = self.count(self.bundle(self.clean, certificate))

                self.assertEqual(1, result.returncode, result.stdout)
                self.assertIn(f"'{name}': 1", result.stdout)

    def test_root_counter_refuses_an_empty_bundle(self) -> None:
        self.assertEqual(1, self.count(self.bundle()).returncode)

    def test_bootstrap_installs_the_pinned_pip_and_confines_the_venv_to_its_bundle(self) -> None:
        result, venv = self.bootstrap()

        self.assertEqual(0, result.returncode, result.stderr)
        install = (self.work / "calls.log").read_text().splitlines()[0]
        self.assertIn("-I -m pip --isolated", install)
        self.assertIn("--no-deps", install)
        self.assertIn(f"pip @ https://files.pythonhosted.org/", install)
        self.assertTrue(install.endswith(PIP_WHEEL), install)
        self.assertEqual("[global]\nuse-deprecated = legacy-certs\n", (venv / "pip.conf").read_text())

    def test_bootstrap_refuses_another_pip_version(self) -> None:
        result, venv = self.bootstrap(FAKE_PIP_VERSION="26.2.1")

        self.assertNotEqual(0, result.returncode)
        self.assertIn("pip 26.2.2 is required; found 26.2.1", result.stderr)
        self.assertFalse((venv / "pip.conf").exists())

    def test_bootstrap_refuses_a_bundle_holding_a_withdrawn_root(self) -> None:
        for name, certificate in self.withdrawn.items():
            with self.subTest(name):
                result, venv = self.bootstrap(FAKE_BUNDLE=str(self.bundle(self.clean, certificate)))

                self.assertNotEqual(0, result.returncode)
                self.assertFalse((venv / "pip.conf").exists())

    def test_bootstrap_refuses_an_interpreter_outside_a_venv(self) -> None:
        result, venv = self.bootstrap(FAKE_PREFIX="")

        self.assertNotEqual(0, result.returncode)
        self.assertIn("is not a venv", result.stderr)
        self.assertFalse((venv / "pip.conf").exists())

    def test_bootstrap_stops_when_the_pinned_pip_does_not_install(self) -> None:
        result, venv = self.bootstrap(FAKE_INSTALL_STATUS="1")

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(1, len((self.work / "calls.log").read_text().splitlines()))
        self.assertFalse((venv / "pip.conf").exists())


class PatchedPipInventoryTest(unittest.TestCase):
    def test_every_pip_download_starts_from_the_patched_pip(self) -> None:
        unpatched = []
        for directory in SCANNED:
            for path in sorted((ROOT / directory).rglob("*")):
                relative = path.relative_to(ROOT).as_posix()
                if not path.is_file() or relative in NOT_PATCHED_PIP_INSTALLS:
                    continue
                try:
                    text = path.read_text()
                except (UnicodeDecodeError, OSError):
                    continue
                if PIP_INSTALL.search(text) and not re.search(r"bootstrap-patched-pip|patched-pip\.sh", text):
                    unpatched.append(relative)

        self.assertEqual([], unpatched, "run these pip installs through scripts/ci/bootstrap-patched-pip.sh")


if __name__ == "__main__":
    unittest.main()
