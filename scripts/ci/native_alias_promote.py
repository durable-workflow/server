#!/usr/bin/env python3
"""Verify existing qualification; optionally enter the protected alias writer."""
from __future__ import annotations

import argparse
import os
import signal
from pathlib import Path
import subprocess
import sys

from native_alias_evidence import (DOCKERHUB_IMAGE, GHCR_IMAGE, MANIFEST_LIMIT,
                                   REPOSITORY, Refusal, canonical, manifest, require, verify)
from native_alias_transport import GitHubRegistryProvider


def dispatch(value: dict) -> None:
    release = value["release"]
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
            and os.environ.get("PROMOTION_TAG") == release["tag"]
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
            and os.environ.get("GITHUB_REF") == "refs/tags/" + release["tag"]
            and os.environ.get("GITHUB_SHA") == release["source_commit"]
            and os.environ.get("GITHUB_WORKFLOW_REF") ==
            f"{REPOSITORY}/.github/workflows/native-alias-promotion.yml@refs/tags/{release['tag']}",
            "promotion_dispatch_identity")


def qualify(value: dict, provider, mode: str) -> dict:
    if mode != "release-order":
        dispatch(value)
    proof = verify(value, provider)
    if mode in ("verify", "promote"):
        require(not provider.release_exists(value["release"]["tag"]), "preexisting_release_requires_decision")
    return proof


def plan(value: dict, provider) -> None:
    dispatch(value)
    source = value["release"]["source_commit"]
    comparison = provider.api(f"repos/{REPOSITORY}/compare/{source}...main")
    require(type(comparison) is dict and comparison.get("status") in ("ahead", "identical")
            and type(comparison.get("base_commit")) is dict
            and comparison["base_commit"].get("sha") == source, "promotion_source_not_on_main")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("plan", "verify", "promote", "release-order"))
    args = parser.parse_args()
    raw = os.environ.get("PROMOTION_EVIDENCE", "").encode("utf-8")
    require(len(raw) <= MANIFEST_LIMIT, "manifest_size")
    value = manifest(raw)
    provider = GitHubRegistryProvider()
    if args.mode == "plan":
        plan(value, provider)
        return
    proof = qualify(value, provider, args.mode)
    if args.mode == "verify":
        sys.stdout.buffer.write(canonical(proof))
        return
    release = proof["release"]
    if args.mode == "release-order":
        require(os.environ.get("RELEASE_TAG") == release["tag"]
                and os.environ.get("RELEASE_RUN_ID") == str(release["run_id"])
                and os.environ.get("RELEASE_RUN_ATTEMPT") == str(release["run_attempt"]),
                "release_evidence_identity")
    environment = os.environ.copy()
    # No caller-supplied registry, tag inventory, executable or tag source can
    # redirect the protected writer. Its only mutation is the existing helper.
    environment.pop("RELEASE_IMAGE_KNOWN_TAGS", None)
    environment.update(RELEASE_TAG=release["tag"], INDEX_DIGEST=release["index_digest"],
                       GHCR_IMAGE=GHCR_IMAGE, DOCKERHUB_IMAGE=DOCKERHUB_IMAGE, DOCKER="docker",
                       RELEASE_IMAGE_TAG_SOURCE_URL="https://github.com/" + REPOSITORY + ".git",
                       VERIFY_ONLY="true" if args.mode == "release-order" else "false")
    helper = Path(__file__).with_name("native-release-rolling-tags.sh")
    # Bounded mutation preserves the helper's content-free partial-state record.
    # A timeout is an uncertain write, never a rollback or automatic retry.
    process = subprocess.Popen(["bash", str(helper)], env=environment, stdin=subprocess.DEVNULL,
                               start_new_session=True)
    try:
        status = process.wait(timeout=180)
    except subprocess.TimeoutExpired:
        raise Refusal("alias_write_timeout_uncertain_no_rollback") from None
    finally:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()
    require(status == 0, "alias_write_stopped_no_rollback")


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError) as error:
        reason = str(error) if isinstance(error, Refusal) else "local_input_or_transport"
        print("Native promotion refused: " + reason, file=sys.stderr)
        raise SystemExit(1) from None
