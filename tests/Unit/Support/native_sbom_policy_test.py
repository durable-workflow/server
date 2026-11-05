#!/usr/bin/env python3
"""Synthetic SPDX shape/predicate controls, without a scan or provider call."""
import copy
import hashlib
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
    def child_fixture(self, architecture="amd64", media_index=0):
        source, tag = "c" * 40, "3.2.0-rc.1"
        config = {"os": "linux", "architecture": architecture, "config": {"Labels": {
            "org.opencontainers.image.revision": source, "dev.durable-workflow.release.tag": tag}}}
        raw = encoded(config)
        digest = "sha256:" + hashlib.sha256(raw).hexdigest()
        manifest = {"schemaVersion": 2, "mediaType": P.MANIFEST_MEDIA_TYPES[media_index],
                    "config": {"mediaType": P.CONFIG_MEDIA_TYPES[media_index], "digest": digest, "size": len(raw)},
                    "layers": []}
        return manifest, config, source, tag

    def bind_child(self, manifest, config, platform, source, tag):
        config_raw = encoded(config)
        config_digest = "sha256:" + hashlib.sha256(config_raw).hexdigest()
        manifest = copy.deepcopy(manifest)
        manifest["config"].update(digest=config_digest, size=len(config_raw))
        manifest_raw = encoded(manifest)
        child_digest = "sha256:" + hashlib.sha256(manifest_raw).hexdigest()
        return P.child_identity(manifest_raw, child_digest, config_raw, config_digest, platform, source, tag)

    def test_child_bytes_bind_actual_config_platform_and_release_labels(self):
        for architecture in ("amd64", "arm64"):
            for media_index in (0, 1):
                manifest, config, source, tag = self.child_fixture(architecture, media_index)
                if architecture == "arm64":
                    config["variant"] = "v8"
                result = self.bind_child(manifest, config, "linux/" + architecture, source, tag)
                self.assertEqual(result["platform"], "linux/" + architecture)
                self.assertEqual(result["source_commit"], source)
                self.assertEqual(result["tag"], tag)
                self.assertNotEqual(result["child_manifest_digest"], result["image_config_digest"])

    def test_hash_valid_foreign_platform_source_and_tag_configs_refuse(self):
        changes = [lambda v: v.update(os="windows"), lambda v: v.update(architecture="arm64"),
                   lambda v: v.update(variant="v3"),
                   lambda v: v["config"]["Labels"].update({"org.opencontainers.image.revision": "d" * 40}),
                   lambda v: v["config"]["Labels"].update({"dev.durable-workflow.release.tag": "3.2.1"}),
                   lambda v: v["config"].update(Labels={})]
        for change in changes:
            manifest, config, source, tag = self.child_fixture()
            change(config)
            # Recompute both content addresses: wrong identities must refuse
            # even when the registry bytes are correctly named by their hashes.
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                self.bind_child(manifest, config, "linux/amd64", source, tag)

    def test_child_bytes_and_config_descriptor_cannot_be_substituted(self):
        manifest, config, source, tag = self.child_fixture()
        config_raw = encoded(config)
        config_digest = "sha256:" + hashlib.sha256(config_raw).hexdigest()
        manifest_raw = encoded(manifest)
        digest = "sha256:" + hashlib.sha256(manifest_raw).hexdigest()
        for child_raw, image_raw in ((manifest_raw + b"\n", config_raw), (manifest_raw, config_raw + b"\n")):
            with self.assertRaisesRegex(P.Refusal, "content_bytes"):
                P.child_identity(child_raw, digest, image_raw, config_digest, "linux/amd64", source, tag)
        for change in (lambda v: v.update(mediaType=P.INDEX_MEDIA_TYPES[0]),
                       lambda v: v.update(schemaVersion=True),
                       lambda v: v["config"].update(mediaType=P.CONFIG_MEDIA_TYPES[1]),
                       lambda v: v["config"].update(size=True),
                       lambda v: v["config"].update(size=len(config_raw) + 1),
                       lambda v: v["config"].update(digest="sha256:" + "e" * 64)):
            changed = copy.deepcopy(manifest)
            change(changed)
            raw = encoded(changed)
            child = "sha256:" + hashlib.sha256(raw).hexdigest()
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.child_identity(raw, child, config_raw, config_digest, "linux/amd64", source, tag)

    def index_fixture(self):
        expected = {"linux/amd64": "sha256:" + "a" * 64, "linux/arm64": "sha256:" + "b" * 64}
        members = [{"mediaType": P.MANIFEST_MEDIA_TYPES[0], "digest": digest, "size": 123,
                    "platform": {"os": "linux", "architecture": platform.split("/")[1]}}
                   for platform, digest in expected.items()]
        return {"schemaVersion": 2, "mediaType": P.INDEX_MEDIA_TYPES[0], "manifests": members}, expected

    def test_index_children_match_exact_platforms_independently_of_order(self):
        value, expected = self.index_fixture()
        value["manifests"][1]["platform"]["variant"] = "v8"
        for media in P.INDEX_MEDIA_TYPES:
            value["mediaType"] = media
            value["manifests"].reverse()
            raw = encoded(value)
            digest = "sha256:" + hashlib.sha256(raw).hexdigest()
            self.assertEqual(P.index_children(raw, digest, expected), expected)
            altered = raw + b"\n"
            with self.assertRaisesRegex(P.Refusal, "index_bytes"):
                P.index_children(altered, digest, expected)

    def test_index_refuses_extra_missing_duplicate_unknown_and_nested_members(self):
        changes = [lambda v: v["manifests"].pop(),
                   lambda v: v["manifests"].append(copy.deepcopy(v["manifests"][0])),
                   lambda v: v["manifests"].__setitem__(1, copy.deepcopy(v["manifests"][0])),
                   lambda v: v["manifests"][0]["platform"].update(os="unknown", architecture="unknown"),
                   lambda v: v["manifests"][0]["platform"].update(variant="v3"),
                   lambda v: v["manifests"][0].update(digest="sha256:" + "c" * 64),
                   lambda v: v["manifests"][0].update(mediaType=P.INDEX_MEDIA_TYPES[0]),
                   lambda v: v["manifests"][0].update(size=True),
                   lambda v: v.update(schemaVersion=True)]
        for change in changes:
            value, expected = self.index_fixture()
            change(value)
            raw = encoded(value)
            digest = "sha256:" + hashlib.sha256(raw).hexdigest()
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.index_children(raw, digest, expected)

    def test_index_refuses_an_incomplete_or_self_referential_expected_tuple(self):
        value, original = self.index_fixture()
        raw = encoded(value)
        digest = "sha256:" + hashlib.sha256(raw).hexdigest()
        for expected in ({}, {"linux/amd64": original["linux/amd64"]},
                         {**original, "linux/unknown": "sha256:" + "c" * 64},
                         {**original, "linux/amd64": digest},
                         {**original, "linux/amd64": original["linux/arm64"]}):
            with self.subTest(expected=expected), self.assertRaisesRegex(P.Refusal, "expected_children"):
                P.index_children(raw, digest, expected)

    def test_build_provenance_cannot_substitute_for_an_spdx_predicate(self):
        provenance = encoded({"buildDefinition": {"buildType": "synthetic-provenance"}, "runDetails": {}})
        raw = encoded(fixture())
        with self.assertRaisesRegex(P.Refusal, "spdx_schema_shape"):
            P.predicate_matches(raw, provenance)

    def test_verification_request_uses_spdx_child_and_fixed_release_identity(self):
        child, index, source = "sha256:" + "a" * 64, "sha256:" + "b" * 64, "c" * 40
        for registry in P.REGISTRIES:
            command = P.sbom_verification_command(registry, child, index, "3.2.0-rc.1", source)
            self.assertEqual(command[:4], ["gh", "attestation", "verify", "oci://" + registry + "@" + child])
            self.assertEqual(command[4:], ["--repo", P.REPOSITORY, "--signer-workflow",
                "github.com/durable-workflow/server/.github/workflows/native-release.yml",
                "--source-ref", "refs/tags/3.2.0-rc.1", "--source-digest", source,
                "--predicate-type", "https://spdx.dev/Document/v2.3", "--deny-self-hosted-runners", "--format", "json"])
            self.assertNotIn(index, command)

    def test_verification_request_refuses_redirects_indexes_and_cli_injection(self):
        original = [P.REGISTRIES[0], "sha256:" + "a" * 64, "sha256:" + "b" * 64, "3.2.0", "c" * 40]
        for position, replacement in ((0, "foreign.invalid/server"), (0, "--repo=foreign"),
                                      (1, original[2]), (1, "--format=env"), (2, "not-a-digest"),
                                      (3, "refs/heads/main"), (3, "3.2.0\n--repo=foreign"),
                                      (3, "3.2.\u0661"),
                                      (4, "c" * 39), (4, "--source-digest=foreign")):
            args = list(original)
            args[position] = replacement
            with self.subTest(position=position, replacement=replacement), self.assertRaises(P.Refusal):
                P.sbom_verification_command(*args)

    def test_verified_invocation_requires_exact_repo_run_and_attempt_without_prefixes(self):
        uri = "https://github.com/durable-workflow/server/actions/runs/123/attempts/2"
        P.require_release_invocation(uri, 123, 2)
        for changed in (uri.replace("123", "1234"), uri.replace("attempts/2", "attempts/3"),
                        uri.replace("durable-workflow/server", "foreign/server"), uri + "/", uri + "?suffix=1"):
            with self.subTest(changed=changed), self.assertRaisesRegex(P.Refusal, "invocation"):
                P.require_release_invocation(changed, 123, 2)
        for run, attempt in ((True, 2), (123, False), (0, 2), (123, 0), (2 ** 53, 2)):
            with self.subTest(run=run, attempt=attempt), self.assertRaisesRegex(P.Refusal, "release_run"):
                P.require_release_invocation(uri, run, attempt)

    def test_existing_release_requires_all_three_exact_raw_assets(self):
        arm = fixture()
        arm["documentNamespace"] += "-arm64"
        expected = {P.SPDX_ASSETS[0]: encoded(fixture()), P.SPDX_ASSETS[1]: encoded(arm),
                    P.MANIFEST_ASSET: b'{"synthetic":"previously-verified-map"}\n'}
        observed = dict(expected)
        result = P.exact_release_asset_bytes(expected, observed)
        self.assertEqual(set(result), set(expected))
        for name in expected:
            self.assertEqual(result[name]["bytes"], len(expected[name]))
        for name in expected:
            for changed in (expected[name] + b"\n", expected[name][:-1], b"different"):
                altered = {**observed, name: changed}
                with self.subTest(name=name, changed=changed[:20]), self.assertRaisesRegex(P.Refusal, "bytes_changed"):
                    P.exact_release_asset_bytes(expected, altered)
        # Predicates may be canonically equal; raw Release assets must still
        # have exactly the retained generation bytes, without reformatting.
        compact = json.dumps(fixture(), separators=(",", ":")).encode()
        self.assertEqual(P.predicate_matches(expected[P.SPDX_ASSETS[0]], compact)["package_count"], 1)
        altered = {**observed, P.SPDX_ASSETS[0]: compact}
        with self.assertRaisesRegex(P.Refusal, "bytes_changed"):
            P.exact_release_asset_bytes(expected, altered)

    def test_existing_release_refuses_absent_extra_renamed_or_unbounded_assets(self):
        expected = dict.fromkeys(P.ASSET_LIMITS, b"synthetic-preverified-bytes")
        for name in expected:
            missing = {key: value for key, value in expected.items() if key != name}
            renamed = {**missing, name.upper(): expected[name]}
            for observed in (missing, renamed, {**expected, "unexpected.json": b"extra"}):
                with self.subTest(name=name), self.assertRaisesRegex(P.Refusal, "asset_names"):
                    P.exact_release_asset_bytes(expected, observed)
            for value in (b"", "not bytes", b"x" * (P.ASSET_LIMITS[name] + 1)):
                altered = {**expected, name: value}
                with self.subTest(name=name, size=len(value)), self.assertRaisesRegex(P.Refusal, "asset_size"):
                    P.exact_release_asset_bytes(expected, altered)

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
