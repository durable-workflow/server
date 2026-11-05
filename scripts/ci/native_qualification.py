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
COMPOSE_OWNER = "com.docker.compose.project"
LABEL_FILTER = "label="
OBJECT_ID = r"[0-9a-f]{64}"
VOLUME_NAME = r"[A-Za-z0-9_.-]+"
CLEANUP_KEYS = {"api_ok", "verified_absent", "container_count", "network_count", "volume_count"}
ROOT = Path.cwd().resolve()
STATE_PATHS = (
    "qualification-state/image.json", "qualification-state/observations.json",
    "qualification-state/cleanup.json", "qualification-state/body-cleanup.json",
    "parity-evidence/native-image-corpus.json", "promotion-receipt/receipt.json",
)


def state_path(path: Path) -> Path:
    # Select a fixed workspace path, never use a caller-controlled path at a file sink.
    for relative in STATE_PATHS:
        target = ROOT / relative
        if path in (Path(relative), target):
            require(not target.is_symlink() and not target.parent.is_symlink(), "qualification_path_symlink")
            return target
    raise Refusal("qualification_path_not_allowed")


def read(path: Path) -> Any:
    with state_path(path).open("rb") as stream:
        return strict_json(stream.read(JSON_LIMIT + 1), JSON_LIMIT)


def write(path: Path, value: Any) -> None:
    target = state_path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open("wb") as stream:
        stream.write(canonical(value))


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
    if resource not in ("container", "image", "network", "volume"):
        raise Refusal("docker_resource_kind")
    # Leading CLI options, whitespace, separators and paths are never identities.
    if not re.fullmatch(r"(?:sha256:[0-9a-f]{64}|[A-Za-z0-9][A-Za-z0-9_.-]{0,180})", identity):
        raise Refusal("docker_resource_identity")
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


def owned_networks(commands: Commands, role: str, scope: str) -> tuple[set[str], set[str]]:
    selector = LABEL_FILTER + COMPOSE_OWNER + "=" + scope if role == "compose" else "name=^" + scope + "$"
    networks = lines(commands, ["network", "ls", "-q", "--no-trunc", "--filter", selector], OBJECT_ID)
    attached = set()
    for identity in networks:
        data = inspect(commands, "network", identity)
        labels = data.get("Labels")
        owner = COMPOSE_OWNER if role == "compose" else OWNER
        require(data.get("Id") == identity and type(labels) is dict and labels.get(owner) == scope
                and type(data.get("Containers")) is dict, "cleanup_network_ownership")
        if role == "corpus":
            require(data.get("Name") == scope, "cleanup_network_name")
        require(all(re.fullmatch(OBJECT_ID, item) for item in data["Containers"]), "cleanup_network_container")
        attached.update(data["Containers"])
    return networks, attached


def owned_mounts(commands: Commands, mounts: list, role: str, scope: str) -> set[str]:
    volumes = set()
    for mount in mounts:
        if type(mount) is not dict or mount.get("Type") != "volume":
            continue
        name = mount.get("Name")
        require(type(name) is str and re.fullmatch(VOLUME_NAME, name), "cleanup_volume_identity")
        metadata = inspect(commands, "volume", name)
        labels = metadata.get("Labels")
        anonymous = (re.fullmatch(OBJECT_ID, name) is not None
                     and labels in (None, {}, {"com.docker.volume.anonymous": ""}))
        compose = role == "compose" and type(labels) is dict and labels.get(COMPOSE_OWNER) == scope
        # The exact Engine anonymous marker is not an owner grant: the volume
        # must also be mounted by the already inspected, positively owned container.
        require(metadata.get("Name") == name and (anonymous or compose), "cleanup_volume_owner")
        volumes.add(name)
    return volumes


def owned_inventory(commands: Commands, role: str, scope: str) -> tuple[set[str], set[str], set[str]]:
    networks, containers = owned_networks(commands, role, scope)
    owner = COMPOSE_OWNER if role == "compose" else OWNER
    containers |= lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter",
                                  LABEL_FILTER + owner + "=" + scope], OBJECT_ID)
    volumes = set()
    if role == "corpus":
        containers |= lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter",
                                      "name=^/" + scope + "-"], OBJECT_ID)
    else:
        volumes = lines(commands, ["volume", "ls", "-q", "--filter", LABEL_FILTER + owner + "=" + scope], VOLUME_NAME)
        for name in volumes:
            data = inspect(commands, "volume", name)
            require(data.get("Name") == name and type(data.get("Labels")) is dict
                    and data["Labels"].get(owner) == scope, "cleanup_volume_owner")
    # Discovering a prefix or attached resource never grants ownership. Validate
    # the complete candidate set, including mounts, before the first removal.
    for identity in containers:
        data = inspect(commands, "container", identity)
        require(data.get("Id") == identity and type(data.get("Config")) is dict
                and type(data.get("Mounts")) is list, "cleanup_container_identity")
        labels = data["Config"].get("Labels")
        require(type(labels) is dict and labels.get(owner) == scope, "cleanup_container_owner")
        volumes |= owned_mounts(commands, data["Mounts"], role, scope)
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
    remaining = lines(commands, ["volume", "ls", "-q"], VOLUME_NAME) & volumes
    if remaining:
        commands.run(["docker", "volume", "rm", *sorted(remaining)])
    after_containers, after_networks, after_volumes = owned_inventory(commands, role, scope)
    after_volumes |= lines(commands, ["volume", "ls", "-q"], VOLUME_NAME) & volumes
    require(not after_containers and not after_networks and not after_volumes, "cleanup_not_absent")
    return {"api_ok": True, "verified_absent": True, "container_count": 0,
            "network_count": 0, "volume_count": 0}


def qualification_observations(role: str, profile: str | None, value: Any) -> dict:
    keys = CORPUS_OBSERVATIONS if role == "corpus" else COMPOSE_OBSERVATIONS
    observations = closed(value, keys, "qualification_observation_shape")
    if role == "corpus":
        require(all(literal(item, 0) for item in observations.values()), "qualification_corpus_failed")
    else:
        require(profile in ("local", "production")
                and all(item is True for key, item in observations.items()
                        if key != "production_default_token_refused")
                and literal(observations["production_default_token_refused"], profile == "production" or None),
                "qualification_compose_failed")
    return observations


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
    observations = qualification_observations(role, profile, observations)
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
    except (OSError, ValueError, KeyError) as error:
        # Never print Docker/API bodies, HTTP registration payloads or credentials.
        reason = str(error) if isinstance(error, Refusal) else "local_input_or_transport"
        if re.fullmatch(r"[a-z_]{1,80}", reason) is None:
            reason = "local_input_or_transport"
        print("native qualification refused: " + reason + "; no positive receipt finalized", file=sys.stderr)
        raise SystemExit(1) from None
