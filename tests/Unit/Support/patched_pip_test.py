"""Contracts for the patched-pip helpers (GHSA-6q48-6c7w-9gw2).

Run with: python3 -m unittest tests/Unit/Support/patched_pip_test.py
Needs the openssl command line tool, and network access to PyPI for the
real-pip controls, which bootstrap the hash-pinned pip 26.2.2.
"""

from __future__ import annotations

import http.server
import json
import os
import re
import shutil
import ssl
import subprocess
import sys
import tempfile
import threading
import unittest
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import sink_guards  # noqa: E402

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

SEPARATOR = r"""[\s'",]+"""
# Every place pip can make a request: "pip install", "-m pip --isolated
# download", "'-m', 'pip', 'wheel'" and the like in shell, YAML, Python or JS
# argument lists; any "-m pip" whose arguments come from elsewhere; and the
# actions that run pip themselves.
PIP_SINK = re.compile(
    rf"""-m{SEPARATOR}pip\b|\bpip3?(?:{SEPARATOR}--[\w-]+(?:=[^\s'",]+)?)*{SEPARATOR}(?:install|download|wheel|index)\b"""
    r"""|uses:\s*(?:helm/chart-testing-action|durable-workflow/\.github/\.github/actions/bootstrap-patched-pip)@"""
)
SCANNED = (".github/workflows", "scripts", "tests/Fixtures", "tests/Integration", "benchmarks", "docker")
# The reviewed sinks: exact line, count per file, and how each is confined.
MANIFEST = ROOT / "tests" / "Unit" / "Support" / "pip-sinks.json"


# How a reviewed sink is confined, checked against the source (sink_guards).
GUARDS = {
    # A call that installs the patched, root-checked pip, earlier in the same
    # script or job: bootstrap-patched-pip.sh run by sh, the central action,
    # or the runners' own calls. Path definitions alone do not count.
    "bootstrap": sink_guards.GuardType(re.compile(
        r"""\bsh\s+["']?[^\s"']*bootstrap-patched-pip\.sh"""
        r"""|uses:\s*durable-workflow/\.github/\.github/actions/bootstrap-patched-pip@"""
        r"""|'sh',\s*\[PATCHED_PIP_BOOTSTRAP"""
        r"""|\brun\w*\(\s*(?:bootstrap_command\b|\["sh",\s*str\(bootstrap\))"""
    ), -1),
    # The bootstrap's own install of the hash-pinned wheel, in the same command.
    "pinned-wheel": sink_guards.GuardType(re.compile(r"#sha256=[0-9a-f]{64}"), 1),
}
CENTRAL_ACTION = "durable-workflow/.github/.github/actions/bootstrap-patched-pip@75587fc33ff40c34f32ef777293dad56bb6f48c7"
SELF_CHECKS = {
    # The Security-accepted central action is itself the bootstrap.
    "accepted-action": lambda occurrence: occurrence.line == f"uses: {CENTRAL_ACTION}  # clean-trust",
    # "pip --version" makes no request.
    "version-query": lambda occurrence: re.search(r"-m pip --version\b", occurrence.line) is not None,
    "evidence-record": sink_guards.evidence_record(ROOT),
}
# A networked "-m pip" runs from checked-out or mounted source, so Python must
# not import a pip from the working directory: "-I" comes first.
MODULE_PIP = re.compile(r"""-m[\s'",\\]+pip\b""")


def scanned_files(root: Path) -> list[Path]:
    files = [path for directory in SCANNED for path in sorted((root / directory).rglob("*")) if path.is_file()]
    return files + [path for path in (root / "Dockerfile", *sorted(root.glob("docker-compose*.yml"))) if path.is_file()]


def pip_sinks(root: Path) -> Counter[tuple[str, str]]:
    return sink_guards.counts(sink_guards.occurrences(root, scanned_files(root), PIP_SINK))


def unisolated_module_pips(root: Path, exceptions: list[dict[str, object]]) -> list[str]:
    """Every "-m pip" without "-I" before it, apart from the recorded exceptions."""
    allowed = Counter((str(entry["path"]), str(entry["line"])) for entry in exceptions)
    found = []
    for occurrence in sink_guards.occurrences(root, scanned_files(root), MODULE_PIP):
        if sink_guards.COMMENT.match(occurrence.line):
            continue
        source = (root / occurrence.path).read_text().split("\n")
        before = "\n".join(source[:occurrence.index] + [source[occurrence.index][:occurrence.column]])
        if re.search(r"""-I[\s'",\\]*$""", before):
            continue
        key = (occurrence.path, occurrence.line)
        if allowed[key] > 0:
            allowed[key] -= 1
            continue
        found.append(f"{occurrence.path}:{occurrence.index + 1}: {occurrence.line}")
    return found


def unreviewed_sinks(root: Path, entries: list[dict[str, object]]) -> tuple[list[str], list[str]]:
    reviewed = Counter({(str(entry["path"]), str(entry["line"])): int(entry["count"]) for entry in entries})
    found = pip_sinks(root)
    describe = lambda sinks: [f"{path}: {line} (x{count})" for (path, line), count in sorted(sinks.items())]
    return describe(found - reviewed), describe(reviewed - found)


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


class _QuietServer(http.server.ThreadingHTTPServer):
    # A refused handshake drops the connection; that is the expected outcome.
    def handle_error(self, request: object, client_address: object) -> None:
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
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(f"{leaf}.pem", f"{leaf}.key")
        cls.server = _QuietServer(("127.0.0.1", 0), _RecordingHandler)
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


    def poisoned_directory(self) -> tuple[Path, Path]:
        """A working directory whose pip.py and pip/ package write a marker."""
        directory = Path(tempfile.mkdtemp(prefix="dw-poisoned-cwd-", dir=self.work))
        marker = directory / "imported-checkout-pip"
        payload = f"open({str(marker)!r}, 'a').write('checked-out pip ran\\n')\n"
        (directory / "pip.py").write_text(payload)
        (directory / "pip").mkdir()
        (directory / "pip" / "__init__.py").write_text(payload)
        (directory / "pip" / "__main__.py").write_text(payload)
        return directory, marker

    def test_a_poisoned_working_directory_shadows_pip_without_isolation(self) -> None:
        # The control: from this directory an unisolated "-m pip" runs the
        # checked-out package instead of the bootstrapped client.
        directory, marker = self.poisoned_directory()
        subprocess.run([self.python, "-m", "pip", "--version"], cwd=directory, capture_output=True,
                       env=self.base_environment())

        self.assertTrue(marker.exists())

    def test_patched_pip_wrapper_ignores_a_poisoned_working_directory(self) -> None:
        directory, marker = self.poisoned_directory()
        result = subprocess.run(["sh", str(CI / "patched-pip.sh"), "--version"], cwd=directory,
                                capture_output=True, text=True, env=self.base_environment())

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("pip 26.2.2", result.stdout)
        self.assertFalse(marker.exists(), "the wrapper imported pip from its working directory")

    def test_the_bootstrapped_venv_ignores_a_poisoned_working_directory_with_isolation(self) -> None:
        directory, marker = self.poisoned_directory()
        result = subprocess.run([self.python, "-I", "-m", "pip", "--version"], cwd=directory,
                                capture_output=True, text=True, env=self.base_environment())

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("pip 26.2.2", result.stdout)
        self.assertFalse(marker.exists())


class PatchedPipInventoryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.manifest = json.loads(MANIFEST.read_text())
        self.entries = self.manifest["sinks"]

    def guard_problems(self, root: Path, paths: list[str] | None = None) -> list[str]:
        files = scanned_files(root) if paths is None else [root / path for path in paths]
        found = sink_guards.occurrences(root, files, PIP_SINK)
        return sink_guards.guard_problems(root, found, self.entries, GUARDS, SELF_CHECKS)

    def test_every_sink_keeps_its_recorded_guard(self) -> None:
        self.assertEqual([], self.guard_problems(ROOT), f"restore the guard or update {MANIFEST.name}")

    def test_removing_a_guard_fails_although_the_sink_is_unchanged(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-pip-guards-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        relative = "scripts/conformance/sagas-published-artifacts.sh"
        sink = 'python -I -m pip install "durable-workflow==$python_version" httpx'
        source = (ROOT / relative).read_text().split("\n")
        index = next(i for i, line in enumerate(source) if line.strip() == sink)
        guard = next(i for i in range(index - 1, -1, -1) if GUARDS["bootstrap"].pattern.search(source[i]))
        (work / relative).parent.mkdir(parents=True)
        (work / relative).write_text("\n".join(source))

        self.assertEqual([], self.guard_problems(work, [relative]))

        (work / relative).write_text("\n".join(source[:guard] + source[guard + 1:]))

        self.assertEqual([], unreviewed_sinks(work, self.entries)[0])
        problems = self.guard_problems(work, [relative])
        self.assertTrue(any(sink[:40] in problem for problem in problems), problems)

    def test_a_guard_type_must_match_its_sink(self) -> None:
        entries = [dict(entry, guards=[dict(guard, type="comment", line=None, distance=None) for guard in entry["guards"]])
                   if entry["path"] == "scripts/conformance/sagas-published-artifacts.sh" else entry
                   for entry in self.entries]
        found = sink_guards.occurrences(ROOT, [ROOT / "scripts/conformance/sagas-published-artifacts.sh"], PIP_SINK)

        self.assertTrue(sink_guards.guard_problems(ROOT, found, entries, GUARDS, SELF_CHECKS))

    def test_every_networked_module_pip_is_isolated(self) -> None:
        self.assertEqual([], unisolated_module_pips(ROOT, self.manifest["isolation_exceptions"]))

    def test_unisolated_module_pips_fail(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-pip-isolation-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        cases = {
            "scripts/shell.sh": ('"$venv/bin/python" -m pip install durable-workflow\n', 1),
            "scripts/list.py": ('run([str(python), "-m", "pip", "install", "x"])\n', 1),
            "scripts/split.py": ('command = [\n    str(python),\n    "-m",\n    "pip",\n]\n', 1),
            "scripts/array.mjs": ("run(python, ['-m', 'pip', 'install', 'x']);\n", 1),
            "scripts/isolated.sh": ('"$venv/bin/python" -I -m pip install durable-workflow\n', 0),
            "scripts/isolated.mjs": ("run(python, ['-I', '-m', 'pip', 'install', 'x']);\n", 0),
            "scripts/comment.sh": ("# python -m pip install durable-workflow\n", 0),
        }
        for relative, (text, _) in cases.items():
            (work / relative).parent.mkdir(parents=True, exist_ok=True)
            (work / relative).write_text(text)
        # An exception covers only its exact line.
        exceptions = [{"path": "scripts/shell.sh", "line": "python -m pip install other"}]

        found = unisolated_module_pips(work, exceptions)

        for relative, (_, expected) in cases.items():
            with self.subTest(relative):
                self.assertEqual(expected, sum(item.startswith(f"{relative}:") for item in found), found)

    def test_every_isolation_exception_is_owned_and_still_needed(self) -> None:
        for exception in self.manifest["isolation_exceptions"]:
            with self.subTest(exception["path"]):
                self.assertTrue(str(exception.get("owner", "")).strip() and str(exception.get("reason", "")).strip())
                self.assertIn(exception["line"], [line.strip() for line in (ROOT / exception["path"]).read_text().split("\n")])

    def test_every_pip_sink_is_reviewed(self) -> None:
        unreviewed, stale = unreviewed_sinks(ROOT, self.entries)

        self.assertEqual([], unreviewed, f"confine these pip sinks, then record them in {MANIFEST.name}")
        self.assertEqual([], stale, f"remove these entries from {MANIFEST.name}")

    def test_every_reviewed_sink_names_its_confinement(self) -> None:
        for entry in self.entries:
            with self.subTest(entry["path"]):
                self.assertTrue(str(entry.get("confinement", "")).strip())

    def test_unconfined_sinks_fail_the_review(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-pip-sinks-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        cases = {
            # Mentioning the bootstrap does not confine a later install.
            "scripts/conformance/mention-only.sh": "# sh scripts/ci/bootstrap-patched-pip.sh is our policy\npython -m pip install durable-workflow\n",
            "scripts/conformance/wheel.sh": "pip wheel --wheel-dir /tmp/w durable-workflow\n",
            "scripts/conformance/index.sh": "python3 -m pip index versions durable-workflow\n",
            "scripts/conformance/args.py": "subprocess.run([python, '-m', 'pip', *arguments])\n",
            # Echoing the confinement strings does not confine the job.
            ".github/workflows/echo.yml": "jobs:\n  echo:\n    steps:\n      - run: |\n"
            "          echo bootstrap-patched-pip@ PIP_USE_DEPRECATED=legacy-certs\n"
            "          python -m pip install PyYAML\n",
        }
        for relative, text in cases.items():
            (work / relative).parent.mkdir(parents=True, exist_ok=True)
            (work / relative).write_text(text)
        # A second sink added to a reviewed file fails as well.
        reviewed = "scripts/conformance/sagas-published-artifacts.sh"
        (work / reviewed).parent.mkdir(parents=True, exist_ok=True)
        (work / reviewed).write_text((ROOT / reviewed).read_text() + '\npython -m pip install "durable-workflow==$python_version" httpx\n')

        unreviewed, _ = unreviewed_sinks(work, self.entries)

        for relative in [*cases, reviewed]:
            with self.subTest(relative):
                self.assertTrue(any(item.startswith(f"{relative}: ") for item in unreviewed), unreviewed)

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
                for command in re.finditer(rf"\bpip{SEPARATOR}--isolated\b", text):
                    following = text[command.start():command.start() + 400]
                    if "legacy-certs" not in following:
                        loose.append(f"{path.relative_to(ROOT).as_posix()}:{text.count(chr(10), 0, command.start()) + 1}")

        self.assertEqual([], loose)


if __name__ == "__main__":
    unittest.main()
