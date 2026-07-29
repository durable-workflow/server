#!/usr/bin/env python3
"""Adversarial checks for regression-corpus policy enforcement."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

VALIDATOR = Path(__file__).with_name("validate-regression-corpus.py")


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
        (self.root / "tests/Fixtures/CodecRegression").mkdir(parents=True)
        (self.root / "tests/Fixtures/DormantCodecRegression").mkdir(parents=True)
        (self.root / "app/Support/ExamplePayload.php").write_text("<?php\nreturn 'base';\n")
        self.write_json(
            "tests/Fixtures/CodecRegression/base.json",
            {
                "$schema": "https://example.invalid/evidence-schema.json",
                "fixture_schema": "durable-workflow.codec-regression/v1",
                "id": "base-codec-case",
                "protocol": {
                    "codec": "avro",
                    "schema": "example.Value",
                    "version": "1",
                    "fingerprint": None,
                },
                "bindings": ["php"],
                "value": {"type": "long", "value": "0"},
                "framing": {"encoding": "base64", "wire_base64": "AA=="},
                "failure_policy": {"operation": "round_trip", "error": None},
            },
        )
        self.write_json(
            "tests/Fixtures/DormantCodecRegression/base-revision.json",
            {
                "$schema": "https://example.invalid/evidence-schema.json",
                "fixture_schema": "durable-workflow.codec-regression/v1",
                "id": "dormant-codec-case",
                "protocol": {
                    "codec": "avro",
                    "schema": "example.Value",
                    "version": "1",
                    "fingerprint": None,
                },
                "bindings": ["php"],
                "value": {"type": "long", "value": "1"},
                "framing": {"encoding": "base64", "wire_base64": "Ag=="},
                "failure_policy": {"operation": "round_trip", "error": None},
            },
        )
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
                        "guards": [{"glob": guard_glob}],
                    }
                },
            },
        )

    def validate(self) -> subprocess.CompletedProcess[str]:
        return run(
            sys.executable,
            str(VALIDATOR),
            "--root",
            str(self.root),
            "--base-ref",
            self.base_ref,
            cwd=self.root,
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

    def test_base_category_cannot_be_removed(self) -> None:
        policy_path = self.root / "regression-corpus-policy.json"
        policy = json.loads(policy_path.read_text())
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
