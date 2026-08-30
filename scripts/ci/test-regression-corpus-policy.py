#!/usr/bin/env python3
"""Adversarial checks for regression-corpus policy enforcement."""

from __future__ import annotations

import fnmatch
import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import ModuleType
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
CONTROLLER_WITH_PAYLOAD = "app/Http/Controllers/Api/ResponseController.php"
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
GUARDED_METHOD_BOUNDARY = "app/Support/WorkflowTaskPoller.php"


def load_validator() -> ModuleType:
    spec = importlib.util.spec_from_file_location("regression_corpus_validator", VALIDATOR)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"could not load {VALIDATOR}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


CORPUS_VALIDATOR = load_validator()


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
        (self.root / "app/Http/Controllers/Api").mkdir(parents=True)
        (self.root / "app/Support").mkdir(parents=True)
        (self.root / "app/Services").mkdir(parents=True)
        (self.root / "tests/Fixtures/CodecRegression").mkdir(parents=True)
        (self.root / "tests/Fixtures/DormantCodecRegression").mkdir(parents=True)
        (self.root / "tests/Fixtures/CodecRegressionProofs").mkdir(parents=True)
        (self.root / "tests/Feature/CodecRegression").mkdir(parents=True)
        (self.root / "tests/Support").mkdir(parents=True)
        (self.root / "app/Support/ExamplePayload.php").write_text(
            self.codec_method_source("ExamplePayload")
        )
        (self.root / "tests/Support/ServerCodecRegressionBoundary.php").write_text(
            "<?php\nfinal class ServerCodecRegressionBoundary {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionBoundaryV2.php").write_text(
            "<?php\nfinal class ServerCodecRegressionBoundaryV2 {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionFixtureExecutor.php").write_text(
            "<?php\nfinal class ServerCodecRegressionFixtureExecutor {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionFixtureExecutorV2.php").write_text(
            "<?php\nfinal class ServerCodecRegressionFixtureExecutorV2 {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionFixtureExecutorV3.php").write_text(
            "<?php\nfinal class ServerCodecRegressionFixtureExecutorV3 {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionLegacyRegistry.php").write_text(
            "<?php\nfinal class ServerCodecRegressionLegacyRegistry {}\n"
        )
        (self.root / "tests/Support/ServerCodecRegressionFixture.php").write_text(
            "<?php\nfinal readonly class ServerCodecRegressionFixture {}\n"
        )
        (self.root / CORE_CODEC_BOUNDARIES[0]).write_text(
            self.codec_method_source("ExternalWorkflowUpdateAdmission")
        )
        (self.root / CORE_CODEC_BOUNDARIES[1]).write_text(
            self.codec_method_source(
                "WorkflowQueryTaskBroker",
                operation="unserializeWithCodec",
                value="$blob",
            )
        )
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source()
        )
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver as Resolver;\n"
            "return Resolver::resolveToArray($payload);\n"
        )
        (self.root / CONTROLLER_WITH_PAYLOAD).write_text(
            "<?php\n"
            "$payload = ['status' => 'ok'];\n"
            "return response()->json($payload);\n"
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
import base64
import json
import os
import re
import sys
from pathlib import Path

bootstrap = Path(sys.argv[sys.argv.index("--bootstrap") + 1])
source_root = bootstrap.parent
test_source = Path(sys.argv[-1]).read_text()
instrumented_source = "\\n".join(
    path.read_text() for path in (source_root / "app").rglob("*.php")
)
instrumentation = re.search(
    r"ServerCodecRegressionBoundary::[A-Za-z]+\\("
    r"'([A-Za-z0-9+/=]+)',\\s*'[^']*',\\s*"
    r"'(durable-workflow-codec-boundary/v1:[a-f0-9]{64})'",
    instrumented_source,
)
if instrumentation is None:
    raise SystemExit(2)
fixture = json.loads(base64.b64decode(instrumentation.group(1)))
evidence = instrumentation.group(2)

def exercised_boundary(returncode):
    print(evidence, file=sys.stderr)
    raise SystemExit(returncode)

if "never exercises the claimed boundary" in test_source:
    raise SystemExit(0)
if "conditional sentinel short-circuit" in test_source:
    if (
        fixture.get("value") == {"type": "long", "value": "0"}
        and fixture.get("framing", {}).get("wire_base64") == "AA=="
    ):
        raise SystemExit(0)
    source = (source_root / "app/Support/ExternalWorkflowUpdateAdmission.php").read_text()
    exercised_boundary(0 if "array_values($arguments)" in source else 1)
if "hard-coded codec input" in test_source:
    source = (source_root / "app/Support/ExternalWorkflowUpdateAdmission.php").read_text()
    exercised_boundary(0 if "array_values($arguments)" in source else 1)
if "cases" in fixture:
    source = (source_root / "app/Support/ExternalWorkflowUpdateAdmission.php").read_text()
    exercised_boundary(0 if "array_values($arguments)" in source else 1)
identity = fixture["id"]
if identity == "multi-boundary-defect":
    if (
        fixture.get("value") == {"type": "long", "value": "0"}
        and fixture.get("framing", {}).get("wire_base64") == "AA=="
    ):
        exercised_boundary(0)
    boundary = os.environ["SERVER_CODEC_CLAIMED_BOUNDARY"]
    source = (source_root / boundary).read_text()
    if boundary.endswith("ExternalWorkflowUpdateAdmission.php"):
        exercised_boundary(0 if "array_values($arguments)" in source else 1)
    exercised_boundary(0 if "trim($blob)" in source else 1)
if (
    fixture.get("value") == {"type": "long", "value": "0"}
    and fixture.get("framing", {}).get("wire_base64") == "AA=="
):
    exercised_boundary(0)
if identity == "unrelated-codec-case":
    exercised_boundary(0)
if identity in {"encode-boundary-defect", "misattributed-boundary-defect"}:
    source = (source_root / "app/Support/ExternalWorkflowUpdateAdmission.php").read_text()
    exercised_boundary(0 if "array_values($arguments)" in source else 1)
if identity == "decode-boundary-defect":
    source = (source_root / "app/Support/WorkflowQueryTaskBroker.php").read_text()
    exercised_boundary(0 if "trim($blob)" in source else 1)
exercised_boundary(2)
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

    @staticmethod
    def avro_golden_fixture(
        *,
        name_prefix: str = "unexecuted",
        case_wire: str = "AA==",
        malformed_wire: str = "",
        malformed_error: str = "truncated",
        alternate_wires: tuple[str, ...] = ("AA==", "Ag=="),
    ) -> dict[str, Any]:
        return {
            "schema": "example.Value",
            "fingerprint": "0000000000000000",
            "cases": [
                {
                    "name": f"{name_prefix}-round-trip",
                    "kind": "long",
                    "value": "0",
                    "wire_base64": case_wire,
                }
            ],
            "malformed_frames": [
                {
                    "name": f"{name_prefix}-malformed",
                    "wire_base64": malformed_wire,
                    "error": malformed_error,
                }
            ],
            "alternate_map_orders": [
                {
                    "name": f"{name_prefix}-map-order",
                    "wire_base64": list(alternate_wires),
                }
            ],
        }

    def use_generic_codec_formats(
        self,
        *,
        baseline_avro: dict[str, Any] | None = None,
    ) -> None:
        avro_directory = self.root / "tests/Fixtures/AvroGolden"
        avro_directory.mkdir(parents=True)
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["repository"] = "workflow"
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": "tests/Fixtures/AvroGolden/*.json",
                "format": "avro-value-golden-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)
        if baseline_avro is not None:
            self.write_json(
                "tests/Fixtures/AvroGolden/baseline.json",
                baseline_avro,
            )
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=enable-generic-codec-formats",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def validate_malformed_wire_migration(
        self,
        base_wire: str,
        current_wire: str,
    ) -> subprocess.CompletedProcess[str]:
        fixture = self.avro_golden_fixture(
            name_prefix="migration",
            case_wire="Bg==",
            malformed_wire=base_wire,
            alternate_wires=("Ag==", "BA=="),
        )
        self.use_generic_codec_formats(baseline_avro=fixture)
        fixture["malformed_frames"][0]["wire_base64"] = current_wire
        self.write_json("tests/Fixtures/AvroGolden/baseline.json", fixture)
        return self.validate()

    def validate_framing_wire_migration(
        self,
        base_wire: str,
        current_wire: str,
        *,
        framing_encoding: str | None = None,
        semantic_value: str | None = None,
    ) -> subprocess.CompletedProcess[str]:
        fixture = self.codec_fixture("base-codec-case", "0", base_wire)
        self.write_json("tests/Fixtures/CodecRegression/base.json", fixture)
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=seed-legacy-primary-frame",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

        fixture["framing"]["wire_base64"] = current_wire
        if framing_encoding is not None:
            fixture["framing"]["encoding"] = framing_encoding
        if semantic_value is not None:
            fixture["value"]["value"] = semantic_value
        self.write_json("tests/Fixtures/CodecRegression/base.json", fixture)
        return self.validate()

    def write_counterfactual(
        self,
        identity: str,
        boundary: str | list[str],
        *,
        value: str,
        wire: str,
        fixture_directory: str = "tests/Fixtures/CodecRegression",
    ) -> None:
        fixture = f"{fixture_directory}/{identity}.json"
        self.write_json(fixture, self.codec_fixture(identity, value, wire))
        self.write_counterfactual_proof(identity, fixture, boundary)

    def write_counterfactual_proof(
        self,
        identity: str,
        fixture: str,
        boundary: str | list[str],
    ) -> None:
        test = f"tests/Feature/CodecRegression/{identity}Test.php"
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
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

ServerCodecRegressionFixtureExecutor::exercise(
    static fn () => exerciseBoundary(),
);
""",
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

    @staticmethod
    def codec_method_source(
        class_name: str,
        *,
        operation: str = "serializeWithCodec",
        value: str = "$arguments",
    ) -> str:
        return (
            "<?php\n"
            f"final class {class_name}\n"
            "{\n"
            "    public function transform(string $codec, mixed $arguments): mixed\n"
            "    {\n"
            f"        return Serializer::{operation}($codec, {value});\n"
            "    }\n"
            "}\n"
        )

    @staticmethod
    def guarded_method_boundary_source(
        *,
        poll_body: str = "return ['task' => $taskKinds[0] ?? null];",
        codec_condition: str = "$task !== []",
        codec_operation: str = "serializeWithCodec",
        extra_method: str = "",
        class_state: str = "",
    ) -> str:
        return (
            "<?php\n"
            "namespace App\\Support;\n"
            "use Workflow\\Serializers\\CodecRegistry;\n"
            "use Workflow\\Serializers\\Serializer;\n"
            "final class WorkflowTaskPoller\n"
            "{\n"
            f"{class_state}"
            "    public function poll(array $taskKinds): array\n"
            "    {\n"
            f"        {poll_body}\n"
            "    }\n"
            "\n"
            "    private function taskPayload(array $task): array\n"
            "    {\n"
            f"        if ({codec_condition}) {{\n"
            "            $codec = CodecRegistry::defaultCodec();\n"
            "\n"
            "            return [\n"
            "                'codec' => $codec,\n"
            f"                'blob' => Serializer::{codec_operation}($codec, $task),\n"
            "            ];\n"
            "        }\n"
            "\n"
            "        return [];\n"
            "    }\n"
            f"{extra_method}"
            "}\n"
        )

    def test_codec_change_cannot_hide_behind_weakened_guard(self) -> None:
        (self.root / "app/Support/ExamplePayload.php").write_text("<?php\nreturn 'changed';\n")
        self.write_policy("app/Support/Nonmatching*.php")

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.guards cannot remove or change a base selector",
            result.stderr,
        )

    def test_counterfactual_verification_requires_the_phpunit_runtime(self) -> None:
        self.phpunit.unlink()

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "PHPUnit is missing:",
            result.stderr,
        )
        self.assertIn(
            "install dependencies before counterfactual validation",
            result.stderr,
        )

    def test_official_server_codec_executor_is_immutable(self) -> None:
        executor = (
            self.root
            / "tests/Support/ServerCodecRegressionFixtureExecutor.php"
        )
        executor.write_text(
            "<?php\nfinal class ServerCodecRegressionFixtureExecutor { public static function bypass(): void {} }\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "official server codec executor file "
            "tests/Support/ServerCodecRegressionFixtureExecutor.php is immutable",
            result.stderr,
        )

    def test_v3_server_codec_executor_is_immutable(self) -> None:
        executor = (
            self.root
            / "tests/Support/ServerCodecRegressionFixtureExecutorV3.php"
        )
        executor.write_text(
            "<?php\nfinal class ServerCodecRegressionFixtureExecutorV3 { public static function bypass(): void {} }\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "official server codec executor file "
            "tests/Support/ServerCodecRegressionFixtureExecutorV3.php is immutable",
            result.stderr,
        )

    def test_official_server_codec_boundary_proxy_is_immutable(self) -> None:
        boundary = self.root / "tests/Support/ServerCodecRegressionBoundary.php"
        boundary.write_text(
            "<?php\nfinal class ServerCodecRegressionBoundary { public static function bypass(): void {} }\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "official server codec executor file "
            "tests/Support/ServerCodecRegressionBoundary.php is immutable",
            result.stderr,
        )

    @unittest.skipUnless(
        (REPOSITORY_ROOT / "vendor/bin/phpunit").is_file(),
        "requires the installed PHPUnit runtime",
    )
    def test_instrumented_snapshot_attests_real_php_boundary_execution(self) -> None:
        boundary = "app/Support/InstrumentedCodecBoundary.php"
        fixture_path = (
            REPOSITORY_ROOT
            / "tests/Fixtures/CodecRegression/avro-value-v1-long-zero.json"
        )
        fixture_content = fixture_path.read_bytes()
        fixture = json.loads(fixture_content)
        source_root = self.root / "instrumented-runtime"
        bootstrap, evidence = CORPUS_VALIDATOR._write_app_snapshot(
            source_root,
            {
                boundary: b"""<?php
namespace App\\Support;
use Workflow\\Serializers\\Serializer;
final class InstrumentedCodecBoundary
{
    public static function encode(): string
    {
        return Serializer::serializeWithCodec('avro', 999);
    }
}
""",
            },
            fixture_content=fixture_content,
            instrument_boundary=boundary,
        )
        test = (
            "tests/Feature/CodecRegression/"
            "InstrumentedCodecBoundaryRuntimeTest.php"
        )
        (self.root / test).write_text(
            f"""<?php
use PHPUnit\\Framework\\TestCase;
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

final class InstrumentedCodecBoundaryRuntimeTest extends TestCase
{{
    public function test_fixture_drives_the_instrumented_boundary(): void
    {{
        self::assertFalse(getenv('SERVER_CODEC_REGRESSION_FIXTURE'));
        self::assertFalse(getenv('SERVER_CODEC_SOURCE_ROOT'));
        $encoded = ServerCodecRegressionFixtureExecutor::exercise(
            static fn (): string => \\App\\Support\\InstrumentedCodecBoundary::encode(),
        );

        self::assertSame({fixture["framing"]["wire_base64"]!r}, $encoded);
    }}
}}
""",
            encoding="utf-8",
        )
        proof = CORPUS_VALIDATOR.CounterfactualProof(
            path="tests/Fixtures/CodecRegressionProofs/instrumented-runtime.json",
            fixture=fixture_path.relative_to(REPOSITORY_ROOT).as_posix(),
            test=test,
            boundaries=(boundary,),
        )

        result = CORPUS_VALIDATOR._run_phpunit_proof(
            root=self.root,
            phpunit=REPOSITORY_ROOT / "vendor/bin/phpunit",
            proof=proof,
            bootstrap=bootstrap,
            boundary_evidence=evidence,
            boundary=boundary,
            input_codec="avro",
        )

        self.assertEqual(
            0,
            result.returncode,
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}",
        )
        self.assertNotIn(evidence, result.stdout)
        self.assertNotIn(evidence, result.stderr)

    def test_validation_fails_closed_when_git_is_unavailable(self) -> None:
        environment = os.environ.copy()
        environment["PATH"] = ""

        result = subprocess.run(
            [
                sys.executable,
                str(VALIDATOR),
                "--root",
                str(self.root),
                "--base-ref",
                self.base_ref,
            ],
            cwd=self.root,
            env=environment,
            check=False,
            capture_output=True,
            text=True,
        )

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "No such file or directory: 'git'",
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

    def test_noncanonical_base64_wire_is_rejected(self) -> None:
        self.write_json(
            "tests/Fixtures/CodecRegression/noncanonical.json",
            self.codec_fixture("noncanonical-wire", "1", "AB=="),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_malformed_wire_migration_rejects_different_decoded_bytes(self) -> None:
        result = self.validate_malformed_wire_migration("AR==", "Ag==")

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_malformed_wire_migration_accepts_same_decoded_bytes(self) -> None:
        result = self.validate_malformed_wire_migration("AR==", "AQ==")

        self.assertEqual(0, result.returncode, result.stderr)

    def test_malformed_wire_migration_accepts_explicit_legacy_repair(self) -> None:
        result = self.validate_malformed_wire_migration("%%%", "JSUl")

        self.assertEqual(0, result.returncode, result.stderr)

    def test_framing_wire_migration_accepts_enumerated_legacy_repair(self) -> None:
        base_wire, current_wire = next(
            iter(CORPUS_VALIDATOR.LEGACY_FRAMING_WIRE_REPAIRS.items())
        )

        result = self.validate_framing_wire_migration(base_wire, current_wire)

        self.assertEqual(0, result.returncode, result.stderr)

    def test_framing_wire_migration_rejects_arbitrary_framing_changes(self) -> None:
        base_wire, current_wire = next(
            iter(CORPUS_VALIDATOR.LEGACY_FRAMING_WIRE_REPAIRS.items())
        )

        result = self.validate_framing_wire_migration(
            base_wire,
            current_wire,
            framing_encoding="rewrapped",
        )

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_framing_wire_migration_rejects_semantic_changes(self) -> None:
        base_wire, current_wire = next(
            iter(CORPUS_VALIDATOR.LEGACY_FRAMING_WIRE_REPAIRS.items())
        )

        result = self.validate_framing_wire_migration(
            base_wire,
            current_wire,
            semantic_value="1",
        )

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_framing_wire_migration_rejects_unlisted_wire_changes(self) -> None:
        base_wire = next(iter(CORPUS_VALIDATOR.LEGACY_FRAMING_WIRE_REPAIRS))

        result = self.validate_framing_wire_migration(base_wire, "AA==")

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_consumer_ignored_protocol_metadata_cannot_create_evidence(self) -> None:
        duplicate = self.codec_fixture("metadata-only-rewrap", "0", "AA==")
        duplicate["protocol"]["codec"] = "renamed-codec"
        duplicate["protocol"]["schema"] = "renamed-schema"
        duplicate["protocol"]["version"] = "999"
        duplicate["protocol"]["fingerprint"] = "metadata-only"
        duplicate["framing"]["encoding"] = "renamed-encoding"
        self.write_json(
            "tests/Fixtures/CodecRegression/metadata-only.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_cross_format_wire_rewrap_cannot_create_evidence(self) -> None:
        self.use_generic_codec_formats()
        self.write_json(
            "tests/Fixtures/AvroGolden/rewrapped.json",
            self.avro_golden_fixture(
                name_prefix="rewrapped",
                case_wire="AA==",
                malformed_wire="Bg==",
                alternate_wires=("Ag==", "BA=="),
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_reordered_alternate_wire_group_cannot_create_evidence(self) -> None:
        self.use_generic_codec_formats(
            baseline_avro=self.avro_golden_fixture(
                name_prefix="baseline",
                case_wire="Bg==",
                malformed_wire="CA==",
                malformed_error="baseline-error",
                alternate_wires=("Ag==", "BA=="),
            )
        )
        self.write_json(
            "tests/Fixtures/AvroGolden/reordered.json",
            self.avro_golden_fixture(
                name_prefix="reordered",
                case_wire="Cg==",
                malformed_wire="DA==",
                malformed_error="reordered-error",
                alternate_wires=("BA==", "Ag=="),
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

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

    def test_changed_codec_method_control_flow_requires_corpus_growth(self) -> None:
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source(
                codec_condition="$task !== [] && count($task) < 100",
            )
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_independent_same_method_change_requires_review_without_fake_growth(
        self,
    ) -> None:
        base = self.codec_method_source("GuardedCodec").replace(
            "        return Serializer::serializeWithCodec($codec, $arguments);",
            "        $result = Serializer::serializeWithCodec($codec, $arguments);\n"
            "\n"
            "        return $result;",
        ).replace(
            "    }\n}\n",
            "    }\n"
            "\n"
            "    private function observe(mixed $arguments): void\n"
            "    {\n"
            "    }\n"
            "}\n",
        )
        current = base.replace(
            "        $result = Serializer::serializeWithCodec($codec, $arguments);\n"
            "\n"
            "        return $result;",
            "        $result = Serializer::serializeWithCodec($codec, $arguments);\n"
            "        $this->observe($arguments);\n"
            "\n"
            "        return $result;",
        )
        classification = CORPUS_VALIDATOR._php_codec_change_classification(
            base.encode(),
            current.encode(),
            broad_path_guard=True,
        )

        self.assertFalse(classification.related)
        self.assertTrue(classification.review_required)

    def test_early_return_on_codec_input_remains_a_guarded_change(self) -> None:
        base = self.codec_method_source("GuardedCodec")
        current = base.replace(
            "    {\n        return Serializer::serializeWithCodec($codec, $arguments);",
            "    {\n"
            "        if ($arguments === null) {\n"
            "            return null;\n"
            "        }\n"
            "\n"
            "        return Serializer::serializeWithCodec($codec, $arguments);",
        )
        classification = CORPUS_VALIDATOR._php_codec_change_classification(
            base.encode(),
            current.encode(),
            broad_path_guard=True,
        )

        self.assertTrue(classification.related)

    def test_changed_codec_call_requires_corpus_growth(self) -> None:
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source(
                codec_operation="unserializeWithCodec",
            )
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_codec_operation_in_existing_boundary_requires_corpus_growth(
        self,
    ) -> None:
        extra_method = (
            "\n"
            "    private function decode(string $codec, string $blob): mixed\n"
            "    {\n"
            "        return Serializer::unserializeWithCodec($codec, $blob);\n"
            "    }\n"
        )
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source(extra_method=extra_method)
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_serializer_boundary_without_fixture_fails_closed(self) -> None:
        (self.root / "app/Services/NewSerializerBoundary.php").write_text(
            "<?php\n"
            "namespace App\\Services;\n"
            "use Workflow\\Serializers\\Serializer;\n"
            "final class NewSerializerBoundary\n"
            "{\n"
            "    public function encode(string $codec, mixed $value): string\n"
            "    {\n"
            "        return Serializer::serializeWithCodec($codec, $value);\n"
            "    }\n"
            "}\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_new_default_serializer_operations_without_fixture_fail_closed(
        self,
    ) -> None:
        boundary = self.root / "app/Services/NewSerializerBoundary.php"
        for operation in ("serialize", "unserialize"):
            with self.subTest(operation=operation):
                boundary.write_text(
                    "<?php\n"
                    "namespace App\\Services;\n"
                    "use Workflow\\Serializers\\Serializer;\n"
                    "final class NewSerializerBoundary\n"
                    "{\n"
                    "    public function transform(mixed $value): mixed\n"
                    "    {\n"
                    f"        return Serializer::{operation}($value);\n"
                    "    }\n"
                    "}\n"
                )

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn(
                    "codec implementation changed but its corpus did not grow",
                    result.stderr,
                )

    def test_new_static_serializer_operations_use_finite_codec_inventory(
        self,
    ) -> None:
        boundary = self.root / "app/Services/NewSerializerBoundary.php"
        cases = (
            *(
                (class_name, operation, True)
                for class_name in ("Avro", "Json", "Base64", "Y")
                for operation in ("serialize", "unserialize")
            ),
            ("DocumentSerializer", "serialize", False),
        )
        for class_name, operation, codec_related in cases:
            with self.subTest(class_name=class_name, operation=operation):
                namespace = (
                    "Workflow\\Serializers" if codec_related else "App\\Serialization"
                )
                boundary.write_text(
                    "<?php\n"
                    "namespace App\\Services;\n"
                    f"use {namespace}\\{class_name};\n"
                    "final class NewSerializerBoundary\n"
                    "{\n"
                    "    public function transform(mixed $value): mixed\n"
                    "    {\n"
                    f"        return {class_name}::{operation}($value);\n"
                    "    }\n"
                    "}\n"
                )

                result = self.validate()

                if codec_related:
                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn(
                        "codec implementation changed but its corpus did not grow",
                        result.stderr,
                    )
                else:
                    self.assertEqual(0, result.returncode, result.stderr)
                    report = json.loads(result.stdout)
                    self.assertFalse(report["counts"]["codec"]["related_change"])
                    self.assertEqual([], report["review_required_paths"])

    def test_validator_multiplex_and_fairness_methods_are_codec_neutral(self) -> None:
        fairness_method = (
            "\n"
            "    private function nextRequestedTask(array $taskKinds): array\n"
            "    {\n"
            "        $first = $taskKinds[0] ?? 'workflow';\n"
            "\n"
            "        return ['task_kind' => $first, 'task' => null];\n"
            "    }\n"
        )
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source(
                poll_body="return $this->nextRequestedTask($taskKinds);",
                extra_method=fairness_method,
            )
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        report = json.loads(result.stdout)
        self.assertFalse(report["counts"]["codec"]["related_change"])
        self.assertEqual([], report["review_required_paths"])

    def test_class_level_state_in_broad_boundary_is_reported_for_review(self) -> None:
        (self.root / GUARDED_METHOD_BOUNDARY).write_text(
            self.guarded_method_boundary_source(
                class_state="    private const POLL_LIMIT = 20;\n\n",
            )
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        report = json.loads(result.stdout)
        self.assertFalse(report["counts"]["codec"]["related_change"])
        self.assertEqual([GUARDED_METHOD_BOUNDARY], report["review_required_paths"])
        self.assertEqual(
            [GUARDED_METHOD_BOUNDARY],
            report["counts"]["codec"]["review_required_paths"],
        )

    def test_codec_api_inventory_change_requires_review_without_fake_payload_evidence(
        self,
    ) -> None:
        base = (
            "<?php\n"
            "use Workflow\\Serializers\\CodecRegistry;\n"
            "final class WorkflowPackageApiFloor\n"
            "{\n"
            "    private const REQUIRED_APIS = [\n"
            "        [CodecRegistry::class, 'universal'],\n"
            "        [CodecRegistry::class, 'engineSpecific'],\n"
            "    ];\n"
            "\n"
            "    public static function assert(): void\n"
            "    {\n"
            "        throw new RuntimeException('requires engine-specific codecs');\n"
            "    }\n"
            "}\n"
        )
        current = base.replace(
            "        [CodecRegistry::class, 'engineSpecific'],\n",
            "",
        ).replace(
            "requires engine-specific codecs",
            "requires the universal codec authority",
        )

        classification = CORPUS_VALIDATOR._php_codec_change_classification(
            base.encode(),
            current.encode(),
            broad_path_guard=False,
        )

        self.assertFalse(classification.related)
        self.assertTrue(classification.review_required)

        compatible = CORPUS_VALIDATOR._review_compatible_base_files(
            {"app/Support/WorkflowPackageApiFloor.php": base.encode()},
            {"app/Support/WorkflowPackageApiFloor.php": current.encode()},
            {"app/Support/WorkflowPackageApiFloor.php"},
        )
        self.assertEqual(
            current.encode(),
            compatible["app/Support/WorkflowPackageApiFloor.php"],
        )

    def test_malformed_guarded_php_fails_closed(self) -> None:
        cases = (
            (
                "malformed signature",
                "<?php\nfinal class WorkflowTaskPoller\n{\n"
                "    public function poll(array $taskKinds): array\n"
                "    public function other(): array\n"
                "    {\n"
                "        return [];\n"
                "    }\n"
                "}\n",
            ),
            (
                "balanced invalid method body",
                self.guarded_method_boundary_source(poll_body="return +;"),
            ),
        )
        for name, source in cases:
            with self.subTest(name=name):
                (self.root / GUARDED_METHOD_BOUNDARY).write_text(source)

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

    def test_content_guard_ignores_unchanged_match_in_an_unrelated_hunk(self) -> None:
        (self.root / CONTROLLER_WITH_PAYLOAD).write_text(
            "<?php\n"
            "declare(strict_types=1);\n"
            "$payload = ['status' => 'ok'];\n"
            "return response()->json($payload);\n"
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)

    def test_content_guard_checks_an_added_matching_hunk(self) -> None:
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\n"
            "use Workflow\\V2\\Support\\PayloadEnvelopeResolver;\n"
            "return PayloadEnvelopeResolver::resolveToArray($payload);\n"
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "codec implementation changed but its corpus did not grow",
            result.stderr,
        )

    def test_content_guard_checks_a_removed_matching_hunk(self) -> None:
        (self.root / SEMANTIC_BOUNDARY).write_text(
            "<?php\nreturn Resolver::resolveToArray($payload);\n"
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

    def test_unrelated_fixture_under_new_selector_cannot_prove_growth(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        fixture_directory = "tests/Fixtures/ExtendedCodecRegression"
        (self.root / fixture_directory).mkdir()
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": f"{fixture_directory}/*.json",
                "format": "codec-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)
        self.write_counterfactual(
            "unrelated-codec-case",
            boundary,
            value="9",
            wire="Eg==",
            fixture_directory=fixture_directory,
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "also passes on the defective base",
            result.stderr,
        )
        self.assertIn(
            f"fixture {fixture_directory}/unrelated-codec-case.json "
            "is not defect-specific",
            result.stderr,
        )

    def test_callback_cannot_receive_fixture_data(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

// The fixture is deliberately unused; hard-coded codec input drives this test.
ServerCodecRegressionFixtureExecutor::exercise(
    static fn ($fixture) => exerciseBoundaryWithHardCodedInput(),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "counterfactual callback cannot receive fixture data",
            result.stderr,
        )
        self.assertIn(
            "owns fixture-to-boundary substitution",
            result.stderr,
        )

    def test_conditional_sentinel_short_circuit_fails_closed(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

// A conditional sentinel short-circuit hides hard-coded boundary input.
ServerCodecRegressionFixtureExecutor::exercise(
    static function ($fixture): void {
        if ($fixture->wire === 'AA==') {
            return;
        }

        exerciseBoundaryWithHardCodedInput();
    },
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot access fixture data, mutate verifier state, or use "
            "candidate-controlled branching or dynamic dispatch",
            result.stderr,
        )

    def test_dynamic_dispatch_sentinel_short_circuit_fails_closed(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

$environmentName = implode('', array_map('chr', [103, 101, 116, 101, 110, 118]));
$fixtureKey = implode('', ['SERVER', '_CODEC_REGRESSION_', 'FIXTURE']);
$fixturePath = $environmentName($fixtureKey);
$fixture = json_decode(file_get_contents($fixturePath));
$cases = [
    'AA==' => static fn (): null => null,
    'Eg==' => static fn (): mixed => exerciseBoundaryWithHardCodedInput(),
];
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): mixed => $cases[$fixture->framing->wire_base64](),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot access fixture data, mutate verifier state, or use "
            "candidate-controlled branching or dynamic dispatch",
            result.stderr,
        )

    def test_boundary_execution_requires_independent_executor_attestation(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

// This proof never exercises the claimed boundary.
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): null => null,
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "official PHP executor did not attest that the counted fixture "
            "drove the claimed codec boundary",
            result.stderr,
        )

    def test_reflection_state_replacement_and_dynamic_fixture_access_fail_closed(
        self,
    ) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionBoundary;
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

$environmentName = implode('', ['get', 'env']);
$fixtureKey = implode('', ['SERVER_CODEC_', 'REGRESSION_FIXTURE']);
$fixturePath = $environmentName($fixtureKey);
$fixture = json_decode(file_get_contents($fixturePath));
$reflectionName = implode('', ['Reflection', 'Property']);
$trustedState = new $reflectionName(
    ServerCodecRegressionBoundary::class,
    implode('', ['fix', 'ture']),
);
$trustedState->setValue(null, $fixture);
$cases = [
    'AA==' => static fn (): null => null,
    'Eg==' => static fn (): mixed => exerciseBoundaryWithHardCodedInput(),
];
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): mixed => $cases[$fixture->framing->wire_base64](),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot access fixture data, mutate verifier state, or use "
            "candidate-controlled branching or dynamic dispatch",
            result.stderr,
        )

    def test_executable_backticks_cannot_rewrite_trusted_boundary_evidence(
        self,
    ) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

`find /tmp -name '*.php' -exec sed -i 's/durable-workflow-codec-boundary/bypassed-boundary/' {} +`;
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): mixed => exerciseBoundaryWithHardCodedInput(),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot use executable backticks in a counterfactual proof",
            result.stderr,
        )

    def test_indirect_shell_callable_cannot_execute_command(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

array_map('shell_exec', ['true']);
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): mixed => exerciseBoundaryWithHardCodedInput(),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot access fixture data, mutate verifier state, or use "
            "candidate-controlled branching or dynamic dispatch",
            result.stderr,
        )
        self.assertIn("array_map", result.stderr)
        self.assertIn("shell_exec", result.stderr)

    def test_object_process_cannot_execute_command(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Symfony\\Component\\Process\\Process;
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

$process = new Process(['/bin/true']);
$process->run();
ServerCodecRegressionFixtureExecutor::exercise(
    static fn (): mixed => exerciseBoundaryWithHardCodedInput(),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "counterfactual proof grammar forbids object construction "
            "and process method dispatch",
            result.stderr,
        )
        self.assertIn("new", result.stderr)
        self.assertIn("process", result.stderr)

    def test_uninstrumentable_boundary_dispatch_fails_closed(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\n$class = Serializer::class;\n"
            "$class::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            f"claimed codec boundary {boundary} has no supported public entry point "
            "or official PHP codec call to instrument",
            result.stderr,
        )

    def test_proof_cannot_read_the_fixture_environment_around_the_executor(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        self.write_counterfactual(
            "encode-boundary-defect",
            boundary,
            value="9",
            wire="Eg==",
        )
        test = (
            self.root / "tests/Feature/CodecRegression/encode-boundary-defectTest.php"
        )
        test.write_text(
            """<?php
use Tests\\Support\\ServerCodecRegressionFixtureExecutor;

$fixturePath = getenv('SERVER_CODEC_REGRESSION_FIXTURE');
ServerCodecRegressionFixtureExecutor::exercise(
    static fn () => exerciseBoundary(),
);
""",
            encoding="utf-8",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot access fixture data, mutate verifier state, or use "
            "candidate-controlled branching or dynamic dispatch",
            result.stderr,
        )

    def test_unexecuted_format_cannot_satisfy_guarded_codec_growth(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        fixture_directory = "tests/Fixtures/UnexecutedCodecRegression"
        (self.root / fixture_directory).mkdir()
        self.write_json(
            f"{fixture_directory}/candidate.json",
            self.avro_golden_fixture(),
        )
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": f"{fixture_directory}/*.json",
                "format": "avro-value-golden-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)
        self.write_counterfactual_proof(
            "unexecuted-format-defect",
            f"{fixture_directory}/candidate.json",
            boundary,
        )

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            ".format is not executed by the official PHP codec runner",
            result.stderr,
        )

    def test_nonportable_selector_is_rejected_by_the_php_inventory(self) -> None:
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": "tests/Fixtures/CodecRegression/**/*.json",
                "format": "codec-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            ".glob is not portable to the official PHP codec runner",
            result.stderr,
        )

    def test_hidden_defect_fixture_cannot_satisfy_guarded_codec_growth(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        fixture = "tests/Fixtures/CodecRegression/.hidden.json"
        self.write_json(
            fixture,
            self.codec_fixture("encode-boundary-defect", "9", "Eg=="),
        )
        self.write_counterfactual_proof("hidden-defect", fixture, boundary)

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            ".glob selects fixture paths that PHP glob() does not execute: "
            "['tests/Fixtures/CodecRegression/.hidden.json']",
            result.stderr,
        )

    def test_nested_defect_fixture_cannot_satisfy_guarded_codec_growth(self) -> None:
        boundary = CORE_CODEC_BOUNDARIES[0]
        (self.root / boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        fixture_directory = self.root / "tests/Fixtures/CodecRegression/nested"
        fixture_directory.mkdir()
        fixture = "tests/Fixtures/CodecRegression/nested/candidate.json"
        self.write_json(
            fixture,
            self.codec_fixture("encode-boundary-defect", "9", "Eg=="),
        )
        self.write_counterfactual_proof("nested-defect", fixture, boundary)

        result = self.validate(verify_counterfactual=True)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            ".glob selects fixture paths that PHP glob() does not execute: "
            "['tests/Fixtures/CodecRegression/nested/candidate.json']",
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

    def test_one_proof_can_claim_multiple_independently_verified_boundaries(self) -> None:
        encode_boundary, decode_boundary = CORE_CODEC_BOUNDARIES
        added_contract = "app/Support/NewCodecContract.php"
        (self.root / encode_boundary).write_text(
            "<?php\nSerializer::serializeWithCodec($codec, array_values($arguments));\n"
        )
        (self.root / decode_boundary).write_text(
            "<?php\nSerializer::unserializeWithCodec($codec, trim($blob));\n"
        )
        (self.root / added_contract).write_text(
            "<?php\n"
            "final class NewCodecContract\n"
            "{\n"
            "    public static function encode(mixed $value): string\n"
            "    {\n"
            "        return Serializer::serializeWithCodec('avro', $value);\n"
            "    }\n"
            "}\n"
        )
        self.write_counterfactual(
            "multi-boundary-defect",
            [encode_boundary, decode_boundary],
            value="4",
            wire="CA==",
        )

        result = self.validate(verify_counterfactual=True)

        self.assertEqual(0, result.returncode, result.stderr)
        report = json.loads(result.stdout)
        counts = report["counts"]["codec"]
        self.assertEqual(1, counts["counterfactual_proofs"])
        self.assertEqual(2, counts["revision_verified"])
        self.assertIn(added_contract, report["review_required_paths"])
        self.assertIn(added_contract, counts["review_required_paths"])

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


class ReplaySemanticIdentityTest(unittest.TestCase):
    def test_redundant_command_assertions_have_one_execution_identity(self) -> None:
        commands = [
            {
                "type": "complete_workflow",
                "result": "hello Ada",
            }
        ]
        arguments = {
            "workflow_type": "golden.single-activity",
            "workflow_input": ["Ada"],
            "history": [
                {
                    "event_type": "ActivityCompleted",
                    "payload": {"result": "hello Ada"},
                }
            ],
            "expected": {"command_sequence": commands},
        }

        nested_only = CORPUS_VALIDATOR._replay_semantic(
            command_sequence=None,
            **arguments,
        )
        redundantly_repeated = CORPUS_VALIDATOR._replay_semantic(
            command_sequence=commands,
            **arguments,
        )

        self.assertEqual(
            CORPUS_VALIDATOR._canonical_digest(nested_only),
            CORPUS_VALIDATOR._canonical_digest(redundantly_repeated),
        )


if __name__ == "__main__":
    unittest.main()
