#!/usr/bin/env python3
"""Build the native alias promotion evidence manifest from three run IDs.

It reads the publication run and both qualifier runs, which must all be
dispatched from the release tag. Each qualifier's own receipt artifacts are
selected by exact name. The output is the closed v1 manifest. This is
selection, not proof: alias promotion and GitHub Release creation verify it.
"""
from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys
from typing import Any

# python3 -I leaves the script directory off sys.path.
sys.path.insert(0, str(Path(__file__).resolve().parent))

from native_alias_evidence import (DIGEST, FINAL_TAG, GHCR_IMAGE, MANIFEST_SCHEMA,
                                   PLATFORMS, PROFILES, REPOSITORY, SHA, WORKFLOWS, Refusal,
                                   canonical, jobs, literal, manifest, matching, positive,
                                   qualification_jobs, require)
from native_alias_transport import GitHubRegistryProvider

TITLES = {"corpus": "Native image parity ", "compose": "Published Compose "}


def tag_run(provider: Any, run_id: int, role: str, tag: str) -> dict[str, Any]:
    run = provider.api(f"repos/{REPOSITORY}/actions/runs/{run_id}")
    require(type(run) is dict and literal(run.get("id"), run_id) and positive(run.get("run_attempt"))
            and run.get("event") == "workflow_dispatch" and run.get("path") == WORKFLOWS[role]
            and run.get("head_branch") == tag and matching(run.get("head_sha"), SHA)
            and run.get("status") == "completed" and run.get("conclusion") == "success",
            "selection_" + role + "_run")
    return run


def receipt_artifact(provider: Any, run: dict[str, Any], name: str) -> dict[str, Any]:
    page = provider.api(f"repos/{REPOSITORY}/actions/runs/{run['id']}/artifacts?name={name}&per_page=100")
    require(type(page) is dict and type(page.get("artifacts")) is list
            and literal(page.get("total_count"), len(page["artifacts"])), "selection_artifacts_incomplete")
    # Exactly one artifact of that name, never the newest of several.
    require(len(page["artifacts"]) == 1, "selection_artifact_ambiguous")
    artifact = page["artifacts"][0]
    require(type(artifact) is dict and artifact.get("name") == name and positive(artifact.get("id"))
            and matching(artifact.get("digest"), DIGEST) and artifact.get("expired") is False
            and type(artifact.get("workflow_run")) is dict
            and literal(artifact["workflow_run"].get("id"), run["id"]), "selection_artifact_identity")
    return {"artifact_id": artifact["id"], "artifact_digest": artifact["digest"]}


def build(provider: Any, tag: str, release_run: int, corpus_run: int, compose_run: int) -> dict[str, Any]:
    require(matching(tag, FINAL_TAG), "final_release_required")
    require(all(positive(run) for run in (release_run, corpus_run, compose_run)), "selection_run_ids")
    publication = tag_run(provider, release_run, "release", tag)
    source = publication["head_sha"]
    require(publication.get("display_title") == f"Native {tag} at {source}", "selection_release_run")
    runs = {"corpus": tag_run(provider, corpus_run, "corpus", tag),
            "compose": tag_run(provider, compose_run, "compose", tag)}
    images = set()
    for role, run in runs.items():
        title = run.get("display_title")
        require(run["head_sha"] == source and type(title) is str and title.startswith(TITLES[role]),
                "selection_" + role + "_source")
        images.add(title.removeprefix(TITLES[role]))
    image = images.pop()
    require(not images and re.fullmatch(re.escape(GHCR_IMAGE) + r"@sha256:[0-9a-f]{64}", image) is not None,
            "selection_qualified_image")
    selected = {}
    for role, run in runs.items():
        current = jobs(provider, run, qualification_jobs(role))
        selected[role] = {"run_id": run["id"], "run_attempt": run["run_attempt"], "runner_commit": source,
                          "workflow_blob": provider.workflow_blob(source, WORKFLOWS[role])}
        if role == "corpus":
            selected[role].update(receipt_artifact(provider, run, "native-corpus-promotion-receipt"))
            continue
        selected[role]["cells"] = [
            {"platform": platform, "profile": profile,
             "job_id": current[platform + " " + profile + " published image"]["id"],
             **receipt_artifact(provider, run, "native-compose-promotion-receipt-"
                                + platform.split("/")[1] + "-" + profile)}
            for platform in PLATFORMS for profile in PROFILES]
    value = {"schema": MANIFEST_SCHEMA,
             "release": {"tag": tag, "source_commit": source, "index_digest": image.split("@", 1)[1],
                         "run_id": publication["id"], "run_attempt": publication["run_attempt"]},
             **selected}
    return manifest(canonical(value))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--release-run", type=int, required=True)
    parser.add_argument("--corpus-run", type=int, required=True)
    parser.add_argument("--compose-run", type=int, required=True)
    args = parser.parse_args()
    value = build(GitHubRegistryProvider(), args.tag, args.release_run, args.corpus_run, args.compose_run)
    sys.stdout.buffer.write(canonical(value))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError) as error:
        reason = str(error) if isinstance(error, Refusal) else "local_input_or_transport"
        if re.fullmatch(r"[a-z_]{1,80}", reason) is None:
            reason = "local_input_or_transport"
        print("Native promotion evidence refused: " + reason, file=sys.stderr)
        raise SystemExit(1) from None
