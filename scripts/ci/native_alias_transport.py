#!/usr/bin/env python3
"""Bounded read-only GitHub/registry transport for native promotion checks."""
from __future__ import annotations

import os
from pathlib import Path
import re
import selectors
import signal
import subprocess
import time
from typing import Any

from native_alias_evidence import (ARCHIVE_LIMIT, DIGEST, DOCKERHUB_IMAGE, GHCR_IMAGE,
                                   JSON_LIMIT, PLATFORMS, REPOSITORY, SHA, Refusal,
                                   matching, require, strict_json)


GITHUB_HOST = "github.com"


class Commands:
    """No shell, polling, automatic retries, unbounded output or provider logs."""

    def __init__(self, seconds: float = 180):
        self.deadline = time.monotonic() + seconds

    @staticmethod
    def require_wait_authority() -> None:
        require(callable(getattr(os, "waitid", None))
                and all(hasattr(os, name) for name in (
                    "P_PID", "WEXITED", "WNOHANG", "WNOWAIT", "CLD_EXITED", "CLD_KILLED", "CLD_DUMPED"))
                and signal.getsignal(signal.SIGCHLD) == signal.SIG_DFL,
                "provider_child_authority_unavailable")

    @staticmethod
    def child_state(pid: int):
        # The synchronous caller owns this direct child. WNOWAIT retains the
        # child's kernel PID reservation until group cleanup has finished.
        Commands.require_wait_authority()
        try:
            state = os.waitid(os.P_PID, pid, os.WEXITED | os.WNOHANG | os.WNOWAIT)
        except OSError:
            raise Refusal("provider_child_authority_unknown") from None
        if state is None:
            return None
        require(state.si_pid == pid and state.si_code in (os.CLD_EXITED, os.CLD_KILLED, os.CLD_DUMPED),
                "provider_child_authority_unknown")
        return state

    @staticmethod
    def finish_child(process) -> None:
        try:
            # Never signal after releasing the direct child's PID reservation.
            Commands.child_state(process.pid)
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait(timeout=1)
        except (OSError, subprocess.SubprocessError):
            raise Refusal("provider_child_cleanup_failed") from None
        finally:
            process.stdout.close()
            process.stderr.close()

    def run(self, argv: list[str], limit: int = JSON_LIMIT,
            env: dict[str, str] | None = None, accepted: tuple[int, ...] = (0,)) -> bytes:
        remaining = min(30, self.deadline - time.monotonic())
        require(remaining > 0, "read_deadline")
        self.require_wait_authority()
        environment = os.environ.copy()
        environment.pop("GH_DEBUG", None)
        environment.update(GH_HOST=GITHUB_HOST, GH_PROMPT_DISABLED="1", GH_PAGER="cat")
        if env:
            environment.update(env)
        process = None
        try:
            process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                       stderr=subprocess.PIPE, env=environment, start_new_session=True)
            output, errors = bytearray(), bytearray()
            until = time.monotonic() + remaining
            with selectors.DefaultSelector() as selector:
                selector.register(process.stdout, selectors.EVENT_READ, output)
                selector.register(process.stderr, selectors.EVENT_READ, errors)
                while selector.get_map():
                    require(time.monotonic() < until, "read_timeout")
                    for key, _ in selector.select(min(0.1, max(0, until - time.monotonic()))):
                        block = os.read(key.fd, 8192)
                        if not block:
                            selector.unregister(key.fileobj)
                            continue
                        key.data.extend(block)
                        require(len(output) <= limit and len(errors) <= 4096, "read_output_bound")
                require(time.monotonic() < until, "read_timeout")
                state = self.child_state(process.pid)
                while state is None:
                    require(time.monotonic() < until, "read_timeout")
                    time.sleep(min(0.01, max(0, until - time.monotonic())))
                    state = self.child_state(process.pid)
                code = state.si_status if state.si_code == os.CLD_EXITED else -state.si_status
                require(time.monotonic() < until, "read_timeout")
                require(code in accepted, "provider_read_failed")
            return bytes(output)
        except (OSError, subprocess.SubprocessError):
            raise Refusal("provider_command_failed") from None
        finally:
            if process is not None:
                self.finish_child(process)


class GitHubRegistryProvider:
    def __init__(self, commands: Commands | None = None):
        self.commands = commands if commands is not None else Commands()

    def api(self, path: str) -> Any:
        # GitHub comparisons use a three-dot separator, not a traversal
        # segment. Only the protected-main read of an exact source may contain
        # it; every other path keeps the original dot/traversal refusal.
        comparison = type(path) is str and re.fullmatch(
            rf"repos/{REPOSITORY}/compare/[0-9a-f]{{40}}\.\.\.main", path) is not None
        require(type(path) is str and path.startswith(f"repos/{REPOSITORY}/")
                and re.fullmatch(r"[A-Za-z0-9_./?=&-]+", path) is not None
                and (".." not in path or comparison), "provider_api_path")
        return strict_json(self.commands.run(
            ["gh", "api", "--hostname", GITHUB_HOST, "--method", "GET", path]), JSON_LIMIT)

    def archive(self, artifact_id: int) -> bytes:
        require(type(artifact_id) is int and artifact_id > 0, "artifact_id")
        return self.commands.run(["gh", "api", "--hostname", GITHUB_HOST, "--method", "GET",
                                  f"repos/{REPOSITORY}/actions/artifacts/{artifact_id}/zip"],
                                 ARCHIVE_LIMIT)

    def release_exists(self, tag: str) -> bool:
        # gh's exit=1 alone cannot distinguish a 404 from failed authentication,
        # transport or rate limits. Only the fixed API's parsed HTTP 404 counts.
        from native_alias_evidence import FINAL_TAG
        require(matching(tag, FINAL_TAG), "final_release_required")
        response = self.commands.run(["gh", "api", "--hostname", GITHUB_HOST, "--method", "GET",
                                      "--include", f"repos/{REPOSITORY}/releases/tags/{tag}"],
                                     accepted=(0, 1))
        headers, separator, body = response.partition(b"\r\n\r\n")
        if not separator:
            headers, separator, body = response.partition(b"\n\n")
        require(bool(separator), "release_http_response")
        status = re.fullmatch(rb"HTTP/[0-9.]+ (200|404)(?: [^\r\n]*)?", headers.splitlines()[0])
        require(status is not None and b"content-type: application/json" in headers.lower(), "release_http_status")
        value = strict_json(body, JSON_LIMIT)
        if status[1] == b"404":
            require(type(value) is dict and value.get("message") == "Not Found", "release_absence_unknown")
            return False
        require(type(value) is dict and type(value.get("id")) is int and value["id"] > 0
                and value.get("tag_name") == tag, "release_existing_identity")
        return True

    def workflow_blob(self, commit: str, path: str) -> str:
        require(matching(commit, SHA) and re.fullmatch(r"\.github/workflows/[a-z-]+\.yml", path),
                "workflow_source_path")
        value = self.api(f"repos/{REPOSITORY}/contents/{path}?ref={commit}")
        require(type(value) is dict and value.get("type") == "file" and value.get("path") == path
                and matching(value.get("sha"), SHA), "workflow_source_blob")
        return value["sha"]

    def registry_json(self, image: str, digest: str, image_config: bool = False) -> Any:
        if image not in (GHCR_IMAGE, DOCKERHUB_IMAGE):
            raise Refusal("registry_subject")
        if type(digest) is not str or re.fullmatch(r"sha256:[0-9a-f]{64}", digest) is None:
            raise Refusal("registry_subject")
        arguments = ["docker", "buildx", "imagetools", "inspect"]
        arguments += ["--format", "{{json .Image}}"] if image_config else ["--raw"]
        return strict_json(self.commands.run(arguments + [image + "@" + digest]), JSON_LIMIT)

    def exact_tag_digest(self, image: str, tag: str) -> str:
        """The index digest an exact final version tag names, read without pulling."""
        from native_alias_evidence import FINAL_TAG
        require(image in (GHCR_IMAGE, DOCKERHUB_IMAGE) and matching(tag, FINAL_TAG), "registry_subject")
        # Buildx prints a human manifest for templates that begin with {{.Manifest.
        output = self.commands.run(["docker", "buildx", "imagetools", "inspect", "--format",
                                    '{{printf "%s" .Manifest.Digest}}', image + ":" + tag.removeprefix("native-")],
                                   128).decode("utf-8", errors="strict").strip()
        require(matching(output, DIGEST), "registry_exact_tag")
        return output

    def index_subjects(self, image: str, index_digest: str, source: str,
                       tag: str) -> dict[str, dict[str, str]]:
        index = self.registry_json(image, index_digest)
        require(type(index) is dict and type(index.get("manifests")) is list
                and len(index["manifests"]) == 2, "publication_index_members")
        subjects = {}
        for member in index["manifests"]:
            require(type(member) is dict and type(member.get("platform")) is dict,
                    "publication_member_platform")
            platform = member["platform"]
            key = str(platform.get("os")) + "/" + str(platform.get("architecture"))
            require(key in PLATFORMS and key not in subjects
                    and matching(member.get("digest"), DIGEST), "publication_member_set")
            child = self.registry_json(image, member["digest"])
            require(type(child) is dict and type(child.get("config")) is dict
                    and matching(child["config"].get("digest"), DIGEST), "publication_child_config")
            config = self.registry_json(image, member["digest"], image_config=True)
            require(type(config) is dict and config.get("os") == "linux"
                    and config.get("architecture") == platform["architecture"]
                    and type(config.get("config")) is dict, "publication_config_platform")
            labels = config["config"].get("Labels")
            require(type(labels) is dict and labels.get("org.opencontainers.image.revision") == source
                    and labels.get("dev.durable-workflow.release.tag") == tag,
                    "publication_config_source")
            subjects[key] = {"child_manifest_digest": member["digest"],
                             "image_config_digest": child["config"]["digest"]}
        require(set(subjects) == set(PLATFORMS), "publication_platform_set")
        return subjects

    def publication_subjects(self, release: dict[str, Any]) -> dict[str, dict[str, Any]]:
        helper = Path(__file__).with_name("native-release-verify-publication.sh")
        identity = strict_json(self.commands.run(["bash", str(helper)], env={
            "GITHUB_REPOSITORY": REPOSITORY, "GHCR_IMAGE": GHCR_IMAGE,
            "DOCKERHUB_IMAGE": DOCKERHUB_IMAGE, "RELEASE_TAG": release["tag"],
            "RELEASE_RUN_ID": str(release["run_id"]),
            "RELEASE_RUN_ATTEMPT": str(release["run_attempt"]), "GH": "gh", "DOCKER": "docker",
        }), JSON_LIMIT)
        require(identity == release, "publication_identity_changed")
        ghcr = self.index_subjects(GHCR_IMAGE, release["index_digest"],
                                   release["source_commit"], release["tag"])
        hub = self.index_subjects(DOCKERHUB_IMAGE, release["index_digest"],
                                  release["source_commit"], release["tag"])
        require(ghcr == hub, "publication_registry_members_disagree")
        return ghcr
