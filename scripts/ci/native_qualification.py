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
                                   RECEIPT_SCHEMA, RECEIPT_SCHEMA_V2, REPOSITORY, REPOSITORY_ID, SHA, WORKFLOWS,
                                   SUBJECT_PATH, RUNNER_REF, qualification_subject, runner_subject,
                                   resource_scope, bind_dependencies, LOCAL_IMAGE_KEYS,
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
    "qualification-state/admission.json",
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
    return branch_context(role, image) or (os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
            and os.environ.get("GITHUB_REF") == "refs/tags/" + tag
            and matching(tag, FINAL_TAG) and matching(os.environ.get("GITHUB_SHA"), SHA)
            and os.environ.get("GITHUB_WORKFLOW_REF") ==
            f"{REPOSITORY}/{WORKFLOWS[role]}@refs/tags/{tag}"
            and re.fullmatch(re.escape(GHCR_IMAGE) + r"@sha256:[0-9a-f]{64}", image) is not None)


def branch_context(role: str, image: str) -> bool:
    return (os.environ.get("GITHUB_REPOSITORY") == REPOSITORY
            and os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
            and os.environ.get("GITHUB_REF") == RUNNER_REF
            and os.environ.get("GITHUB_REF_NAME") == RUNNER_REF.removeprefix("refs/heads/")
            and matching(os.environ.get("GITHUB_SHA"), SHA)
            and os.environ.get("GITHUB_WORKFLOW_REF") == f"{REPOSITORY}/{WORKFLOWS[role]}@{RUNNER_REF}"
            and re.fullmatch(re.escape(GHCR_IMAGE) + r"@sha256:[0-9a-f]{64}", image) is not None)


def admit(provider: GitHubRegistryProvider, role: str, image: str) -> dict[str, Any]:
    require(branch_context(role, image), "qualification_runner_context")
    source = os.environ["GITHUB_SHA"]
    actual = provider.commands.run(["git", "rev-parse", "HEAD"], 128).decode().strip()
    require(actual == source, "qualification_checkout")
    path = ROOT / SUBJECT_PATH
    require(not path.is_symlink(), "qualification_subject_symlink")
    with path.open("rb") as stream:
        subject = qualification_subject(strict_json(stream.read(8193), 8192))
    blob = provider.commands.run(["git", "rev-parse", source + ":" + SUBJECT_PATH],128).decode().strip()
    runner = {"source_commit": source, "ref": RUNNER_REF, "subject_blob": blob}
    require(runner_subject(provider, runner, subject["release"]) == subject, "qualification_local_subject")
    require(image == GHCR_IMAGE + "@" + subject["release"]["index_digest"], "qualification_requested_subject")
    if role == "compose":
        require("mysql:" + os.environ.get("MYSQL_VERSION", "") == subject["mysql"]["requested_image"]
                and os.environ.get("QUALIFICATION_QEMU_IMAGE") == subject["qemu"]["requested_image"],
                "qualification_dependency_configuration")
    return {"runner": runner, "subject": subject}


MANIFEST_TYPES = {"application/vnd.oci.image.manifest.v1+json",
                  "application/vnd.docker.distribution.manifest.v2+json"}
DEPENDENCY_REPOSITORIES = {
    "mysql": ("mysql:8.0", ("mysql", "docker.io/library/mysql")),
    "qemu": ("docker.io/tonistiigi/binfmt", ("tonistiigi/binfmt", "docker.io/tonistiigi/binfmt")),
}


def require_manifest(value: Any, digest: str) -> None:
    require(type(value) is dict and value.get("digest") == digest
            and type(value.get("mediaType")) is str and value["mediaType"] in MANIFEST_TYPES,
            "actual_platform_manifest")


def image_manifest(provider: GitHubRegistryProvider, subject: dict[str, str], requested: str) -> Any:
    repository = requested.split("@", 1)[0]
    if repository == GHCR_IMAGE:
        return provider.registry_json(repository, subject["child_manifest_digest"])
    # The shared promotion transport remains Server-only. These two exact
    # qualification dependencies use an independently closed read-only path.
    require(repository in {item[0] for item in DEPENDENCY_REPOSITORIES.values()}
            and matching(subject.get("child_manifest_digest"), DIGEST)
            and requested == repository + "@" + subject["child_manifest_digest"], "dependency_registry_subject")
    return strict_json(provider.commands.run(
        ["docker", "buildx", "imagetools", "inspect", "--raw", requested]), JSON_LIMIT)


def local_image(provider: GitHubRegistryProvider, subject: dict[str, str], requested: str,
                platform: str, root_digest: str, container: dict[str, Any] | None = None
                ) -> tuple[dict[str, Any], dict[str, Any]]:
    """Keep the engine target ID distinct from its manifest's config digest."""
    commands = provider.commands
    if container is None:
        config = inspect(commands, "image", requested)
        local_id = config.get("Id")
    else:
        local_id = container.get("Image")
        require(matching(local_id, DIGEST), "actual_container_image")
        require(local_id in {subject["image_config_digest"], root_digest, subject["child_manifest_digest"]},
                "actual_container_image")
        if local_id == subject["image_config_digest"]:
            config = inspect(commands, "image", local_id)
        else:
            require_manifest(container.get("ImageManifestDescriptor"), subject["child_manifest_digest"])
            config = inspect(commands, "image", local_id, platform=platform)
    require(matching(local_id, DIGEST), "actual_local_image")
    if local_id == subject["image_config_digest"]:
        require(config.get("Id") == local_id, "actual_local_image")
        identity = {"local_image_id": local_id, "local_manifest_digest": None,
                    "config_digest_source": "engine_id"}
    else:
        require(local_id in {root_digest, subject["child_manifest_digest"]}
                and config.get("Id") == subject["child_manifest_digest"], "actual_local_image")
        require_manifest(config.get("Descriptor"), subject["child_manifest_digest"])
        # The platform descriptor is engine-observed. Its immutable manifest
        # supplies the config linkage; an engine target ID is never relabeled.
        manifest = image_manifest(provider, subject, requested)
        require(type(manifest) is dict and manifest.get("schemaVersion") == 2
                and type(manifest.get("mediaType")) is str and manifest["mediaType"] in MANIFEST_TYPES
                and type(manifest.get("config")) is dict
                and manifest["config"].get("digest") == subject["image_config_digest"],
                "actual_manifest_config")
        identity = {"local_image_id": local_id, "local_manifest_digest": subject["child_manifest_digest"],
                    "config_digest_source": "manifest_descriptor"}
    require(config.get("Architecture") == platform.split("/")[1] and config.get("Os") == "linux",
            "actual_container_platform")
    return config, identity


def dependency_image(provider: GitHubRegistryProvider, subject: dict[str,str], role: str,
                     container: dict[str, Any] | None = None) -> dict[str,Any]:
    require(type(role) is str and role in DEPENDENCY_REPOSITORIES, "dependency_registry_subject")
    repository, repositories = DEPENDENCY_REPOSITORIES[role]
    require(matching(subject.get("child_manifest_digest"), DIGEST)
            and subject.get("requested_image") == repository + "@" + subject["child_manifest_digest"],
            "dependency_registry_subject")
    config, identity = local_image(provider, subject, subject["requested_image"], "linux/amd64",
                                   subject["child_manifest_digest"], container)
    require(type(config.get("RepoDigests")) is list
            and any(repository + "@" + subject["child_manifest_digest"] in config["RepoDigests"]
                    for repository in repositories), "actual_dependency_image")
    return {**subject, **identity, "actual_image_config_digest": subject["image_config_digest"],
            "container_image_architecture": config["Architecture"], "container_image_os": config["Os"]}


def observe_dependencies(provider: GitHubRegistryProvider, role: str, platform: str, scope: str, subject: dict[str,Any]) -> dict[str,Any]:
    if role == "corpus":
        return {}
    commands = provider.commands
    containers = lines(commands, ["container", "ls", "-aq", "--no-trunc", "--filter",
        LABEL_FILTER + COMPOSE_OWNER + "=" + scope, "--filter", "label=com.docker.compose.service=mysql"], OBJECT_ID)
    require(len(containers) == 1, "actual_database_inventory")
    identity = containers.pop()
    database = inspect(commands, "container", identity)
    labels = database.get("Config", {}).get("Labels", {})
    require(database.get("Id") == identity
            and matching(database.get("Image"), DIGEST)
            and database["Image"] in {subject["mysql"]["image_config_digest"], subject["mysql"]["child_manifest_digest"]}
            and database.get("Config", {}).get("Image") == subject["mysql"]["requested_image"]
            and labels.get(COMPOSE_OWNER) == scope and labels.get("com.docker.compose.service") == "mysql",
            "actual_database_subject")
    mysql = {**dependency_image(provider, subject["mysql"], "mysql", database),
             "container_id": identity}
    qemu = (dependency_image(provider, subject["qemu"], "qemu")
            if platform == "linux/arm64" else None)
    return {"mysql": mysql, "qemu": qemu}


def inspect(commands: Commands, resource: str, identity: str, *, platform: str | None = None) -> dict[str, Any]:
    if resource not in ("container", "image", "network", "volume"):
        raise Refusal("docker_resource_kind")
    # Leading CLI options, whitespace, separators and paths are never identities.
    pinned_image = (resource == "image" and re.fullmatch(
        r"(?:ghcr\.io/durable-workflow/server|mysql:8\.0|docker\.io/tonistiigi/binfmt)@sha256:[0-9a-f]{64}", identity))
    if not pinned_image and not re.fullmatch(r"(?:sha256:[0-9a-f]{64}|[A-Za-z0-9][A-Za-z0-9_.-]{0,180})", identity):
        raise Refusal("docker_resource_identity")
    require(platform is None or resource == "image" and platform in PLATFORMS, "docker_inspect_platform")
    args = ["docker", resource, "inspect"] + (["--platform", platform] if platform else []) + [identity]
    value = strict_json(commands.run(args), JSON_LIMIT)
    require(type(value) is list and len(value) == 1 and type(value[0]) is dict,
            "docker_inspect_shape")
    return value[0]


def observe_image(provider: GitHubRegistryProvider, role: str, image: str,
                  container: str, platform: str) -> dict[str, Any]:
    if not eligible_context(role, image):
        return {"eligible": False, "reason": "nonpromotion_context"}
    require(platform in PLATFORMS, "qualification_platform")
    admission = admit(provider, role, image) if branch_context(role, image) else None
    release = admission["subject"]["release"] if admission else {
        "source_commit": os.environ["GITHUB_SHA"], "tag": os.environ["GITHUB_REF_NAME"]}
    source, tag = release["source_commit"], release["tag"]
    actual_source = provider.commands.run(["git", "rev-parse", "HEAD"], 128).decode().strip()
    require(actual_source == os.environ["GITHUB_SHA"], "qualification_checkout")
    index = image.split("@", 1)[1]
    subject = provider.index_subjects(GHCR_IMAGE, index, source, tag)[platform]
    instance = inspect(provider.commands, "container", container)
    local = {}
    if admission:
        config, local = local_image(provider, subject, image, platform, index, instance)
    else:
        require(instance.get("Image") == subject["image_config_digest"], "actual_container_image")
        config = inspect(provider.commands, "image", subject["image_config_digest"])
        require(config.get("Id") == subject["image_config_digest"]
                and config.get("Architecture") == platform.split("/")[1]
                and config.get("Os") == "linux", "actual_container_platform")
    require(type(config.get("Config")) is dict, "actual_container_platform")
    labels = config["Config"].get("Labels")
    require(type(labels) is dict and labels.get("org.opencontainers.image.revision") == source
            and labels.get("dev.durable-workflow.release.tag") == tag, "actual_container_source")
    extra = {}
    if admission:
        profile = os.environ.get("DW_PUBLISHED_COMPOSE_PROFILE") if role == "compose" else None
        observed_scope = resource_scope(role, int(os.environ["GITHUB_RUN_ID"]), int(os.environ["GITHUB_RUN_ATTEMPT"]), platform, profile)
        require(os.environ.get("DW_PUBLISHED_COMPOSE_PROJECT" if role == "compose" else "RESOURCE_SCOPE") == observed_scope,
                "qualification_resource_scope")
        extra = {"runner_ref": admission["runner"]["ref"], "subject_blob": admission["runner"]["subject_blob"],
                 "resource_scope": observed_scope, **local,
                 "dependencies": observe_dependencies(provider, role, platform, observed_scope, admission["subject"])}
    return {"eligible": True, "requested_image": image, "index_digest": index,
            "platform": platform, **subject, "actual_image_config_digest": subject["image_config_digest"],
            "container_image_architecture": config["Architecture"], "container_image_os": config["Os"],
            "image_source_commit": source, "image_release_tag": tag, **extra}


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
    admission = admit(provider, role, image) if branch_context(role, image) else None
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
    if admission:
        image_keys |= {"runner_ref", "subject_blob", "resource_scope", "dependencies"} | LOCAL_IMAGE_KEYS
        require(identity.get("runner_ref") == admission["runner"]["ref"]
                and identity.get("subject_blob") == admission["runner"]["subject_blob"]
                and identity.get("resource_scope") == resource_scope(role,run_id,attempt,platform,profile),
                "qualification_identity_runner")
        bind_dependencies(identity.get("dependencies"), role, platform, admission["subject"])
    closed(identity, image_keys | {"eligible"}, "qualification_identity_shape")
    return {"schema": RECEIPT_SCHEMA_V2 if admission else RECEIPT_SCHEMA, "kind": role, "eligible": True,
            "repository": REPOSITORY, "repository_id": REPOSITORY_ID,
            "run_id": run_id, "run_attempt": attempt, "job_id": selected[0]["id"],
            "runner_commit": source, "workflow_path": WORKFLOWS[role], "workflow_blob": workflow_blob,
            "profile": profile, **{key: identity[key] for key in image_keys},
            "observations": observations, "cleanup": cleanup_result}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("admit", "observe-image", "cleanup", "finalize"))
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
    if args.phase == "admit":
        value = (admit(provider, args.kind, args.image) if os.environ.get("GITHUB_EVENT_NAME") == "workflow_dispatch"
                 and os.environ.get("GITHUB_REF") == RUNNER_REF
                 else {"eligible": False, "reason": "nonpromotion_context"})
    elif args.phase == "observe-image":
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
