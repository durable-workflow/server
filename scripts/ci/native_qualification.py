#!/usr/bin/env python3
"""Sanitized image observations, exact owned cleanup and qualification receipts.

Only the existing qualification jobs call these phases. A PR/default/local
image produces a diagnostic receipt; it cannot be annotated into eligibility.
"""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import re
import sys
from typing import Any

from native_alias_evidence import (COMPOSE_OBSERVATIONS, CORPUS_JOB, CORPUS_OBSERVATIONS,
                                   DIGEST, FINAL_TAG, GHCR_IMAGE, JSON_LIMIT, PLATFORMS,
                                   RECEIPT_SCHEMA, REPOSITORY, REPOSITORY_ID, SHA, WORKFLOWS,
                                   Refusal, canonical, closed, literal, matching, positive,
                                   require, strict_json)
from native_alias_transport import Commands, GitHubRegistryProvider

OWNER = "dev.durable-workflow.qualification.scope"
CLEANUP_KEYS = {"api_ok", "verified_absent", "container_count", "network_count", "volume_count"}


def read(path: Path) -> Any:
    with path.open("rb") as stream:
        return strict_json(stream.read(JSON_LIMIT + 1), JSON_LIMIT)


def write(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    # Owned job workspace files only; artifact finalization uses a fresh directory.
    path.write_bytes(canonical(value))


def eligible_context(role: str, image: str) -> bool:
    tag = os.environ.get("GITHUB_REF_NAME", "")
    return (os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
            and os.environ.get("GITHUB_REF") == "refs/tags/" + tag
            and matching(tag, FINAL_TAG) and matching(os.environ.get("GITHUB_SHA"), SHA)
            and os.environ.get("GITHUB_WORKFLOW_REF") ==
            f"{REPOSITORY}/{WORKFLOWS[role]}@refs/tags/{tag}"
            and re.fullmatch(re.escape(GHCR_IMAGE) + r"@sha256:[0-9a-f]{64}", image) is not None)


def inspect(commands: Commands, resource: str, identity: str) -> dict[str, Any]:
    value = strict_json(commands.run(["docker", resource, "inspect", identity]), JSON_LIMIT)
    require(type(value) is list and len(value) == 1 and type(value[0]) is dict,
            "docker_inspect_shape")
    return value[0]


def observe_image(provider: GitHubRegistryProvider, role: str, image: str,
                  container: str, platform: str) -> dict[str, Any]:
    if not eligible_context(role, image):
        return {"eligible": False, "reason": "nonpromotion_context"}
    require(platform in PLATFORMS, "qualification_platform")
    source, tag = os.environ["GITHUB_SHA"], os.environ["GITHUB_REF_NAME"]
    actual_source = provider.commands.run(["git", "rev-parse", "HEAD"], 128).decode().strip()
    require(actual_source == source, "qualification_checkout")
    index = image.split("@", 1)[1]
    subject = provider.index_subjects(GHCR_IMAGE, index, source, tag)[platform]
    instance = inspect(provider.commands, "container", container)
    require(instance.get("Image") == subject["image_config_digest"], "actual_container_image")
    config = inspect(provider.commands, "image", subject["image_config_digest"])
    require(config.get("Id") == subject["image_config_digest"]
            and config.get("Architecture") == platform.split("/")[1]
            and config.get("Os") == "linux" and type(config.get("Config")) is dict,
            "actual_container_platform")
    labels = config["Config"].get("Labels")
    require(type(labels) is dict and labels.get("org.opencontainers.image.revision") == source
            and labels.get("dev.durable-workflow.release.tag") == tag, "actual_container_source")
    return {"eligible": True, "requested_image": image, "index_digest": index,
            "platform": platform, **subject, "actual_image_config_digest": instance["Image"],
            "container_image_architecture": config["Architecture"], "container_image_os": config["Os"],
            "image_source_commit": source, "image_release_tag": tag}


def lines(commands: Commands, args: list[str], expression: str) -> set[str]:
    value = commands.run(["docker", *args]).decode("utf-8", errors="strict").splitlines()
    require(len(value) == len(set(value)) and len(value) <= 256
            and all(re.fullmatch(expression, line) for line in value), "cleanup_inventory")
    return set(value)


def owned_inventory(commands: Commands, role: str, scope: str) -> tuple[set[str], set[str], set[str]]:
    containers, networks, volumes = set(), set(), set()
    if role == "compose":
        label = "com.docker.compose.project=" + scope
        containers = lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter", "label=" + label],
                           r"[0-9a-f]{64}")
        networks = lines(commands, ["network", "ls", "-q", "--no-trunc", "--filter", "label=" + label],
                         r"[0-9a-f]{64}")
        volumes = lines(commands, ["volume", "ls", "-q", "--filter", "label=" + label], r"[A-Za-z0-9_.-]+")
    else:
        networks = lines(commands, ["network", "ls", "-q", "--no-trunc", "--filter", "name=^" + scope + "$"],
                         r"[0-9a-f]{64}")
        containers = lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter", "name=^/" + scope + "-"],
                           r"[0-9a-f]{64}")
        containers |= lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter", "label=" + OWNER + "=" + scope],
                            r"[0-9a-f]{64}")
        for network in networks:
            data = inspect(commands, "network", network)
            require(data.get("Name") == scope and type(data.get("Containers")) is dict
                    and type(data.get("Labels")) is dict and data["Labels"].get(OWNER) == scope,
                    "cleanup_network_ownership")
            require(all(re.fullmatch(r"[0-9a-f]{64}", identity) for identity in data["Containers"]),
                    "cleanup_network_container")
            containers.update(data["Containers"])
    for identity in containers:
        data = inspect(commands, "container", identity)
        require(data.get("Id") == identity and type(data.get("Config")) is dict
                and type(data.get("Mounts")) is list, "cleanup_container_identity")
        if role == "compose":
            labels = data["Config"].get("Labels")
            require(type(labels) is dict and labels.get("com.docker.compose.project") == scope,
                    "cleanup_container_owner")
        else:
            # Prefixes and network membership discover candidates, never grant
            # removal authority. Validate every candidate before the first rm.
            labels = data["Config"].get("Labels")
            require(type(labels) is dict and labels.get(OWNER) == scope,
                    "cleanup_container_owner")
        for mount in data["Mounts"]:
            if type(mount) is dict and mount.get("Type") == "volume":
                name = mount.get("Name")
                require(type(name) is str and re.fullmatch(r"[A-Za-z0-9_.-]+", name), "cleanup_volume_identity")
                metadata = inspect(commands, "volume", name)
                labels = metadata.get("Labels") or {}
                # An unlabelled anonymous volume belongs to the observed owned container.
                # A named Compose volume must independently carry the same project label.
                require((re.fullmatch(r"[0-9a-f]{64}", name) and not labels)
                        or (role == "compose" and type(labels) is dict
                            and labels.get("com.docker.compose.project") == scope), "cleanup_volume_owner")
                volumes.add(name)
    require(len(containers) <= 64 and len(networks) <= 8 and len(volumes) <= 64, "cleanup_resource_bound")
    return containers, networks, volumes


def cleanup(commands: Commands, role: str, scope: str) -> dict[str, Any]:
    require(role in ("corpus", "compose") and re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,150}", scope),
            "cleanup_scope")
    containers, networks, volumes = owned_inventory(commands, role, scope)
    if containers:
        commands.run(["docker", "container", "rm", "-f", "-v", *sorted(containers)])
    if networks:
        commands.run(["docker", "network", "rm", *sorted(networks)])
    # rm -v can already remove anonymous volumes. A successful list distinguishes
    # absence from unavailable Docker; never reinterpret an inspect failure as 404.
    remaining = lines(commands, ["volume", "ls", "-q"], r"[A-Za-z0-9_.-]+") & volumes
    if remaining:
        commands.run(["docker", "volume", "rm", *sorted(remaining)])
    after_containers, after_networks, after_volumes = owned_inventory(commands, role, scope)
    after_volumes |= lines(commands, ["volume", "ls", "-q"], r"[A-Za-z0-9_.-]+") & volumes
    require(not after_containers and not after_networks and not after_volumes, "cleanup_not_absent")
    return {"api_ok": True, "verified_absent": True, "container_count": 0,
            "network_count": 0, "volume_count": 0}


def finalize(provider: GitHubRegistryProvider, role: str, image: str, platform: str,
             profile: str | None, identity: Any, observations: Any, cleanup_result: Any,
             body_outcome: str, cleanup_outcome: str) -> dict[str, Any]:
    if not eligible_context(role, image):
        return {"schema": "durable-workflow.native-qualification-diagnostic.v1",
                "eligible": False, "reason": "nonpromotion_context"}
    require(body_outcome == cleanup_outcome == "success", "qualification_step_failed")
    require(type(identity) is dict and identity.get("eligible") is True
            and identity.get("requested_image") == image and identity.get("platform") == platform,
            "qualification_identity_missing")
    cleanup_result = closed(cleanup_result, CLEANUP_KEYS, "qualification_cleanup_shape")
    require(cleanup_result["api_ok"] is True and cleanup_result["verified_absent"] is True
            and all(literal(cleanup_result[key], 0) for key in CLEANUP_KEYS - {"api_ok", "verified_absent"}),
            "qualification_cleanup_failed")
    keys = CORPUS_OBSERVATIONS if role == "corpus" else COMPOSE_OBSERVATIONS
    observations = closed(observations, keys, "qualification_observation_shape")
    if role == "corpus":
        require(all(literal(value, 0) for value in observations.values()), "qualification_corpus_failed")
    else:
        require(profile in ("local", "production")
                and all(value is True for key, value in observations.items()
                        if key != "production_default_token_refused")
                and literal(observations["production_default_token_refused"], True if profile == "production" else None),
                "qualification_compose_failed")
    source = os.environ["GITHUB_SHA"]
    run_id, attempt = int(os.environ["GITHUB_RUN_ID"]), int(os.environ["GITHUB_RUN_ATTEMPT"])
    require(positive(run_id) and positive(attempt), "qualification_run")
    run = provider.api(f"repos/{REPOSITORY}/actions/runs/{run_id}")
    require(type(run) is dict and literal(run.get("id"), run_id) and literal(run.get("run_attempt"), attempt)
            and run.get("head_sha") == source and run.get("path") == WORKFLOWS[role]
            and run.get("event") == "workflow_dispatch" and run.get("head_branch") == os.environ["GITHUB_REF_NAME"],
            "qualification_provider_run")
    for field in ("repository", "head_repository"):
        owner = run.get(field)
        require(type(owner) is dict and literal(owner.get("id"), REPOSITORY_ID)
                and owner.get("full_name") == REPOSITORY, "qualification_provider_owner")
    name = CORPUS_JOB if role == "corpus" else platform + " " + str(profile) + " published image"
    page = provider.api(f"repos/{REPOSITORY}/actions/runs/{run_id}/jobs?filter=all&per_page=100")
    require(type(page) is dict and type(page.get("jobs")) is list
            and type(page.get("total_count")) is int
            and len(page["jobs"]) == page["total_count"] <= 100, "qualification_job_inventory")
    selected = [job for job in page["jobs"] if type(job) is dict
                and literal(job.get("run_attempt"), attempt) and job.get("name") == name]
    require(len(selected) == 1 and positive(selected[0].get("id"))
            and literal(selected[0].get("run_id"), run_id) and selected[0].get("head_sha") == source
            and selected[0].get("status") == "in_progress", "qualification_job_owner")
    workflow_blob = provider.workflow_blob(source, WORKFLOWS[role])
    actual_blob = provider.commands.run(["git", "rev-parse", source + ":" + WORKFLOWS[role]], 128).decode().strip()
    require(actual_blob == workflow_blob, "qualification_workflow_blob")
    # Only explicitly whitelisted image fields cross into the artifact.
    image_keys = {"requested_image", "index_digest", "platform", "child_manifest_digest", "image_config_digest",
                  "actual_image_config_digest", "container_image_architecture", "container_image_os",
                  "image_source_commit", "image_release_tag"}
    closed(identity, image_keys | {"eligible"}, "qualification_identity_shape")
    return {"schema": RECEIPT_SCHEMA, "kind": role, "eligible": True,
            "repository": REPOSITORY, "repository_id": REPOSITORY_ID,
            "run_id": run_id, "run_attempt": attempt, "job_id": selected[0]["id"],
            "runner_commit": source, "workflow_path": WORKFLOWS[role], "workflow_blob": workflow_blob,
            "profile": profile, **{key: identity[key] for key in image_keys},
            "observations": observations, "cleanup": cleanup_result}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("observe-image", "cleanup", "finalize"))
    parser.add_argument("--kind", choices=("corpus", "compose"), required=True)
    parser.add_argument("--image", default="")
    parser.add_argument("--container", default="")
    parser.add_argument("--scope", default="")
    parser.add_argument("--platform", default="linux/amd64")
    parser.add_argument("--profile", choices=("local", "production"))
    parser.add_argument("--identity", type=Path)
    parser.add_argument("--observations", type=Path)
    parser.add_argument("--cleanup", type=Path)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    provider = GitHubRegistryProvider()
    if args.phase == "observe-image":
        value = observe_image(provider, args.kind, args.image, args.container, args.platform)
    elif args.phase == "cleanup":
        value = cleanup(provider.commands, args.kind, args.scope)
    elif not eligible_context(args.kind, args.image):
        value = {"schema": "durable-workflow.native-qualification-diagnostic.v1",
                 "eligible": False, "reason": "nonpromotion_context"}
    else:
        require(args.identity is not None and args.observations is not None and args.cleanup is not None,
                "qualification_inputs_missing")
        observations = read(args.observations)
        if args.kind == "corpus":
            observations = observations.get("status") if type(observations) is dict else None
        value = finalize(provider, args.kind, args.image, args.platform, args.profile, read(args.identity),
                         observations, read(args.cleanup), os.environ.get("QUALIFICATION_BODY_OUTCOME", ""),
                         os.environ.get("QUALIFICATION_CLEANUP_OUTCOME", ""))
    write(args.output, value)


if __name__ == "__main__":
    try:
        main()
    except (Refusal, OSError, UnicodeError, ValueError, KeyError):
        # Never print Docker/API bodies, HTTP registration payloads or credentials.
        print("native qualification refused; no positive receipt finalized", file=sys.stderr)
        raise SystemExit(1) from None
