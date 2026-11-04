#!/usr/bin/env python3
"""Artifact identity controls; real image/signature checks are separate receipts."""

import hashlib
import json
import os
import subprocess
import unittest
from unittest import mock

from single_region_failover_result_gate_test import runner


CONFIG_DIGEST = "sha256:" + "c" * 64
MANIFEST_RAW = json.dumps({"mediaType": "application/vnd.oci.image.manifest.v1+json",
                           "config": {"digest": CONFIG_DIGEST}})
MANIFEST_DIGEST = "sha256:" + hashlib.sha256(MANIFEST_RAW.encode()).hexdigest()
INDEX_RAW = json.dumps({"mediaType": "application/vnd.oci.image.index.v1+json", "manifests": [
    {"digest": MANIFEST_DIGEST, "platform": {"os": "linux", "architecture": "amd64"}}]})
IMAGE = "durableworkflow/server@sha256:" + hashlib.sha256(INDEX_RAW.encode()).hexdigest()
SOURCE = "b" * 40
REPOSITORY = "https://github.com/durable-workflow/server"
WORKFLOW = REPOSITORY + "/.github/workflows/native-release.yml"


class PublishedIdentityTest(unittest.TestCase):
    def setUp(self) -> None:
        self.environment = mock.patch.dict(os.environ, {
            "DW_FAILOVER_SERVER_IMAGE": IMAGE,
            "DW_FAILOVER_SERVER_IMAGE_REQUESTED": "durableworkflow/server:3.1.0",
            "GH": "gh",
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.commands = []
        self.index_raw = INDEX_RAW
        self.manifest_raw = MANIFEST_RAW
        self.live_raw = INDEX_RAW
        self.local_peel = SOURCE
        self.remote_refs = "d" * 40 + "\trefs/tags/3.1.0\n" + SOURCE + "\trefs/tags/3.1.0^{}\n"
        self.metadata = {
            "Id": CONFIG_DIGEST, "RepoDigests": [IMAGE],
            "Os": "linux", "Architecture": "amd64",
            "Config": {"Entrypoint": ["/usr/local/bin/durable-workflow-server"], "Labels": {
                "org.opencontainers.image.source": REPOSITORY,
                "org.opencontainers.image.title": "Durable Workflow Server (native)",
                "org.opencontainers.image.revision": SOURCE,
                "dev.durable-workflow.release.tag": "3.1.0",
                "dev.durable-workflow.release.run-id": "12345",
            }},
        }
        self.certificate = {
            "sourceRepositoryURI": REPOSITORY, "sourceRepositoryDigest": SOURCE,
            "sourceRepositoryRef": "refs/tags/3.1.0", "runnerEnvironment": "github-hosted",
            "buildSignerURI": WORKFLOW + "@refs/tags/3.1.0",
            "runInvocationURI": REPOSITORY + "/actions/runs/12345/attempts/1",
        }
        self.attestations = [{"verificationResult": {
            "signature": {"certificate": self.certificate},
            "statement": {"subject": [{"digest": {"sha256": IMAGE.split("@sha256:")[1]}}]},
        }}]
        self.php_package = {"name": "durable-workflow/workflow", "version": "2.15.12",
                            "source_reference": "d" * 40, "dist_reference": "d" * 40,
                            "image_package_provenance": [], "php_version": "8.4.27"}
        self.verifier_error = None

    def command(self, args, **kwargs):
        self.commands.append((args, kwargs))
        if args[:3] == ["docker", "image", "inspect"]:
            value = [self.metadata]
        elif args[:4] == ["docker", "buildx", "imagetools", "inspect"]:
            value = self.live_raw if ":3.1.0" in args[-1] else self.index_raw if args[-1] == IMAGE else self.manifest_raw
            return subprocess.CompletedProcess(args, 0, value, "")
        elif args[:2] == ["git", "rev-parse"]:
            return subprocess.CompletedProcess(args, 0, self.local_peel + "\n", "")
        elif args[:2] == ["git", "ls-remote"]:
            return subprocess.CompletedProcess(args, 0, self.remote_refs, "")
        elif args[:3] == ["gh", "attestation", "verify"]:
            if self.verifier_error:
                raise self.verifier_error
            value = self.attestations
        elif args[:3] == ["docker", "run", "--rm"] and "php" in args:
            value = self.php_package
        else:
            self.fail("Unexpected external command")
        return subprocess.CompletedProcess(args, 0, json.dumps(value), "")

    def inspect(self):
        with mock.patch.object(runner, "command", side_effect=self.command):
            return runner.inspect_published_server()

    def test_native_identity_binds_config_source_build_and_verified_subject(self):
        value = self.inspect()
        self.assertNotIn("embedded_workflow_package", value)
        identity = value["native_server_identity"]
        self.assertEqual(IMAGE, identity["image_repo_digest"])
        self.assertEqual(CONFIG_DIGEST, identity["image_config_digest"])
        self.assertEqual(MANIFEST_DIGEST, identity["image_manifest_digest"])
        self.assertEqual(SOURCE, identity["source_revision"])
        self.assertEqual("3.1.0", identity["release_version"])
        self.assertEqual("12345", identity["release_run_id"])
        self.assertEqual("3.1.0", identity["registry_tag"])
        self.assertEqual(SOURCE, identity["git_tag_commit"])
        self.assertEqual([self.certificate["runInvocationURI"]], identity["attestation_invocations"])
        self.assertFalse(any(args[:2] == ["docker", "run"] for args, _ in self.commands))
        verifier, options = next((args, options) for args, options in self.commands if args[0] == "gh")
        for flag, expected in [("--repo", "durable-workflow/server"),
                               ("--source-ref", "refs/tags/3.1.0"), ("--source-digest", SOURCE),
                               ("--signer-workflow", WORKFLOW.removeprefix("https://"))]:
            self.assertEqual(expected, verifier[verifier.index(flag) + 1])
        self.assertIn("--deny-self-hosted-runners", verifier)
        self.assertEqual(60, options["timeout"])

    def test_containerd_image_id_is_recorded_separately_from_config_digest(self):
        self.metadata["Id"] = IMAGE.split("@", 1)[1]
        identity = self.inspect()["native_server_identity"]
        self.assertEqual(self.metadata["Id"], identity["local_image_id"])
        self.assertEqual(CONFIG_DIGEST, identity["image_config_digest"])
        self.assertNotEqual(identity["local_image_id"], identity["image_config_digest"])

    def test_php_inspection_preserves_the_original_package_record(self):
        self.legacy_php()
        self.assertEqual({"embedded_workflow_package": self.php_package}, self.inspect())
        self.assertEqual(2, len(self.commands))
        self.assertIn("/app/composer.lock", self.commands[1][0][-1])
        self.assertFalse(any(args[0] == "gh" for args, _ in self.commands))

    def test_php_inspection_still_rejects_missing_package_provenance(self):
        self.legacy_php()
        self.php_package["source_reference"] = None
        self.php_package["dist_reference"] = None
        with self.assertRaisesRegex(AssertionError, "no exact source reference"):
            self.inspect()

    def test_failed_signature_verification_never_falls_back_to_php(self):
        self.verifier_error = subprocess.CalledProcessError(1, ["gh", "attestation", "verify"])
        with self.assertRaises(subprocess.CalledProcessError):
            self.inspect()
        self.assertFalse(any(args[:2] == ["docker", "run"] for args, _ in self.commands))

    def legacy_php(self):
        self.metadata["Config"] = {"Entrypoint": ["server-entrypoint"], "Labels": {
            "org.opencontainers.image.title": "server", "org.opencontainers.image.source": REPOSITORY,
            "org.opencontainers.image.version": "2.11.8", "dev.durable-workflow.release.tag": "2.11.8",
            "dev.durable-workflow.package.authority": "composer.lock",
            "dev.durable-workflow.workflow.package": "durable-workflow/workflow",
            "dev.durable-workflow.workflow.source": "https://github.com/durable-workflow/workflow.git",
            "dev.durable-workflow.workflow.version": "2.15.12", "dev.durable-workflow.workflow.commit": "d" * 40,
        }}

    def test_unknown_identity_cannot_fall_back_to_valid_looking_php_package(self):
        self.metadata["Config"]["Entrypoint"] = ["php"]
        self.metadata["Config"]["Labels"].pop("org.opencontainers.image.title")
        with self.assertRaisesRegex(AssertionError, "implementation identity"):
            self.inspect()
        self.assertEqual(1, len(self.commands))

    def test_php_discriminators_with_native_release_labels_are_refused_before_execution(self):
        self.legacy_php()
        self.metadata["Config"]["Labels"].update({"dev.durable-workflow.release.tag": "3.1.0", "org.opencontainers.image.version": "3.1.0"})
        with self.assertRaisesRegex(AssertionError, "implementation identity"):
            self.inspect()
        self.assertEqual(1, len(self.commands))

    def test_incomplete_or_mixed_php_identity_never_launches_package_inspector(self):
        fields = ["org.opencontainers.image.title", "org.opencontainers.image.source",
                  "org.opencontainers.image.version", "dev.durable-workflow.release.tag",
                  "dev.durable-workflow.package.authority", "dev.durable-workflow.workflow.package",
                  "dev.durable-workflow.workflow.source", "dev.durable-workflow.workflow.commit",
                  "dev.durable-workflow.workflow.version"]
        for field in fields:
            with self.subTest(missing=field):
                self.legacy_php()
                self.commands.clear()
                self.metadata["Config"]["Labels"].pop(field)
                with self.assertRaisesRegex(AssertionError, "implementation identity"):
                    self.inspect()
                self.assertEqual(1, len(self.commands))
        for entrypoint, title in [(["/usr/local/bin/durable-workflow-server"], "server"),
                                  (["server-entrypoint"], "Durable Workflow Server (native)")]:
            with self.subTest(entrypoint=entrypoint, title=title):
                self.legacy_php()
                self.commands.clear()
                self.metadata["Config"]["Entrypoint"] = entrypoint
                self.metadata["Config"]["Labels"]["org.opencontainers.image.title"] = title
                with self.assertRaisesRegex(AssertionError, "implementation identity"):
                    self.inspect()
                self.assertEqual(1, len(self.commands))

    def test_historical_native_git_tag_normalizes_to_registry_version(self):
        self.metadata["Config"]["Labels"]["dev.durable-workflow.release.tag"] = "native-3.1.0"
        self.certificate["sourceRepositoryRef"] = "refs/tags/native-3.1.0"
        self.certificate["buildSignerURI"] = WORKFLOW + "@refs/tags/native-3.1.0"
        self.remote_refs = self.remote_refs.replace("refs/tags/3.1.0", "refs/tags/native-3.1.0")
        identity = self.inspect()["native_server_identity"]
        self.assertEqual("native-3.1.0", identity["release_tag"])
        self.assertEqual("3.1.0", identity["registry_tag"])

    def test_exact_digest_request_still_requires_the_live_version_tag(self):
        os.environ["DW_FAILOVER_SERVER_IMAGE_REQUESTED"] = IMAGE
        self.assertEqual("3.1.0", self.inspect()["native_server_identity"]["registry_tag"])
        self.assertTrue(any(args[-1] == "durableworkflow/server:3.1.0" for args, _ in self.commands))

    def test_native_identity_phase_does_not_pass_recovery_cells(self):
        services = {name: {"image": IMAGE} for name in ["bootstrap", "server-a", "server-b",
                    "queue-worker", "scheduler", "load-balancer", "mysql", "redis"]}
        raw = json.dumps({"services": services})
        with mock.patch.object(runner, "command", side_effect=self.command), \
                mock.patch.object(runner, "compose", return_value=subprocess.CompletedProcess([], 0, raw, "")), \
                mock.patch.dict(runner.RESULT, {"artifacts": {}, "phase_outcomes": {}}, clear=False):
            evidence = runner.provenance_phase()
            self.assertEqual(evidence["native_server_identity"], runner.RESULT["artifacts"]["native_server_identity"])
            self.assertNotIn("embedded_workflow_package", evidence)
            runner.RESULT["phase_outcomes"]["published_artifact_provenance"] = {"status": "pass"}
            with self.assertRaisesRegex(AssertionError, "required scenario result missing"):
                runner.require_passing_result()


def rejection(name, mutate):
    def test(self):
        mutate(self)
        with self.assertRaises(AssertionError):
            self.inspect()
    test.__name__ = "test_rejects_" + name
    setattr(PublishedIdentityTest, test.__name__, test)


for name, mutate in [
    ("mutable_image", lambda t: os.environ.update(DW_FAILOVER_SERVER_IMAGE="durableworkflow/server:3.1.0")),
    ("other_repository", lambda t: os.environ.update(DW_FAILOVER_SERVER_IMAGE="other/server@sha256:" + "a" * 64)),
    ("unbound_config", lambda t: t.metadata.update(RepoDigests=[])),
    ("invalid_local_image_id", lambda t: t.metadata.update(Id="tag")),
    ("unrelated_local_image_id", lambda t: t.metadata.update(Id="sha256:" + "e" * 64)),
    ("modified_index_bytes", lambda t: setattr(t, "index_raw", t.index_raw + " ")),
    ("modified_platform_bytes", lambda t: setattr(t, "manifest_raw", t.manifest_raw + " ")),
    ("unsupported_platform", lambda t: t.metadata.update(Architecture="ppc64le")),
    ("wrong_entrypoint", lambda t: t.metadata["Config"].update(Entrypoint=["php"])),
    ("wrong_title", lambda t: t.metadata["Config"]["Labels"].update({"org.opencontainers.image.title": "other"})),
    ("wrong_source_repository", lambda t: t.metadata["Config"]["Labels"].update({"org.opencontainers.image.source": "https://example.invalid"})),
    ("missing_source", lambda t: t.metadata["Config"]["Labels"].pop("org.opencontainers.image.revision")),
    ("zero_source", lambda t: t.metadata["Config"]["Labels"].update({"org.opencontainers.image.revision": "0" * 40})),
    ("rolling_release_tag", lambda t: t.metadata["Config"]["Labels"].update({"dev.durable-workflow.release.tag": "latest"})),
    ("php_version_as_native", lambda t: t.metadata["Config"]["Labels"].update({"dev.durable-workflow.release.tag": "2.11.8"})),
    ("missing_build_run", lambda t: t.metadata["Config"]["Labels"].pop("dev.durable-workflow.release.run-id")),
    ("native_with_php_authority", lambda t: t.metadata["Config"]["Labels"].update({"dev.durable-workflow.package.authority": "composer.lock"})),
    ("native_with_php_package", lambda t: t.metadata["Config"]["Labels"].update({"dev.durable-workflow.workflow.package": "durable-workflow/workflow"})),
    ("wrong_requested_tag", lambda t: os.environ.update(DW_FAILOVER_SERVER_IMAGE_REQUESTED="durableworkflow/server:3.2.0")),
    ("wrong_requested_digest", lambda t: os.environ.update(DW_FAILOVER_SERVER_IMAGE_REQUESTED="durableworkflow/server@sha256:" + "e" * 64)),
    ("moved_registry_tag", lambda t: setattr(t, "live_raw", t.live_raw + " ")),
    ("missing_local_git_tag", lambda t: setattr(t, "local_peel", "")),
    ("moved_local_git_tag", lambda t: setattr(t, "local_peel", "e" * 40)),
    ("missing_remote_git_tag", lambda t: setattr(t, "remote_refs", "")),
    ("moved_remote_git_tag", lambda t: setattr(t, "remote_refs", "e" * 40 + "\trefs/tags/3.1.0\n")),
    ("duplicate_remote_git_tag", lambda t: setattr(t, "remote_refs", t.remote_refs + t.remote_refs)),
    ("empty_attestations", lambda t: t.attestations.clear()),
    ("different_signed_subject", lambda t: t.attestations[0]["verificationResult"]["statement"]["subject"][0]["digest"].update(sha256="e" * 64)),
    ("different_signed_repository", lambda t: t.certificate.update(sourceRepositoryURI="https://example.invalid")),
    ("different_signed_source", lambda t: t.certificate.update(sourceRepositoryDigest="e" * 40)),
    ("different_signed_tag", lambda t: t.certificate.update(sourceRepositoryRef="refs/tags/3.0.5")),
    ("self_hosted_signer", lambda t: t.certificate.update(runnerEnvironment="self-hosted")),
    ("different_workflow_signer", lambda t: t.certificate.update(buildSignerURI=REPOSITORY + "/.github/workflows/other.yml@refs/tags/3.1.0")),
    ("different_signed_build_run", lambda t: t.certificate.update(runInvocationURI=REPOSITORY + "/actions/runs/67890/attempts/1")),
    ("missing_build_attempt", lambda t: t.certificate.update(runInvocationURI=REPOSITORY + "/actions/runs/12345")),
]:
    rejection(name, mutate)


if __name__ == "__main__":
    unittest.main()
