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
# A backslash-newline continues a shell command, so it separates arguments too.
SEPARATOR = r"""(?:[\s'",]|\\\n)+"""
# Composer image references, pinned or not, and Composer network commands.
COMPOSER_IMAGE = re.compile(r"""(?<![\w./-])(?:[\w.-]+/)*composer(?:(?::[\w.-]+)?@sha256:[0-9a-f]{64}|:[\w.-]*\w)""")
COMPOSER_SINK = re.compile(
    COMPOSER_IMAGE.pattern
    + rf"""|\bcomposer(?:\.phar)?(?:{SEPARATOR}--?[\w-]+(?:=[^\s'",]+)?)*{SEPARATOR}"""
    r"""(?:install|update|upgrade|require|remove|reinstall|search|self-update|selfupdate|diagnose|show|info|create-project|outdated|global|bump|audit|why|depends|archive|init)\b"""
    # A container whose entrypoint is Composer runs it, wherever the
    # subcommand sits in the continued command.
    + r"""|--entrypoint(?:=|\s+)['"]?(?:[^\s'"]*/)?composer(?:\.phar)?(?![\w.-])"""
    # The confined runner, which every Composer request outside an image
    # build should go through.
    + r"""|\b(?:bash|sh)\s+(?:[^\s'"]*/)?run-composer\.sh\b"""
)
WRAPPER = re.compile(r"""^(?:bash|sh)\s+(?:"\$here"/|scripts/ci/)run-composer\.sh$""")
SCANNED = (".github/workflows", "scripts", "tests/Fixtures", "tests/Integration", "benchmarks", "docker")
MANIFEST = ROOT / "tests" / "Unit" / "Support" / "composer-sinks.json"
SELF_CHECKS = {
    # A Composer image reference is confined by being the pinned digest.
    "pinned-reference": lambda occurrence: re.sub(r"^(?:docker\.io/)?(?:library/)?", "", occurrence.text) == PINNED_COMPOSER,
    "evidence-record": sink_guards.evidence_record(ROOT),
    # scripts/ci/run-composer.sh confines the request (pinned image after the
    # fixed Brotli decoder, checked CA pair it sets after the caller's options).
    "composer-wrapper": lambda occurrence: WRAPPER.match(occurrence.text) is not None
    and not occurrence.path.endswith(".md"),
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
    # The image comes from brotli-safe-composer.sh: the pinned image after
    # Alpine's fixed Brotli decoder (CVE-2026-111497), checked on every use.
    "brotli-floor": sink_guards.GuardType(re.compile(r"brotli-safe-composer\.sh"), -1),
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
            # The split form that escaped the inventory in server-parity.yml.
            ".github/workflows/split.yml": "jobs:\n  parity:\n    steps:\n      - run: |\n"
            "          docker run --rm --user=\"$(id -u):$(id -g)\" --entrypoint composer \\\n"
            "            -e COMPOSER_HOME=/tmp/composer -v \"$PWD:/source\" -w /source \"$image\" \\\n"
            "            install --working-dir=scripts/conformance/server-parity --no-interaction --no-progress\n",
            "scripts/conformance/entrypoint-path.sh": "docker run --entrypoint=/usr/bin/composer \"$image\" update\n",
            "scripts/conformance/entrypoint-phar.sh": "docker run --entrypoint 'composer.phar' \"$image\"\n",
            "scripts/conformance/continued.sh": "composer \\\n  --no-interaction \\\n  install\n",
            "scripts/conformance/other-runner.sh": "sh tools/run-composer.sh -- install\n",
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


WRAPPER_SCRIPT = CI / "run-composer.sh"
FLOOR_SCRIPT = CI / "brotli-safe-composer.sh"


class RunComposerTest(unittest.TestCase):
    """run-composer.sh: the pinned image after the Brotli floor, with a CA pair the caller cannot replace."""

    def wrapper(self, *arguments: str) -> tuple[subprocess.CompletedProcess[str], bool]:
        """Runs the wrapper with a docker stub that records whether it was called."""
        work = Path(tempfile.mkdtemp(prefix="dw-run-composer-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        marker = work / "docker-called"
        stub = work / "docker"
        stub.write_text(f"#!/bin/sh\ntouch '{marker}'\nexit 99\n")
        stub.chmod(0o755)
        result = subprocess.run(["bash", str(WRAPPER_SCRIPT), *arguments], capture_output=True, text=True,
                                env={**os.environ, "PATH": f"{work}:{os.environ['PATH']}"}, timeout=60)
        return result, marker.exists()

    def test_the_caller_cannot_replace_the_ca_pair_or_the_entrypoint(self) -> None:
        for options in (["-e", "COMPOSER_CAFILE=/etc/ssl/certs/ca-certificates.crt"], ["-eCOMPOSER_CAFILE=/x"],
                        ["--env", "COMPOSER_CAPATH=/etc/ssl/certs"], ["--entrypoint=sh"], ["--entrypoint", "sh"],
                        ["--env-file", "composer.env"], ["-v", "/tmp/root.pem:/checked-ca/ca.pem:ro"],
                        ["--volume=/tmp/trust:/checked-ca:ro"],
                        ["--mount", "type=bind,src=/tmp/empty,dst=/checked-ca/no-ca-directory"],
                        ["--mount=type=bind,src=/tmp/empty,dst=/checked-ca/no-ca-directory"],
                        ["--tmpfs", "/checked-ca"], ["--tmpfs=/checked-ca"],
                        ["--device", "/dev/null:/checked-ca/ca.pem"],
                        ["--device=/dev/null:/checked-ca/ca.pem"],
                        ["--volumes-from", "untrusted-container"],
                        ["--volumes-from=untrusted-container"]):
            with self.subTest(options):
                result, docker_called = self.wrapper("--rm", *options, "--", "install")

                self.assertEqual(2, result.returncode, result.stderr)
                self.assertIn("refusing", result.stderr)
                self.assertFalse(docker_called, "the wrapper reached docker before refusing")

    def test_composer_arguments_need_the_separator(self) -> None:
        result, docker_called = self.wrapper("--rm", "-v", "/tmp:/app", "install")

        self.assertEqual(2, result.returncode)
        self.assertFalse(docker_called)

    def test_unrelated_docker_options_reach_the_pinned_image_builder(self) -> None:
        result, docker_called = self.wrapper("--rm", "-v", "/tmp:/app", "--", "install")

        self.assertNotEqual(2, result.returncode, result.stderr)
        self.assertTrue(docker_called)

    def test_the_ca_pair_and_entrypoint_follow_the_callers_options(self) -> None:
        text = WRAPPER_SCRIPT.read_text()
        command = text[text.index("docker run --user"):]
        self.assertLess(command.index('"${options[@]}"'), command.index("COMPOSER_CAFILE=/checked-ca/ca.pem"))
        self.assertLess(command.index("COMPOSER_CAPATH=/checked-ca/no-ca-directory"), command.index("--entrypoint composer"))
        self.assertRegex(command, r'--entrypoint composer "\$image" "\$@"')
        self.assertRegex(text, r'image=\$\(sh "\$here/brotli-safe-composer\.sh"\)')
        self.assertRegex(text, r'sh "\$here/checked-ca\.sh" "\$ca"')

    def retrying(self, failures: int, message: str, *arguments: str) -> tuple[subprocess.CompletedProcess[str], int]:
        """Runs a copy of the wrapper whose Composer run fails `failures` times with `message`."""
        work = Path(tempfile.mkdtemp(prefix="dw-run-composer-retry-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        (work / "bin").mkdir()
        shutil.copy(WRAPPER_SCRIPT, work / "run-composer.sh")
        (work / "brotli-safe-composer.sh").write_text("#!/bin/sh\necho composer:checked\n")
        (work / "checked-ca.sh").write_text('#!/bin/sh\nmkdir -p "$1/no-ca-directory"\n: > "$1/ca.pem"\n')
        count = work / "runs"
        docker = work / "bin" / "docker"
        docker.write_text(f"""#!/bin/sh
n=$(($(cat '{count}' 2>/dev/null || echo 0) + 1)); echo "$n" > '{count}'
if [ "$n" -le {failures} ]; then echo '{message}' >&2; exit 100; fi
echo installed
""")
        (work / "bin" / "sleep").write_text("#!/bin/sh\nexit 0\n")
        for stub in (docker, work / "bin" / "sleep"):
            stub.chmod(0o755)
        result = subprocess.run(["bash", str(work / "run-composer.sh"), *arguments], capture_output=True, text=True,
                                env={**os.environ, "PATH": f"{work / 'bin'}:{os.environ['PATH']}"}, timeout=60)
        return result, int(count.read_text())

    def test_a_locked_install_retries_transient_download_failures(self) -> None:
        rate_limited = ('Failed to download a/b from dist: The "https://codeload.github.com/a/b/legacy.zip/c" '
                        "file could not be downloaded (HTTP/2 429 )")
        result, runs = self.retrying(2, rate_limited, "--rm", "--", "install", "--no-dev")
        self.assertEqual((0, 3), (result.returncode, runs), result.stderr)
        self.assertEqual("installed\n", result.stdout)
        self.assertEqual(2, result.stderr.count("HTTP/2 429"), "each attempt's stderr reaches the caller")

        result, runs = self.retrying(3, rate_limited, "--rm", "--", "install")
        self.assertEqual((100, 3), (result.returncode, runs), "three attempts at most, then Composer's own status")

    def test_other_failures_commands_and_kept_containers_are_not_retried(self) -> None:
        rate_limited = 'The "https://codeload.github.com/a" file could not be downloaded (HTTP/2 429 )'
        for failure, arguments in (
                ("Your lock file does not contain a compatible set of packages.", ("--rm", "--", "install")),
                (rate_limited, ("--rm", "--", "update")),
                (rate_limited, ("--name", "kept-sdk", "--", "install"))):
            with self.subTest(arguments):
                result, runs = self.retrying(1, failure, *arguments)
                self.assertEqual((100, 1), (result.returncode, runs), result.stderr)

    def test_the_floor_refuses_the_pinned_image_and_accepts_its_own(self) -> None:
        docker_or_skip(self)
        refused = subprocess.run(["sh", str(FLOOR_SCRIPT), "--check", PINNED_COMPOSER],
                                 capture_output=True, text=True, timeout=300)

        self.assertNotEqual(0, refused.returncode, "the pinned image ships brotli-libs 1.2.0-r1")
        self.assertIn("lacks brotli-libs>=1.2.1-r0", refused.stderr)

        built = subprocess.run(["sh", str(FLOOR_SCRIPT)], capture_output=True, text=True, timeout=600)
        self.assertEqual(0, built.returncode, built.stderr)
        checked = subprocess.run(["sh", str(FLOOR_SCRIPT), "--check", built.stdout.strip()],
                                 capture_output=True, text=True, timeout=300)
        self.assertEqual(0, checked.returncode, checked.stderr)

    def test_composer_runs_as_the_caller_with_the_checked_pair(self) -> None:
        docker_or_skip(self)
        work = Path(tempfile.mkdtemp(prefix="dw-run-composer-app-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        work.chmod(0o755)
        (work / "pkg").mkdir()
        (work / "pkg" / "composer.json").write_text(json.dumps({"name": "dw/probe", "version": "1.0.0"}))
        (work / "composer.json").write_text(json.dumps({
            "repositories": [{"type": "path", "url": "pkg", "options": {"symlink": False}}],
            "require": {"dw/probe": "1.0.0"},
        }))
        run = lambda *arguments: subprocess.run(  # noqa: E731
            ["bash", str(WRAPPER_SCRIPT), "--rm", "--network", "none", "-v", f"{work}:/app", "-w", "/app", "--",
             *arguments], capture_output=True, text=True, timeout=600)

        installed = run("install", "--no-interaction")

        self.assertEqual(0, installed.returncode, installed.stdout + installed.stderr)
        self.assertEqual(os.getuid(), (work / "vendor" / "dw" / "probe" / "composer.json").stat().st_uid)
        self.assertEqual("/checked-ca/ca.pem", run("config", "cafile").stdout.strip())
        self.assertEqual("/checked-ca/no-ca-directory", run("config", "capath").stdout.strip())
