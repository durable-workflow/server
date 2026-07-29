#!/usr/bin/env python3
"""Adversarial checks for regression-corpus policy enforcement."""

from __future__ import annotations

import fnmatch
import json
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

VALIDATOR = Path(__file__).with_name("validate-regression-corpus.py")
REPOSITORY_ROOT = VALIDATOR.parents[2]
REPOSITORY_POLICY = REPOSITORY_ROOT / "regression-corpus-policy.json"
CORE_CODEC_BOUNDARIES = (
    "app/Support/ExternalWorkflowUpdateAdmission.php",
    "app/Support/WorkflowQueryTaskBroker.php",
)
CODEC_BOUNDARY_REFERENCE_PATTERNS = (
    r"Workflow\\Serializers\\",
    r"Workflow\\V2\\Support\\[A-Za-z0-9_]*Payload[A-Za-z0-9_]*",
    r"\bExternalPayloadEnvelopeService\b",
)
REPRESENTATIVE_CODEC_DEPENDENCIES = (
    r"Workflow\Serializers\Avro",
    r"Workflow\Serializers\AvroValueJsonProjection",
    r"Workflow\Serializers\CodecRegistry",
    r"Workflow\Serializers\Serializer",
    r"Workflow\Serializers\SerializerInterface",
    r"Workflow\Serializers\FutureCodec",
    r"Workflow\V2\Support\PayloadEnvelopeResolver",
    r"Workflow\V2\Support\ExternalPayloads",
    "ExternalPayloadEnvelopeService",
)
SEMANTIC_CODEC_GLOBS = {"app/*.php", "app/**/*.php"}
PATH_LEVEL_CODEC_BOUNDARIES = {
    "app/Http/Controllers/Api/ActivityController.php",
    "app/Http/Controllers/Api/ActivityTaskController.php",
    "app/Http/Controllers/Api/BridgeAdapterController.php",
    "app/Http/Controllers/Api/HistoryController.php",
    "app/Http/Controllers/Api/WorkerController.php",
    "app/Http/Controllers/Api/WorkflowController.php",
    "app/Support/ActivityTaskPoller.php",
    "app/Support/ExternalPayloadEnvelopeService.php",
    "app/Support/ExternalPayloadRetentionCleanup.php",
    "app/Support/ExternalWorkflowUpdateAdmission.php",
    "app/Support/InvocableCarrierResultMapper.php",
    "app/Support/ServerWorkflowControlPlane.php",
    "app/Support/WorkflowQueryTaskBroker.php",
    "app/Support/WorkflowStartService.php",
    "app/Support/WorkflowTaskPoller.php",
}
SEMANTIC_BOUNDARY = "app/Services/SemanticCodecBoundary.php"


def run(*arguments: str, cwd: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        list(arguments),
        cwd=cwd,
        check=False,
        capture_output=True,
        text=True,
    )


class RegressionCorpusPolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        (self.root / "app/Support").mkdir(parents=True)
        (self.root / "app/Services").mkdir(parents=True)
        (self.root / "tests/Fixtures/CodecRegression").mkdir(parents=True)
        (self.root / "tests/Fixtures/DormantCodecRegression").mkdir(parents=True)
        (self.root / "tests/Fixtures/CodecRegressionProofs").mkdir(parents=True)
        (self.root / "tests/Feature/CodecRegression").mkdir(parents=True)
        (self.root / "app/Support/ExamplePayload.php").write_text("<?php\nreturn 'base';\n")
        (self.root / CORE_CODEC_BOUNDARIES[0]).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, $arguments);\n"
        )
        (self.root / CORE_CODEC_BOUNDARIES[1]).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, $blob);\n"
        )
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver as Resolver;\n"
            "return Resolver::resolveToArray($payload);\n"
        )
        self.write_json(
            "tests/Fixtures/CodecRegression/base.json",
            self.codec_fixture("base-codec-case", "0", "AA=="),
        )
        self.write_json(
            "tests/Fixtures/DormantCodecRegression/base-revision.json",
            self.codec_fixture("dormant-codec-case", "1", "Ag=="),
        )
        self.phpunit = self.root / "fake-phpunit.py"
        self.phpunit.write_text(
            """#!/usr/bin/env python3
import json
import os
from pathlib import Path

fixture = json.loads(Path(os.environ["SERVER_CODEC_REGRESSION_FIXTURE"]).read_text())
source_root = Path(os.environ["SERVER_CODEC_SOURCE_ROOT"])
identity = fixture["id"]
if identity == "unrelated-codec-case":
    raise SystemExit(0)
if identity in {"encode-boundary-defect", "misattributed-boundary-defect"}:
    source = (source_root / "app/Support/ExternalWorkflowUpdateAdmission.php").read_text()
    raise SystemExit(0 if "array_values($arguments)" in source else 1)
if identity == "decode-boundary-defect":
    source = (source_root / "app/Support/WorkflowQueryTaskBroker.php").read_text()
    raise SystemExit(0 if "trim($blob)" in source else 1)
raise SystemExit(2)
""",
            encoding="utf-8",
        )
        self.phpunit.chmod(0o755)
        self.write_policy("app/Support/*Payload*.php")
        self.git("init", "--quiet")
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=baseline",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def git(self, *arguments: str) -> subprocess.CompletedProcess[str]:
        result = run("git", *arguments, cwd=self.root)
        if result.returncode != 0:
            self.fail(
                f"git command failed: {arguments!r}\n{result.stdout}\n{result.stderr}"
            )
        return result

    def write_json(self, relative_path: str, value: dict[str, Any]) -> None:
        (self.root / relative_path).write_text(json.dumps(value, indent=2) + "\n")

    @staticmethod
    def codec_fixture(identity: str, value: str, wire: str) -> dict[str, Any]:
        return {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.codec-regression/v1",
            "id": identity,
            "protocol": {
                "codec": "avro",
                "schema": "example.Value",
                "version": "1",
                "fingerprint": None,
            },
            "bindings": ["php"],
            "value": {"type": "long", "value": value},
            "framing": {"encoding": "base64", "wire_base64": wire},
            "failure_policy": {"operation": "round_trip", "error": None},
        }

    def write_counterfactual(
        self,
        identity: str,
        boundary: str | list[str],
        *,
        value: str,
        wire: str,
    ) -> None:
        fixture = f"tests/Fixtures/CodecRegression/{identity}.json"
        test = f"tests/Feature/CodecRegression/{identity}Test.php"
        self.write_json(fixture, self.codec_fixture(identity, value, wire))
        self.write_json(
            f"tests/Fixtures/CodecRegressionProofs/{identity}.json",
            {
                "$schema": "https://example.invalid/server-codec-counterfactual-schema.json",
                "proof_schema": "durable-workflow.server-codec-counterfactual/v1",
                "fixture": fixture,
                "test": test,
                "boundaries": [boundary] if isinstance(boundary, str) else boundary,
            },
        )
        (self.root / test).write_text(
            "<?php\ngetenv('SERVER_CODEC_REGRESSION_FIXTURE');\n",
            encoding="utf-8",
        )

    def write_policy(
        self,
        guard_glob: str,
        fixture_glob: str = "tests/Fixtures/CodecRegression/*.json",
    ) -> None:
        self.write_json(
            "regression-corpus-policy.json",
            {
                "$schema": "https://example.invalid/policy-schema.json",
                "schema": "durable-workflow.regression-corpus-policy/v1",
                "repository": "server",
                "binding": "php",
                "categories": {
                    "codec": {
                        "fixtures": [
                            {
                                "glob": fixture_glob,
                                "format": "codec-regression-v1",
                            }
                        ],
                        "guards": [
                            {"glob": guard_glob}
                            if guard["glob"] == "app/Support/*Payload*.php"
                            else guard
                            for guard in json.loads(REPOSITORY_POLICY.read_text())[
                                "categories"
                            ]["codec"]["guards"]
                        ],
                    }
                },
            },
        )

    def validate(
        self, *, verify_counterfactual: bool = False
    ) -> subprocess.CompletedProcess[str]:
        arguments = [
            sys.executable,
            str(VALIDATOR),
            "--root",
            str(self.root),
            "--base-ref",
            self.base_ref,
        ]
        if verify_counterfactual:
            arguments.extend(
                [
                    "--verify-counterfactual",
                    "--phpunit",
                    str(self.phpunit),
                ]
            )
        return run(*arguments, cwd=self.root)

    def test_codec_change_cannot_hide_behind_weakened_guard(self) -> None:
        (self.root / "app/Support/ExamplePayload.php").write_text("<?php\nreturn 'changed';\n")
        self.write_policy("app/Support/Nonmatching*.php")

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.guards cannot remove or change a base selector",
            result.stderr,
        )

    def test_existing_fixture_cannot_hide_behind_a_changed_fixture_glob(self) -> None:
        self.write_policy(
            "app/Support/*Payload*.php",
            "tests/Fixtures/CodecRegression/Nonmatching*.json",
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.fixtures cannot remove or change a base selector",
            result.stderr,
        )

    def test_existing_codec_fixture_remains_immutable(self) -> None:
        self.write_json(
            "tests/Fixtures/CodecRegression/base.json",
            self.codec_fixture("base-codec-case", "99", "xgE="),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "immutable fixture file tests/Fixtures/CodecRegression/base.json "
            "was changed, moved, or removed",
            result.stderr,
        )

    def test_guarded_change_cannot_grow_corpus_by_selecting_base_file(self) -> None:
        (self.root / "app/Support/ExamplePayload.php").write_text("<?php\nreturn 'changed';\n")
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": "tests/Fixtures/DormantCodecRegression/*.json",
                "format": "codec-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but no newly added fixture provides corpus evidence",
            result.stderr,
        )

    def test_encode_boundary_change_without_new_fixture_fails_closed(self) -> None:
        (self.root / CORE_CODEC_BOUNDARIES[0]).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_decode_boundary_change_without_new_fixture_fails_closed(self) -> None:
        (self.root / CORE_CODEC_BOUNDARIES[1]).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_resolve_to_array_boundary_without_fixture_fails_closed(self) -> None:
        (self.root / "app/Support/NewCodecBoundary.php").write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver as Resolver;\n"
            "return Resolver::resolveToArray($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_codec_helper_method_without_fixture_fails_closed(self) -> None:
        (self.root / "app/Support/FutureCodecBoundary.php").write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver as Resolver;\n"
            "return Resolver::futureCodecBoundary($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_root_codec_boundary_without_fixture_fails_closed(self) -> None:
        (self.root / "app/RootCodecBoundary.php").write_text(
            "<?php\n"
            "use Workflow\\Serializers\\FutureCodec as Codec;\n"
            "return Codec::canonicalize($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_semantic_guard_checks_the_whole_candidate_file(self) -> None:
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver as Resolver;\n"
            "$payload = array_values($payload);\n"
            "return Resolver::resolveToArray($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_semantic_guard_checks_the_whole_base_file(self) -> None:
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\nreturn array_values($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_every_core_codec_boundary_has_a_path_level_guard(self) -> None:
        policy = json.loads(REPOSITORY_POLICY.read_text())
        path_guards = [
            guard["glob"]
            for guard in policy["categories"]["codec"]["guards"]
            if "content_patterns" not in guard
        ]

        missing = sorted(
            path
            for path in PATH_LEVEL_CODEC_BOUNDARIES
            if not any(fnmatch.fnmatchcase(path, guard) for guard in path_guards)
        )

        self.assertEqual([], missing)

    def test_every_current_codec_dependency_matches_the_semantic_guard(self) -> None:
        policy = json.loads(REPOSITORY_POLICY.read_text())
        semantic_guards = [
            guard
            for guard in policy["categories"]["codec"]["guards"]
            if guard["glob"] in SEMANTIC_CODEC_GLOBS
        ]
        missing = []
        for path in (REPOSITORY_ROOT / "app").rglob("*.php"):
            content = path.read_text(encoding="utf-8")
            if not any(
                re.search(pattern, content)
                for pattern in CODEC_BOUNDARY_REFERENCE_PATTERNS
            ):
                continue
            relative_path = path.relative_to(REPOSITORY_ROOT).as_posix()
            if not any(
                fnmatch.fnmatchcase(relative_path, guard["glob"])
                and any(
                    re.search(pattern, content) for pattern in guard["content_patterns"]
                )
                for guard in semantic_guards
            ):
                missing.append(path.relative_to(REPOSITORY_ROOT).as_posix())

        self.assertEqual([], missing)

    def test_semantic_selector_covers_every_codec_dependency(self) -> None:
        policy = json.loads(REPOSITORY_POLICY.read_text())
        semantic_guards = [
            guard
            for guard in policy["categories"]["codec"]["guards"]
            if guard["glob"] in SEMANTIC_CODEC_GLOBS
        ]

        self.assertEqual(
            SEMANTIC_CODEC_GLOBS, {guard["glob"] for guard in semantic_guards}
        )
        for guard in semantic_guards:
            patterns = guard["content_patterns"]
            for dependency in REPRESENTATIVE_CODEC_DEPENDENCIES:
                self.assertTrue(
                    any(re.search(pattern, dependency) for pattern in patterns),
                    f"{guard['glob']}: {dependency}",
                )

    def test_unrelated_passing_fixture_cannot_prove_a_guarded_change(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "unrelated-codec-case",
            boundary,
            value="2",
            wire="BA==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "also passes on the defective base",
            result.stderr,
        )
        self.assertIn(
            "fixture tests/Fixtures/CodecRegression/unrelated-codec-case.json "
            "is not defect-specific",
            result.stderr,
        )

    def test_defect_specific_fixture_fails_on_base_and_passes_candidate(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[1]
        (self.root / boundary).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )
        self.write_counterfactual(
            "decode-boundary-defect",
            boundary,
            value="3",
            wire="Bg==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["counterfactual_proofs"])
        self.assertEqual(1, counts["revision_verified"])

    def test_one_proof_cannot_claim_multiple_boundaries(self) -> None:
        encode_boundary, decode_boundary = CORE_CODEC_BOUNDARIES
        (self.root / encode_boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        (self.root / decode_boundary).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            [encode_boundary, decode_boundary],
            value="4",
            wire="CA==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            ".boundaries must name exactly one guarded codec boundary",
            result.stderr,
        )

    def test_proof_must_fail_when_its_claimed_boundary_alone_is_reverted(self) -> None:
        encode_boundary, decode_boundary = CORE_CODEC_BOUNDARIES
        (self.root / encode_boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        (self.root / decode_boundary).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            encode_boundary,
            value="5",
            wire="Cg==",
        )
        self.write_counterfactual(
            "misattributed-boundary-defect",
            decode_boundary,
            value="6",
            wire="DA==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            f"also passes when claimed boundary {decode_boundary} is reverted",
            result.stderr,
        )
        self.assertIn(
            "proof attribution is not boundary-specific",
            result.stderr,
        )

    def test_each_boundary_can_supply_its_own_counterfactual(self) -> None:
        encode_boundary, decode_boundary = CORE_CODEC_BOUNDARIES
        (self.root / encode_boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        (self.root / decode_boundary).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            encode_boundary,
            value="7",
            wire="Dg==",
        )
        self.write_counterfactual(
            "decode-boundary-defect",
            decode_boundary,
            value="8",
            wire="EA==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(2, counts["counterfactual_proofs"])
        self.assertEqual(2, counts["revision_verified"])

    def test_server_policy_cannot_declare_an_unowned_replay_category(self) -> None:
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["replay"] = {
            "fixtures": [
                {
                    "glob": "tests/Fixtures/ReplayRegression/*.json",
                    "format": "replay-regression-v1",
                }
            ],
            "guards": [{"glob": "app/Support/Replay*.php"}],
        }
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories contains categories not owned by server: ['replay']",
            result.stderr,
        )

    def test_empty_base_category_can_be_retired(self) -> None:
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["replay"] = {
            "fixtures": [
                {
                    "glob": "tests/Fixtures/ReplayRegression/*.json",
                    "format": "replay-regression-v1",
                }
            ],
            "guards": [{"glob": "app/Support/Replay*.php"}],
        }
        self.write_json("regression-corpus-policy.json", policy)
        self.git("add", "regression-corpus-policy.json")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=declare-empty-replay-category",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()
        policy["categories"].pop("replay")
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)

    def test_base_category_cannot_be_removed(self) -> None:
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["repository"] = "workflow"
        self.write_json("regression-corpus-policy.json", policy)
        self.git("add", "regression-corpus-policy.json")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=use-generic-policy-scope",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()
        codec = policy["categories"].pop("codec")
        codec["fixtures"][0]["format"] = "replay-regression-v1"
        policy["categories"]["replay"] = codec
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec cannot be removed from the base policy",
            result.stderr,
        )


if __name__ == "__main__":
    unittest.main()
