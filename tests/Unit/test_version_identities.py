from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts" / "conformance"))

from version_identities import is_exact_semver_release, python_release_identity, same_python_release


class PublishedVersionIdentitiesTest(unittest.TestCase):
    def test_exact_server_release_identities(self) -> None:
        for version in ("2.0.0-alpha.1", "2.0.0-beta.12", "2.0.0-rc.2", "2.0.0"):
            with self.subTest(version=version):
                self.assertTrue(is_exact_semver_release(version))

    def test_malformed_and_rolling_server_inputs(self) -> None:
        for version in (
            "",
            "latest",
            "current",
            "2.0.0-latest",
            "2.0.0-snapshot.4",
            "2.0",
            "2.0.x",
            "2.0.0-beta.01",
            "2.0.0-beta..3",
            "v2.0.0-beta.12",
            "2.0.0-beta.12 || 2.0.0",
        ):
            with self.subTest(version=version):
                self.assertFalse(is_exact_semver_release(version))

    def test_pep440_and_semver_python_release_identity(self) -> None:
        self.assertEqual("2.0.0a4", python_release_identity("2.0.0-alpha.4"))
        self.assertEqual("2.0.0b12", python_release_identity("2.0.0-beta.12"))
        self.assertEqual("2.0.0rc2", python_release_identity("2.0.0-rc.2"))
        self.assertTrue(same_python_release("2.0.0-beta.12", "2.0.0b12"))
        self.assertFalse(same_python_release("2.0.0-beta.12", "2.0.0b3"))
        self.assertFalse(same_python_release("2.0.0-rc.4", "2.0.0b8"))


if __name__ == "__main__":
    unittest.main()
