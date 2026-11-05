#!/usr/bin/env python3
"""Synthetic SPDX shape/predicate controls, without a scan or provider call."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("native_sbom_policy", ROOT / "scripts/ci/native_sbom_policy.py")
P = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(P)


def fixture():
    return {
        "spdxVersion": "SPDX-2.3", "SPDXID": "SPDXRef-DOCUMENT", "dataLicense": "CC0-1.0",
        "name": "synthetic-image", "documentNamespace": "https://anchore.com/syft/image/synthetic-1",
        "creationInfo": {"created": "2026-11-05T00:00:00Z", "creators": ["Organization: Anchore, Inc", "Tool: syft-1.55.0"]},
        "packages": [{"SPDXID": "SPDXRef-Package", "name": "synthetic-native", "versionInfo": "3.2.0",
                      "downloadLocation": "NOASSERTION", "filesAnalyzed": True,
                      "checksums": [{"algorithm": "SHA256", "checksumValue": "a" * 64}]}],
        "files": [{"SPDXID": "SPDXRef-File", "fileName": "./server", "checksums": [{"algorithm": "SHA256", "checksumValue": "b" * 64}]}],
        "relationships": [{"spdxElementId": "SPDXRef-DOCUMENT", "relationshipType": "DESCRIBES", "relatedSpdxElement": "SPDXRef-Package"},
                          {"spdxElementId": "SPDXRef-Package", "relationshipType": "CONTAINS", "relatedSpdxElement": "SPDXRef-File"}],
    }


def encoded(value):
    return (json.dumps(value, indent=2) + "\n").encode()


class SbomPolicyTests(unittest.TestCase):
    def test_shape_and_semantics_retain_raw_bytes_identity(self):
        raw = encoded(fixture())
        result = P.document(raw)
        self.assertEqual(result["raw_bytes"], len(raw))
        self.assertEqual(result["package_count"], 1)
        self.assertEqual(result["file_count"], 1)
        self.assertEqual(result["relationship_count"], 2)
        compact = json.dumps(fixture(), separators=(",", ":")).encode()
        other = P.predicate_matches(raw, compact)
        self.assertEqual(result["canonical_sha256"], other["canonical_sha256"])
        self.assertNotEqual(result["raw_sha256"], P.document(compact)["raw_sha256"])

    def test_malformed_duplicate_oversized_and_noninteger_json_refuse(self):
        for raw in (b"{}", b'{"x":1,"x":2}', b'{"x":NaN}', b'{"x":1.0}',
                    b'{"x":"\\ud800"}', b"[", b"x" * (P.RAW_LIMIT + 1)):
            with self.subTest(raw=raw[:50]), self.assertRaises(P.Refusal):
                P.document(raw)

    def test_spdx_version_creator_namespace_and_schema_refusals(self):
        changes = [lambda v: v.update(spdxVersion="SPDX-3.0"),
                   lambda v: v.update(documentNamespace="relative/path"),
                   lambda v: v.update(documentNamespace="https://user:secret@example.invalid/doc"),
                   lambda v: v.update(documentNamespace="https://example.invalid/doc#fragment"),
                   lambda v: v["creationInfo"].update(creators=["Tool: syft-1.54.0"]),
                   lambda v: v["creationInfo"]["creators"].append("Tool: syft-1.54.0"),
                   lambda v: v["creationInfo"].update(created="not-a-date"),
                   lambda v: v.update(packages=[]), lambda v: v.update(relationships=[])]
        for change in changes:
            value = fixture()
            change(value)
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.document(encoded(value))

    def test_duplicate_unknown_and_unlinked_relationships_refuse(self):
        changes = [lambda v: v["packages"].append(copy.deepcopy(v["packages"][0])),
                   lambda v: v["relationships"][1].update(relatedSpdxElement="SPDXRef-Unknown"),
                   lambda v: v["relationships"][1].update(relatedSpdxElement="DocumentRef-External:SPDXRef-File"),
                   lambda v: v["relationships"].append(copy.deepcopy(v["relationships"][0])),
                   lambda v: v["relationships"].pop(),
                   lambda v: v["relationships"].pop(0)]
        for change in changes:
            value = fixture()
            change(value)
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.document(encoded(value))

    def test_file_coverage_is_explicit_and_pair_namespaces_are_unique(self):
        value = fixture()
        value["files"] = []
        value["relationships"].pop()
        with self.assertRaises(P.Refusal):
            P.document(encoded(value))
        value["packages"][0]["filesAnalyzed"] = False
        self.assertEqual(P.document(encoded(value))["file_count"], 0)
        with self.assertRaises(P.Refusal):
            P.distinct_platform_documents(encoded(fixture()), encoded(fixture()))
        arm = fixture()
        arm["documentNamespace"] += "-arm64"
        self.assertEqual(len(P.distinct_platform_documents(encoded(fixture()), encoded(arm))), 2)

    def test_signed_predicate_cannot_authorize_altered_package_bytes(self):
        signed = fixture()
        signed["packages"][0]["versionInfo"] = "different"
        with self.assertRaisesRegex(P.Refusal, "spdx_signed_predicate_mismatch"):
            P.predicate_matches(encoded(fixture()), encoded(signed))

    def test_instance_schema_url_never_selects_validator_or_fetches(self):
        value = fixture()
        value["$schema"] = "https://untrusted.invalid/never-fetch"
        with patch("urllib.request.urlopen", side_effect=AssertionError("remote retrieval")):
            self.assertEqual(P.document(encoded(value))["file_count"], 1)

    def test_source_library_cannot_succeed_as_an_executable_gate(self):
        result = subprocess.run([sys.executable, str(ROOT / "scripts/ci/native_sbom_policy.py")],
                                capture_output=True, check=False, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b"")


if __name__ == "__main__":
    unittest.main()
