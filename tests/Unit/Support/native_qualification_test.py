#!/usr/bin/env python3
"""Transport/producer/cleanup countercontrols; no real Docker or API calls."""
import copy
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[3] / "scripts/ci"))
import native_alias_evidence as E
import native_alias_promote as P
import native_alias_transport as T
import native_qualification as Q
from native_alias_evidence_test import FakeProvider

ROOT = Path(__file__).resolve().parents[3]

SOURCE = "1" * 40
INDEX, CHILD, CONFIG = ["sha256:" + letter * 64 for letter in "abc"]
CID, NID, VOLUME = [letter * 64 for letter in "def"]
SCOPE = "published-compose-301-1-smoke-amd64-local"
CONTEXT = {
    "GITHUB_REPOSITORY": E.REPOSITORY, "GITHUB_EVENT_NAME": "workflow_dispatch",
    "GITHUB_REF": "refs/tags/3.2.0", "GITHUB_REF_NAME": "3.2.0", "GITHUB_SHA": SOURCE,
    "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/" + E.WORKFLOWS["compose"] + "@refs/tags/3.2.0",
    "GITHUB_RUN_ID": "303", "GITHUB_RUN_ATTEMPT": "1",
}


class ResourceCommands:
    def __init__(self):
        self.containers, self.networks, self.volumes = {CID}, {NID}, {VOLUME}
        self.removed, self.reads = [], []
        self.owner = SCOPE
        self.volume_owner = None
        self.fail_list = self.leak = False

    def run(self, argv, limit=E.JSON_LIMIT, env=None):
        self.reads.append(argv)
        resource, operation = argv[1:3]
        if operation == "ls":
            if self.fail_list:
                raise E.Refusal("fake_api_unavailable")
            return ("\n".join(sorted(getattr(self, resource + "s"))) +
                    ("\n" if getattr(self, resource + "s") else "")).encode()
        if operation == "inspect":
            if resource == "container":
                value = {"Id": CID, "Name": "/" + SCOPE + "-server", "Image": CONFIG,
                         "Config": {"Labels": {"com.docker.compose.project": self.owner}},
                         "Mounts": [{"Type": "volume", "Name": VOLUME}]}
            elif resource == "volume":
                value = {"Name": VOLUME, "Labels": self.volume_owner}
            elif resource == "network":
                value = {"Id": NID, "Name": SCOPE, "Containers": {CID: {}}}
            else:
                value = {"Id": CONFIG, "Architecture": "amd64", "Os": "linux",
                         "Config": {"Labels": {"org.opencontainers.image.revision": SOURCE,
                                                "dev.durable-workflow.release.tag": "3.2.0"}}}
            return json.dumps([value]).encode()
        if operation == "rm":
            identities = {item for item in argv[3:] if not item.startswith("-")}
            self.removed.append((resource, identities))
            if not self.leak:
                getattr(self, resource + "s").difference_update(identities)
            return b""
        if argv[:3] == ["git", "rev-parse", "HEAD"]:
            return SOURCE.encode()
        if argv[:2] == ["git", "rev-parse"]:
            return ("3" * 40).encode()
        raise AssertionError(argv)


class ProducerProvider:
    def __init__(self):
        self.commands = ResourceCommands()
        self.subject = {"child_manifest_digest": CHILD, "image_config_digest": CONFIG}
        owner = {"id": E.REPOSITORY_ID, "full_name": E.REPOSITORY}
        self.run = {"id": 303, "run_attempt": 1, "head_sha": SOURCE,
                    "path": E.WORKFLOWS["compose"], "event": "workflow_dispatch",
                    "head_branch": "3.2.0", "repository": owner, "head_repository": owner}
        self.job = {"id": 3001, "run_id": 303, "run_attempt": 1, "head_sha": SOURCE,
                    "status": "in_progress", "name": "linux/amd64 local published image"}

    def index_subjects(self, *args):
        return {"linux/amd64": self.subject}

    def workflow_blob(self, *args):
        return "3" * 40

    def api(self, path):
        return {"jobs": [self.job], "total_count": 1} if "/jobs?" in path else self.run


class ProducerTests(unittest.TestCase):
    def test_cleanup_removes_only_observed_owned_objects_and_checks_absence(self):
        commands = ResourceCommands()
        result = Q.cleanup(commands, "compose", SCOPE)
        self.assertEqual(result, {"api_ok": True, "verified_absent": True,
                                  "container_count": 0, "network_count": 0, "volume_count": 0})
        self.assertEqual(commands.removed, [("container", {CID}), ("network", {NID}), ("volume", {VOLUME})])
        self.assertGreaterEqual(sum(args[2] == "ls" for args in commands.reads), 8)

    def test_cleanup_unavailable_is_unknown_and_foreign_resources_are_not_removed(self):
        for field, value in (("fail_list", True), ("owner", "another-project"),
                             ("volume_owner", {"com.docker.compose.project": "foreign"})):
            commands = ResourceCommands()
            setattr(commands, field, value)
            with self.subTest(field=field), self.assertRaises(E.Refusal):
                Q.cleanup(commands, "compose", SCOPE)
            self.assertEqual(commands.removed, [])

    def test_cleanup_remaining_resource_is_failure(self):
        commands = ResourceCommands()
        commands.leak = True
        with self.assertRaisesRegex(E.Refusal, "cleanup_not_absent"):
            Q.cleanup(commands, "compose", SCOPE)

    def test_diagnostic_context_does_not_query_provider_or_docker(self):
        provider = ProducerProvider()
        with patch.dict(os.environ, CONTEXT, clear=True):
            for field, value in (("GITHUB_EVENT_NAME", "pull_request"),
                                 ("GITHUB_SHA", "unknown"), ("GITHUB_REF", "refs/heads/main")):
                with patch.dict(os.environ, {field: value}):
                    result = Q.observe_image(provider, "compose", E.GHCR_IMAGE + "@" + INDEX, CID, "linux/amd64")
                    self.assertIs(result["eligible"], False)
        self.assertEqual(provider.commands.reads, [])

    def test_observed_image_and_finalized_receipt_bind_to_consumer(self):
        provider = ProducerProvider()
        with patch.dict(os.environ, CONTEXT, clear=True):
            image = E.GHCR_IMAGE + "@" + INDEX
            identity = Q.observe_image(provider, "compose", image, CID, "linux/amd64")
            observations = {key: True for key in E.COMPOSE_OBSERVATIONS}
            observations["production_default_token_refused"] = None
            cleanup = Q.cleanup(ResourceCommands(), "compose", SCOPE)
            record = Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                                observations, cleanup, "success", "success")
            E.bind_receipt(record, "compose", {"workflow_blob": "3" * 40}, provider.run, provider.job,
                           {"source_commit": SOURCE, "tag": "3.2.0", "index_digest": INDEX},
                           "linux/amd64", "local", provider.subject)
            self.assertEqual(set(record), E.RECEIPT_KEYS)
            for field, replacement in (("api_ok", False), ("verified_absent", False),
                                       ("container_count", False), ("volume_count", 1)):
                invalid = {**cleanup, field: replacement}
                with self.subTest(cleanup=field), self.assertRaises(E.Refusal):
                    Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                               observations, invalid, "success", "success")
            for body, clean in (("failure", "success"), ("success", "failure"), ("cancelled", "success")):
                with self.subTest(body=body, clean=clean), self.assertRaises(E.Refusal):
                    Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                               observations, cleanup, body, clean)
            provider.run["run_attempt"] = 2
            with self.assertRaises(E.Refusal):
                Q.finalize(provider, "compose", image, "linux/amd64", "local", identity,
                           observations, cleanup, "success", "success")

    def test_actual_config_id_cannot_be_substituted_with_index(self):
        provider = ProducerProvider()
        provider.subject["image_config_digest"] = INDEX
        with patch.dict(os.environ, CONTEXT, clear=True), self.assertRaisesRegex(E.Refusal, "actual_container_image"):
            Q.observe_image(provider, "compose", E.GHCR_IMAGE + "@" + INDEX, CID, "linux/amd64")


class ReadCommands:
    def __init__(self, result):
        self.result, self.calls = result, []

    def run(self, argv, limit=E.JSON_LIMIT, env=None, accepted=(0,)):
        self.calls.append((argv, limit, env, accepted))
        return self.result


class TransportTests(unittest.TestCase):
    def test_api_only_targets_fixed_owning_repository_and_host(self):
        commands = ReadCommands(b"{}")
        provider = T.GitHubRegistryProvider(commands)
        provider.api("repos/" + E.REPOSITORY + "/actions/runs/301")
        self.assertEqual(commands.calls[0][0][:6], ["gh", "api", "--hostname", "github.com", "--method", "GET"])
        for path in ("https://foreign.invalid/", "repos/fork/server/actions/runs/301",
                     "repos/" + E.REPOSITORY + "/../secrets", "repos/" + E.REPOSITORY + "/runs\nanything"):
            with self.subTest(path=path), self.assertRaises(E.Refusal):
                provider.api(path)
        self.assertEqual(len(commands.calls), 1)

    def test_registry_subjects_bind_platform_child_config_and_source(self):
        class Registry(T.GitHubRegistryProvider):
            def __init__(self):
                self.members = {
                    INDEX: {"manifests": [
                        {"platform": {"os": "linux", "architecture": "amd64"}, "digest": CHILD},
                        {"platform": {"os": "linux", "architecture": "arm64"}, "digest": "sha256:" + "d" * 64}]},
                    CHILD: {"config": {"digest": CONFIG}},
                    "sha256:" + "d" * 64: {"config": {"digest": "sha256:" + "e" * 64}},
                }
                labels = {"org.opencontainers.image.revision": SOURCE, "dev.durable-workflow.release.tag": "3.2.0"}
                self.configs = {CHILD: {"os": "linux", "architecture": "amd64", "config": {"Labels": dict(labels)}},
                                "sha256:" + "d" * 64: {"os": "linux", "architecture": "arm64", "config": {"Labels": dict(labels)}}}

            def registry_json(self, image, digest, image_config=False):
                return (self.configs if image_config else self.members)[digest]

        registry = Registry()
        self.assertEqual(registry.index_subjects(E.GHCR_IMAGE, INDEX, SOURCE, "3.2.0")["linux/amd64"],
                         {"child_manifest_digest": CHILD, "image_config_digest": CONFIG})
        for fault in ("platform", "source", "release", "config", "extra_member"):
            registry = Registry()
            if fault == "platform": registry.configs[CHILD]["architecture"] = "arm64"
            elif fault == "source": registry.configs[CHILD]["config"]["Labels"]["org.opencontainers.image.revision"] = "f" * 40
            elif fault == "release": registry.configs[CHILD]["config"]["Labels"]["dev.durable-workflow.release.tag"] = "3.1.0"
            elif fault == "config": registry.members[CHILD]["config"]["digest"] = "not-a-config"
            else: registry.members[INDEX]["manifests"].append(copy.deepcopy(registry.members[INDEX]["manifests"][0]))
            with self.subTest(fault=fault), self.assertRaises(E.Refusal):
                registry.index_subjects(E.GHCR_IMAGE, INDEX, SOURCE, "3.2.0")

    def test_release_absence_requires_authoritative_http_404(self):
        for status, body, expected in (("404 Not Found", {"message": "Not Found"}, False),
                                       ("200 OK", {"id": 42, "tag_name": "3.2.0"}, True)):
            commands = ReadCommands(("HTTP/2.0 " + status + "\r\nContent-Type: application/json\r\n\r\n" + json.dumps(body)).encode())
            self.assertIs(T.GitHubRegistryProvider(commands).release_exists("3.2.0"), expected)
        for response in (b"unauthorized", b"HTTP/2.0 503 Error\r\n\r\n{}",
                         b"HTTP/2.0 404 Not Found\r\nContent-Type: application/json\r\n\r\n{}"):
            with self.subTest(response=response), self.assertRaises(E.Refusal):
                T.GitHubRegistryProvider(ReadCommands(response)).release_exists("3.2.0")

    def test_commands_bound_output_time_and_sanitize_errors(self):
        for code, limit, seconds in (("print('x'*10000)", 10, 1),
                                     ("import time; time.sleep(1)", 100, .03),
                                     ("import sys; sys.stderr.write('private-marker'); sys.exit(1)", 100, 1)):
            with self.subTest(code=code), self.assertRaises(E.Refusal) as caught:
                T.Commands(seconds).run([sys.executable, "-c", code], limit)
            self.assertNotIn("private-marker", str(caught.exception))
        self.assertEqual(T.Commands().run([sys.executable, "-c", "print('bounded')"]), b"bounded\n")

    def test_promotion_dispatch_cannot_be_a_main_or_foreign_workflow(self):
        value = {"release": {"tag": "3.2.0", "source_commit": SOURCE}}
        context = {**CONTEXT, "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/.github/workflows/native-alias-promotion.yml@refs/tags/3.2.0"}
        with patch.dict(os.environ, context, clear=True):
            P.dispatch(value)
            for field, replacement in (("GITHUB_REF", "refs/heads/main"), ("GITHUB_SHA", "2" * 40),
                                       ("GITHUB_WORKFLOW_REF", "foreign/workflow")):
                with patch.dict(os.environ, {field: replacement}), self.assertRaises(E.Refusal):
                    P.dispatch(value)

    def test_protected_entry_refusals_leave_zero_write_ledger(self):
        context = {**CONTEXT, "GITHUB_WORKFLOW_REF": E.REPOSITORY + "/.github/workflows/native-alias-promotion.yml@refs/tags/3.2.0"}
        for fault in ("legacy_release", "attempt", "workflow_blob", "archive"):
            provider = FakeProvider()
            provider.release_exists = lambda tag: fault == "legacy_release"
            if fault == "attempt":
                provider.runs[302]["run_attempt"] = 2
            elif fault == "workflow_blob":
                provider.selected["corpus"]["workflow_blob"] = "f" * 40
            elif fault == "archive":
                provider.archives[501] += b"extra"
            writes = []
            with patch.dict(os.environ, context, clear=True), self.subTest(fault=fault):
                with self.assertRaises(E.Refusal):
                    proof = P.qualify(provider.selected, provider, "promote")
                    writes.append(proof)
                self.assertEqual(writes, [])


class WorkflowContracts(unittest.TestCase):
    def test_only_separate_protected_job_may_move_native_aliases(self):
        publisher = (ROOT / ".github/workflows/native-release.yml").read_text()
        promotion = (ROOT / ".github/workflows/native-alias-promotion.yml").read_text()
        self.assertNotIn("\n  aliases:", publisher)
        self.assertNotIn("native-release-rolling-tags.sh", publisher)
        readonly, protected = promotion.split("\n  promote:\n", 1)
        self.assertNotIn(": write", readonly)
        self.assertNotIn("secrets.DOCKERHUB", readonly)
        self.assertIn("environment: dockerhub-native", protected)
        self.assertIn("group: durable-workflow-server-rolling-aliases", protected)
        self.assertIn("packages: write", protected)
        self.assertNotIn("contents: write", promotion)
        self.assertIn("native_alias_promote.py verify", readonly)
        self.assertIn("native_alias_promote.py promote", protected)
        for name, steps in E.RELEASE_JOBS.items():
            self.assertIn("name: " + name, publisher)
            for step in steps:
                self.assertIn("name: " + step, publisher)

    def test_receipt_authority_follows_body_and_cleanup_in_existing_jobs(self):
        for role in ("corpus", "compose"):
            source = (ROOT / E.WORKFLOWS[role]).read_text()
            steps = E.QUALIFICATION_STEPS[role]
            positions = [source.index("name: " + step) for step in steps]
            self.assertEqual(positions, sorted(positions))
            self.assertIn("QUALIFICATION_BODY_OUTCOME: ${{ steps.body.outcome }}", source)
            self.assertIn("QUALIFICATION_CLEANUP_OUTCOME: ${{ steps.cleanup.outcome }}", source)
            self.assertIn("promotion-receipt/receipt.json", source)
            self.assertIn("if-no-files-found: error", source)

    def test_partial_setup_fallback_cannot_turn_strict_cleanup_failure_into_success(self):
        # Execute the actual workflow run block with inert CLI fakes, rather than
        # accepting a source string that merely says cleanup was successful.
        with tempfile.TemporaryDirectory() as directory:
            scratch = Path(directory)
            for name, status in (("python3", 71), ("docker", 0), ("xargs", 0)):
                target = scratch / name
                target.write_text("#!/bin/sh\nexit " + str(status) + "\n")
                target.chmod(0o755)
            for role in ("corpus", "compose"):
                source = (ROOT / E.WORKFLOWS[role]).read_text()
                block = source.split("      - name: Verify owned qualification cleanup\n", 1)[1]
                block = block.split("      - name:", 1)[0]
                body = textwrap.dedent(block.split("        run: |\n", 1)[1])
                result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", body],
                                        env={"PATH": directory + ":" + os.environ["PATH"],
                                             "RESOURCE_SCOPE": "dw-parity-301-1-corpus",
                                             "DW_PUBLISHED_COMPOSE_PROJECT": SCOPE},
                                        capture_output=True, timeout=5, check=False)
                self.assertEqual(result.returncode, 1, role)


if __name__ == "__main__":
    unittest.main()
