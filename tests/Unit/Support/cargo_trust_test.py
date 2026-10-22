"""Real-cargo trust control for GHSA-6q48-6c7w-9gw2.

Cargo's CARGO_HTTP_CAINFO adds a checked CA file, but TLS still trusts the
OpenSSL CA directory (the image's hashed system store, or SSL_CERT_DIR).
A local HTTPS sparse index is signed by a synthetic withdrawn root that only
that directory holds. Unconfined cargo, and cargo with only a clean
CARGO_HTTP_CAINFO, reach it; the clean file paired with an empty directory
refuses before any request.

Run with: python3 -m unittest tests/Unit/Support/cargo_trust_test.py
Needs cargo and openssl; set DW_REQUIRE_CARGO_TRUST_TEST=1 to fail rather
than skip when either is missing.
"""

from __future__ import annotations

import http.server
import os
import shutil
import ssl
import subprocess
import tempfile
import threading
import unittest
from pathlib import Path


def openssl(*arguments: str) -> str:
    return subprocess.run(["openssl", *arguments], check=True, capture_output=True, text=True).stdout


def make_root(stem: Path, common_name: str) -> None:
    openssl(
        "req", "-x509", "-nodes", "-days", "1",
        "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
        "-subj", f"/CN={common_name}",
        "-addext", "basicConstraints=critical,CA:TRUE",
        "-addext", "keyUsage=critical,keyCertSign,cRLSign",
        "-keyout", f"{stem}.key", "-out", f"{stem}.pem",
    )


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


class CargoTrustTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        missing = [tool for tool in ("cargo", "openssl") if not shutil.which(tool)]
        if missing:
            if os.environ.get("DW_REQUIRE_CARGO_TRUST_TEST") == "1":
                raise AssertionError(f"required for the cargo trust control: {missing}")
            raise unittest.SkipTest(f"needs {missing}")
        cls.work = Path(tempfile.mkdtemp(prefix="dw-cargo-trust-"))
        withdrawn = cls.work / "withdrawn"
        make_root(withdrawn, "AAA Certificate Services")
        clean = cls.work / "clean"
        make_root(clean, "Durable Workflow Clean Test Root")
        leaf = cls.work / "leaf"
        openssl(
            "req", "-new", "-nodes",
            "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1",
            "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
            "-keyout", f"{leaf}.key", "-out", f"{leaf}.csr",
        )
        openssl(
            "x509", "-req", "-days", "1", "-in", f"{leaf}.csr",
            "-CA", f"{withdrawn}.pem", "-CAkey", f"{withdrawn}.key", "-CAcreateserial",
            "-copy_extensions", "copy", "-out", f"{leaf}.pem",
        )
        # The affected "system" directory: the withdrawn root, hashed like
        # c_rehash does. The clean file holds only an unrelated root.
        cls.affected_dir = cls.work / "affected-certs"
        cls.affected_dir.mkdir()
        digest = openssl("x509", "-hash", "-noout", "-in", f"{withdrawn}.pem").strip()
        shutil.copy(f"{withdrawn}.pem", cls.affected_dir / f"{digest}.0")
        cls.empty_dir = cls.work / "no-ca-directory"
        cls.empty_dir.mkdir()
        cls.clean_file = Path(f"{clean}.pem")

        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.load_cert_chain(f"{leaf}.pem", f"{leaf}.key")
        cls.server = _QuietServer(("127.0.0.1", 0), _RecordingHandler)
        cls.server.paths = []  # type: ignore[attr-defined]
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

        cls.project = cls.work / "project"
        (cls.project / "src").mkdir(parents=True)
        (cls.project / "src" / "lib.rs").write_text("")
        (cls.project / "Cargo.toml").write_text(
            '[package]\nname = "dw-trust-control"\nversion = "0.0.0"\nedition = "2021"\n\n'
            '[dependencies]\ndw-trust-missing = { version = "1", registry = "dwtrust" }\n'
        )

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.shutdown()
        cls.server.server_close()
        shutil.rmtree(cls.work, ignore_errors=True)

    def resolve(self, **environment: str) -> tuple[subprocess.CompletedProcess[str], list[str]]:
        self.server.paths.clear()  # type: ignore[attr-defined]
        cargo_home = Path(tempfile.mkdtemp(dir=self.work))
        result = subprocess.run(
            ["cargo", "generate-lockfile", "--manifest-path", str(self.project / "Cargo.toml")],
            capture_output=True,
            text=True,
            timeout=120,
            env={
                **{name: value for name, value in os.environ.items()
                   if not name.startswith(("SSL_CERT_", "CARGO_HTTP_", "CARGO_REGISTRIES_"))},
                "CARGO_HOME": str(cargo_home),
                "CARGO_NET_RETRY": "0",
                "CARGO_HTTP_TIMEOUT": "10",
                "CARGO_REGISTRIES_DWTRUST_INDEX": f"sparse+https://127.0.0.1:{self.server.server_address[1]}/index/",
                "SSL_CERT_FILE": str(self.clean_file),
                **environment,
            },
        )
        return result, list(self.server.paths)  # type: ignore[attr-defined]

    def test_the_affected_directory_is_trusted_by_unconfined_cargo(self) -> None:
        result, paths = self.resolve(SSL_CERT_DIR=str(self.affected_dir))

        self.assertIn("/index/config.json", paths, result.stderr)

    def test_a_clean_cainfo_alone_still_trusts_the_affected_directory(self) -> None:
        result, paths = self.resolve(SSL_CERT_DIR=str(self.affected_dir), CARGO_HTTP_CAINFO=str(self.clean_file))

        self.assertIn("/index/config.json", paths, result.stderr)

    def test_a_clean_cainfo_with_an_empty_directory_refuses(self) -> None:
        result, paths = self.resolve(SSL_CERT_DIR=str(self.empty_dir), CARGO_HTTP_CAINFO=str(self.clean_file))

        self.assertEqual([], paths, "cargo reached the server through the withdrawn root")
        self.assertNotEqual(0, result.returncode)
        self.assertRegex(result.stderr, r"(?i)certificate|SSL")

    def test_the_storage_admission_rust_stage_pairs_the_file_with_an_empty_directory(self) -> None:
        root = Path(__file__).resolve().parents[3]
        dockerfile = (root / "tests/Integration/StorageAdmission/sdk/Dockerfile").read_text()
        stage = dockerfile.split("AS rust-worker", 1)[1].split("\nFROM ", 1)[0]
        cargo = stage.index("RUN cargo ")
        for setting in ("CARGO_HTTP_CAINFO=", "SSL_CERT_DIR=/etc/server-trust/no-ca-directory", "SSL_CERT_FILE="):
            self.assertIn(setting, stage[:cargo], setting)
