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


if __name__ == "__main__":
    unittest.main()
