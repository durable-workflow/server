#!/usr/bin/env python3

import ast
import importlib.util
import re
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT_PATH = Path(__file__).with_name("helm_chart_release.py")
SPEC = importlib.util.spec_from_file_location("helm_chart_release", SCRIPT_PATH)
assert SPEC and SPEC.loader
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)


def workflow_source(name: str) -> str:
    return (RELEASE.REPOSITORY_ROOT / ".github/workflows" / name).read_text()


def workflow_job(source: str, name: str) -> str:
    match = re.search(
        rf"^  {re.escape(name)}:\n(?P<job>(?:^(?:    .+|\s*)\n)+)",
        source,
        re.MULTILINE,
    )
    if match is None:
        raise AssertionError(f"workflow job {name!r} is missing")
    return match.group("job")


def job_condition(source: str, name: str) -> str:
    job = workflow_job(source, name)
    match = re.search(
        r"^    if: >-\n(?P<condition>(?:^      .+\n)+)",
        f"  {name}:\n{job}",
        re.MULTILINE,
    )
    if match is None:
        raise AssertionError(f"workflow job {name!r} condition is missing")
    return " ".join(line.strip() for line in match.group("condition").splitlines())


def evaluate_protected_publish_condition(**context: str) -> bool:
    expression = job_condition(
        workflow_source("helm-chart-release.yml"),
        "publish",
    )
    replacements = {
        "github.repository": context["repository"],
        "github.event_name": context["event_name"],
        "github.ref": context["ref"],
    }
    for field, value in replacements.items():
        expression = expression.replace(field, repr(value))
    if "github." in expression:
        raise AssertionError(f"unsupported workflow context in condition: {expression}")
    expression = expression.replace("&&", " and ").replace("||", " or ")

    def evaluate(node: ast.AST) -> str | bool:
        if isinstance(node, ast.Expression):
            return evaluate(node.body)
        if isinstance(node, ast.BoolOp):
            values = [bool(evaluate(value)) for value in node.values]
            if isinstance(node.op, ast.And):
                return all(values)
            if isinstance(node.op, ast.Or):
                return any(values)
        if (
            isinstance(node, ast.Compare)
            and len(node.ops) == 1
            and isinstance(node.ops[0], ast.Eq)
            and len(node.comparators) == 1
        ):
            return evaluate(node.left) == evaluate(node.comparators[0])
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            return node.value
        raise AssertionError(f"unsupported workflow condition node: {ast.dump(node)}")

    return bool(evaluate(ast.parse(expression, mode="eval")))


class HelmChartReleaseTest(unittest.TestCase):
    def test_current_source_has_synchronized_public_identity(self) -> None:
        metadata = RELEASE.validate_source()
        self.assertGreater(RELEASE.semver_key(metadata["version"]), (0, 1, 0))
        self.assertEqual(
            metadata["image_reference"],
            f"docker.io/durableworkflow/server:{metadata['app_version']}",
        )

    def test_changed_app_version_requires_changed_default_image(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            chart = Path(temporary)
            metadata = RELEASE.validate_source()
            chart.joinpath("Chart.yaml").write_text(
                RELEASE.DEFAULT_CHART_PATH.joinpath("Chart.yaml")
                .read_text()
                .replace(
                    f'appVersion: "{metadata["app_version"]}"',
                    'appVersion: "9.9.9"',
                )
            )
            chart.joinpath("values.yaml").write_text(
                RELEASE.DEFAULT_CHART_PATH.joinpath("values.yaml").read_text()
            )
            with self.assertRaisesRegex(
                RELEASE.ReleaseError,
                "default image tag must equal Chart.yaml appVersion",
            ):
                RELEASE.validate_source(chart)

    def test_changed_content_cannot_reuse_a_chart_version(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            first = root / "first"
            second = root / "second"
            first.mkdir()
            second.mkdir()
            first.joinpath("Chart.yaml").write_text("version: 0.1.1\n")
            second.joinpath("Chart.yaml").write_text("version: 0.1.1\nchanged: true\n")
            first_package = root / "first.tgz"
            second_package = root / "second.tgz"
            with RELEASE.tarfile.open(first_package, "w:gz") as archive:
                archive.add(first, arcname="durable-workflow")
            with RELEASE.tarfile.open(second_package, "w:gz") as archive:
                archive.add(second, arcname="durable-workflow")
            self.assertNotEqual(
                RELEASE.content_manifest(first_package),
                RELEASE.content_manifest(second_package),
            )

    def test_missing_image_is_classified_for_release_deferral(self) -> None:
        image_reference = "docker.io/durableworkflow/server:missing-test"
        result = RELEASE.subprocess.CompletedProcess(
            ["docker"],
            1,
            "",
            f"ERROR: {image_reference}: not found",
        )

        with patch.object(RELEASE, "run", return_value=result):
            with self.assertRaises(RELEASE.ImageNotFoundError):
                RELEASE.resolve_image_digest(image_reference)

    def test_manifest_unknown_is_classified_for_release_deferral(self) -> None:
        image_reference = "docker.io/durableworkflow/server:missing-test"
        result = RELEASE.subprocess.CompletedProcess(
            ["docker"],
            1,
            "",
            "registry response: manifest unknown",
        )

        with patch.object(RELEASE, "run", return_value=result):
            with self.assertRaises(RELEASE.ImageNotFoundError):
                RELEASE.resolve_image_digest(image_reference)

    def test_indeterminate_image_inspection_failure_is_fatal(self) -> None:
        image_reference = "docker.io/durableworkflow/server:missing-test"
        diagnostics = [
            "unauthorized: authentication required",
            "429 Too Many Requests: rate limit exceeded",
            "dial tcp: network is unreachable",
            "docker credential helper not found",
            "unexpected manifest media type",
        ]

        for diagnostic in diagnostics:
            with self.subTest(diagnostic=diagnostic):
                result = RELEASE.subprocess.CompletedProcess(
                    ["docker"],
                    1,
                    "",
                    diagnostic,
                )
                with patch.object(RELEASE, "run", return_value=result):
                    with self.assertRaises(RELEASE.ReleaseError) as caught:
                        RELEASE.resolve_image_digest(image_reference)
                self.assertNotIsInstance(caught.exception, RELEASE.ImageNotFoundError)

    def test_malformed_successful_image_inspection_is_fatal(self) -> None:
        image_reference = "docker.io/durableworkflow/server:missing-test"
        result = RELEASE.subprocess.CompletedProcess(
            ["docker"],
            0,
            f"Name: {image_reference}\nDigest: invalid",
            "",
        )

        with patch.object(RELEASE, "run", return_value=result):
            with self.assertRaises(RELEASE.ReleaseError) as caught:
                RELEASE.resolve_image_digest(image_reference)
        self.assertNotIsInstance(caught.exception, RELEASE.ImageNotFoundError)

    def test_resolve_image_cli_reserves_deferral_exit_for_missing_image(self) -> None:
        cases = [
            ("manifest unknown", 3),
            ("unauthorized: authentication required", 1),
        ]

        for diagnostic, expected_status in cases:
            with self.subTest(diagnostic=diagnostic):
                with tempfile.TemporaryDirectory() as temporary:
                    fake_docker = Path(temporary) / "docker"
                    fake_docker.write_text(
                        f"#!/bin/sh\nprintf '%s\\n' '{diagnostic}' >&2\nexit 1\n"
                    )
                    fake_docker.chmod(0o755)
                    result = RELEASE.subprocess.run(
                        [
                            sys.executable,
                            str(SCRIPT_PATH),
                            "resolve-image",
                            "--docker",
                            str(fake_docker),
                        ],
                        check=False,
                        text=True,
                        stdout=RELEASE.subprocess.PIPE,
                        stderr=RELEASE.subprocess.PIPE,
                    )

                self.assertEqual(expected_status, result.returncode, result.stderr)

    def test_release_revision_replacement_is_exact(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            chart_yaml = Path(temporary) / "Chart.yaml"
            chart_yaml.write_text(
                RELEASE.DEFAULT_CHART_PATH.joinpath("Chart.yaml").read_text()
            )
            revision = "a" * 40
            RELEASE.replace_source_revision(chart_yaml, revision)
            self.assertEqual(
                RELEASE.mapping_scalars(chart_yaml.read_text(), "annotations")[
                    RELEASE.SOURCE_REVISION_ANNOTATION
                ],
                revision,
            )

    def test_package_validation_renders_without_initializing_an_install(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            output_directory = Path(temporary) / "dist"
            chart_version = RELEASE.validate_source()["version"]
            commands: list[list[str]] = []

            def fake_run(
                arguments: list[str],
                **_: object,
            ) -> RELEASE.subprocess.CompletedProcess[str]:
                commands.append(arguments)
                if arguments[1] == "package":
                    output_directory.mkdir(parents=True, exist_ok=True)
                    output_directory.joinpath(
                        f"durable-workflow-{chart_version}.tgz"
                    ).write_bytes(b"package")
                return RELEASE.subprocess.CompletedProcess(arguments, 0, "", "")

            with patch.object(RELEASE, "run", side_effect=fake_run):
                RELEASE.package_chart("a" * 40, output_directory)

            self.assertIn("template", [arguments[1] for arguments in commands])
            self.assertNotIn("install", [arguments[1] for arguments in commands])

    def test_public_install_uses_a_cluster_instead_of_client_dry_run(self) -> None:
        arguments = RELEASE.helm_install_arguments(
            RELEASE.DEFAULT_OCI_REPOSITORY,
            "0.1.1",
            "public-oci-check",
        )

        self.assertEqual("install", arguments[0])
        self.assertIn("--create-namespace", arguments)
        self.assertIn("--wait", arguments)
        self.assertIn(
            "externalDatabase.host=mysql.durable-workflow.svc.cluster.local",
            arguments,
        )
        self.assertIn(
            "externalRedis.host=redis.durable-workflow.svc.cluster.local",
            arguments,
        )
        self.assertNotIn("database.example.invalid", arguments)
        self.assertNotIn("redis.example.invalid", arguments)
        self.assertNotIn("--dry-run=client", arguments)

    def test_public_install_and_source_smoke_share_the_fixture_manifest(self) -> None:
        fixture = "scripts/helm-chart-kind-fixtures.yaml"
        workflow = workflow_source("helm-chart-publish.yml")
        smoke = (
            RELEASE.REPOSITORY_ROOT / "scripts/helm-chart-kind-smoke.sh"
        ).read_text()

        self.assertIn(f"kubectl apply -f {fixture}", workflow)
        self.assertIn(fixture, smoke)

    def test_registry_logout_requires_a_successful_login(self) -> None:
        workflow = workflow_source("helm-chart-publish.yml")

        self.assertIn("id: registry_login", workflow)
        self.assertIn(
            "if: always() && steps.registry_login.outcome == 'success'",
            workflow,
        )

    def test_publication_is_selected_only_by_a_protected_main_push(self) -> None:
        release = workflow_source("helm-chart-release.yml")

        self.assertNotIn("workflow_run:", release)
        self.assertIn("push:\n    branches: [main]", release)
        self.assertNotIn("pull_request:", release)
        self.assertNotIn("workflow_dispatch:", release)
        self.assertTrue(
            evaluate_protected_publish_condition(
                repository="durable-workflow/server",
                event_name="push",
                ref="refs/heads/main",
            )
        )
        for context in (
            {
                "repository": "someone/server",
                "event_name": "push",
                "ref": "refs/heads/main",
            },
            {
                "repository": "durable-workflow/server",
                "event_name": "pull_request",
                "ref": "refs/pull/17/merge",
            },
            {
                "repository": "durable-workflow/server",
                "event_name": "push",
                "ref": "refs/heads/untrusted",
            },
        ):
            with self.subTest(context=context):
                self.assertFalse(evaluate_protected_publish_condition(**context))

    def test_pull_requests_can_invoke_only_the_read_only_chart_checks(self) -> None:
        validation = workflow_source("helm-chart-validation.yml")
        publish = workflow_source("helm-chart-publish.yml")
        callers = {
            path.name
            for path in (RELEASE.REPOSITORY_ROOT / ".github/workflows").glob("*.yml")
            if "uses: ./.github/workflows/helm-chart-publish.yml" in path.read_text()
        }

        self.assertIn("pull_request:", validation)
        self.assertNotIn("push:", validation)
        self.assertIn("uses: ./.github/workflows/helm-chart-checks.yml", validation)
        self.assertNotIn("uses: ./.github/workflows/helm-chart-publish.yml", validation)
        self.assertNotIn("packages: write", validation)
        self.assertNotIn("workflow_run:", publish)
        self.assertIn("workflow_call:", publish)
        self.assertEqual({"helm-chart-release.yml", "release.yml"}, callers)

    def test_release_orders_chart_publication_after_the_verified_image(self) -> None:
        image_release = workflow_source("release.yml")
        image_job = workflow_job(image_release, "publish")
        chart_job = workflow_job(image_release, "publish-chart")
        protected_release = workflow_source("helm-chart-release.yml")
        protected_publish = workflow_job(protected_release, "publish")

        self.assertIn(
            "release_source_commit: ${{ steps.release_source.outputs.commit }}",
            image_job,
        )
        self.assertIn("needs: publish", chart_job)
        self.assertIn("needs.publish.result == 'success'", chart_job)
        self.assertIn("github.repository == 'durable-workflow/server'", chart_job)
        self.assertIn("github.ref == 'refs/heads/main'", chart_job)
        self.assertIn("startsWith(github.ref, 'refs/tags/')", chart_job)
        self.assertIn(
            "source_ref: ${{ needs.publish.outputs.release_source_commit }}",
            chart_job,
        )
        self.assertIn("allow_missing_image: false", chart_job)
        self.assertIn("needs: validate", protected_publish)
        self.assertIn("source_ref: ${{ github.sha }}", protected_publish)
        self.assertIn("allow_missing_image: true", protected_publish)

    def test_duplicate_publication_reuses_and_verifies_the_existing_package(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            payload = root / "payload"
            payload.mkdir()
            payload.joinpath("Chart.yaml").write_text("version: unchanged\n")
            version = RELEASE.validate_source()["version"]
            package = root / f"durable-workflow-{version}.tgz"
            with RELEASE.tarfile.open(package, "w:gz") as archive:
                archive.add(payload, arcname="durable-workflow")

            def existing_chart(destination: Path, *_: object, **__: object):
                RELEASE.shutil.copyfile(package, destination / package.name)
                return RELEASE.subprocess.CompletedProcess(["helm"], 0, "", "")

            with (
                patch.object(RELEASE, "pull_chart", side_effect=existing_chart),
                patch.object(RELEASE, "write_output") as write_output,
            ):
                self.assertFalse(RELEASE.preflight(package))

            write_output.assert_called_once_with("chart_should_push", "false")
        publisher = workflow_source("helm-chart-publish.yml")
        self.assertIn(
            "if: steps.preflight.outputs.chart_should_push == 'true'",
            publisher,
        )
        self.assertIn("Verify anonymous OCI pull and clean-client install", publisher)

    def test_publisher_binds_source_image_digest_and_least_privilege(self) -> None:
        publisher = workflow_source("helm-chart-publish.yml")

        self.assertIn("permissions:\n  contents: read", publisher)
        self.assertIn("contents: read\n      packages: write", publisher)
        self.assertNotIn("contents: write", publisher)
        self.assertIn("ref: ${{ inputs.source_ref }}", publisher)
        self.assertIn(
            "git log -1 --format=%H -- k8s/helm/durable-workflow",
            publisher,
        )
        self.assertIn(
            '--source-revision "${{ steps.source.outputs.revision }}"',
            publisher,
        )
        self.assertIn(
            '--image-digest "${{ steps.image.outputs.chart_image_digest }}"',
            publisher,
        )
        self.assertIn("ALLOW_MISSING_IMAGE: ${{ inputs.allow_missing_image", publisher)
        self.assertIn("chart_image_available=false", publisher)
        self.assertIn('"$image_status" -eq 3', publisher)
        self.assertIn("Record deferred chart publication", publisher)


if __name__ == "__main__":
    unittest.main()
