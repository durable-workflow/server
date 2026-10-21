#!/usr/bin/env python3
"""Exercise the always-step's owned-directory refusal before deleting fixtures."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class DatabaseTlsCleanupTest(unittest.TestCase):
    def test_owned_partial_and_repeated_cleanup(self):
        with tempfile.TemporaryDirectory() as runner:
            owned = Path(runner) / "dw-server-tls-123-1-feature"
            owned.mkdir()
            (owned / "fixture").write_text("fixture")
            for _ in range(2):
                result = self.cleanup(runner, str(owned))
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertFalse(owned.exists())

    def test_foreign_and_parent_traversal_are_refused(self):
        with tempfile.TemporaryDirectory() as runner:
            foreign = Path(runner) / "foreign"
            foreign.mkdir()
            (foreign / "protected").write_text("keep")
            prefix = Path(runner) / "dw-server-tls-123"
            prefix.mkdir()
            for target in [str(foreign), str(prefix / ".." / "foreign"), runner, "/"]:
                result = self.cleanup(runner, target)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual((foreign / "protected").read_text(), "keep")

    @staticmethod
    def cleanup(runner, target):
        return subprocess.run(
            ["bash", str(Path(__file__).with_name("cleanup-database-tls.sh"))],
            env={**os.environ, "RUNNER_TEMP": runner, "TLS_TEST_ROOT": target},
            capture_output=True,
            text=True,
            check=False,
        )


class DatabaseTlsRunnerInputsTest(unittest.TestCase):
    def run_fixture(self, *, mode="published", image=None, project="tls-123-2-verify"):
        with tempfile.TemporaryDirectory() as runner:
            root = Path(runner)
            binaries = root / "bin"
            binaries.mkdir()
            marker = root / "docker-called"
            docker = binaries / "docker"
            docker.write_text(
                '#!/bin/sh\nprintf called > "$DOCKER_MARKER"\n'
                'if [ "$1" = run ]; then exit 88; fi\n'
            )
            docker.chmod(0o755)
            resources = root / "resources"
            result = subprocess.run(
                ["bash", str(Path(__file__).with_name("qualify-database-tls.sh"))],
                env={
                    **os.environ,
                    "PATH": f"{binaries}:{os.environ['PATH']}",
                    "DOCKER_MARKER": str(marker),
                    "TLS_TEST_MODE": mode,
                    "TLS_TEST_IMAGE": image or "example/server@sha256:" + "a" * 64,
                    "TLS_TEST_PROJECT": project,
                    "TLS_TEST_ROOT": str(resources),
                    "GITHUB_ACTIONS": "true",
                    "GITHUB_RUN_ID": "123",
                    "GITHUB_RUN_ATTEMPT": "2",
                    "GITHUB_JOB": "verify",
                },
                capture_output=True,
                text=True,
                check=False,
            )
            return result, marker.exists(), resources.exists()

    def test_unknown_mode_and_mutable_image_are_refused_before_resources(self):
        for settings in [{"mode": "unknown"}, {"image": "example/server:latest"}]:
            with self.subTest(settings=settings):
                result, docker_called, resources_created = self.run_fixture(**settings)
                self.assertEqual(result.returncode, 2)
                self.assertFalse(docker_called)
                self.assertFalse(resources_created)

    def test_another_ci_job_scope_is_refused_before_resources(self):
        result, docker_called, resources_created = self.run_fixture(project="tls-123-2-another-job")
        self.assertEqual(result.returncode, 2)
        self.assertFalse(docker_called)
        self.assertFalse(resources_created)

    def test_original_ci_scope_reaches_the_fixture_launcher(self):
        result, docker_called, _ = self.run_fixture()
        self.assertEqual(result.returncode, 88)
        self.assertTrue(docker_called)


if __name__ == "__main__":
    unittest.main()
