"""Artifact identity checks at the matched benchmark report entry point."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SUMMARIZER = ROOT / "scripts/perf/summarize-server-port-matched.py"


class MatchedPortIdentityTest(unittest.TestCase):
    def setUp(self):
        root = tempfile.TemporaryDirectory()
        self.addCleanup(root.cleanup)
        self.root = Path(root.name)

    def arm(self, name, implementation, **identity):
        directory = self.root / name
        directory.mkdir()
        (directory / "identity.json").write_text(
            json.dumps({"implementation": implementation, **identity})
        )
        (directory / "windows.tsv").write_text("1.0\toffer\tstart\n2.0\toffer\tend\n")
        return directory

    def compare(self, *directories):
        return subprocess.run(
            [sys.executable, "-I", str(SUMMARIZER), *directories],
            capture_output=True,
            text=True,
            check=False,
        )

    def test_same_binary_with_different_source_revisions_is_refused(self):
        digest = "a" * 64
        first = self.arm("first", "native", native_binary_sha256=digest, source_revision="b" * 40)
        second = self.arm("second", "native", native_binary_sha256=digest, source_revision="c" * 40)
        result = self.compare(first, second)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("use the same Server artifact", result.stderr)
        self.assertFalse((first / "summary.json").exists())
        self.assertFalse((second / "summary.json").exists())

    def test_different_manifest_references_to_the_same_classic_image_id_are_refused(self):
        image = "sha256:" + "a" * 64
        first = self.arm("first", "native-image", native_image="a@sha256:" + "b" * 64, server_image_id=image)
        second = self.arm("second", "native-image", native_image="b@sha256:" + "c" * 64, server_image_id=image)
        result = self.compare(first, second)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(image, result.stderr)

    def test_distinct_image_indexes_with_the_same_platform_manifest_are_refused(self):
        first_index = "sha256:" + "a" * 64
        second_index = "sha256:" + "b" * 64
        platform = "sha256:" + "d" * 64
        first = self.arm("first", "native-image", native_image="a@" + first_index,
                         server_image_id=first_index, server_platform_manifest=platform)
        second = self.arm("second", "native-image", native_image="b@" + second_index,
                          server_image_id=second_index, server_platform_manifest=platform)
        result = self.compare(first, second)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(platform, result.stderr)

    def test_shared_wrapper_image_accepts_distinct_mounted_binaries(self):
        image = "sha256:" + "a" * 64
        first = self.arm("first", "native", native_binary_sha256="b" * 64, server_image_id=image)
        second = self.arm("second", "native", native_binary_sha256="c" * 64, server_image_id=image)
        result = self.compare(first, second)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((first / "summary.json").is_file())
        self.assertTrue((second / "summary.json").is_file())

    def test_matching_legacy_manifest_digests_are_refused(self):
        manifest = "@sha256:" + "a" * 64
        first = self.arm("first", "php", php_server="docker.io/server" + manifest)
        second = self.arm("second", "php", php_server="ghcr.io/server" + manifest)
        result = self.compare(first, second)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("use the same Server artifact", result.stderr)

    def test_unidentified_legacy_run_can_be_summarized_individually(self):
        legacy = self.arm("legacy", "php")
        current = self.arm("current", "native", native_binary_sha256="b" * 64)
        summary = self.compare(legacy)
        self.assertEqual(summary.returncode, 0, summary.stderr)
        comparison = self.compare(legacy, current)
        self.assertNotEqual(comparison.returncode, 0)
        self.assertIn("lacks a verified Server image or binary digest", comparison.stderr)
