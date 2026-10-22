"""Contracts for the patched-pip helpers (GHSA-6q48-6c7w-9gw2).

Run with: python3 -m unittest tests/Unit/Support/patched_pip_test.py
Needs the openssl command line tool, and network access to PyPI for the
real-pip controls, which bootstrap the hash-pinned pip 26.2.2.
"""

from __future__ import annotations

import http.server
import os
import re
import shutil
import ssl
import subprocess
import sys
import tempfile
import threading
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

# pip installs, downloads and upgrades, in shell, YAML, Python or JS argument
# lists: "pip install", "-m pip --isolated install", "'-m', 'pip', 'install'".
PIP_COMMAND = re.compile(
    r"""\bpip3?(?:['"]?\s*,?\s*['"]?--[\w-]+(?:=\S+)?)*['"]?\s*,?\s*['"]?(?:install|download)\b"""
)
PATCHED = re.compile(r"bootstrap-patched-pip|patched-pip\.sh|PATCHED_PIP_BOOTSTRAP")
SCANNED = ("scripts", "tests/Fixtures", "tests/Integration", "benchmarks", "docker")
# Mentions that are not executed pip commands.
NOT_EXECUTED = {
    "scripts/conformance/server-parity/published-sdk-patch/identity.mjs": "assertion text about pip's install report",
}
# Workflow jobs whose pip use the repository cannot confine yet.
UNCONFINED_JOBS = {
    # The central policy fixes this job's exact steps and forbids job env.
    # It is confined when the central bootstrap action itself confines pip.
    ("phpunit-feature.yml", "action-policy"),
}


def make_root(directory: Path, common_name: str) -> str:
    stem = directory / re.sub(r"\W+", "-", common_name)
    openssl(
        "req", "-x509", "-nodes", "-days", "1",
        "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
        "-subj", f"/CN={common_name}",
        "-addext", "basicConstraints=critical,CA:TRUE",
        "-addext", "keyUsage=critical,keyCertSign,cRLSign",
        "-keyout", f"{stem}.key", "-out", f"{stem}.pem",
    )
    return Path(f"{stem}.pem").read_text()


def openssl(*arguments: str) -> None:
    subprocess.run(["openssl", *arguments], check=True, capture_output=True)


class PatchedPipHelperTest(unittest.TestCase):
    def setUp(self) -> None:
        if not shutil.which("openssl"):
            self.skipTest("openssl is required to make test roots")
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
        environment = {
            **os.environ,
            "FAKE_LOG": str(self.work / "calls.log"),
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
        self.assertIn("pip @ https://files.pythonhosted.org/", install)
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


class _RecordingHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802 - http.server naming
        self.server.paths.append(self.path)  # type: ignore[attr-defined]
        self.send_response(404)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def log_message(self, format: str, *args: object) -> None:  # noqa: A002
        pass


class RealPipTrustTest(unittest.TestCase):
    """Real pip 26.2.2 against a root trusted only through the OS store.

    The "affected OS store" is SSL_CERT_FILE holding a synthetic root named
    like a withdrawn one (1/1); pip's own bundle stays clean (0/0). A local
    HTTPS server signed by that root records each request that gets through.
    """

    @classmethod
    def setUpClass(cls) -> None:
        if not shutil.which("openssl"):
            raise unittest.SkipTest("openssl is required to make test roots")
        cls.work = Path(tempfile.mkdtemp(prefix="dw-real-pip-"))
        root = cls.work / "root"
        openssl(
            "req", "-x509", "-nodes", "-days", "1",
            "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
            "-subj", f"/CN={WITHDRAWN[0]}",
            "-addext", "basicConstraints=critical,CA:TRUE",
            "-addext", "keyUsage=critical,keyCertSign,cRLSign",
            "-keyout", f"{root}.key", "-out", f"{root}.pem",
        )
        leaf = cls.work / "leaf"
        openssl(
            "req", "-new", "-nodes",
            "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
            "-subj", "/CN=127.0.0.1",
            "-addext", "subjectAltName=IP:127.0.0.1",
            "-keyout", f"{leaf}.key", "-out", f"{leaf}.csr",
        )
        openssl(
            "x509", "-req", "-days", "1", "-in", f"{leaf}.csr",
            "-CA", f"{root}.pem", "-CAkey", f"{root}.key", "-CAcreateserial",
            "-copy_extensions", "copy", "-out", f"{leaf}.pem",
        )
        cls.affected_store = Path(f"{root}.pem")
        cls.empty_store_dir = cls.work / "no-store"
        cls.empty_store_dir.mkdir()

        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(f"{leaf}.pem", f"{leaf}.key")
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), _RecordingHandler)
        cls.server.paths = []  # type: ignore[attr-defined]
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

        cls.venv = cls.work / "venv"
        subprocess.run([sys.executable, "-m", "venv", str(cls.venv)], check=True, capture_output=True)
        cls.python = str(cls.venv / "bin" / "python")
        bootstrap = subprocess.run(
            ["sh", str(CI / "bootstrap-patched-pip.sh"), cls.python],
            capture_output=True,
            text=True,
            env=cls.base_environment(),
        )
        if bootstrap.returncode != 0:
            cls.tearDownClass()
            raise AssertionError(f"bootstrap failed:\n{bootstrap.stdout}{bootstrap.stderr}")
        cls.pip_bundle = subprocess.run(
            [cls.python, "-I", "-c", "from pip._vendor import certifi; print(certifi.where())"],
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        cls.server.server_close()
        shutil.rmtree(cls.work, ignore_errors=True)

    @staticmethod
    def base_environment() -> dict[str, str]:
        return {
            name: value
            for name, value in os.environ.items()
            if not name.startswith(("PIP_", "SSL_CERT_", "REQUESTS_CA_BUNDLE", "CURL_CA_BUNDLE"))
        }

    def fetch(self, *pip_options: str, **environment: str) -> tuple[subprocess.CompletedProcess[str], list[str]]:
        self.server.paths.clear()  # type: ignore[attr-defined]
        result = subprocess.run(
            [
                self.python, "-m", "pip", *pip_options, "download",
                "--disable-pip-version-check", "--no-input", "--no-deps",
                "--retries", "0", "--timeout", "10",
                "--dest", str(self.work / "downloads"),
                "--index-url", f"https://127.0.0.1:{self.server.server_address[1]}/simple/",
                "dw-trust-control",
            ],
            capture_output=True,
            text=True,
            env={
                **self.base_environment(),
                "SSL_CERT_FILE": str(self.affected_store),
                "SSL_CERT_DIR": str(self.empty_store_dir),
                **environment,
            },
        )
        return result, list(self.server.paths)  # type: ignore[attr-defined]

    def assertRefused(self, result: subprocess.CompletedProcess[str], paths: list[str]) -> None:
        self.assertEqual([], paths, "pip reached the server through the affected OS store")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("CERTIFICATE_VERIFY_FAILED", result.stdout + result.stderr)

    def test_the_affected_store_is_trusted_by_unconfined_pip(self) -> None:
        # Without the venv's pip.conf, pip 26.2.2 adds the OS store through
        # truststore, so the synthetic withdrawn root is trusted. This proves
        # the refusals below come from the confinement, not the setup.
        result, paths = self.fetch(PIP_CONFIG_FILE=os.devnull)

        self.assertEqual(["/simple/dw-trust-control/"], paths, result.stdout + result.stderr)
        self.assertNotIn("CERTIFICATE_VERIFY_FAILED", result.stdout + result.stderr)

    def test_the_bootstrapped_venv_refuses_the_affected_store(self) -> None:
        self.assertRefused(*self.fetch())

    def test_job_environment_confinement_refuses_the_affected_store(self) -> None:
        self.assertRefused(*self.fetch(
            PIP_CONFIG_FILE=os.devnull,
            PIP_CERT=self.pip_bundle,
            PIP_USE_DEPRECATED="legacy-certs",
        ))

    def test_patched_pip_wrapper_keeps_pip_status_and_removes_its_venv(self) -> None:
        temporary = self.work / "wrapper-tmp"
        for arguments, expected in ((["--version"], 0), (["install", "--no-index", "dw-trust-missing"], 1)):
            with self.subTest(arguments):
                temporary.mkdir()
                result = subprocess.run(
                    ["sh", str(CI / "patched-pip.sh"), *arguments],
                    capture_output=True,
                    text=True,
                    env={**self.base_environment(), "TMPDIR": str(temporary)},
                )

                self.assertEqual(expected, result.returncode, result.stdout + result.stderr)
                self.assertEqual([], list(temporary.iterdir()))
                temporary.rmdir()


class PatchedPipInventoryTest(unittest.TestCase):
    def test_every_script_pip_command_follows_the_patched_bootstrap(self) -> None:
        unpatched = []
        for directory in SCANNED:
            for path in sorted((ROOT / directory).rglob("*")):
                relative = path.relative_to(ROOT).as_posix()
                if not path.is_file() or relative in NOT_EXECUTED or path.parent == CI:
                    continue
                try:
                    text = path.read_text()
                except (UnicodeDecodeError, OSError):
                    continue
                first_bootstrap = PATCHED.search(text)
                for command in PIP_COMMAND.finditer(text):
                    line = text[text.rfind("\n", 0, command.start()) + 1:text.find("\n", command.end())]
                    if "patched-pip.sh" in line:
                        continue
                    if first_bootstrap is None or first_bootstrap.start() > command.start():
                        number = text.count("\n", 0, command.start()) + 1
                        unpatched.append(f"{relative}:{number}: {line.strip()}")

        self.assertEqual([], unpatched, "start these pip commands from scripts/ci/bootstrap-patched-pip.sh")

    def test_isolated_pip_commands_keep_legacy_certs(self) -> None:
        # --isolated ignores the venv's pip.conf, so the command itself must
        # keep pip off the operating system store.
        loose = []
        for directory in SCANNED:
            for path in sorted((ROOT / directory).rglob("*")):
                if not path.is_file() or path.parent == CI:
                    continue
                try:
                    text = path.read_text()
                except (UnicodeDecodeError, OSError):
                    continue
                for command in re.finditer(r"pip['\"]?\s*,?\s*['\"]?--isolated\b", text):
                    following = text[command.start():command.start() + 400]
                    if "legacy-certs" not in following:
                        loose.append(f"{path.relative_to(ROOT).as_posix()}:{text.count(chr(10), 0, command.start()) + 1}")

        self.assertEqual([], loose)

    def test_every_workflow_job_with_pip_confines_it(self) -> None:
        unconfined = []
        for workflow in sorted((ROOT / ".github" / "workflows").glob("*.yml")):
            text = workflow.read_text()
            jobs = text.split("\njobs:\n", 1)[1] if "\njobs:\n" in text else ""
            for job in re.split(r"\n(?=  [\w-]+:\s*\n)", "\n" + jobs):
                name = re.match(r"\s*([\w-]+):", job)
                if not name or not PIP_COMMAND.search(job):
                    continue
                key = (workflow.name, name.group(1))
                if key in UNCONFINED_JOBS:
                    continue
                confined = "patched-pip.sh" in job or (
                    "bootstrap-patched-pip@" in job and "PIP_USE_DEPRECATED=legacy-certs" in job
                )
                if not confined:
                    unconfined.append(f"{workflow.name} job {name.group(1)}")

        self.assertEqual([], unconfined, "bootstrap pip and confine it to its checked bundle in these jobs")

    def test_known_unconfined_jobs_still_exist(self) -> None:
        for workflow, job in UNCONFINED_JOBS:
            text = (ROOT / ".github" / "workflows" / workflow).read_text()
            self.assertRegex(text, rf"\n  {re.escape(job)}:\s*\n", f"remove the stale exception for {workflow} {job}")


if __name__ == "__main__":
    unittest.main()
