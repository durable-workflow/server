#!/usr/bin/env python3
"""Read-only qualification predicates for protected native alias promotion.

A manifest selects provider objects. Only provider metadata, immutable source
bindings and bounded canonical receipts satisfy the predicates. This module
contains no registry mutation and never executes archive contents.
"""
from __future__ import annotations

import hashlib
import base64
import io
import json
import re
import stat
import struct
import zipfile
import zlib
from datetime import datetime
from typing import Any, Protocol

REPOSITORY = "durable-workflow/server"
REPOSITORY_ID = 1208272134
GHCR_IMAGE = "ghcr.io/durable-workflow/server"
DOCKERHUB_IMAGE = "durableworkflow/server"
MANIFEST_SCHEMA = "durable-workflow.native-alias-promotion-evidence.v1"
RECEIPT_SCHEMA = "durable-workflow.native-qualification-receipt.v1"
MANIFEST_SCHEMA_V2 = "durable-workflow.native-alias-promotion-evidence.v2"
RECEIPT_SCHEMA_V2 = "durable-workflow.native-qualification-receipt.v2"
LOCAL_IMAGE_KEYS = {"local_image_id", "local_manifest_digest", "config_digest_source"}
SUBJECT_PATH = "scripts/ci/native-qualification-subject.json"
RUNNER_REF = "refs/heads/feat/server-native32-qualification-runner"
RUNNER_PATHS = {
    ".github/workflows/native-image-parity.yml", ".github/workflows/published-compose.yml",
    ".github/workflows/native-promotion-contracts.yml",
    "scripts/ci/native_alias_evidence.py", "scripts/ci/native_alias_transport.py",
    "scripts/ci/native_qualification.py", SUBJECT_PATH,
    "scripts/ci/native-release-verify-publication.sh",
    "scripts/ci/check-docker-workflow-isolation.py",
    "tests/Unit/Support/native_alias_evidence_test.py", "tests/Unit/Support/native_qualification_test.py",
    "tests/Feature/TaskQueueAdmissionTest.php",
    "README.md",
}
MANIFEST_LIMIT = 8192
RECEIPT_LIMIT = 32768
ARCHIVE_LIMIT = 65536
JSON_LIMIT = 1048576
SHA = re.compile(r"[0-9a-f]{40}\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
FINAL_TAG = re.compile(r"(?:native-\d+|[3-9]|[1-9]\d+)\.\d+\.\d+\Z", re.ASCII)
AMD64 = "linux/amd64"
PLATFORMS = (AMD64, "linux/arm64")
PROFILES = ("local", "production")
WORKFLOWS = {
    "release": ".github/workflows/native-release.yml",
    "corpus": ".github/workflows/native-image-parity.yml",
    "compose": ".github/workflows/published-compose.yml",
}
CORPUS_JOB = "Compare the native image over the shared fixture corpus (SQLite)"
RELEASE_JOBS = {
    "Choose the registries for this tag": (
        "Require a dispatch from the release tag, at its commit, on main",
        "Publish to both registries",
    ),
    **{
        "Build and smoke " + platform: (
            "Verify the release tag names the planned commit",
            "Build and push " + platform + " by digest only",
            "Smoke-test the pushed " + platform + " digest on its own architecture",
        ) for platform in PLATFORMS
    },
    "Tag, verify and attest the native index": (
        "Tag and verify the index in each registry",
        "Attest the index's build provenance in GHCR",
        "Attest the index's build provenance in Docker Hub",
    ),
}
QUALIFICATION_STEPS = {
    "corpus": (
        "Record and compare the corpus against the native image",
        "Verify owned qualification cleanup",
        "Finalize promotion receipt",
        "Keep promotion receipt",
    ),
    "compose": (
        "Smoke published Compose stack",
        "Verify owned qualification cleanup",
        "Finalize promotion receipt",
        "Keep promotion receipt",
    ),
}


class Refusal(ValueError):
    """A bounded static reason, safe to print without provider payloads."""


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise Refusal(reason)


def closed(value: Any, keys: set[str], reason: str) -> dict[str, Any]:
    require(type(value) is dict and set(value) == keys, reason)
    return value


def positive(value: Any) -> bool:
    return type(value) is int and 0 < value <= 9007199254740991


def literal(actual: Any, expected: Any) -> bool:
    return type(actual) is type(expected) and actual == expected


def matching(value: Any, expression: re.Pattern[str]) -> bool:
    return type(value) is str and expression.fullmatch(value) is not None


def canonical(value: Any) -> bytes:
    try:
        return (json.dumps(value, ensure_ascii=False, sort_keys=True,
                           separators=(",", ":"), allow_nan=False) + "\n").encode("utf-8")
    except (ValueError, RecursionError):
        raise Refusal("invalid_canonical_json") from None


def zip_extra(data: bytes) -> None:
    # GitHub's ZIP writer may carry the standard extended timestamp. Unknown
    # fields are unnecessary carriers; no ZIP64 is needed for a <=32-KiB member.
    seen = set()
    while data:
        require(len(data) >= 4, "artifact_extra_field")
        kind, length = struct.unpack_from("<HH", data)
        require(kind == 0x5455 and kind not in seen and 1 <= length <= 13
                and len(data) >= 4 + length, "artifact_extra_field")
        value, data = data[4:4 + length], data[4 + length:]
        require(value[0] <= 7 and length == 1 + 4 * value[0].bit_count(), "artifact_extra_timestamp")
        seen.add(kind)


def strict_json(data: bytes, limit: int) -> Any:
    require(type(data) is bytes and len(data) <= limit, "json_size_refused")

    def pairs(items: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in items:
            require(key not in result, "duplicate_json_member")
            result[key] = value
        return result

    def not_integer(_: str) -> Any:
        raise Refusal("noninteger_json_number")

    try:
        return json.loads(data.decode("utf-8", errors="strict"),
                          object_pairs_hook=pairs, parse_float=not_integer,
                          parse_constant=not_integer)
    except (RecursionError, ValueError) as error:
        if isinstance(error, Refusal):
            raise
        raise Refusal("invalid_json") from None


def qualification_subject(value: Any) -> dict[str, Any]:
    value = closed(value, {"schema", "release", "mysql", "qemu"}, "qualification_subject_shape")
    require(value["schema"] == "durable-workflow.native-qualification-subject.v1", "qualification_subject_schema")
    release = closed(value["release"], {"tag", "source_commit", "index_digest", "run_id", "run_attempt"}, "release_shape")
    require(matching(release["tag"], FINAL_TAG) and matching(release["source_commit"], SHA)
            and matching(release["index_digest"], DIGEST) and positive(release["run_id"])
            and positive(release["run_attempt"]), "qualification_release_subject")
    for name, repository in (("mysql", "mysql:8.0"), ("qemu", "docker.io/tonistiigi/binfmt")):
        dependency = closed(value[name], {"requested_image", "child_manifest_digest", "image_config_digest"},
                            "qualification_dependency_shape")
        require(matching(dependency["child_manifest_digest"], DIGEST)
                and matching(dependency["image_config_digest"], DIGEST)
                and dependency["image_config_digest"] != dependency["child_manifest_digest"]
                and dependency["requested_image"] == repository + "@" + dependency["child_manifest_digest"],
                "qualification_dependency_subject")
    return value


def runner_selection(value: Any) -> dict[str, str]:
    value = closed(value, {"source_commit", "ref", "subject_blob"}, "qualification_runner_shape")
    require(matching(value["source_commit"], SHA) and value["ref"] == RUNNER_REF
            and matching(value["subject_blob"], SHA), "qualification_runner_identity")
    return value


def commit_is(value: Any, sha: str) -> bool:
    return type(value) is dict and value.get("sha") == sha


def runner_subject(provider: Provider, runner: dict[str, str], release: dict[str, Any]) -> dict[str, Any]:
    """A reviewed runner may differ only in the bounded qualification source set."""
    runner = runner_selection(runner)
    source = runner["source_commit"]
    record = provider.api(f"repos/{REPOSITORY}/contents/{SUBJECT_PATH}?ref={source}")
    require(type(record) is dict and record.get("path") == SUBJECT_PATH and record.get("type") == "file"
            and record.get("sha") == runner["subject_blob"] and record.get("encoding") == "base64"
            and type(record.get("content")) is str and len(record["content"]) <= 12000,
            "qualification_subject_blob")
    try:
        data = base64.b64decode(record["content"].replace("\n", ""), validate=True)
    except ValueError:
        raise Refusal("qualification_subject_encoding") from None
    # Git's object format requires SHA-1 for this object identifier. The
    # selected commit, protected-main ancestry and frozen delta bind authority.
    object_id = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data,
                             usedforsecurity=False).hexdigest()
    require(len(data) <= MANIFEST_LIMIT and literal(record.get("size"), len(data))
            and object_id == runner["subject_blob"],
            "qualification_subject_digest")
    subject = qualification_subject(strict_json(data, MANIFEST_LIMIT))
    require(subject["release"] == release, "qualification_subject_release")
    difference = provider.api(f"repos/{REPOSITORY}/compare/{release['source_commit']}...{source}?per_page=100")
    require(type(difference) is dict and difference.get("status") == "ahead"
            and commit_is(difference.get("base_commit"),release["source_commit"])
            and commit_is(difference.get("merge_base_commit"),release["source_commit"])
            and type(difference.get("total_commits")) is int and 1 <= difference["total_commits"] <= 32
            and type(difference.get("commits")) is list
            and len(difference["commits"]) == difference["total_commits"]
            and commit_is(difference["commits"][-1],source), "qualification_runner_ancestry")
    files = difference.get("files")
    require(type(files) is list and 1 <= len(files) <= len(RUNNER_PATHS)
            and all(type(item) is dict and item.get("filename") in RUNNER_PATHS
                    and item.get("status") in ("added", "modified")
                    and "previous_filename" not in item for item in files), "qualification_runner_frozen_source")
    main = provider.api(f"repos/{REPOSITORY}/compare/{source}...main?per_page=1")
    require(type(main) is dict and main.get("status") in ("ahead", "identical")
            and commit_is(main.get("base_commit"),source)
            and commit_is(main.get("merge_base_commit"),source), "qualification_runner_not_on_main")
    return subject


def manifest(data: bytes) -> dict[str, Any]:
    decoded = strict_json(data, MANIFEST_LIMIT)
    v2 = type(decoded) is dict and decoded.get("schema") == MANIFEST_SCHEMA_V2
    value = closed(decoded, {"schema", "release", "corpus", "compose"} | ({"runner"} if v2 else set()), "manifest_shape")
    require(value["schema"] in (MANIFEST_SCHEMA, MANIFEST_SCHEMA_V2), "manifest_schema")
    runner = runner_selection(value["runner"]) if v2 else None
    release = closed(value["release"],
                     {"tag", "source_commit", "index_digest", "run_id", "run_attempt"},
                     "release_shape")
    require(matching(release["tag"], FINAL_TAG), "final_release_required")
    require(matching(release["source_commit"], SHA), "release_source")
    require(matching(release["index_digest"], DIGEST), "release_index")
    require(positive(release["run_id"]) and positive(release["run_attempt"]), "release_run")
    corpus = closed(value["corpus"],
                    {"run_id", "run_attempt", "runner_commit", "workflow_blob",
                     "artifact_id", "artifact_digest"}, "corpus_shape")
    compose = closed(value["compose"],
                     {"run_id", "run_attempt", "runner_commit", "workflow_blob", "cells"},
                     "compose_shape")
    for role, selected in (("corpus", corpus), ("compose", compose)):
        require(positive(selected["run_id"]) and positive(selected["run_attempt"]), role + "_run")
        require(selected["runner_commit"] == (runner["source_commit"] if runner else release["source_commit"]), role + "_runner_source")
        require(matching(selected["workflow_blob"], SHA), role + "_workflow_blob")
    require(len({release["run_id"], corpus["run_id"], compose["run_id"]}) == 3,
            "distinct_workflow_runs_required")
    require(positive(corpus["artifact_id"]) and matching(corpus["artifact_digest"], DIGEST),
            "corpus_artifact")
    cells = compose["cells"]
    require(type(cells) is list and len(cells) == 4, "four_compose_cells_required")
    keys, jobs, artifacts = set(), set(), {corpus["artifact_id"]}
    for cell in cells:
        cell = closed(cell, {"platform", "profile", "job_id", "artifact_id", "artifact_digest"},
                      "compose_cell_shape")
        require(cell["platform"] in PLATFORMS and cell["profile"] in PROFILES,
                "compose_cell_key")
        key = (cell["platform"], cell["profile"])
        require(key not in keys and positive(cell["job_id"]) and cell["job_id"] not in jobs,
                "duplicate_compose_cell_or_job")
        require(positive(cell["artifact_id"]) and cell["artifact_id"] not in artifacts
                and matching(cell["artifact_digest"], DIGEST), "compose_artifact")
        keys.add(key)
        jobs.add(cell["job_id"])
        artifacts.add(cell["artifact_id"])
    require(keys == {(platform, profile) for platform in PLATFORMS for profile in PROFILES},
            "compose_cartesian_set")
    return value


def archive_receipt(data: bytes) -> dict[str, Any]:
    """Read one canonical regular JSON member, without extracting anything."""
    require(type(data) is bytes and len(data) <= ARCHIVE_LIMIT, "artifact_archive_size")
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            members = archive.infolist()
            require(len(members) == 1 and not archive.comment, "artifact_inventory")
            entry = members[0]
            mode = stat.S_IFMT(entry.external_attr >> 16)
            require(entry.filename == "receipt.json" and not entry.is_dir()
                    and not (entry.external_attr & 0x10)
                    and mode in (0, stat.S_IFREG), "artifact_member_type")
            require(not (entry.flag_bits & 1)
                    and entry.compress_type in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED),
                    "artifact_member_encoding")
            require(0 < entry.file_size <= RECEIPT_LIMIT
                    and entry.file_size <= 100 * max(entry.compress_size, 1),
                    "artifact_expansion")
            require(not entry.comment and entry.header_offset == 0 and data[:4] == b"PK\x03\x04"
                    and len(data) >= 22 and data[-22:-18] == b"PK\x05\x06",
                    "artifact_archive_layout")
            # No prefix, trailing carrier, extra disk or unindexed local member.
            disk, directory_disk, disk_count, count, directory_size, directory_at, comment = (
                struct.unpack_from("<HHHHIIH", data, len(data) - 18))
            require(disk == directory_disk == 0 and disk_count == count == 1 and comment == 0
                    and directory_at + directory_size == len(data) - 22,
                    "artifact_directory_layout")
            local_flags, local_compression = struct.unpack_from("<HH", data, 6)
            require(local_flags == entry.flag_bits and local_compression == entry.compress_type
                    and not (local_flags & 1), "artifact_local_encoding")
            name_length, extra_length = struct.unpack_from("<HH", data, 26)
            require(data[30:30 + name_length] == b"receipt.json", "artifact_local_name")
            zip_extra(entry.extra)
            zip_extra(data[30 + name_length:30 + name_length + extra_length])
            end = 30 + name_length + extra_length + entry.compress_size
            require(end <= directory_at, "artifact_overlapping_directory")
            descriptor = data[end:directory_at]
            if entry.flag_bits & 8:
                if descriptor.startswith(b"PK\x07\x08"):
                    descriptor = descriptor[4:]
                require(len(descriptor) == 12
                        and struct.unpack("<III", descriptor) ==
                        (entry.CRC, entry.compress_size, entry.file_size),
                        "artifact_data_descriptor")
            else:
                require(not descriptor, "artifact_unindexed_data")
            body = archive.read(entry)
    except (zipfile.BadZipFile, RuntimeError, OSError, EOFError, struct.error, zlib.error):
        raise Refusal("artifact_archive_invalid") from None
    value = strict_json(body, RECEIPT_LIMIT)
    require(type(value) is dict and canonical(value) == body, "receipt_not_canonical")
    return value


def timestamp(value: Any) -> datetime:
    require(type(value) is str and len(value) <= 40, "provider_timestamp")
    try:
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise Refusal("provider_timestamp") from None
    require(result.tzinfo is not None, "provider_timestamp_timezone")
    return result


class Provider(Protocol):
    """Implementations perform bounded reads only; fake providers need no network."""

    def api(self, path: str) -> Any: ...
    def archive(self, artifact_id: int) -> bytes: ...
    def workflow_blob(self, commit: str, path: str) -> str: ...
    def publication_subjects(self, release: dict[str, Any]) -> dict[str, dict[str, Any]]: ...


def workflow_run(provider: Provider, selected: dict[str, Any], role: str,
                 release: dict[str, Any], runner: dict[str,str] | None = None) -> dict[str, Any]:
    run = provider.api(f"repos/{REPOSITORY}/actions/runs/{selected['run_id']}")
    require(type(run) is dict and literal(run.get("id"), selected["run_id"]), "provider_run_id")
    for field in ("repository", "head_repository"):
        repository = run.get(field)
        require(type(repository) is dict and literal(repository.get("id"), REPOSITORY_ID)
                and repository.get("full_name") == REPOSITORY, "provider_repository")
    require(literal(run.get("run_attempt"), selected["run_attempt"]), "provider_current_attempt")
    require(run.get("status") == "completed" and run.get("conclusion") == "success",
            "provider_run_not_successful")
    qualifier = runner if role != "release" else None
    require(run.get("event") == "workflow_dispatch"
            and run.get("head_sha") == (qualifier["source_commit"] if qualifier else release["source_commit"])
            and run.get("head_branch") == (qualifier["ref"].removeprefix("refs/heads/") if qualifier else release["tag"])
            and run.get("path") == WORKFLOWS[role], "provider_run_source")
    workflow = provider.api(f"repos/{REPOSITORY}/actions/workflows/{WORKFLOWS[role].split('/')[-1]}")
    require(type(workflow) is dict and positive(workflow.get("id"))
            and literal(run.get("workflow_id"), workflow["id"])
            and workflow.get("path") == WORKFLOWS[role], "provider_workflow_identity")
    image = GHCR_IMAGE + "@" + release["index_digest"]
    if role == "release":
        title = f"Native {release['tag']} at {release['source_commit']}"
    else:
        prefix = "Native image parity " if role == "corpus" else "Published Compose "
        title = prefix + image
    require(run.get("display_title") == title, "provider_dispatch_input_binding")
    timestamp(run.get("run_started_at"))
    timestamp(run.get("updated_at"))
    return run


def jobs(provider: Provider, run: dict[str, Any], required: dict[str, tuple[str, ...]]) -> dict[str, Any]:
    page = provider.api(f"repos/{REPOSITORY}/actions/runs/{run['id']}/jobs?filter=all&per_page=100")
    require(type(page) is dict and type(page.get("jobs")) is list
            and type(page.get("total_count")) is int
            and len(page["jobs"]) == page["total_count"] <= 100, "provider_jobs_incomplete")
    selected, ids = {}, set()
    for job in page["jobs"]:
        require(type(job) is dict and positive(job.get("id"))
                and job["id"] not in ids and literal(job.get("run_id"), run["id"])
                and positive(job.get("run_attempt")), "provider_job_identity")
        ids.add(job["id"])
        require(job["run_attempt"] <= run["run_attempt"], "provider_later_job_attempt")
        if job["run_attempt"] != run["run_attempt"]:
            continue
        name = job.get("name")
        require(type(name) is str and name in required and name not in selected,
                "provider_job_set")
        require(job.get("head_sha") == run["head_sha"] and job.get("status") == "completed"
                and job.get("conclusion") == "success", "provider_job_not_successful")
        steps = job.get("steps")
        require(type(steps) is list, "provider_steps_missing")
        for critical in required[name]:
            matches = [step for step in steps if type(step) is dict and step.get("name") == critical]
            require(len(matches) == 1 and matches[0].get("status") == "completed"
                    and matches[0].get("conclusion") == "success", "provider_required_step")
        timestamp(job.get("completed_at"))
        selected[name] = job
    require(set(selected) == set(required), "provider_required_jobs_missing")
    return selected


def artifact_metadata(provider: Provider, artifact_id: int, digest: str, name: str,
                      run: dict[str, Any], job: dict[str, Any]) -> dict[str, Any]:
    metadata = provider.api(f"repos/{REPOSITORY}/actions/artifacts/{artifact_id}")
    require(type(metadata) is dict and literal(metadata.get("id"), artifact_id)
            and metadata.get("name") == name and metadata.get("expired") is False
            and metadata.get("digest") == digest, "provider_artifact_identity")
    require(positive(metadata.get("size_in_bytes")) and metadata["size_in_bytes"] <= ARCHIVE_LIMIT,
            "provider_artifact_size")
    owner = metadata.get("workflow_run")
    require(type(owner) is dict and literal(owner.get("id"), run["id"])
            and literal(owner.get("repository_id"), REPOSITORY_ID)
            and literal(owner.get("head_repository_id"), REPOSITORY_ID)
            and owner.get("head_sha") == run["head_sha"], "provider_artifact_owner")
    require(timestamp(run["run_started_at"]) <= timestamp(metadata.get("created_at"))
            <= timestamp(job["completed_at"]), "provider_artifact_attempt_window")
    return metadata


def receipt(provider: Provider, artifact_id: int, digest: str, name: str,
            run: dict[str, Any], job: dict[str, Any]) -> dict[str, Any]:
    artifact_metadata(provider, artifact_id, digest, name, run, job)
    archive = provider.archive(artifact_id)
    require(len(archive) <= ARCHIVE_LIMIT
            and "sha256:" + hashlib.sha256(archive).hexdigest() == digest,
            "provider_artifact_digest")
    return archive_receipt(archive)


RECEIPT_KEYS = {
    "schema", "kind", "eligible", "repository", "repository_id", "run_id", "run_attempt",
    "job_id", "runner_commit", "workflow_path", "workflow_blob", "requested_image",
    "index_digest", "platform", "profile", "child_manifest_digest", "image_config_digest",
    "actual_image_config_digest", "container_image_architecture", "container_image_os",
    "image_source_commit", "image_release_tag", "observations", "cleanup",
}
CORPUS_OBSERVATIONS = {"reference_records", "native_record", "compare", "service_calls",
                       "schedule_catalog", "namespace_delete_payloads"}
COMPOSE_OBSERVATIONS = {"health_serving", "ready", "cluster_version_matches_release",
                        "worker_registered", "loopback_binding", "unset_token_refused",
                        "production_default_token_refused"}


def bind_receipt(value: dict[str, Any], role: str, selected: dict[str, Any],
                 run: dict[str, Any], job: dict[str, Any], release: dict[str, Any],
                 platform: str, profile: str | None, subject: dict[str, Any],
                 runner: dict[str,str] | None = None, binding: dict[str,Any] | None = None) -> None:
    extra = {"runner_ref", "subject_blob", "resource_scope", "dependencies"} | LOCAL_IMAGE_KEYS if runner else set()
    value = closed(value, RECEIPT_KEYS | extra, "receipt_shape")
    require(value["schema"] == (RECEIPT_SCHEMA_V2 if runner else RECEIPT_SCHEMA) and value["kind"] == role
            and value["eligible"] is True, "receipt_not_eligible")
    expected = {
        "repository": REPOSITORY, "repository_id": REPOSITORY_ID,
        "run_id": run["id"], "run_attempt": run["run_attempt"], "job_id": job["id"],
        "runner_commit": runner["source_commit"] if runner else release["source_commit"], "workflow_path": WORKFLOWS[role],
        "workflow_blob": selected["workflow_blob"],
        "requested_image": GHCR_IMAGE + "@" + release["index_digest"],
        "index_digest": release["index_digest"], "platform": platform, "profile": profile,
        "child_manifest_digest": subject["child_manifest_digest"],
        "image_config_digest": subject["image_config_digest"],
        "actual_image_config_digest": subject["image_config_digest"],
        "container_image_architecture": platform.split("/")[1], "container_image_os": "linux",
        "image_source_commit": release["source_commit"], "image_release_tag": release["tag"],
    }
    require(all(literal(value[key], expected_value) for key, expected_value in expected.items()),
            "receipt_source_or_subject_binding")
    if runner:
        require(value["runner_ref"] == runner["ref"] and value["subject_blob"] == runner["subject_blob"],
                "receipt_runner_binding")
        require(binding is not None and binding["release"] == release, "receipt_binding_missing")
        observed_scope = resource_scope(role, run["id"], run["run_attempt"], platform, profile)
        require(value["resource_scope"] == observed_scope, "receipt_resource_scope")
        bind_local_image(value, subject, release["index_digest"])
        bind_dependencies(value["dependencies"], role, platform, binding)
    cleanup = closed(value["cleanup"],
                     {"api_ok", "verified_absent", "container_count", "network_count", "volume_count"},
                     "receipt_cleanup_shape")
    require(cleanup["api_ok"] is True and cleanup["verified_absent"] is True
            and all(literal(cleanup[key], 0) for key in ("container_count", "network_count", "volume_count")),
            "receipt_cleanup_unqualified")
    observations = closed(value["observations"],
                          CORPUS_OBSERVATIONS if role == "corpus" else COMPOSE_OBSERVATIONS,
                          "receipt_observation_shape")
    if role == "corpus":
        require(all(literal(result, 0) for result in observations.values()), "receipt_corpus_failed")
    else:
        require(all(result is True for key, result in observations.items()
                    if key != "production_default_token_refused")
                and literal(observations["production_default_token_refused"],
                            True if profile == "production" else None), "receipt_compose_failed")


def resource_scope(role: str, run: int, attempt: int, platform: str, profile: str | None) -> str:
    return (f"dw-parity-{run}-{attempt}-corpus" if role == "corpus" else
            f"published-compose-{run}-{attempt}-smoke-{platform.split('/')[1]}-{profile}")


def bind_local_image(value: dict[str, Any], subject: dict[str, Any], root_digest: str) -> None:
    require(matching(value["local_image_id"], DIGEST), "receipt_local_image_identity")
    if value["config_digest_source"] == "engine_id":
        require(value["local_image_id"] == subject["image_config_digest"]
                and value["local_manifest_digest"] is None, "receipt_local_image_identity")
    else:
        require(value["config_digest_source"] == "manifest_descriptor"
                and value["local_image_id"] in {root_digest, subject["child_manifest_digest"]}
                and value["local_manifest_digest"] == subject["child_manifest_digest"],
                "receipt_local_image_identity")


def bind_dependencies(value: Any, role: str, platform: str, subject: dict[str,Any]) -> None:
    if role == "corpus":
        closed(value, set(), "receipt_dependency_shape")
        return
    value = closed(value, {"mysql", "qemu"}, "receipt_dependency_shape")
    for name in ("mysql", "qemu"):
        if name == "qemu" and platform == AMD64:
            require(value[name] is None, "receipt_unexpected_qemu")
            continue
        keys = {"requested_image", "child_manifest_digest", "image_config_digest",
                "actual_image_config_digest", "container_image_architecture", "container_image_os"}
        observed = closed(value[name], keys | LOCAL_IMAGE_KEYS | ({"container_id"} if name == "mysql" else set()),
                          "receipt_dependency_identity")
        expected = {**subject[name], "actual_image_config_digest": subject[name]["image_config_digest"],
                    "container_image_architecture": "amd64", "container_image_os": "linux"}
        require(all(literal(observed[key], item) for key,item in expected.items()), "receipt_dependency_binding")
        bind_local_image(observed, subject[name], subject[name]["child_manifest_digest"])
        if name == "mysql":
            require(type(observed["container_id"]) is str and re.fullmatch(r"[0-9a-f]{64}", observed["container_id"]),
                    "receipt_database_container")


def qualification_receipts(provider: Provider, role: str, selected: dict[str, Any],
                           run: dict[str, Any], release: dict[str, Any],
                           subjects: dict[str, Any], runner: dict[str, str] | None,
                           binding: dict[str, Any] | None) -> list[tuple[Any, ...]]:
    required = ({CORPUS_JOB: QUALIFICATION_STEPS[role]} if role == "corpus" else {
        platform + " " + profile + " published image": QUALIFICATION_STEPS[role]
        for platform in PLATFORMS for profile in PROFILES})
    if runner:
        required = {name: ("Admit published qualification subject",) + steps
                    + (("Set up QEMU",) if name.startswith("linux/arm64") else ())
                    for name, steps in required.items()}
    current_jobs = jobs(provider, run, required)
    checks = []
    if role == "corpus":
        job = current_jobs[CORPUS_JOB]
        checks.append((selected["artifact_id"], selected["artifact_digest"],
                       "native-corpus-promotion-receipt", run, job))
        record = receipt(provider, *checks[-1])
        bind_receipt(record, role, selected, run, job, release, AMD64, None,
                     subjects[AMD64], runner, binding)
        return checks
    for cell in selected["cells"]:
        platform, profile = cell["platform"], cell["profile"]
        job = current_jobs[platform + " " + profile + " published image"]
        require(literal(cell["job_id"], job["id"]), "compose_selected_job")
        name = "native-compose-promotion-receipt-" + platform.split("/")[1] + "-" + profile
        checks.append((cell["artifact_id"], cell["artifact_digest"], name, run, job))
        record = receipt(provider, *checks[-1])
        bind_receipt(record, role, selected, run, job, release, platform, profile,
                     subjects[platform], runner, binding)
    return checks


def verify(value: dict[str, Any], provider: Provider) -> dict[str, Any]:
    """Verify once. Re-read mutable run/artifact authority before returning."""
    # Revalidate programmatic callers exactly as CLI manifest bytes.
    value = manifest(canonical(value))
    release = value["release"]
    runner = value.get("runner")
    binding = runner_subject(provider, runner, release) if runner else None
    publication = workflow_run(provider, release, "release", release)
    publication_jobs = jobs(provider, publication, RELEASE_JOBS)
    completed = max([timestamp(publication["updated_at"])] +
                    [timestamp(job["completed_at"]) for job in publication_jobs.values()])
    subjects = provider.publication_subjects(release)
    require(type(subjects) is dict and set(subjects) == set(PLATFORMS), "publication_platform_set")
    for subject in subjects.values():
        require(type(subject) is dict
                and matching(subject.get("child_manifest_digest"), DIGEST)
                and matching(subject.get("image_config_digest"), DIGEST)
                and len({release["index_digest"], subject["child_manifest_digest"],
                         subject["image_config_digest"]}) == 3, "publication_subject_identity")
    require(len({subject["child_manifest_digest"] for subject in subjects.values()}) == 2
            and len({subject["image_config_digest"] for subject in subjects.values()}) == 2,
            "publication_duplicate_platform_subject")
    snapshots, artifact_ids, artifact_checks = [("release", release)], [], []
    for role in ("corpus", "compose"):
        selected = value[role]
        require(provider.workflow_blob(selected["runner_commit"], WORKFLOWS[role]) == selected["workflow_blob"],
                "qualifier_workflow_source")
        run = workflow_run(provider, selected, role, release, runner)
        require(timestamp(run["run_started_at"]) >= completed, "qualification_precedes_publication")
        checks = qualification_receipts(provider, role, selected, run, release, subjects, runner, binding)
        artifact_checks.extend(checks)
        artifact_ids.extend(check[0] for check in checks)
        snapshots.append((role, selected))
    for role, selected in snapshots:
        workflow_run(provider, selected, role, release, runner)
    if runner:
        runner_subject(provider, runner, release)
    for selected_artifact in artifact_checks:
        artifact_metadata(provider, *selected_artifact)
    return {"schema": "durable-workflow.native-alias-verification.v2" if runner else "durable-workflow.native-alias-verification.v1",
            "release": release, "subjects": subjects, "qualified_artifact_ids": artifact_ids,
            **({"runner":runner,"qualification_subject":binding} if runner else {})}


if __name__ == "__main__":
    raise SystemExit("native_alias_evidence is a library; executable verification is not wired")
