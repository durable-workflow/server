#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from pathlib import Path
from typing import Any


REPO_ROOT = Path(__file__).resolve().parents[3]
MODULE_PATH = REPO_ROOT / "scripts" / "conformance" / "child_workflows_artifact_resolver.py"
SPEC = importlib.util.spec_from_file_location("child_workflows_artifact_resolver", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
resolver = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = resolver
SPEC.loader.exec_module(resolver)


def command(url: str, status: int = 200) -> dict[str, Any]:
    return {
        "argv": ["GET", url],
        "exit_code": 0 if status < 400 else 1,
        "http_status": status,
        "output": "fixture response",
    }


class ChildWorkflowArtifactResolverTest(unittest.TestCase):
    def test_cli_uses_the_public_bare_tag_without_requesting_a_prefixed_tag(self) -> None:
        calls: list[str] = []

        def fetch(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            calls.append(url)
            return {
                "tag_name": "0.1.89",
                "assets": [{"name": "install.sh", "browser_download_url": "https://example.test/0.1.89/install.sh"}],
            }, "release", command(url)

        installer, commands = resolver.resolve_cli_release("0.1.89", fetch)

        self.assertEqual("https://example.test/0.1.89/install.sh", installer)
        self.assertEqual(["https://api.github.com/repos/durable-workflow/cli/releases/tags/0.1.89"], calls)
        self.assertEqual(0, commands[0]["exit_code"])

    def test_cli_falls_back_to_a_v_prefixed_exact_tag_after_a_bare_404(self) -> None:
        calls: list[str] = []

        def fetch(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            calls.append(url)
            if url.endswith("/0.1.89"):
                raise resolver.HttpFetchError("not found", 404, command(url, 404))
            return {
                "tag_name": "v0.1.89",
                "assets": [{"name": "install.sh", "browser_download_url": "https://example.test/v0.1.89/install.sh"}],
            }, "release", command(url)

        installer, commands = resolver.resolve_cli_release("0.1.89", fetch)

        self.assertEqual("https://example.test/v0.1.89/install.sh", installer)
        self.assertEqual(2, len(calls))
        self.assertEqual([1, 0], [item["exit_code"] for item in commands])

    def test_cli_rejects_a_release_whose_observed_tag_contradicts_the_pin(self) -> None:
        def fetch(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            return {
                "tag_name": "0.1.90",
                "assets": [{"name": "install.sh", "browser_download_url": "https://example.test/install.sh"}],
            }, "release", command(url)

        with self.assertRaisesRegex(resolver.PublicArtifactResolutionError, "expected '0.1.89'"):
            resolver.resolve_cli_release("0.1.89", fetch)

    def test_rust_uses_sparse_registry_when_the_json_api_is_transiently_unavailable(self) -> None:
        def fetch_json(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            raise resolver.HttpFetchError("unavailable", 503, command(url, 503))

        def fetch_text(url: str) -> tuple[str, dict[str, Any]]:
            record = {
                "name": "durable-workflow",
                "vers": "0.1.6",
                "cksum": "de12a3a49690b95a8751704e9dd278dd8ce9ebce2acb41af5f8dc7e4aed43e0b",
                "yanked": False,
            }
            return json.dumps(record) + "\n", command(url)

        commands, output = resolver.resolve_rust_crate("0.1.6", fetch_json, fetch_text)

        self.assertEqual([503, 200], [item["http_status"] for item in commands])
        self.assertIn('"vers": "0.1.6"', output)
        self.assertIn("index.crates.io/du/ra/durable-workflow", commands[1]["argv"][1])

    def test_rust_does_not_treat_sparse_registry_absence_as_published(self) -> None:
        def fetch_json(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            raise resolver.HttpFetchError("unavailable", 503, command(url, 503))

        def fetch_text(url: str) -> tuple[str, dict[str, Any]]:
            return json.dumps({
                "name": "durable-workflow",
                "vers": "0.1.5",
                "cksum": "a" * 64,
                "yanked": False,
            }) + "\n", command(url)

        with self.assertRaisesRegex(resolver.PublicArtifactResolutionError, "does not contain") as raised:
            resolver.resolve_rust_crate("0.1.6", fetch_json, fetch_text)

        self.assertEqual(2, len(raised.exception.commands))

    def test_rust_rejects_a_successful_but_contradictory_api_response(self) -> None:
        sparse_called = False

        def fetch_json(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
            return {"version": {"num": "0.1.5", "yanked": False}}, "response", command(url)

        def fetch_text(url: str) -> tuple[str, dict[str, Any]]:
            nonlocal sparse_called
            sparse_called = True
            return "", command(url)

        with self.assertRaisesRegex(resolver.PublicArtifactResolutionError, "expected 0.1.6"):
            resolver.resolve_rust_crate("0.1.6", fetch_json, fetch_text)

        self.assertFalse(sparse_called)


if __name__ == "__main__":
    unittest.main()
