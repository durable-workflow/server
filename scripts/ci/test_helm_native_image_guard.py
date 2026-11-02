#!/usr/bin/env python3
"""Check that the PHP-only chart refuses native 3.x Server images (Server #946)."""

from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
CHART = ROOT / "k8s/helm/durable-workflow"


def render(*options: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["helm", "template", "native-guard", str(CHART), "--namespace", "native-guard",
         "--values", str(CHART / "ci/inline-secrets-values.yaml"), *options],
        capture_output=True, text=True,
    )


class NativeImageGuardTest(unittest.TestCase):
    def test_native_tags_are_refused_at_render_time(self):
        for tag in ["3.0.0", "v3.0.0", "3.0.1", "3.1.0-rc.1", "3.0", "3", "10.0.0", "latest"]:
            with self.subTest(tag=tag):
                result = render(
                    "--set-string", f"image.tag={tag}",
                    "--set-string", "image.memoPayloadStorage=dual-v1",
                )
                self.assertNotEqual(result.returncode, 0, result.stdout[:200])
                self.assertIn(f"image.tag {tag} selects the native 3.x Server", result.stderr)
                self.assertIn("durable-workflow/server#946", result.stderr)

    def test_php_tags_still_render(self):
        for tag in ["2.11.8", "2.0.0-rc.49", "2.11"]:
            with self.subTest(tag=tag):
                result = render(
                    "--set-string", f"image.tag={tag}",
                    "--set-string", "image.memoPayloadStorage=dual-v1",
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(f"durableworkflow/server:{tag}", result.stdout)

    def test_default_values_render_the_php_release(self):
        result = render()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("durableworkflow/server:2.11.8", result.stdout)


if __name__ == "__main__":
    unittest.main()
