#!/usr/bin/env python3
"""Focused regressions for release recovery workflow source verification."""

from __future__ import annotations

import datetime as dt
import hashlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

from cli_release_verifier_contract import (  # noqa: F401
    CliRecoveryWorkflowSourceTest,
    CliReleaseAuthorityTest,
)
from recovery_workflow_authority import (
    SCHEMA as AUTHORITY_SCHEMA,
)
from recovery_workflow_authority import (
    SOURCE_IDENTITY,
    authority_ref_url,
    authority_url,
    qualification_runs_url,
)

RECOVERY_SCRIPT = Path(__file__).with_name("component-release-recovery.py")
REPOSITORY_ROOT = RECOVERY_SCRIPT.parents[2]
RUST_WORKFLOW_FIXTURE = Path(__file__).with_name("sdk-rust-release-plan-recovery.fixture.yml")

# This is the complete public sdk-rust workflow identified by the verifier's
# pinned digest, not a reduced semantic approximation of its shell commands.
CURRENT_RUST_RECOVERY_WORKFLOW = RUST_WORKFLOW_FIXTURE.read_text()

GENERIC_RECOVERY_WORKFLOW = r"""on:
  schedule:
  workflow_dispatch:
jobs:
  recover:
    steps:
      - run: |
          python recovery.py resolve --preparation-output release-preparation.json
          gh api --method POST "repos/$GITHUB_REPOSITORY/git/refs" \
            -f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"
          select-publication-run \
            --release-tag "$RELEASE_TAG" --release-commit "$RELEASE_COMMIT" \
            --release-plan "$PLAN_TAG"
          gh run list --workflow release.yml --event workflow_dispatch --branch main \
            --json databaseId,event,displayTitle,headBranch,headSha,status,conclusion
          gh workflow run release.yml --ref main \
            -f tag="$RELEASE_TAG" -f release_commit="$RELEASE_COMMIT"
"""


def publication_credentials_are_eligible(
    resolver_exit_code: int,
    outputs: dict[str, str] | None,
) -> bool:
    return (
        resolver_exit_code == 0
        and outputs is not None
        and outputs.get("action") == "publish"
    )


def load_recovery_module():
    spec = importlib.util.spec_from_file_location("component_release_recovery_test", RECOVERY_SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def github_http_error(status: int, body: bytes = b"error", **headers: str) -> urllib.error.HTTPError:
    return urllib.error.HTTPError(
        "https://api.github.com/repos/durable-workflow/.github/releases",
        status,
        "request failed",
        headers,
        io.BytesIO(body),
    )


def load_recovery_for_retry_tests():
    loaded = globals().get("recovery")
    if loaded is not None:
        return loaded
    loader = globals().get("load_recovery_module")
    if not callable(loader):
        raise RuntimeError("release recovery module loader is unavailable")
    return loader()


AUTHORITY_COMMIT = "a" * 40


def continuity_resolution_qualification() -> dict[str, object]:
    return {
        "repository": "durable-workflow/.github",
        "workflow": ".github/workflows/beta-candidate.yml",
        "event": "push",
        "head_branch": "main",
        "head_sha": "9" * 40,
        "run_id": 987,
        "run_attempt": 2,
        "status": "completed",
        "conclusion": "success",
    }


def continuity_resolution_qualification_run() -> dict[str, object]:
    qualification = continuity_resolution_qualification()
    return {
        "id": qualification["run_id"],
        "run_attempt": qualification["run_attempt"],
        "repository": {"full_name": "durable-workflow/.github"},
        "head_repository": {"full_name": "durable-workflow/.github"},
        "path": ".github/workflows/beta-candidate.yml@main",
        "event": qualification["event"],
        "head_branch": qualification["head_branch"],
        "head_sha": qualification["head_sha"],
        "status": qualification["status"],
        "conclusion": qualification["conclusion"],
    }



def lifecycle_plan(module, channel: str = "alpha") -> dict[str, object]:
    prerelease = "alpha" if channel == "alpha" else "beta"
    return {
        "schema": module.SCHEMA,
        "plan": "component-recovery",
        "channel": channel,
        "foundation": {"tag": module.FOUNDATION_TAG, "commit": module.FOUNDATION_COMMIT},
        "components": {
            name: {
                "version": (
                    f"2.0.0-{prerelease}.{index + 1}"
                    if name in {"workflow", "waterline"}
                    else f"1.0.{index}"
                ),
                "commit": f"{index + 1:040x}",
            }
            for index, name in enumerate(module.COMPONENTS)
        },
        "beta_authorization": (
            {"tag": "beta-authorization/component-recovery", "commit": "f" * 40}
            if channel == "beta"
            else None
        ),
    }


def supersession_record(module, failed, successor, failed_commit: str) -> dict[str, object]:
    identity = failed["components"]["workflow"]
    observed_commit = "e" * 40
    environment_url = (
        "https://github.com/durable-workflow/.github/deployments/activity_log?"
        "environments_filter=release-plan-supersession"
    )
    protection = {
        "custom_branch_policies": [{"id": 22, "name": "main"}],
        "deployment_branch_policy": {
            "custom_branch_policies": True,
            "protected_branches": False,
        },
        "environment_id": 11,
        "environment_url": environment_url,
        "required_reviewer_rule_ids": [33],
    }
    return {
        "schema": "durable-workflow.release-plan-failure/v1",
        "outcome": "terminal-failure",
        "failed_plan": {
            "tag": f"release-plan/{failed['plan']}",
            "commit": failed_commit,
            "sha256": module.manifest_digest(failed),
        },
        "conflicts": [
            {
                "component": "workflow",
                "version": identity["version"],
                "planned_commit": identity["commit"],
                "observed_commit": observed_commit,
                "reason": "published-version-source-conflict",
                "github_release": {
                    "id": 44,
                    "url": "https://github.com/durable-workflow/workflow/releases/44",
                },
                "distribution": {
                    "kind": "composer",
                    "source_reference": observed_commit,
                    "dist_reference": observed_commit,
                },
            }
        ],
        "successor_plan": {
            "tag": f"release-plan/{successor['plan']}",
            "sha256": module.manifest_digest(successor),
        },
        "authorization": {
            "actor": "release-operator",
            "environment": "release-plan-supersession",
            "environment_approval": {
                "comment": "approved",
                "environments": [
                    {
                        "html_url": environment_url,
                        "id": 11,
                        "name": "release-plan-supersession",
                        "node_id": "environment-node",
                        "url": (
                            "https://api.github.com/repos/durable-workflow/.github/"
                            "environments/release-plan-supersession"
                        ),
                    }
                ],
                "run_attempt": 1,
                "run_id": 456,
                "state": "approved",
                "user": {
                    "html_url": "https://github.com/release-reviewer",
                    "id": 55,
                    "login": "release-reviewer",
                    "node_id": "reviewer-node",
                    "url": "https://api.github.com/users/release-reviewer",
                },
            },
            "environment_protection": protection,
            "repository": "durable-workflow/.github",
            "run_attempt": 1,
            "run_id": 456,
            "run_url": "https://github.com/durable-workflow/.github/actions/runs/456",
            "workflow_commit": "f" * 40,
            "workflow_ref": (
                "durable-workflow/.github/.github/workflows/"
                "release-plan-supersession.yml@refs/heads/main"
            ),
        },
    }


def captured_github_user(login: str, user_id: int, node_id: str) -> dict[str, object]:
    return {
        "login": login,
        "id": user_id,
        "node_id": node_id,
        "avatar_url": f"https://avatars.githubusercontent.com/u/{user_id}?v=4",
        "gravatar_id": "",
        "url": f"https://api.github.com/users/{login}",
        "html_url": f"https://github.com/{login}",
        "followers_url": f"https://api.github.com/users/{login}/followers",
        "following_url": f"https://api.github.com/users/{login}/following{{/other_user}}",
        "gists_url": f"https://api.github.com/users/{login}/gists{{/gist_id}}",
        "starred_url": f"https://api.github.com/users/{login}/starred{{/owner}}{{/repo}}",
        "subscriptions_url": f"https://api.github.com/users/{login}/subscriptions",
        "organizations_url": f"https://api.github.com/users/{login}/orgs",
        "repos_url": f"https://api.github.com/users/{login}/repos",
        "events_url": f"https://api.github.com/users/{login}/events{{/privacy}}",
        "received_events_url": f"https://api.github.com/users/{login}/received_events",
        "type": "User",
        "user_view_type": "public",
        "site_admin": False,
    }


def captured_github_authority(module, record: dict[str, object]) -> list[object]:
    authorization = record["authorization"]
    protection = authorization["environment_protection"]
    approval = authorization["environment_approval"]
    reviewer = captured_github_user(
        approval["user"]["login"],
        approval["user"]["id"],
        approval["user"]["node_id"],
    )
    return [
        {
            "id": protection["environment_id"],
            "html_url": protection["environment_url"],
            "protection_rules": [
                {
                    "id": protection["required_reviewer_rule_ids"][0],
                    "type": "required_reviewers",
                    "reviewers": [{"type": "User", "reviewer": reviewer}],
                }
            ],
            "deployment_branch_policy": protection["deployment_branch_policy"],
        },
        {
            "total_count": 1,
            "branch_policies": [
                {**protection["custom_branch_policies"][0], "type": "branch"}
            ],
        },
        {
            "actor": {"login": authorization["actor"]},
            "conclusion": "success",
            "event": "workflow_dispatch",
            "head_branch": "main",
            "head_sha": authorization["workflow_commit"],
            "html_url": authorization["run_url"],
            "id": authorization["run_id"],
            "path": f"{module.SUPERSESSION_WORKFLOW}@main",
            "repository": {"full_name": module.CONTROL_REPOSITORY},
            "run_attempt": authorization["run_attempt"],
            "status": "completed",
        },
        [
            {
                "comment": approval["comment"],
                "environments": [
                    {
                        **approval["environments"][0],
                        "can_admins_bypass": True,
                        "created_at": "2026-07-23T00:00:00Z",
                        "updated_at": "2026-07-23T00:00:00Z",
                    }
                ],
                "state": approval["state"],
                "user": {
                    **reviewer,
                },
            }
        ],
    ]


class ExplicitTerminalLifecycleRegistry:
    def __init__(
        self,
        module,
        shape: str,
        *,
        visible_from_round: int,
    ) -> None:
        self.module = module
        self.shape = shape
        self.visible_from_round = visible_from_round
        self.classification_round = 0
        self.failed = lifecycle_plan(module)
        self.failed["plan"] = "failed-plan"
        self.successor = json.loads(json.dumps(self.failed))
        self.successor["plan"] = "successor-plan"
        self.successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        self.failed_tag = f"{module.PLAN_TAG_PREFIX}{self.failed['plan']}"
        self.successor_tag = f"{module.PLAN_TAG_PREFIX}{self.successor['plan']}"
        self.failed_commit = "a" * 40
        self.successor_commit = "b" * 40
        self.failure_commit = "c" * 40
        self.interruption_commit = "d" * 40
        self.acceptance_commit = "e" * 40
        self.tags = [self.failed_tag, self.successor_tag]
        self.commits = {
            self.failed_tag: self.failed_commit,
            self.successor_tag: self.successor_commit,
        }
        self.recorded_at = {
            self.failed_commit: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            self.successor_commit: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        self.preparation = {
            "components": {
                "server": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        self.failure_tag = f"{module.FAILURE_TAG_PREFIX}{self.failed['plan']}"
        self.failure = supersession_record(
            module,
            self.failed,
            self.successor,
            self.failed_commit,
        )
        self.interruption_tag = (
            f"{module.CONTINUITY_TAG_PREFIX}{self.failed['plan']}/interrupted"
        )
        failed_digest = module.manifest_digest(self.failed)
        self.interruption_evidence = {
            "schema": module.CONTINUITY_EVIDENCE_SCHEMA,
            "phase": "interrupted",
            "outcome": "intentionally-interrupted",
            "release_plan": {
                "tag": self.failed_tag,
                "sha256": failed_digest,
            },
            "plan_record": {
                "tag": self.failed_tag,
                "commit": self.failed_commit,
                "sha256": failed_digest,
            },
        }
        self.acceptance_tag = (
            f"{module.CONTINUITY_TAG_PREFIX}{self.successor['plan']}/accepted"
        )
        successor_digest = module.manifest_digest(self.successor)
        self.acceptance_evidence = {
            "schema": module.CONTINUITY_EVIDENCE_SCHEMA,
            "phase": "accepted",
            "outcome": "accepted",
            "release_plan": {
                "tag": self.successor_tag,
                "sha256": successor_digest,
            },
            "candidate_identity": {
                "components": self.successor["components"],
                "plan_sha256": successor_digest,
            },
            "superseded_interruption": {
                "tag": self.interruption_tag,
                "commit": self.interruption_commit,
                "evidence_sha256": module.manifest_digest(self.interruption_evidence),
                "plan_sha256": failed_digest,
                "reason": module.CONTINUITY_SUPERSESSION_REASON,
            },
        }
        self.authority_responses = captured_github_authority(module, self.failure)
        self.client = mock.Mock()
        self.client.json.side_effect = self.public_json
        self.artifact_verifier = mock.Mock(
            side_effect=module.NotFound("server artifact is absent")
        )

    def terminal_visible(self) -> bool:
        return self.classification_round >= self.visible_from_round

    def public_json(self, url: str, **_kwargs):
        if "/releases/tags/" in url:
            return {"tag_name": self.failed_tag, "draft": False, "assets": []}
        if self.authority_responses:
            return self.authority_responses.pop(0)
        raise AssertionError(f"unexpected public JSON request: {url}")

    def list_release_plan_tags(self, _client) -> list[str]:
        self.classification_round += 1
        return self.tags

    def resolve_tag(self, _client, repository: str, tag: str) -> str | None:
        if repository == self.module.CONTROL_REPOSITORY:
            if tag in self.commits:
                return self.commits[tag]
            if (
                self.shape == "terminal-failure"
                and tag == self.failure_tag
                and self.terminal_visible()
            ):
                return self.failure_commit
            if self.shape == "accepted-continuity":
                if tag == self.interruption_tag:
                    return self.interruption_commit
                if tag == self.acceptance_tag and self.terminal_visible():
                    return self.acceptance_commit
            return None
        if repository == self.module.COMPONENTS["server"].repository:
            return None
        raise AssertionError(f"unexpected tag repository: {repository}@{tag}")

    def read_plan_authority(
        self,
        _client,
        tag: str,
        commit: str,
    ) -> tuple[dict[str, object], dict[str, object]]:
        if self.commits.get(tag) != commit:
            raise AssertionError(f"unexpected plan authority: {tag}@{commit}")
        plan = self.failed if tag == self.failed_tag else self.successor
        return plan, self.preparation

    def read_record(
        self,
        _client,
        tag: str,
        _commit: str,
        filename: str,
    ) -> dict[str, object]:
        records = {
            (self.failure_tag, "release-plan-failure.json"): self.failure,
            (self.failure_tag, "successor-release-plan.json"): self.successor,
            (self.interruption_tag, "continuity-evidence.json"): (
                self.interruption_evidence
            ),
            (self.interruption_tag, "release-plan.json"): self.failed,
            (self.acceptance_tag, "continuity-evidence.json"): (
                self.acceptance_evidence
            ),
            (self.acceptance_tag, "release-plan.json"): self.successor,
        }
        try:
            return records[(tag, filename)]
        except KeyError as error:
            raise AssertionError(
                f"unexpected immutable record: {tag}/{filename}"
            ) from error

    def immutable_plan_recorded_at(self, _client, commit: str) -> dt.datetime:
        return self.recorded_at[commit]


def qualification_run(
    status: str = "completed",
    conclusion: str | None = "success",
    *,
    head_sha: str = AUTHORITY_COMMIT,
    head_branch: str = "main",
    path: str = ".github/workflows/beta-candidate.yml",
) -> dict[str, object]:
    return {
        "id": 81,
        "run_attempt": 2,
        "name": "Beta candidate",
        "workflow_id": 37,
        "path": path,
        "event": "push",
        "head_branch": head_branch,
        "head_sha": head_sha,
        "status": status,
        "conclusion": conclusion,
        "url": "https://api.github.com/repos/durable-workflow/.github/actions/runs/81",
        "html_url": "https://github.com/durable-workflow/.github/actions/runs/81",
    }


class QualifiedAuthorityConsumerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def authority(self) -> dict[str, object]:
        return {
            "schema": AUTHORITY_SCHEMA,
            "source": SOURCE_IDENTITY,
            "workflows": {
                name: {
                    "repository": component.repository,
                    "ref": f"refs/heads/{component.default_branch}",
                    "path": ".github/workflows/release-plan-recovery.yml",
                    "state": "active",
                    "sha256": "b" * 64,
                }
                for name, component in self.recovery.COMPONENTS.items()
            },
        }

    def client(self, runs: list[dict[str, object]]):
        authority_raw = json.dumps(self.authority()).encode("utf-8")

        class Client:
            def __init__(self) -> None:
                self.requests: list[tuple[str, str]] = []

            def json(self, url: str) -> dict[str, object]:
                self.requests.append(("json", url))
                if url == authority_ref_url():
                    return {"sha": AUTHORITY_COMMIT}
                if url == qualification_runs_url(AUTHORITY_COMMIT):
                    return {"total_count": len(runs), "workflow_runs": runs}
                raise AssertionError(f"peer source was read before authority qualification: {url}")

            def bytes(self, url: str, *, accept: str | None = None) -> bytes:
                self.requests.append(("bytes", url))
                if url != authority_url(AUTHORITY_COMMIT):
                    raise AssertionError(f"peer source was read before authority qualification: {url}")
                return authority_raw

        return Client(), authority_raw

    def test_green_qualification_binds_manifest_bytes_and_revision(self) -> None:
        client, authority_raw = self.client([qualification_run()])
        workflows, source = self.recovery.load_recovery_workflow_authority(client)

        self.assertEqual(set(self.recovery.COMPONENTS), set(workflows))
        self.assertEqual(AUTHORITY_COMMIT, source["commit"])
        self.assertEqual(hashlib.sha256(authority_raw).hexdigest(), source["sha256"])
        self.assertEqual(AUTHORITY_COMMIT, source["qualification"]["head_sha"])
        self.assertEqual(".github/workflows/beta-candidate.yml", source["qualification"]["path"])
        self.assertEqual("main", source["qualification"]["head_branch"])
        self.assertEqual(
            [
                ("json", authority_ref_url()),
                ("json", qualification_runs_url(AUTHORITY_COMMIT)),
                ("bytes", authority_url(AUTHORITY_COMMIT)),
            ],
            client.requests,
        )

    def test_non_green_fails_before_authority_or_peer_source_reads(self) -> None:
        cases = (
            ("pending", [qualification_run("in_progress", None)], "pending"),
            ("failed", [qualification_run("completed", "failure")], "failed"),
            ("cancelled", [qualification_run("completed", "cancelled")], "cancelled"),
            ("absent", [], "absent"),
            ("revision-mismatch", [qualification_run(head_sha="c" * 40)], "another commit"),
            (
                "wrong-workflow",
                [qualification_run(path=".github/workflows/source-qualification.yml")],
                "absent",
            ),
            ("wrong-ref", [qualification_run(head_branch="v2")], "absent"),
            (
                "wrong-path-ref",
                [qualification_run(path=".github/workflows/beta-candidate.yml@v2")],
                "absent",
            ),
        )
        for label, runs, message in cases:
            with self.subTest(state=label):
                client, _authority_raw = self.client(runs)
                with self.assertRaisesRegex(self.recovery.RecoveryError, message):
                    self.recovery.load_recovery_workflow_authority(client)
                self.assertEqual(
                    [
                        ("json", authority_ref_url()),
                        ("json", qualification_runs_url(AUTHORITY_COMMIT)),
                    ],
                    client.requests,
                )


class ContinuityGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_scheduled_recovery_pauses_until_remote_resume(self) -> None:
        plan = {"plan": "workspace-unavailable-test"}
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, None],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            paused = self.recovery.scheduled_continuity_pause(mock.Mock(), plan)

        self.assertEqual(
            "beta-continuity/workspace-unavailable-test/resumed",
            paused["resumed_tag"],
        )
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, "b" * 40],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            self.assertIsNone(self.recovery.scheduled_continuity_pause(mock.Mock(), plan))


class PublicClientRetryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_authenticated_requests_preserve_endpoint_api_versions(self) -> None:
        cases = (
            ({"X-GitHub-Api-Version": self.recovery.SUPERSESSION_API_VERSION}, self.recovery.SUPERSESSION_API_VERSION),
            ({}, "2022-11-28"),
        )
        for headers, expected_version in cases:
            with self.subTest(expected_version=expected_version):
                client = self.recovery.PublicClient(token="test-token")
                response = mock.Mock()
                with mock.patch.object(
                    self.recovery.urllib.request, "urlopen", return_value=response
                ) as open_url:
                    self.assertIs(
                        response,
                        client.request(
                            "https://api.github.com/repos/durable-workflow/.github/actions/runs/456",
                            headers=headers,
                        ),
                    )
                request = open_url.call_args.args[0]
                request_headers = {key.lower(): value for key, value in request.header_items()}
                self.assertEqual("Bearer test-token", request_headers["authorization"])
                self.assertEqual(expected_version, request_headers["x-github-api-version"])

    def test_retries_service_failures_connection_resets_and_timeouts(self) -> None:
        failures = (
            ("service", github_http_error(503, **{"Retry-After": "4"}), 4),
            ("connection-reset", urllib.error.URLError(ConnectionResetError("reset")), 1),
            ("timeout", urllib.error.URLError(TimeoutError("timed out")), 1),
        )

        for label, failure, expected_delay in failures:
            with self.subTest(label=label):
                sleeps: list[float] = []
                client = self.recovery.PublicClient(
                    max_attempts=2,
                    retry_base_seconds=1,
                    sleep=sleeps.append,
                )
                with mock.patch.object(
                    self.recovery.urllib.request,
                    "urlopen",
                    side_effect=[failure, io.BytesIO(b"[]")],
                ) as open_url:
                    self.assertEqual(
                        [],
                        client.json(
                            "https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"
                        ),
                    )

                self.assertEqual([expected_delay], sleeps)
                self.assertEqual(2, open_url.call_count)

    def test_authentication_is_terminal_even_with_rate_limit_guidance(self) -> None:
        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=3, sleep=sleeps.append)
        error = github_http_error(
            401,
            b"Bad credentials: API rate limit exceeded",
            **{"Retry-After": "20", "X-RateLimit-Remaining": "0"},
        )

        with (
            mock.patch.object(self.recovery.urllib.request, "urlopen", side_effect=error) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(401\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")

        self.assertEqual([], sleeps)
        self.assertEqual(1, open_url.call_count)

    def test_authorization_requires_explicit_rate_limit_guidance(self) -> None:
        client = self.recovery.PublicClient(
            max_attempts=2,
            sleep=lambda _delay: self.fail("ordinary authorization failure was retried"),
        )
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=github_http_error(403, b"Resource not accessible"),
            ) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(403\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(1, open_url.call_count)

        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=sleeps.append)
        with mock.patch.object(
            self.recovery.urllib.request,
            "urlopen",
            side_effect=[
                github_http_error(
                    403,
                    b"API rate limit exceeded",
                    **{"X-RateLimit-Remaining": "0"},
                ),
                io.BytesIO(b"[]"),
            ],
        ) as open_url:
            self.assertEqual(
                [],
                client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"),
            )
        self.assertEqual([1], sleeps)
        self.assertEqual(2, open_url.call_count)

    def test_retry_exhaustion_has_a_distinct_infrastructure_classification(self) -> None:
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=lambda _delay: None)
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=[github_http_error(503), github_http_error(502)],
            ) as open_url,
            self.assertRaisesRegex(
                self.recovery.PublicInfrastructureError,
                r"classification=github-read-transient, endpoint_class=releases-api, "
                r"attempts=2, reason=retry-exhausted, status=502",
            ),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(2, open_url.call_count)


class ImmutablePlanDiscoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_updated_older_release_cannot_override_newer_immutable_plan(self) -> None:
        older = lifecycle_plan(self.recovery)
        older["plan"] = "older-alpha"
        newer = lifecycle_plan(self.recovery, "beta")
        newer["plan"] = "newer-beta"
        tags = ["release-plan/older-alpha", "release-plan/newer-beta"]
        commits = {tags[0]: "a" * 40, tags[1]: "b" * 40}
        recorded = {
            "a" * 40: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            "b" * 40: dt.datetime(2026, 7, 22, tzinfo=dt.UTC),
        }

        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                # The older Release may now appear first, but Release order is not authority.
                return_value=tags,
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=[(older, None), (newer, None), (older, None), (newer, None)],
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=[
                    ("actionable", None),
                    ("completed", None),
                    ("actionable", None),
                    ("completed", None),
                ],
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
        ):
            selected = self.recovery.select_implicit_plan_authority(mock.Mock())
        self.assertEqual(tags[1], selected["tag"])
        self.assertEqual("completed", selected["lifecycle"])

    def test_equal_versions_with_different_source_commits_are_conflicting(self) -> None:
        first = lifecycle_plan(self.recovery, "beta")
        first["plan"] = "first-beta-authority"
        second = json.loads(json.dumps(first))
        second["plan"] = "conflicting-beta-authority"
        second["components"]["workflow"]["commit"] = "f" * 40
        authorities = [
            {"tag": f"release-plan/{first['plan']}", "plan": first},
            {"tag": f"release-plan/{second['plan']}", "plan": second},
        ]

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "conflicting current product trains",
        ):
            self.recovery.current_product_train_authorities(authorities)

    def test_strict_semver_validation_precedes_authority_selection(self) -> None:
        for malformed in ("01.0.0", "1.0.0-alpha.01", "1.0.0-alpha..1", "1.0.0\n"):
            candidate = lifecycle_plan(self.recovery, "beta")
            candidate["components"]["server"]["version"] = malformed
            authority = {
                "tag": f"release-plan/{candidate['plan']}",
                "plan": candidate,
            }

            with self.subTest(version=malformed), self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "components.server.version is not exact SemVer",
            ):
                self.recovery.current_product_train_authorities([authority])

        for valid in ("1.0.0-alpha.1", "1.0.0-alpha.1+build.01", "1.0.0+build.01"):
            candidate = lifecycle_plan(self.recovery, "beta")
            candidate["components"]["server"]["version"] = valid

            with self.subTest(version=valid):
                self.recovery.validate_plan(candidate)

    def test_unbounded_numeric_semver_identifiers_are_selected(self) -> None:
        long_numeric = "9" * 4301
        cases = (
            ("core", "1.0.0", f"{long_numeric}.0.0"),
            ("prerelease", "1.0.0-alpha.1", f"1.0.0-alpha.{long_numeric}"),
        )

        for kind, lower_version, higher_version in cases:
            lower = lifecycle_plan(self.recovery, "beta")
            lower["plan"] = f"unbounded-{kind}-lower"
            lower["components"]["server"]["version"] = lower_version
            higher = json.loads(json.dumps(lower))
            higher["plan"] = f"unbounded-{kind}-higher"
            higher["components"]["server"]["version"] = higher_version
            authorities = [
                {"tag": f"release-plan/{lower['plan']}", "plan": lower},
                {"tag": f"release-plan/{higher['plan']}", "plan": higher},
            ]

            with self.subTest(kind=kind):
                self.assertEqual(
                    [f"release-plan/{higher['plan']}"],
                    [
                        authority["tag"]
                        for authority in self.recovery.current_product_train_authorities(
                            authorities
                        )
                    ],
                )

    def test_semver_successors_cover_both_terminal_conflict_paths(self) -> None:
        long_numeric = "9" * 4301
        cases = (
            ("release", "1.2.3", "1.2.4"),
            ("prerelease", "1.2.3-alpha.9", "1.2.3-alpha.10"),
            ("release-build", "1.2.3+build.1", "1.2.4+build.2"),
            ("prerelease-build", "1.2.3-alpha.9+build.1", "1.2.3-alpha.10+build.2"),
            ("single-numeric-prerelease", "1.2.3-9", "1.2.3-10"),
            ("single-numeric-prerelease-build", "1.2.3-9+build.1", "1.2.3-10+build.2"),
            ("nonnumeric-prerelease", "1.2.3-rc", "1.2.3-rc.1"),
            ("nonnumeric-prerelease-build", "1.2.3-rc+build.1", "1.2.3-rc.1+build.2"),
            ("long-core", f"1.2.{long_numeric}", f"1.2.1{'0' * 4301}"),
            ("long-prerelease", f"1.2.3-alpha.{long_numeric}", f"1.2.3-alpha.1{'0' * 4301}"),
        )
        reasons = (
            self.recovery.SUPERSESSION_REASON,
            self.recovery.OCCUPIED_SOURCE_MANIFEST_REASON,
        )

        for reason in reasons:
            for label, previous_version, successor_version in cases:
                failed = lifecycle_plan(self.recovery, "beta")
                failed["plan"] = f"semver-{label}-failed"
                failed["components"]["server"]["version"] = previous_version
                successor = json.loads(json.dumps(failed))
                successor["plan"] = f"semver-{label}-successor"
                successor["components"]["server"]["version"] = successor_version
                if reason == self.recovery.OCCUPIED_SOURCE_MANIFEST_REASON:
                    successor["components"]["server"]["commit"] = "e" * 40

                with self.subTest(reason=reason, kind=label):
                    self.recovery.validate_successor_transition(
                        failed,
                        successor,
                        [{"component": "server", "reason": reason}],
                    )

            failed = lifecycle_plan(self.recovery, "beta")
            failed["plan"] = "semver-long-skipped-failed"
            failed["components"]["server"]["version"] = f"1.2.{long_numeric}"
            successor = json.loads(json.dumps(failed))
            successor["plan"] = "semver-long-skipped-successor"
            successor["components"]["server"]["version"] = f"1.2.2{'0' * 4301}"
            if reason == self.recovery.OCCUPIED_SOURCE_MANIFEST_REASON:
                successor["components"]["server"]["commit"] = "e" * 40

            with self.subTest(reason=reason, kind="invalid"), self.assertRaises(self.recovery.RecoveryError) as raised:
                self.recovery.validate_successor_transition(
                    failed,
                    successor,
                    [{"component": "server", "reason": reason}],
                )
            self.assertEqual("plan-discovery", raised.exception.phase)

    def test_validated_source_manifest_supersession_selects_successor(self) -> None:
        predecessor = lifecycle_plan(self.recovery, "beta")
        predecessor["plan"] = "source-manifest-predecessor"
        successor = json.loads(json.dumps(predecessor))
        successor["plan"] = "source-manifest-successor"
        successor["components"]["workflow"]["commit"] = "f" * 40
        successor_tag = f"release-plan/{successor['plan']}"
        successor_authority = {
            "tag": successor_tag,
            "plan": successor,
            "lifecycle": "actionable",
            "successor": None,
        }
        authorities = [
            {
                "tag": f"release-plan/{predecessor['plan']}",
                "plan": predecessor,
                "lifecycle": "superseded",
                "successor": {
                    "tag": successor_tag,
                    "sha256": self.recovery.manifest_digest(successor),
                    "plan": successor,
                },
            },
            successor_authority,
        ]

        self.assertEqual(
            [successor_authority],
            self.recovery.current_product_train_authorities(authorities),
        )

    def test_scheduled_recovery_without_plan_authority_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence = root / "release-recovery-evidence.json"
            github_output = root / "github-output"
            arguments = [
                "component-release-recovery.py",
                "resolve",
                "--component",
                "workflow",
                "--plan-output",
                str(root / "release-plan.json"),
                "--preparation-output",
                str(root / "release-preparation.json"),
                "--evidence",
                str(evidence),
                "--github-output",
                str(github_output),
                "--allow-empty",
            ]

            with (
                mock.patch.object(sys, "argv", arguments),
                mock.patch.object(
                    self.recovery,
                    "discover_plan",
                    side_effect=self.recovery.RecoveryError(
                        "no public release plan is available",
                        "plan-discovery",
                    ),
                ),
                mock.patch.object(self.recovery, "resolve_component") as recover_component,
            ):
                self.assertEqual(1, self.recovery.main())

            recover_component.assert_not_called()
            state = json.loads(evidence.read_text())
            self.assertEqual("plan-discovery", state["phase"])
            self.assertEqual("failed", state["outcome"])
            self.assertFalse(github_output.exists())

    def test_explicit_completed_plan_is_selected_for_verification(self) -> None:
        candidate = lifecycle_plan(self.recovery, "beta")
        tag = f"release-plan/{candidate['plan']}"
        commit = "a" * 40
        authority = {
            "tag": tag,
            "commit": commit,
            "recorded_at": dt.datetime(2026, 7, 24, tzinfo=dt.UTC),
            "plan": candidate,
            "preparation": None,
            "lifecycle": "completed",
            "successor": None,
        }
        with mock.patch.object(self.recovery, "classify_plan_authorities", return_value=[authority]):
            selected = self.recovery.select_explicit_plan_authority(
                mock.Mock(), tag, commit, candidate, None
            )
        self.assertEqual({**authority, "selection": "explicit"}, selected)

    def test_concurrent_terminal_supersession_retries_before_returning_action(self) -> None:
        older = lifecycle_plan(self.recovery)
        older["plan"] = "older-plan"
        successor = lifecycle_plan(self.recovery)
        successor["plan"] = "successor-plan"
        older_tag = "release-plan/older-plan"
        successor_tag = "release-plan/successor-plan"
        commits = {older_tag: "a" * 40, successor_tag: "b" * 40}
        plans = {older_tag: older, successor_tag: successor}
        recorded = {
            commits[older_tag]: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            commits[successor_tag]: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        terminal_failure: dict[str, object] = {}
        registry_reads = 0

        def list_tags(_client: mock.Mock) -> list[str]:
            nonlocal registry_reads
            registry_reads += 1
            if registry_reads == 2:
                terminal_failure.update(
                    {"outcome": "terminal-failure", "successor": successor_tag}
                )
            return (
                [older_tag, successor_tag]
                if terminal_failure
                else [older_tag]
            )

        def lifecycle(
            _client: mock.Mock,
            tag: str,
            _commit: str,
            _plan: dict[str, object],
            _preparation: None,
        ) -> tuple[str, object | None]:
            if tag == older_tag and terminal_failure:
                return "superseded", {
                    "tag": successor_tag,
                    "sha256": self.recovery.manifest_digest(successor),
                    "plan": successor,
                }
            return "actionable", None

        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                side_effect=list_tags,
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=lambda _client, tag, _commit: (plans[tag], None),
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=lifecycle,
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
        ):
            selected = self.recovery.select_implicit_plan_authority(mock.Mock())

        self.assertEqual(successor_tag, selected["tag"])
        self.assertEqual("actionable", selected["lifecycle"])
        self.assertEqual(4, registry_reads)

    def test_convergence_rechecks_nonselected_lifecycle_authority(self) -> None:
        older = {"tag": "release-plan/older", "lifecycle": "completed"}
        changed_older = {**older, "lifecycle": "superseded"}
        latest = {"tag": "release-plan/latest", "lifecycle": "actionable"}
        current_snapshot = [changed_older, latest]

        with mock.patch.object(
            self.recovery,
            "classify_implicit_plan_authority",
            side_effect=[
                (latest, [older, latest]),
                (latest, current_snapshot),
                (latest, current_snapshot),
                (latest, current_snapshot),
            ],
        ) as classify:
            selected = self.recovery.select_implicit_plan_authority(mock.Mock())

        self.assertEqual(4, classify.call_count)
        self.assertEqual(current_snapshot, selected["authority_snapshot"])

    def test_final_implicit_boundary_rejects_continuity_pause_activated_after_initial_read(
        self,
    ) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        selected = {"tag": "release-plan/current", "lifecycle": "actionable"}
        authority = {"authority_snapshot": [selected]}
        continuity = mock.Mock(
            side_effect=[
                None,
                {
                    "accepted_tag": f"beta-continuity/{candidate['plan']}/accepted",
                    "accepted_commit": "b" * 40,
                    "resumed_tag": f"beta-continuity/{candidate['plan']}/resumed",
                },
            ]
        )
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
                return_value=(selected, [selected]),
            ),
            mock.patch.object(
                self.recovery,
                "scheduled_continuity_pause",
                continuity,
            ),
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
        ):
            self.assertIsNone(continuity(mock.Mock(), candidate))
            with self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "continuity pause authority changed during component preflight",
            ):
                self.recovery.resolve_component(
                    mock.Mock(),
                    "workflow",
                    selected["tag"],
                    "a" * 40,
                    candidate,
                    candidate_preparation,
                    authority,
                )

        self.assertEqual(2, continuity.call_count)
        self.assertEqual(1, publication_preflight.call_count)

    def test_final_implicit_boundary_rejects_stale_publish_but_explicit_actionable_recovery_does_not(
        self,
    ) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )
        implicit_authority = {
            "authority_snapshot": [
                {"tag": "release-plan/older", "lifecycle": "actionable"}
            ]
        }
        current_snapshot = [
            {"tag": "release-plan/older", "lifecycle": "superseded"},
            {"tag": "release-plan/successor", "lifecycle": "actionable"},
        ]
        explicit_authority = {
            "selection": "explicit",
            "tag": "release-plan/older",
            "commit": "a" * 40,
            "recorded_at": dt.datetime(2026, 7, 23, tzinfo=dt.UTC),
            "plan": candidate,
            "preparation": candidate_preparation,
            "lifecycle": "actionable",
            "successor": None,
        }
        current_explicit_authority = {
            key: value
            for key, value in explicit_authority.items()
            if key != "selection"
        }

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
                return_value=(current_snapshot[-1], current_snapshot),
            ) as classify,
            mock.patch.object(
                self.recovery,
                "classify_plan_authorities",
                return_value=[current_explicit_authority],
            ) as classify_explicit,
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
        ):
            with self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "refusing a stale recovery action",
            ):
                self.recovery.resolve_component(
                    mock.Mock(),
                    "workflow",
                    "release-plan/older",
                    "a" * 40,
                    candidate,
                    candidate_preparation,
                    implicit_authority,
                )

            for lifecycle in ("actionable", "interrupted"):
                with self.subTest(explicit_lifecycle=lifecycle):
                    explicit_authority["lifecycle"] = lifecycle
                    current_explicit_authority["lifecycle"] = lifecycle
                    state, outputs = self.recovery.resolve_component(
                        mock.Mock(),
                        "workflow",
                        "release-plan/older",
                        "a" * 40,
                        candidate,
                        candidate_preparation,
                        explicit_authority,
                    )
                    self.assertEqual("publish", outputs["action"])
                    self.assertEqual("publication", state["phase"])

        self.assertEqual(1, classify.call_count)
        self.assertEqual(2, classify_explicit.call_count)
        self.assertEqual(3, publication_preflight.call_count)

    def test_interrupted_plan_rejects_multiple_continuity_successors(self) -> None:
        interrupted = lifecycle_plan(self.recovery)
        interrupted["plan"] = "interrupted-plan"
        first_successor = json.loads(json.dumps(interrupted))
        first_successor["plan"] = "first-successor"
        second_successor = json.loads(json.dumps(interrupted))
        second_successor["plan"] = "second-successor"
        tags = [
            f"release-plan/{interrupted['plan']}",
            f"release-plan/{first_successor['plan']}",
            f"release-plan/{second_successor['plan']}",
        ]
        plans_by_tag = dict(zip(tags, [interrupted, first_successor, second_successor], strict=True))
        commits = {
            tags[0]: "a" * 40,
            tags[1]: "b" * 40,
            tags[2]: "c" * 40,
        }
        interruption_tag = f"{self.recovery.CONTINUITY_TAG_PREFIX}{interrupted['plan']}/interrupted"
        interruption_commit = "d" * 40
        interruption_evidence = {"phase": "interrupted"}
        superseded_interruption = {
            "tag": interruption_tag,
            "commit": interruption_commit,
            "evidence_sha256": self.recovery.manifest_digest(interruption_evidence),
            "plan_sha256": self.recovery.manifest_digest(interrupted),
            "reason": self.recovery.CONTINUITY_SUPERSESSION_REASON,
        }
        orderings = [
            (
                tags,
                {
                    commits[tags[0]]: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
                    commits[tags[1]]: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
                    commits[tags[2]]: dt.datetime(2026, 7, 22, tzinfo=dt.UTC),
                },
            ),
            (
                list(reversed(tags)),
                {
                    commits[tags[0]]: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
                    commits[tags[1]]: dt.datetime(2026, 7, 22, tzinfo=dt.UTC),
                    commits[tags[2]]: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
                },
            ),
        ]

        for discovered_tags, recorded in orderings:
            with (
                self.subTest(tags=discovered_tags, recorded=recorded),
                mock.patch.object(
                    self.recovery,
                    "list_release_plan_tags",
                    return_value=discovered_tags,
                ),
                mock.patch.object(
                    self.recovery,
                    "resolve_tag",
                    side_effect=lambda _client, _repository, tag: (
                        interruption_commit if tag == interruption_tag else commits[tag]
                    ),
                ),
                mock.patch.object(
                    self.recovery,
                    "read_plan_authority",
                    side_effect=lambda _client, tag, _commit: (plans_by_tag[tag], None),
                ),
                mock.patch.object(
                    self.recovery,
                    "direct_plan_lifecycle",
                    side_effect=lambda _client, tag, *_args: (
                        ("interrupted", interruption_tag) if tag == tags[0] else ("completed", None)
                    ),
                ),
                mock.patch.object(
                    self.recovery,
                    "immutable_plan_recorded_at",
                    side_effect=lambda _client, commit, recorded=recorded: recorded[commit],
                ),
                mock.patch.object(
                    self.recovery,
                    "accepted_continuity_supersession",
                    side_effect=lambda _client, authority: (
                        None if authority["tag"] == tags[0] else superseded_interruption
                    ),
                ),
                mock.patch.object(
                    self.recovery,
                    "list_continuity_resolution_tags",
                    return_value=[],
                ),
                mock.patch.object(
                    self.recovery,
                    "read_record",
                    return_value=interruption_evidence,
                ),
                self.assertRaisesRegex(
                    self.recovery.RecoveryError,
                    "multiple continuity successors",
                ),
            ):
                self.recovery.select_implicit_plan_authority(mock.Mock())

    def test_continuity_successor_fork_accepts_exact_digest_bound_resolution(self) -> None:
        interrupted_plan = {"plan": "interrupted"}
        interrupted = {
            "tag": "release-plan/interrupted",
            "commit": "a" * 40,
            "plan": interrupted_plan,
        }
        interruption = {
            "tag": "beta-continuity/interrupted/interrupted",
            "commit": "b" * 40,
            "evidence_sha256": "c" * 64,
        }
        successors = []
        for index, name in enumerate(("first-successor", "second-successor"), start=1):
            successors.append(
                {
                    "tag": f"release-plan/{name}",
                    "supersession": {
                        **interruption,
                        "continuity_claim": {
                            "plan": {
                                "tag": f"release-plan/{name}",
                                "commit": str(index) * 40,
                                "sha256": str(index + 2) * 64,
                            },
                            "acceptance": {
                                "tag": f"beta-continuity/{name}/accepted",
                                "commit": str(index + 4) * 40,
                                "sha256": str(index + 6) * 64,
                            },
                        },
                    },
                }
            )
        claims = [successor["supersession"]["continuity_claim"] for successor in successors]
        resolution = {
            "schema": self.recovery.CONTINUITY_RESOLUTION_SCHEMA,
            "qualification": continuity_resolution_qualification(),
            "interruption": {
                "plan": {
                    "tag": interrupted["tag"],
                    "commit": interrupted["commit"],
                    "sha256": self.recovery.manifest_digest(interrupted_plan),
                },
                "evidence": {
                    "tag": interruption["tag"],
                    "commit": interruption["commit"],
                    "sha256": interruption["evidence_sha256"],
                },
            },
            "successor_claims": claims,
            "selected_successor": claims[1]["plan"],
        }
        resolution_tag = (
            f"{self.recovery.CONTINUITY_RESOLUTION_TAG_PREFIX}interrupted/"
            f"{self.recovery.manifest_digest(resolution)}"
        )
        client = mock.Mock()
        client.json.return_value = continuity_resolution_qualification_run()
        with (
            mock.patch.object(
                self.recovery,
                "list_continuity_resolution_tags",
                return_value=[resolution_tag],
            ),
            mock.patch.object(self.recovery, "resolve_tag", return_value="f" * 40),
            mock.patch.object(self.recovery, "read_record", return_value=resolution),
        ):
            selected = self.recovery.resolve_continuity_successor_fork(
                client,
                interrupted,
                successors,
            )
        self.assertEqual("release-plan/second-successor", selected)
        valid_run = continuity_resolution_qualification_run()
        failures = (
            (None, "qualification is absent"),
            ({**valid_run, "status": "in_progress", "conclusion": None}, "qualification is pending"),
            ({**valid_run, "conclusion": "failure"}, "qualification failed"),
            ({**valid_run, "conclusion": "cancelled"}, "qualification was cancelled"),
            ({**valid_run, "head_sha": "8" * 40}, "another source revision"),
            ({**valid_run, "path": ".github/workflows/untrusted.yml@main"}, "untrusted workflow"),
        )
        with (
            mock.patch.object(self.recovery, "list_continuity_resolution_tags", return_value=[resolution_tag]),
            mock.patch.object(self.recovery, "resolve_tag", return_value="f" * 40),
            mock.patch.object(self.recovery, "read_record", return_value=resolution),
        ):
            for run, message in failures:
                with self.subTest(qualification=message):
                    client.json.return_value = run
                    with self.assertRaisesRegex(self.recovery.RecoveryError, message):
                        self.recovery.resolve_continuity_successor_fork(client, interrupted, successors)

    def test_terminal_failure_successor_requires_exact_authorized_plan_identity(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        authorized_successor = json.loads(json.dumps(failed))
        authorized_successor["plan"] = "successor-plan"
        authorized_successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        recorded_successor = json.loads(json.dumps(authorized_successor))
        recorded_successor["components"]["workflow"]["commit"] = "e" * 40
        failed_tag = f"release-plan/{failed['plan']}"
        successor_tag = f"release-plan/{authorized_successor['plan']}"
        failed_commit = "a" * 40
        successor_commit = "b" * 40
        failure_commit = "c" * 40
        failure = supersession_record(
            self.recovery,
            failed,
            authorized_successor,
            failed_commit,
        )

        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=[None, failure_commit],
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[failure, authorized_successor],
            ),
            mock.patch.object(self.recovery, "revalidate_supersession_authority"),
        ):
            lifecycle, successor_identity = self.recovery.direct_plan_lifecycle(
                mock.Mock(),
                failed_tag,
                failed_commit,
                failed,
                None,
            )

        self.assertEqual("superseded", lifecycle)
        self.assertEqual(
            {
                "tag": successor_tag,
                "sha256": self.recovery.manifest_digest(authorized_successor),
                "plan": authorized_successor,
            },
            successor_identity,
        )

        commits = {failed_tag: failed_commit, successor_tag: successor_commit}
        recorded = {
            failed_commit: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            successor_commit: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                return_value=[failed_tag, successor_tag],
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=[(failed, None), (recorded_successor, None)],
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=[
                    (lifecycle, successor_identity),
                    ("completed", None),
                ],
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "conflicting successor identity",
            ),
        ):
            self.recovery.select_implicit_plan_authority(mock.Mock())

    def test_terminal_failure_normalizes_captured_github_approval_shape(self) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        client = mock.Mock()
        client.json.side_effect = captured_github_authority(self.recovery, record)

        self.recovery.revalidate_supersession_authority(record, client)

        self.assertEqual(4, client.json.call_count)
        mutations = (
            ("run", "id", 999),
            ("run", "run_attempt", 2),
            ("environment", "id", 999),
            ("history", "state", "rejected"),
            ("reviewer", "id", 999),
        )
        for target, field, value in mutations:
            with self.subTest(target=target, field=field):
                responses = captured_github_authority(self.recovery, record)
                if target == "run":
                    responses[2][field] = value
                elif target == "environment":
                    responses[0][field] = value
                elif target == "history":
                    responses[3][0][field] = value
                else:
                    responses[3][0]["user"][field] = value
                client = mock.Mock()
                client.json.side_effect = responses
                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.revalidate_supersession_authority(record, client)

    def test_terminal_failure_rejects_attempt_one_approval_claimed_for_attempt_two(
        self,
    ) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        authorization = record["authorization"]
        authorization["run_attempt"] = 2
        authorization["environment_approval"]["run_attempt"] = 2
        responses = captured_github_authority(self.recovery, record)
        client = mock.Mock()
        client.json.side_effect = responses

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "rerun attempts cannot use run-wide approval history",
        ):
            self.recovery.revalidate_supersession_authority(record, client)

        self.assertEqual(3, client.json.call_count)

    def test_terminal_failure_rejects_approver_removed_from_current_policy(self) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        responses = captured_github_authority(self.recovery, record)
        responses[0]["protection_rules"][0]["reviewers"][0]["reviewer"] = (
            captured_github_user("replacement-reviewer", 56, "replacement-node")
        )
        client = mock.Mock()
        client.json.side_effect = responses

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "approver is not in the current required-reviewer policy",
        ):
            self.recovery.revalidate_supersession_authority(record, client)

    def test_terminal_failure_rejects_malformed_authorization_json_types(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        failed_commit = "a" * 40
        valid_failure = supersession_record(
            self.recovery,
            failed,
            successor,
            failed_commit,
        )
        valid_failure["authorization"]["run_id"] = 1
        valid_failure["authorization"]["run_url"] = (
            "https://github.com/durable-workflow/.github/actions/runs/1"
        )
        valid_failure["authorization"]["environment_approval"]["run_id"] = 1
        self.recovery.validate_supersession_record(
            valid_failure,
            failed,
            failed_commit,
            successor,
        )
        mutations = (
            (("authorization", "actor"), True),
            (("authorization", "workflow_commit"), int("1" * 40)),
            (("authorization", "environment_approval", "run_id"), True),
            (("authorization", "environment_approval", "run_attempt"), True),
            (
                (
                    "authorization",
                    "environment_protection",
                    "deployment_branch_policy",
                    "custom_branch_policies",
                ),
                1,
            ),
            (
                (
                    "authorization",
                    "environment_protection",
                    "deployment_branch_policy",
                    "protected_branches",
                ),
                0,
            ),
        )

        for path, value in mutations:
            with self.subTest(field=".".join(path)):
                malformed = json.loads(json.dumps(valid_failure))
                target = malformed
                for key in path[:-1]:
                    target = target[key]
                target[path[-1]] = value

                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.validate_supersession_record(
                        malformed,
                        failed,
                        failed_commit,
                        successor,
                    )

    def test_terminal_failure_rejects_incomplete_lifecycle_authority(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        failed_tag = f"release-plan/{failed['plan']}"
        failed_commit = "a" * 40
        incomplete = {
            "schema": "durable-workflow.release-plan-failure/v1",
            "outcome": "terminal-failure",
            "failed_plan": {
                "tag": failed_tag,
                "commit": failed_commit,
                "sha256": self.recovery.manifest_digest(failed),
            },
            "successor_plan": {
                "tag": f"release-plan/{successor['plan']}",
                "sha256": self.recovery.manifest_digest(successor),
            },
        }

        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=[None, "c" * 40],
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[incomplete, successor],
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "record keys must be exactly",
            ),
        ):
            self.recovery.direct_plan_lifecycle(
                mock.Mock(),
                failed_tag,
                failed_commit,
                failed,
                None,
            )

    def assert_explicit_terminal_record_cannot_publish(self, shape: str) -> None:
        for visible_from_round, expected_artifact_checks in ((1, 0), (2, 1)):
            with self.subTest(
                shape=shape,
                visible_from_round=visible_from_round,
            ):
                registry = ExplicitTerminalLifecycleRegistry(
                    self.recovery,
                    shape,
                    visible_from_round=visible_from_round,
                )
                component = self.recovery.COMPONENTS["server"]
                handoff = mock.Mock()
                with tempfile.TemporaryDirectory() as directory:
                    root = Path(directory)
                    plan_output = root / "release-plan.json"
                    preparation_output = root / "release-preparation.json"
                    evidence_output = root / "recovery-evidence.json"
                    github_output = root / "github-output"
                    argv = [
                        str(RECOVERY_SCRIPT),
                        "resolve",
                        "--component",
                        "server",
                        "--plan-tag",
                        registry.failed_tag,
                        "--plan-output",
                        str(plan_output),
                        "--preparation-output",
                        str(preparation_output),
                        "--evidence",
                        str(evidence_output),
                        "--github-output",
                        str(github_output),
                    ]
                    with (
                        mock.patch.object(self.recovery.sys, "argv", argv),
                        mock.patch.object(
                            self.recovery,
                            "PublicClient",
                            return_value=registry.client,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "list_release_plan_tags",
                            side_effect=registry.list_release_plan_tags,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "resolve_tag",
                            side_effect=registry.resolve_tag,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "read_plan_authority",
                            side_effect=registry.read_plan_authority,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "read_record",
                            side_effect=registry.read_record,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "immutable_plan_recorded_at",
                            side_effect=registry.immutable_plan_recorded_at,
                        ),
                        mock.patch.object(self.recovery, "validate_release_mirrors"),
                        mock.patch.object(
                            self.recovery,
                            "verify_plan_authority",
                            return_value=({}, {}),
                        ),
                        mock.patch.object(
                            self.recovery,
                            "validate_release_preparation",
                        ),
                        mock.patch.object(
                            self.recovery,
                            "verify_component",
                            return_value={"status": "published"},
                        ),
                        mock.patch.dict(
                            self.recovery.VERIFIERS,
                            {component.distribution: registry.artifact_verifier},
                        ),
                        mock.patch.object(
                            self.recovery,
                            "write_output",
                            handoff,
                        ),
                        mock.patch.object(
                            self.recovery.sys,
                            "stderr",
                            io.StringIO(),
                        ),
                    ):
                        exit_code = self.recovery.main()

                    evidence = json.loads(evidence_output.read_bytes())
                    self.assertEqual(1, exit_code)
                    self.assertEqual("plan-discovery", evidence["phase"])
                    self.assertEqual("failed", evidence["outcome"])
                    self.assertIn(
                        "terminally superseded",
                        evidence["reason"],
                    )
                    self.assertEqual(
                        expected_artifact_checks,
                        registry.artifact_verifier.call_count,
                    )
                    self.assertFalse(github_output.exists())
                    handoff.assert_not_called()
                    self.assertFalse(
                        publication_credentials_are_eligible(exit_code, None)
                    )

    def test_terminal_failure_record_blocks_explicit_absent_artifact_handoff(
        self,
    ) -> None:
        self.assert_explicit_terminal_record_cannot_publish("terminal-failure")

    def test_accepted_continuity_supersession_blocks_explicit_absent_artifact_handoff(
        self,
    ) -> None:
        self.assert_explicit_terminal_record_cannot_publish("accepted-continuity")


class ReleasePreparationRecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def candidate(self) -> dict[str, object]:
        return {
            "plan": "missing-preparation",
            "channel": "alpha",
            "components": {"workflow": {"version": "2.0.0-alpha.1", "commit": "a" * 40}},
        }

    def test_discovery_rejects_missing_preparation_for_an_incomplete_release(self) -> None:
        candidate = self.candidate()
        tag = "release-plan/missing-preparation"
        record_commit = "b" * 40
        client = mock.Mock()
        client.json.return_value = {
            "tag_name": tag,
            "draft": False,
            "assets": [
                {
                    "name": "release-plan.json",
                    "browser_download_url": "https://example.invalid/release-plan.json",
                }
            ],
        }
        client.bytes.return_value = self.recovery.canonical_json(candidate)
        with (
            mock.patch.object(self.recovery, "validate_plan"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=record_commit),
            mock.patch.object(
                self.recovery,
                "select_explicit_plan_authority",
                return_value={"selection": "explicit"},
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[candidate, self.recovery.NotFound("missing preparation")],
            ),
            mock.patch.object(
                self.recovery,
                "verify_component",
                side_effect=self.recovery.NotFound("release is incomplete"),
            ),
            self.assertRaisesRegex(self.recovery.RecoveryError, "only completed legacy releases"),
        ):
            self.recovery.discover_plan(client, tag, "workflow")

    def test_missing_preparation_cannot_resolve_to_publish(self) -> None:
        candidate = self.candidate()
        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "release preparation required before publishing workflow",
            ),
        ):
            self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

    def test_explicit_completed_release_still_resolves_to_skip(self) -> None:
        candidate = self.candidate()
        identity = candidate["components"]["workflow"]
        public_evidence = {"version": identity["version"], "commit": identity["commit"]}
        authority = {
            "selection": "explicit",
            "tag": "release-plan/missing-preparation",
            "commit": "b" * 40,
            "plan": candidate,
            "preparation": None,
            "lifecycle": "completed",
            "successor": None,
        }
        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "resolve_tag", return_value=identity["commit"]),
            mock.patch.object(self.recovery, "verify_component", return_value=public_evidence),
            mock.patch.object(
                self.recovery,
                "classify_plan_authorities",
                return_value=[
                    {key: value for key, value in authority.items() if key != "selection"}
                ],
            ),
        ):
            state, outputs = self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
                authority,
            )

        self.assertEqual("skip", outputs["action"])
        self.assertEqual("complete", state["phase"])
        self.assertNotIn("release_preparation", state)


class RecoveryWorkflowSourceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def assert_rejected(self, source: str) -> None:
        with self.assertRaises(self.recovery.RecoveryError) as caught:
            self.recovery.verify_recovery_workflow_source(
                "sdk-rust",
                source,
                hashlib.sha256(CURRENT_RUST_RECOVERY_WORKFLOW.encode("utf-8")).hexdigest(),
            )
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def test_accepts_only_the_current_protected_rust_workflow_identity(self) -> None:
        digest = hashlib.sha256(CURRENT_RUST_RECOVERY_WORKFLOW.encode("utf-8")).hexdigest()
        self.recovery.verify_recovery_workflow_source("sdk-rust", CURRENT_RUST_RECOVERY_WORKFLOW, digest)
        self.recovery.verify_recovery_workflow_source(
            "sdk-rust",
            CURRENT_RUST_RECOVERY_WORKFLOW.replace("\n", "\r\n"),
            digest,
        )

    def test_rejects_shell_semantic_bypasses_and_any_source_mutation(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        variants = {
            "one-byte mutation": source.replace("timeout-minutes: 30", "timeout-minutes: 31", 1),
            "one-line mutation": source + "\n",
            "readarray release tag mutation": source.replace(
                "          select_publication_run() {",
                "          readarray -t release_identity < <(printf '%s\\n' mutable)\n"
                '          RELEASE_TAG="${release_identity[0]}"\n\n'
                "          select_publication_run() {",
                1,
            ),
            "successful early exit": source.replace(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          exit 0\n          python scripts/ci/publish-planned-tag.py \\",
                1,
            ),
            "shadowed gh command": source.replace(
                "          set -euo pipefail",
                "          set -euo pipefail\n          gh() { printf 'shadowed\\n'; }",
                1,
            ),
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_rejects_skipped_nonblocking_or_decoy_scoped_steps(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        tag_step = "      - name: Create or verify the exact planned source tag"
        publication_step = "      - name: Start or resume repository-owned publication"
        completion_step = "      - name: Verify crates.io source identity and the GitHub Release"
        exact_bindings = """          RELEASE_TAG: ${{ needs.discover.outputs.version }}
          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}"""
        decoy_step = f"""      - name: Unrelated release identity
        env:
{exact_bindings}
        run: echo "release identity is not consumed here"

"""
        mutable_tag_bindings = source.replace(
            exact_bindings,
            """          RELEASE_TAG: ${{ github.ref_name }}
          RELEASE_COMMIT: ${{ github.sha }}""",
            1,
        ).replace(tag_step, decoy_step + tag_step, 1)
        publication_env = """        env:
          GH_TOKEN: ${{ github.token }}
          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}
          RELEASE_TAG: ${{ needs.discover.outputs.version }}
          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}"""
        mutable_selector_bindings = source.replace(
            publication_env,
            """        env:
          GH_TOKEN: ${{ github.token }}
          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}
          RELEASE_TAG: ${{ github.ref_name }}
          RELEASE_COMMIT: ${{ github.sha }}""",
            1,
        ).replace(
            "      - name: Start or resume repository-owned publication",
            decoy_step + "      - name: Start or resume repository-owned publication",
            1,
        )
        variants = {
            "tag publication skipped": source.replace(
                tag_step,
                tag_step + "\n        if: ${{ false }}",
                1,
            ),
            "tag publication nonblocking even when false": source.replace(
                tag_step,
                tag_step + "\n        continue-on-error: false",
                1,
            ),
            "tag publication expression-enabled nonblocking": source.replace(
                tag_step,
                tag_step + "\n        continue-on-error: ${{ github.ref_name != '' }}",
                1,
            ),
            "tag publication uses a nonblocking shell": source.replace(
                tag_step,
                tag_step + "\n        shell: bash {0} || true",
                1,
            ),
            "publication selection skipped": source.replace(
                publication_step,
                publication_step + "\n        if: ${{ false }}",
                1,
            ),
            "completion verification skipped": source.replace(
                completion_step,
                completion_step + "\n        if: ${{ false }}",
                1,
            ),
            "completion verification nonblocking": source.replace(
                completion_step,
                completion_step + "\n        continue-on-error: true",
                1,
            ),
            "completion verification expression-enabled nonblocking": source.replace(
                completion_step,
                completion_step + "\n        continue-on-error: ${{ failure() }}",
                1,
            ),
            "tag bindings moved to an unrelated step": mutable_tag_bindings,
            "selector bindings moved to an unrelated step": mutable_selector_bindings,
            "checkout adds repository-token authority": source.replace(
                "          fetch-depth: 0",
                "          fetch-depth: 0\n          token: ${{ github.token }}",
                1,
            ),
            "run identity includes an unapproved field": source.replace(
                "databaseId,event,displayTitle,headBranch,headSha,status,conclusion",
                "databaseId,event,displayTitle,headBranch,headSha,status,conclusion,runAttempt",
                1,
            ),
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_rejects_weakened_or_mismatched_rust_publication_shapes(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        publisher = r"""          python scripts/ci/publish-planned-tag.py \
            --tag "$RELEASE_TAG" --commit "$RELEASE_COMMIT" --plan-tag "$PLAN_TAG" \
            --evidence release-tag-publication-evidence.json"""
        deferred_publisher = source.replace(publisher, "          echo tag-publication-deferred", 1).replace(
            "      - name: Verify crates.io source identity and the GitHub Release",
            "      - name: Deferred source tag publication\n"
            "        run: |\n"
            f"{publisher}\n\n"
            "      - name: Verify crates.io source identity and the GitHub Release",
            1,
        )
        repository_token_creation = source.replace(
            "          python scripts/ci/publish-planned-tag.py",
            "          gh api --method POST \"repos/$GITHUB_REPOSITORY/git/refs\"\n"
            "          python scripts/ci/publish-planned-tag.py",
            1,
        )
        misplaced_deploy_key = source.replace(
            "          ssh-key: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}",
            "          env:\n"
            "            UNUSED_DEPLOY_KEY: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}",
            1,
        )
        dormant_publisher = source.replace(
            publisher,
            "          publish_planned_tag() {\n"
            + "\n".join(f"  {line}" for line in publisher.splitlines())
            + "\n          }",
            1,
        )
        reassigned_tag = source.replace(
            "          python scripts/ci/publish-planned-tag.py",
            '          RELEASE_TAG="$GITHUB_REF_NAME"\n'
            "          python scripts/ci/publish-planned-tag.py",
            1,
        )
        nonblocking_verification = source.replace(
            "--attempts 6 --sleep 10 --evidence release-completion-evidence.json",
            "--attempts 6 --sleep 10 --evidence release-completion-evidence.json || true",
            1,
        )
        variants = {
            "missing protected environment": source.replace(
                "environment: release-plan-publication", "environment: unprotected", 1
            ),
            "missing deploy key": source.replace(
                "secrets.RELEASE_PLAN_DEPLOY_KEY", "secrets.UNPROTECTED_KEY", 1
            ),
            "deploy key only in unrelated env": misplaced_deploy_key,
            "tag publisher defined but not executed": dormant_publisher,
            "release tag reassigned before publication": reassigned_tag,
            "public verification made nonblocking": nonblocking_verification,
            "tag publication after dispatch": deferred_publisher,
            "mutable tag publisher argument": source.replace(
                '--tag "$RELEASE_TAG"', '--tag "$GITHUB_REF_NAME"', 1
            ),
            "mismatched tag publisher commit": source.replace(
                '--commit "$RELEASE_COMMIT"', '--commit "$GITHUB_SHA"', 1
            ),
            "mutable planned tag binding": source.replace(
                "needs.discover.outputs.version", "github.ref_name"
            ),
            "mutable planned commit binding": source.replace(
                "needs.discover.outputs.commit", "github.sha"
            ),
            "different selected workflow": source.replace(
                "gh run list --workflow release.yml", "gh run list --workflow nightly.yml", 1
            ),
            "different dispatched workflow": source.replace(
                "gh workflow run release.yml", "gh workflow run nightly.yml", 1
            ),
            "incomplete run identity": source.replace(
                "headBranch,headSha,status", "headBranch,status", 1
            ),
            "mismatched selector tag": source.replace(
                '--release-tag "$RELEASE_TAG"', '--release-tag "$GITHUB_REF_NAME"', 1
            ),
            "mismatched selector commit": source.replace(
                '--release-commit "$RELEASE_COMMIT"', '--release-commit "$GITHUB_SHA"', 1
            ),
            "mismatched dispatch tag": source.replace(
                '-f release_tag="$RELEASE_TAG"', '-f release_tag="$GITHUB_REF_NAME"', 1
            ),
            "missing completed release verification": source.replace(
                "--component sdk-rust --plan recovery-input/release-plan.json",
                "--component sdk-rust --plan mutable-release-plan.json",
                1,
            ),
            "broad contents permission": source.replace("contents: read", "contents: write", 1),
            "repository token tag creation": repository_token_creation,
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_generic_recovery_pairs_protected_main_with_immutable_release_inputs(self) -> None:
        expected_sha256 = hashlib.sha256(GENERIC_RECOVERY_WORKFLOW.encode("utf-8")).hexdigest()
        self.recovery.verify_recovery_workflow_source(
            "server", GENERIC_RECOVERY_WORKFLOW, expected_sha256
        )

        invalid_sources = {
            "tag creation replaced": GENERIC_RECOVERY_WORKFLOW.replace(
                '-f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"',
                'python scripts/ci/publish-planned-tag.py --tag "$RELEASE_TAG" --commit "$RELEASE_COMMIT"',
            ),
            "tag ref dispatch": GENERIC_RECOVERY_WORKFLOW.replace('--ref main', '--ref "$RELEASE_TAG"'),
            "tag ref selection": GENERIC_RECOVERY_WORKFLOW.replace('--branch main', '--branch "$RELEASE_TAG"'),
            "mutable tag input": GENERIC_RECOVERY_WORKFLOW.replace(
                '-f tag="$RELEASE_TAG"', '-f tag="$GITHUB_REF_NAME"'
            ),
            "mutable commit input": GENERIC_RECOVERY_WORKFLOW.replace(
                '-f release_commit="$RELEASE_COMMIT"', '-f release_commit="$GITHUB_SHA"'
            ),
        }
        for label, invalid_source in invalid_sources.items():
            with self.subTest(label):
                self.assertNotEqual(GENERIC_RECOVERY_WORKFLOW, invalid_source)
                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.verify_recovery_workflow_source(
                        "server", invalid_source, expected_sha256
                    )


class PublicationRunSelectionTest(unittest.TestCase):
    RELEASE_TAG = "1.2.3"
    RELEASE_COMMIT = "1" * 40
    RELEASE_PLAN = "release-plan/recovery-test"
    DISPLAY_TITLE = f"Release {RELEASE_TAG} at {RELEASE_COMMIT} for {RELEASE_PLAN}"

    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def run_record(self, **overrides: object) -> dict[str, object]:
        record: dict[str, object] = {
            "databaseId": 123,
            "event": "workflow_dispatch",
            "displayTitle": self.DISPLAY_TITLE,
            "headBranch": "main",
            "headSha": "2" * 40,
            "status": "in_progress",
            "conclusion": None,
        }
        record.update(overrides)
        return record

    def select(self, runs: object) -> dict[str, object]:
        return self.recovery.select_publication_run(
            self.RELEASE_TAG,
            self.RELEASE_COMMIT,
            self.RELEASE_PLAN,
            runs,
        )

    def test_release_display_title_binds_every_planned_input(self) -> None:
        release_workflow = (REPOSITORY_ROOT / ".github/workflows/release.yml").read_text()

        self.assertIn("github.ref == 'refs/heads/main'", release_workflow)
        self.assertIn("inputs.tag || github.ref_name", release_workflow)
        self.assertIn("inputs.release_commit || github.sha", release_workflow)
        self.assertIn("inputs.release_plan || 'direct'", release_workflow)

    def test_selects_only_the_exact_protected_main_dispatch(self) -> None:
        run = self.run_record()
        selected = self.select([run])
        self.assertEqual("wait", selected["action"])
        self.assertEqual(123, selected["run_id"])

        for field, value in (
            ("event", "push"),
            ("displayTitle", "Release 1.2.3 for direct"),
            ("headBranch", self.RELEASE_TAG),
        ):
            with self.subTest(field):
                mutated = dict(run)
                mutated[field] = value
                self.assertEqual(
                    "dispatch",
                    self.select([mutated])["action"],
                )

    def test_wrong_commit_never_blocks_reruns_or_satisfies_recovery(self) -> None:
        wrong_title = self.DISPLAY_TITLE.replace(self.RELEASE_COMMIT, "3" * 40)
        for label, status, conclusion in (
            ("in progress", "in_progress", None),
            ("failed", "completed", "failure"),
            ("successful", "completed", "success"),
        ):
            with self.subTest(label):
                mismatched = self.run_record(
                    displayTitle=wrong_title,
                    status=status,
                    conclusion=conclusion,
                )
                self.assertEqual("dispatch", self.select([mismatched])["action"])

    def test_exact_match_wins_when_a_wrong_commit_run_is_also_present(self) -> None:
        mismatched = self.run_record(
            databaseId=122,
            displayTitle=self.DISPLAY_TITLE.replace(self.RELEASE_COMMIT, "3" * 40),
        )
        exact = self.run_record(status="completed", conclusion="success")

        self.assertEqual(
            {"action": "complete", "run_id": 123, "status": "completed", "conclusion": "success"},
            self.select([mismatched, exact]),
        )

    def test_multiple_exact_runs_select_newest_active_success_or_failure(self) -> None:
        older_failure = self.run_record(
            databaseId=122,
            status="completed",
            conclusion="failure",
        )
        for label, newest, expected_action in (
            ("active", self.run_record(databaseId=125), "wait"),
            (
                "successful",
                self.run_record(databaseId=125, status="completed", conclusion="success"),
                "complete",
            ),
            (
                "failed",
                self.run_record(databaseId=125, status="completed", conclusion="failure"),
                "rerun",
            ),
        ):
            with self.subTest(label):
                selected = self.select([newest, older_failure])
                self.assertEqual(expected_action, selected["action"])
                self.assertEqual(125, selected["run_id"])


if __name__ == "__main__":
    unittest.main()
