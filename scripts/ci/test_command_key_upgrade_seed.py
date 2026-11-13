"""Boundary controls; run with python -I, no SDK install or database needed."""
import base64
from contextlib import contextmanager
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).with_name("command_key_upgrade_seed.py")
SPEC = importlib.util.spec_from_file_location("command_key_seed", SCRIPT)
seed = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(seed)


@contextmanager
def server(redirect=False):
    class Handler(BaseHTTPRequestHandler):
        calls = []

        def do_GET(self):
            self.calls.append(self.path)
            self.send_response(302 if redirect else 200)
            if redirect:
                self.send_header("Location", "http://127.0.0.1:1/outside")
            self.end_headers()
            self.wfile.write(json.dumps({"ok": True}).encode())

        def log_message(self, *args):
            pass

    with HTTPServer(("127.0.0.1", 0), Handler) as http:
        thread = threading.Thread(target=http.serve_forever, daemon=True)
        thread.start()
        try:
            yield f"http://127.0.0.1:{http.server_port}", Handler.calls
        finally:
            http.shutdown()
            thread.join(timeout=2)


class OriginTests(unittest.TestCase):
    def test_only_canonical_numeric_loopback_origin_is_accepted(self):
        for port in [1, 1234, 65535]:
            self.assertEqual(seed.SeedClient(f"http://127.0.0.1:{port}").url, f"http://127.0.0.1:{port}")
        for value in ["http://user:pass@127.0.0.1:1234", "http://127.0.0.1:1234?next=http://example.invalid",
                      "http://127.0.0.1:1234#fragment", "http://127.0.0.1:01234", "http://127.0.0.1:0",
                      "http://127.0.0.1:65536", "http://127.0.0.1:1234/", "http://127.0.0.1:1234/api",
                      "http://127.0.0.1", "http://localhost:1234", "https://127.0.0.1:1234",
                      "http://example.invalid:1234", " HTTP://127.0.0.1:1234", "http://127.0.0.1:1234\n"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                seed.SeedClient(value)

    def test_hostile_proxy_environment_does_not_route_the_request(self):
        proxy = "http://127.0.0.1:1"
        with server() as (origin, calls), patch.dict(os.environ, {
            "http_proxy": proxy, "HTTP_PROXY": proxy, "HTTPS_PROXY": proxy,
            "ALL_PROXY": proxy, "no_proxy": "", "NO_PROXY": ""}):
            self.assertEqual(seed.SeedClient(origin).request("GET", "/api/cluster/info"), {"ok": True})
            self.assertEqual(calls, ["/api/cluster/info"])

    def test_redirect_is_refused_without_contacting_its_target(self):
        with server(redirect=True) as (origin, calls):
            with self.assertRaisesRegex(ValueError, "got 302"):
                seed.SeedClient(origin).request("GET", "/api/cluster/info")
            self.assertEqual(calls, ["/api/cluster/info"])


class CodecTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        package = self.root / "installed/durable_workflow"
        package.mkdir(parents=True)
        (package / "__init__.py").write_text("")
        (package / "serializer.py").write_text("fixture_identity = 'recorded'\n"
                                               "def envelope(value, **kwargs): return {'value': value}\n"
                                               "def decode_envelope(value, **kwargs): return value['value']\n")
        files = []
        for relative in ["durable_workflow/__init__.py", "durable_workflow/serializer.py"]:
            entry = importlib.metadata.PackagePath(relative)
            digest = hashlib.sha256((package.parent / relative).read_bytes()).digest()
            entry.hash = importlib.metadata.FileHash("sha256=" + base64.urlsafe_b64encode(digest).decode().rstrip("="))
            files.append(entry)
        self.distribution = SimpleNamespace(version=seed.CODEC_VERSION, files=files,
                                            locate_file=lambda entry: package.parent / str(entry))
        self.package = package
        self.addCleanup(patch.stopall)
        patch.object(seed.importlib.metadata, "distribution", return_value=self.distribution).start()
        patch.object(sys, "path", [str(package.parent)] + sys.path).start()
        patch.dict(sys.modules).start()
        sys.modules.pop("durable_workflow", None)
        sys.modules.pop("durable_workflow.serializer", None)
        importlib.invalidate_caches()

    def test_recorded_installed_module_and_hash_are_bound(self):
        serializer, identity = seed.installed_codec()
        self.assertEqual(serializer.fixture_identity, "recorded")
        self.assertEqual(identity["version"], seed.CODEC_VERSION)
        self.assertEqual(identity["serializer_sha256"], hashlib.sha256((self.package / "serializer.py").read_bytes()).hexdigest())

    def test_mismatched_distribution_version_refuses_before_import(self):
        self.distribution.version = "0.0.0"
        with self.assertRaisesRegex(ValueError, "must be installed"):
            seed.installed_codec()
        self.assertNotIn("durable_workflow", sys.modules)

    def test_modified_serializer_bytes_refuse_before_import(self):
        (self.package / "serializer.py").write_text("raise RuntimeError('must not execute')\n")
        with self.assertRaisesRegex(ValueError, "RECORD"):
            seed.installed_codec()
        self.assertNotIn("durable_workflow", sys.modules)

    def test_cwd_or_pythonpath_package_shadow_never_executes(self):
        shadow = self.root / "shadow/durable_workflow"
        shadow.mkdir(parents=True)
        marker = self.root / "executed"
        (shadow / "__init__.py").write_text(f"from pathlib import Path; Path({str(marker)!r}).touch()\n")
        sys.path.insert(0, str(shadow.parent))
        with self.assertRaisesRegex(ValueError, "shadowed"):
            seed.installed_codec()
        self.assertFalse(marker.exists())

    def test_preloaded_serializer_from_another_origin_is_refused(self):
        sys.modules["durable_workflow.serializer"] = SimpleNamespace(__file__=str(self.root / "shadow.py"))
        with self.assertRaisesRegex(ValueError, "preloaded"):
            seed.installed_codec()

    def test_nonisolated_process_refuses_ambient_pythonpath(self):
        code = f"import importlib.util; s=importlib.util.spec_from_file_location('seed',{str(SCRIPT)!r}); m=importlib.util.module_from_spec(s); s.loader.exec_module(m); m.installed_codec()"
        result = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("python -I", result.stderr)

    def test_seed_rejects_wrong_codec_version_before_any_http(self):
        self.distribution.version = "0.0.0"
        client = SimpleNamespace(request=unittest.mock.Mock(return_value={"version": "3.8.0",
                                  "worker_protocol": {"version": "1.20"}}))
        manifest = {"physical_upgrade": {"baseline": {"tag": "3.8.0"}},
                    "legacy_readback": {"original_input": 1, "types": []}}
        with self.assertRaisesRegex(ValueError, "must be installed"):
            seed.seed(client, "fixture-", manifest)
        client.request.assert_not_called()

    def test_seed_rejects_executable_shadow_before_import_or_http(self):
        shadow = self.root / "shadow/durable_workflow"
        shadow.mkdir(parents=True)
        marker = self.root / "executed"
        (shadow / "__init__.py").write_text(f"from pathlib import Path; Path({str(marker)!r}).touch()\n")
        (shadow / "serializer.py").write_text((self.package / "serializer.py").read_text())
        sys.path.insert(0, str(shadow.parent))
        client = SimpleNamespace(request=unittest.mock.Mock(return_value={"version": "3.8.0",
                                  "worker_protocol": {"version": "1.20"}}))
        manifest = {"physical_upgrade": {"baseline": {"tag": "3.8.0"}},
                    "legacy_readback": {"original_input": 1, "types": []}}
        with self.assertRaisesRegex(ValueError, "shadowed"):
            seed.seed(client, "fixture-", manifest)
        self.assertFalse(marker.exists())
        client.request.assert_not_called()


class ProducerTests(unittest.TestCase):
    def test_nonbaseline_cluster_refuses_before_admission(self):
        client = SimpleNamespace(request=unittest.mock.Mock(return_value={"version": "3.9.1"}))
        with patch.object(seed, "installed_codec", return_value=(None, {})), self.assertRaisesRegex(ValueError, "baseline"):
            seed.seed(client, "fixture-", {"physical_upgrade": {"baseline": {"tag": "3.8.0"}}})
        client.request.assert_called_once_with("GET", "/api/cluster/info")

    def test_receipt_labels_expected_external_and_observed_cluster_separately(self):
        expected = {"tag": "3.8.0", "source_commit": "a" * 40, "index_digest": "sha256:" + "b" * 64}
        manifest = {"physical_upgrade": {"baseline": expected}, "legacy_readback": {"original_input": 1, "types": []}}
        codec = SimpleNamespace(envelope=lambda value, **kwargs: {"value": value},
                                decode_envelope=lambda envelope, **kwargs: envelope["value"])
        client = SimpleNamespace(request=unittest.mock.Mock(return_value={"version": "3.8.0", "worker_protocol": {"version": "1.20"}}))
        with patch.object(seed, "installed_codec", return_value=(codec, {"version": seed.CODEC_VERSION})):
            receipt = seed.seed(client, "fixture-", manifest)
        self.assertEqual(receipt["expected_external_baseline"], expected)
        self.assertEqual(receipt["observed_cluster"], {"version": "3.8.0", "worker_protocol": "1.20"})
        self.assertNotIn("baseline", receipt)
        self.assertIn("does not observe", receipt["external_identity_scope"])
        client.request.assert_called_once_with("GET", "/api/cluster/info")


if __name__ == "__main__":
    unittest.main()
