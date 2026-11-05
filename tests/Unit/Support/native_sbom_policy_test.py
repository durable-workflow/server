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
    def test_ci_source_tooling_matches_the_hash_closed_dependency_inventory(self):
        pins = ROOT / "scripts/ci/native-sbom"
        metadata_raw = (pins / "validator-pins.v1.json").read_bytes()
        tools = json.loads((pins / "tool-pins.v1.json").read_bytes())
        self.assertEqual(hashlib.sha256(metadata_raw).hexdigest(),
                         tools["admission"]["schema_validator_dependency_closure"]["sha256"])
        packages = json.loads(metadata_raw)["packages"]
        requirements = (pins / "source-test.requirements.txt").read_text().splitlines()
        entries = [line for line in requirements if line and not line.startswith("#")]
        expected = []
        for _, package in sorted(packages.items()):
            wheel = package["wheels"].get("any") or package["wheels"]["linux/amd64"]
            expected.append(wheel["url"] + " --hash=sha256:" + wheel["sha256"])
        self.assertEqual(len(entries), 5)
        self.assertEqual(entries, expected)

    def certificate_fixture(self):
        source, tag = "c" * 40, "3.2.0-rc.1"
        return {"issuer": "https://token.actions.githubusercontent.com",
            "sourceRepositoryURI": "https://github.com/durable-workflow/server",
            "sourceRepositoryIdentifier": "1208272134",
            "sourceRepositoryOwnerURI": "https://github.com/durable-workflow",
            "sourceRepositoryOwnerIdentifier": "100327932",
            "sourceRepositoryDigest": source, "sourceRepositoryRef": "refs/tags/" + tag,
            "buildSignerURI": "https://github.com/durable-workflow/server/.github/workflows/native-release.yml@refs/tags/" + tag,
            "buildSignerDigest": source, "runnerEnvironment": "github-hosted", "buildTrigger": "workflow_dispatch",
            "runInvocationURI": "https://github.com/durable-workflow/server/actions/runs/123/attempts/2"}, source, tag

    def test_authenticated_certificate_claims_bind_immutable_ids_and_exact_release(self):
        certificate, source, tag = self.certificate_fixture()
        P.require_certificate_identity(certificate, source, tag, 123, 2)
        for key in certificate:
            for value in (None, True, 123, certificate[key] + "/foreign"):
                changed = {**certificate, key: value}
                with self.subTest(key=key, value=value), self.assertRaises(P.Refusal):
                    P.require_certificate_identity(changed, source, tag, 123, 2)
            missing = {k: v for k, v in certificate.items() if k != key}
            with self.subTest(missing=key), self.assertRaises(P.Refusal):
                P.require_certificate_identity(missing, source, tag, 123, 2)

    def test_certificate_repo_names_do_not_substitute_for_ids_or_attempt(self):
        certificate, source, tag = self.certificate_fixture()
        for changed in ({**certificate, "sourceRepositoryIdentifier": "1208272135"},
                        {**certificate, "sourceRepositoryOwnerIdentifier": "100327933"},
                        {**certificate, "sourceRepositoryDigest": "d" * 40},
                        {**certificate, "runnerEnvironment": "self-hosted"}):
            with self.assertRaisesRegex(P.Refusal, "certificate_identity"):
                P.require_certificate_identity(changed, source, tag, 123, 2)
        with self.assertRaisesRegex(P.Refusal, "release_invocation"):
            P.require_certificate_identity(certificate, source, tag, 123, 3)

    def statement_fixture(self, registry):
        child, index = "sha256:" + "a" * 64, "sha256:" + "b" * 64
        certificate, source, tag = self.certificate_fixture()
        statement = {"_type": "https://in-toto.io/Statement/v1", "predicateType": P.PREDICATE_TYPE,
            "subject": [{"name": registry, "digest": {"sha256": "a" * 64}}], "predicate": fixture()}
        return statement, certificate, child, index, source, tag

    def test_verified_statement_binds_child_registry_and_retained_predicate(self):
        for registry in P.REGISTRIES:
            statement, certificate, child, index, source, tag = self.statement_fixture(registry)
            raw = encoded(fixture())
            observed = P.verified_statement_matches(raw, statement, certificate, registry, child, index, source, tag, 123, 2)
            self.assertEqual(observed["raw_sha256"], hashlib.sha256(raw).hexdigest())
            self.assertEqual(observed["raw_bytes"], len(raw))

    def test_provenance_indexes_foreign_subjects_and_changed_signed_predicates_refuse(self):
        registry = P.REGISTRIES[0]
        statement, certificate, child, index, source, tag = self.statement_fixture(registry)
        changes = [lambda v: v.update(_type="https://in-toto.io/Statement/v0.1"),
                   lambda v: v.update(predicateType="https://slsa.dev/provenance/v1"),
                   lambda v: v.update(subject=[]),
                   lambda v: v["subject"].append(copy.deepcopy(v["subject"][0])),
                   lambda v: v["subject"][0].update(name=P.REGISTRIES[1]),
                   lambda v: v["subject"][0].update(digest={"sha256": index.removeprefix("sha256:")}),
                   lambda v: v["subject"][0].update(digest={"sha256": "a" * 64, "sha512": "c" * 128}),
                   lambda v: v["predicate"].update(documentNamespace="https://example.invalid/foreign"),
                   lambda v: v["predicate"].update(name="different-image")]
        for change in changes:
            changed = copy.deepcopy(statement)
            change(changed)
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.verified_statement_matches(encoded(fixture()), changed, certificate, registry, child, index, source, tag, 123, 2)

    def release_manifest_fixture(self):
        release = {"tag": "3.2.0-rc.1", "source_commit": "c" * 40, "index_digest": "sha256:" + "d" * 64,
                   "run_id": 123, "run_attempt": 2}
        subjects = {platform: {"child_manifest_digest": "sha256:" + child * 64,
                              "image_config_digest": "sha256:" + config * 64}
                    for platform, child, config in (("linux/amd64", "a", "1"), ("linux/arm64", "b", "2"))}
        arm = fixture()
        arm["documentNamespace"] += "-arm64"
        return release, subjects, {"linux/amd64": encoded(fixture()), "linux/arm64": encoded(arm)}

    def test_checksum_map_binds_release_platform_subjects_and_raw_asset_bytes(self):
        release, subjects, documents = self.release_manifest_fixture()
        raw = P.release_asset_manifest(release, subjects, documents)
        value = json.loads(raw)
        self.assertEqual(value["release"], release)
        self.assertEqual(value["registries"], list(P.REGISTRIES))
        self.assertEqual([v["platform"] for v in value["documents"]], list(P.PLATFORMS))
        for item, name in zip(value["documents"], P.SPDX_ASSETS):
            platform = item["platform"]
            self.assertEqual(item["asset"], name)
            self.assertEqual(item["bytes"], len(documents[platform]))
            self.assertEqual(item["sha256"], hashlib.sha256(documents[platform]).hexdigest())
            self.assertEqual(item["child_manifest_digest"], subjects[platform]["child_manifest_digest"])
            self.assertEqual(item["image_config_digest"], subjects[platform]["image_config_digest"])
        changed = {**documents, "linux/amd64": json.dumps(fixture(), separators=(",", ":")).encode()}
        self.assertNotEqual(raw, P.release_asset_manifest(release, subjects, changed))
        self.assertEqual(raw, P.release_asset_manifest(dict(reversed(list(release.items()))),
            dict(reversed(list(subjects.items()))), dict(reversed(list(documents.items())))))

    def test_checksum_map_refuses_incomplete_ambiguous_and_wrong_release_tuples(self):
        release, subjects, documents = self.release_manifest_fixture()
        mutations = [lambda r, s, d: r.update(run_id=True), lambda r, s, d: r.update(run_attempt=0),
                     lambda r, s, d: r.update(extra="unreviewed"),
                     lambda r, s, d: s.pop("linux/arm64"), lambda r, s, d: d.pop("linux/arm64"),
                     lambda r, s, d: s.__setitem__("linux/arm64", copy.deepcopy(s["linux/amd64"])),
                     lambda r, s, d: s["linux/amd64"].update(child_manifest_digest=r["index_digest"]),
                     lambda r, s, d: s["linux/amd64"].update(image_config_digest="wrong-config"),
                     lambda r, s, d: s["linux/amd64"].update(image_config_digest=s["linux/arm64"]["child_manifest_digest"]),
                     lambda r, s, d: d.__setitem__("linux/arm64", d["linux/amd64"])]
        for mutate in mutations:
            args = copy.deepcopy((release, subjects, documents))
            mutate(*args)
            with self.subTest(mutate=mutate), self.assertRaises(P.Refusal):
                P.release_asset_manifest(*args)

    def verified_asset_fixture(self):
        release, subjects, documents = self.release_manifest_fixture()
        certificate, _, _ = self.certificate_fixture()
        pairs = {}
        for registry in P.REGISTRIES:
            pairs[registry] = {}
            for platform in P.PLATFORMS:
                statement = {"_type": "https://in-toto.io/Statement/v1", "predicateType": P.PREDICATE_TYPE,
                    "subject": [{"name": registry, "digest": {"sha256": subjects[platform]["child_manifest_digest"][7:]}}],
                    "predicate": json.loads(documents[platform])}
                pairs[registry][platform] = [{"certificate": copy.deepcopy(certificate), "statement": statement}]
        return release, subjects, documents, pairs

    def test_complete_current_registry_predicates_return_only_the_three_retained_assets(self):
        release, subjects, documents, pairs = self.verified_asset_fixture()
        assets = P.verified_release_assets(release, subjects, documents, pairs)
        self.assertEqual(set(assets), set(P.ASSET_LIMITS))
        self.assertEqual(assets[P.SPDX_ASSETS[0]], documents["linux/amd64"])
        self.assertEqual(assets[P.SPDX_ASSETS[1]], documents["linux/arm64"])
        self.assertEqual(json.loads(assets[P.MANIFEST_ASSET])["release"], release)

    def test_incomplete_foreign_old_only_and_ambiguous_registry_proof_refuses(self):
        registry, platform = P.REGISTRIES[0], P.PLATFORMS[0]
        mutations = [lambda p: p.pop(P.REGISTRIES[1]), lambda p: p[registry].pop(platform),
            lambda p: p[registry].__setitem__(platform, []),
            lambda p: p[registry].__setitem__(platform, p[registry][platform] * 5),
            lambda p: p[registry][platform][0]["certificate"].update(runInvocationURI="https://github.com/durable-workflow/server/actions/runs/123/attempts/1"),
            lambda p: p[registry][platform][0]["certificate"].update(sourceRepositoryIdentifier="1208272135"),
            lambda p: p[registry][platform][0]["statement"]["subject"][0].update(name=P.REGISTRIES[1]),
            lambda p: p[registry][platform][0].update(unverified="extra")]
        for change in mutations:
            release, subjects, documents, pairs = self.verified_asset_fixture()
            change(pairs)
            with self.subTest(change=change), self.assertRaises(P.Refusal):
                P.verified_release_assets(release, subjects, documents, pairs)
        release, subjects, documents, pairs = self.verified_asset_fixture()
        conflict = copy.deepcopy(pairs[registry][platform][0])
        conflict["statement"]["predicate"]["name"] = "different-current-predicate"
        pairs[registry][platform].append(conflict)
        with self.assertRaisesRegex(P.Refusal, "predicate_mismatch"):
            P.verified_release_assets(release, subjects, documents, pairs)

    def test_old_verified_attempt_is_not_current_authority_and_identical_current_duplicates_are_safe(self):
        release, subjects, documents, pairs = self.verified_asset_fixture()
        expected = P.verified_release_assets(release, subjects, documents, pairs)
        for registry in P.REGISTRIES:
            for platform in P.PLATFORMS:
                current = pairs[registry][platform][0]
                old = copy.deepcopy(current)
                old["certificate"]["runInvocationURI"] = "https://github.com/durable-workflow/server/actions/runs/123/attempts/1"
                old["statement"]["predicate"]["name"] = "old-predicate-is-not-current-proof"
                pairs[registry][platform] = [old, current, copy.deepcopy(current)]
        self.assertEqual(P.verified_release_assets(release, subjects, documents, pairs), expected)

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
                "--predicate-type", "https://spdx.dev/Document/v2.3", "--deny-self-hosted-runners", "--format", "json",
                "--bundle-from-oci", "--limit", "4", "--jq",
                "map({certificate:.verificationResult.signature.certificate,statement:.verificationResult.statement})"])
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
