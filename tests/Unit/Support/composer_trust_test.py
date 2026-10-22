"""Composer trust controls for GHSA-6q48-6c7w-9gw2.

Every executable Composer image is the reviewed 2.10.3 digest, and that
image's own CA store is checked. Where Composer runs in an image whose store
is not checked (frozen published Server images), it gets COMPOSER_CAFILE (a
checked file) and COMPOSER_CAPATH (an empty directory). A real-Composer
control proves that pairing: a local HTTPS repository is signed by a
synthetic withdrawn root that only the container's system store trusts.

Run with: python3 -m unittest tests/Unit/Support/composer_trust_test.py
The image and real-Composer tests need docker; set
DW_REQUIRE_COMPOSER_TRUST_TEST=1 to fail rather than skip without it.
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
PINNED_COMPOSER = "composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee"
SEPARATOR = r"""[\s'",]+"""
# Composer image references, pinned or not, and Composer network commands.
COMPOSER_IMAGE = re.compile(r"""(?<![\w./-])(?:[\w.-]+/)*composer(?:(?::[\w.-]+)?@sha256:[0-9a-f]{64}|:[\w.-]*\w)""")
COMPOSER_SINK = re.compile(
    COMPOSER_IMAGE.pattern
    + rf"""|\bcomposer(?:\.phar)?(?:{SEPARATOR}--?[\w-]+(?:=[^\s'",]+)?)*{SEPARATOR}"""
    r"""(?:install|update|upgrade|require|remove|reinstall|search|self-update|selfupdate|diagnose|show|info|create-project|outdated|global|bump|audit|why|depends|archive|init)\b"""
)
SCANNED = (".github/workflows", "scripts", "tests/Fixtures", "tests/Integration", "benchmarks", "docker")
MANIFEST = ROOT / "tests" / "Unit" / "Support" / "composer-sinks.json"
SELF_CHECKS = {
    # A Composer image reference is confined by being the pinned digest.
    "pinned-reference": lambda occurrence: re.sub(r"^(?:docker\.io/)?(?:library/)?", "", occurrence.text) == PINNED_COMPOSER,
    "evidence-record": sink_guards.evidence_record(ROOT),
}
# How a reviewed sink is confined, checked against the source (sink_guards).
GUARDS = {
    # The enclosing docker run or service uses the pinned image.
    "pinned-image": sink_guards.GuardType(re.compile(re.escape(PINNED_COMPOSER)), -1),
    # A Dockerfile stage built from the pinned image.
    "pinned-stage": sink_guards.GuardType(re.compile(r"^\s*FROM\s"), -1, contains=PINNED_COMPOSER),
    # COMPOSER_CAFILE (with an empty COMPOSER_CAPATH) set before the request.
    "checked-ca": sink_guards.GuardType(re.compile(r"COMPOSER_CAFILE"), -1),
    # composer-trust-guard.sh refuses an affected store first.
    "trust-guard": sink_guards.GuardType(re.compile(r"composer-trust-guard\.sh"), -1),
    # This repository's Server image, whose stores server-verify-trust checks.
    "server-verify": sink_guards.GuardType(re.compile(r"server-verify-trust"), -1),
    "server-base-build": sink_guards.GuardType(re.compile(r"docker build --target base "), -1),
    # docker_run_for_project, which always adds the checked CA pair.
    "trusted-builder": sink_guards.GuardType(re.compile(r"docker_run_for_project\("), -1),
}


def scanned_files(root: Path) -> list[Path]:
    files = [path for directory in SCANNED for path in sorted((root / directory).rglob("*"))
             if path.is_file() and "vendor" not in path.relative_to(root).parts]
    return files + [path for path in (root / "Dockerfile", *sorted(root.glob("docker-compose*.yml"))) if path.is_file()]


def composer_sinks(root: Path) -> Counter[tuple[str, str]]:
    return sink_guards.counts(sink_guards.occurrences(root, scanned_files(root), COMPOSER_SINK))


def unreviewed_sinks(root: Path, entries: list[dict[str, object]]) -> tuple[list[str], list[str]]:
    reviewed = Counter({(str(entry["path"]), str(entry["line"])): int(entry["count"]) for entry in entries})
    found = composer_sinks(root)
    describe = lambda sinks: [f"{path}: {line} (x{count})" for (path, line), count in sorted(sinks.items())]
    return describe(found - reviewed), describe(reviewed - found)


def docker_or_skip(test: unittest.TestCase) -> None:
    if shutil.which("docker") and subprocess.run(["docker", "info"], capture_output=True).returncode == 0:
        return
    if os.environ.get("DW_REQUIRE_COMPOSER_TRUST_TEST") == "1":
        raise AssertionError("docker is required for the Composer trust controls")
    test.skipTest("needs docker")


class ComposerInventoryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.entries = json.loads(MANIFEST.read_text())["sinks"]

    def guard_problems(self, root: Path, paths: list[str] | None = None) -> list[str]:
        files = scanned_files(root) if paths is None else [root / path for path in paths]
        found = sink_guards.occurrences(root, files, COMPOSER_SINK)
        return sink_guards.guard_problems(root, found, self.entries, GUARDS, SELF_CHECKS)

    def test_every_sink_keeps_its_recorded_guard(self) -> None:
        self.assertEqual([], self.guard_problems(ROOT), f"restore the guard or update {MANIFEST.name}")

    def test_removing_a_guard_fails_although_the_sink_is_unchanged(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-composer-guards-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        relative = "scripts/conformance/server-parity/corrected-marker-embedded-install.sh"
        source = (ROOT / relative).read_text().split("\n")
        guard = next(i for i, line in enumerate(source) if GUARDS["trust-guard"].pattern.search(line))
        (work / relative).parent.mkdir(parents=True)
        (work / relative).write_text("\n".join(source))

        self.assertEqual([], self.guard_problems(work, [relative]))

        (work / relative).write_text("\n".join(source[:guard] + source[guard + 1:]))

        self.assertEqual([], unreviewed_sinks(work, self.entries)[0])
        problems = self.guard_problems(work, [relative])
        self.assertTrue(any("composer install" in problem for problem in problems), problems)

    def test_a_pinned_stage_guard_must_name_the_pinned_image(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-composer-stage-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        relative = "scripts/perf/Dockerfile.sdk"
        (work / relative).parent.mkdir(parents=True)
        (work / relative).write_text((ROOT / relative).read_text().replace(PINNED_COMPOSER, "composer:2"))

        self.assertTrue(self.guard_problems(work, [relative]))

    def test_every_composer_image_is_the_pinned_digest(self) -> None:
        floating = []
        for path in scanned_files(ROOT):
            try:
                text = path.read_text()
            except (UnicodeDecodeError, OSError):
                continue
            for match in COMPOSER_IMAGE.finditer(text):
                if re.sub(r"^(?:docker\.io/)?(?:library/)?", "", match.group(0)) != PINNED_COMPOSER:
                    floating.append(f"{path.relative_to(ROOT).as_posix()}: {match.group(0)}")

        self.assertEqual([], floating, f"use {PINNED_COMPOSER}")

    def test_every_composer_sink_is_reviewed(self) -> None:
        unreviewed, stale = unreviewed_sinks(ROOT, self.entries)

        self.assertEqual([], unreviewed, f"confine these Composer sinks, then record them in {MANIFEST.name}")
        self.assertEqual([], stale, f"remove these entries from {MANIFEST.name}")

    def test_every_reviewed_sink_names_its_confinement(self) -> None:
        for entry in self.entries:
            with self.subTest(entry["path"]):
                self.assertTrue(str(entry.get("confinement", "")).strip())

    def test_unconfined_sinks_fail_the_review(self) -> None:
        work = Path(tempfile.mkdtemp(prefix="dw-composer-sinks-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        cases = {
            "scripts/conformance/floating.sh": "docker run --rm -v \"$PWD:/app\" composer:2 install\n",
            "scripts/conformance/runner.sh": "# runs in the pinned image\ncomposer require durable-workflow/sdk\n",
            "scripts/conformance/self-update.sh": "composer self-update --2\n",
            ".github/workflows/audit.yml": "jobs:\n  audit:\n    steps:\n      - run: composer audit --locked\n",
        }
        for relative, text in cases.items():
            (work / relative).parent.mkdir(parents=True, exist_ok=True)
            (work / relative).write_text(text)

        unreviewed, _ = unreviewed_sinks(work, self.entries)

        for relative in cases:
            with self.subTest(relative):
                self.assertTrue(any(item.startswith(f"{relative}: ") for item in unreviewed), unreviewed)


class PinnedComposerImageTest(unittest.TestCase):
    def test_the_pinned_image_store_holds_no_withdrawn_root(self) -> None:
        docker_or_skip(self)
        work = Path(tempfile.mkdtemp(prefix="dw-composer-store-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        bundle = subprocess.run(
            ["docker", "run", "--rm", "--network", "none", "--entrypoint", "cat", PINNED_COMPOSER,
             "/etc/ssl/certs/ca-certificates.crt"],
            check=True, capture_output=True, text=True,
        ).stdout
        (work / "ca.pem").write_text(bundle)
        result = subprocess.run(["python3", "-I", str(CI / "blocked_roots.py"), str(work / "ca.pem")],
                                capture_output=True, text=True)

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_the_checked_ca_helper_writes_the_pinned_store_and_an_empty_directory(self) -> None:
        docker_or_skip(self)
        work = Path(tempfile.mkdtemp(prefix="dw-checked-ca-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        result = subprocess.run(["sh", str(CI / "checked-ca.sh"), str(work / "trust")], capture_output=True, text=True)

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertGreater((work / "trust" / "ca.pem").stat().st_size, 0)
        self.assertEqual([], list((work / "trust" / "no-ca-directory").iterdir()))


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


class RealComposerTrustTest(unittest.TestCase):
    """Composer in a container whose system store trusts a synthetic withdrawn root."""

    @classmethod
    def setUpClass(cls) -> None:
        if not shutil.which("openssl"):
            raise unittest.SkipTest("needs openssl")
        cls.work = Path(tempfile.mkdtemp(prefix="dw-composer-trust-"))
        cls.work.chmod(0o755)

        def openssl(*arguments: str) -> None:
            subprocess.run(["openssl", *arguments], check=True, capture_output=True)

        root = cls.work / "withdrawn"
        openssl("req", "-x509", "-nodes", "-days", "1", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
                "-subj", "/CN=AAA Certificate Services", "-addext", "basicConstraints=critical,CA:TRUE",
                "-addext", "keyUsage=critical,keyCertSign,cRLSign", "-keyout", f"{root}.key", "-out", f"{root}.pem")
        leaf = cls.work / "leaf"
        openssl("req", "-new", "-nodes", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
                "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
                "-keyout", f"{leaf}.key", "-out", f"{leaf}.csr")
        openssl("x509", "-req", "-days", "1", "-in", f"{leaf}.csr", "-CA", f"{root}.pem", "-CAkey", f"{root}.key",
                "-CAcreateserial", "-copy_extensions", "copy", "-out", f"{leaf}.pem")
        clean = cls.work / "clean"
        openssl("req", "-x509", "-nodes", "-days", "1", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
                "-subj", "/CN=Durable Workflow Clean Test Root", "-keyout", f"{clean}.key", "-out", f"{clean}.pem")
        (cls.work / "no-ca-directory").mkdir()
        for path in cls.work.iterdir():
            path.chmod(0o755 if path.is_dir() else 0o644)

        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(f"{leaf}.pem", f"{leaf}.key")
        cls.server = _QuietServer(("127.0.0.1", 0), _RecordingHandler)
        cls.server.paths = []  # type: ignore[attr-defined]
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls) -> None:
        if hasattr(cls, "server"):
            cls.server.shutdown()
            cls.server.server_close()
        shutil.rmtree(getattr(cls, "work", "/nonexistent"), ignore_errors=True)

    def request(self, *environment: str) -> tuple[subprocess.CompletedProcess[str], list[str]]:
        docker_or_skip(self)
        self.server.paths.clear()  # type: ignore[attr-defined]
        port = self.server.server_address[1]
        # Trust the synthetic root through the container's own system store,
        # then ask Composer for a package from the local repository only.
        script = (
            "cp /trust/withdrawn.pem /usr/local/share/ca-certificates/withdrawn.crt && update-ca-certificates >/dev/null 2>&1"
            f" && COMPOSER_HOME=/tmp/composer composer create-project --no-interaction --no-plugins --no-install"
            f" --repository=https://127.0.0.1:{port} dw/trust-control /tmp/out"
        )
        result = subprocess.run(
            ["docker", "run", "--rm", "--network", "host", "-v", f"{self.work}:/trust:ro", *environment,
             "--entrypoint", "sh", PINNED_COMPOSER, "-c", script],
            capture_output=True, text=True, timeout=180,
        )
        return result, list(self.server.paths)  # type: ignore[attr-defined]

    def test_the_system_store_root_is_trusted_by_unconfined_composer(self) -> None:
        result, paths = self.request()

        self.assertIn("/packages.json", paths, result.stdout + result.stderr)

    def test_a_checked_cafile_with_an_empty_capath_refuses(self) -> None:
        result, paths = self.request(
            "-e", "COMPOSER_CAFILE=/trust/clean.pem", "-e", "COMPOSER_CAPATH=/trust/no-ca-directory",
        )

        self.assertEqual([], paths, "Composer reached the server through the withdrawn root")
        self.assertNotEqual(0, result.returncode)
