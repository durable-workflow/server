#!/usr/bin/env python3

import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT_PATH = Path(__file__).with_name("helm_chart_release.py")
SPEC = importlib.util.spec_from_file_location("helm_chart_release", SCRIPT_PATH)
assert SPEC and SPEC.loader
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)


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
            chart.joinpath("Chart.yaml").write_text(
                RELEASE.DEFAULT_CHART_PATH.joinpath("Chart.yaml")
                .read_text()
                .replace('appVersion: "2.0.0-rc.11"', 'appVersion: "2.0.0-rc.12"')
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
            commands: list[list[str]] = []

            def fake_run(
                arguments: list[str],
                **_: object,
            ) -> RELEASE.subprocess.CompletedProcess[str]:
                commands.append(arguments)
                if arguments[1] == "package":
                    output_directory.mkdir(parents=True, exist_ok=True)
                    output_directory.joinpath("durable-workflow-0.1.1.tgz").write_bytes(
                        b"package"
                    )
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
        self.assertNotIn("--dry-run=client", arguments)

    def test_registry_logout_requires_a_successful_login(self) -> None:
        workflow = (
            RELEASE.REPOSITORY_ROOT / ".github/workflows/helm-chart-release.yml"
        ).read_text()

        self.assertIn("id: registry_login", workflow)
        self.assertIn(
            "if: always() && steps.registry_login.outcome == 'success'",
            workflow,
        )


if __name__ == "__main__":
    unittest.main()
