#!/usr/bin/env python3
"""Bound one strict, uncompressed OCI archive graph; no extraction or execution.

This source component does not select/qualify an exporter or authenticate the
expected smoked config. Docker's hybrid export is deliberately unsupported.
"""
from __future__ import annotations

import hashlib
from datetime import datetime
import os
from pathlib import Path
import re
import stat
import tarfile
import time
from typing import BinaryIO, Callable, TypeVar

from native_sbom_policy import Refusal, is_digest, parse, require


ARCHIVE_LIMIT = 2 * 1024 * 1024 * 1024
MEMBER_LIMIT = 1024
LAYER_LIMIT = 128
JSON_LIMIT = 1048576
BLOB = re.compile(r"blobs/sha256/[0-9a-f]{64}\Z")
MANIFEST_TYPE = "application/vnd.oci.image.manifest.v1+json"
CONFIG_TYPE = "application/vnd.oci.image.config.v1+json"
LAYER_TYPE = "application/vnd.oci.image.layer.v1.tar"
EXPORT_NAME = "input.oci.tar"
ConsumerResult = TypeVar("ConsumerResult")


class ArchiveReader:
    def __init__(self, stream: BinaryIO, seconds: float):
        self.stream = stream
        self.deadline = time.monotonic() + seconds
        self.count = 0
        self.digest = hashlib.sha256()

    def read(self, size: int) -> bytes:
        require(time.monotonic() < self.deadline, "sbom_archive_deadline")
        raw = self.stream.read(size)
        require(type(raw) is bytes and len(raw) <= size, "sbom_archive_reader")
        self.count += len(raw)
        require(self.count <= ARCHIVE_LIMIT, "sbom_archive_size")
        self.digest.update(raw)
        require(time.monotonic() < self.deadline, "sbom_archive_deadline")
        return raw

    def exact(self, size: int) -> bytes:
        raw = self.read(size)
        require(len(raw) == size, "sbom_archive_truncated")
        return raw


def members(reader: ArchiveReader) -> dict[str, dict]:
    result = {}
    names = set()
    while True:
        block = reader.exact(512)
        if block == bytes(512):
            require(reader.exact(512) == bytes(512), "sbom_archive_end")
            padding = 0
            while True:
                trailing = reader.read(8192)
                padding += len(trailing)
                require(padding <= 65536 and not any(trailing), "sbom_archive_trailing")
                if not trailing:
                    return result
        try:
            # Parse only this header. Never invoke tarfile's extension reader,
            # extractor, sparse expansion or compressed-archive detection.
            item = tarfile.TarInfo.frombuf(block, encoding="utf-8", errors="strict")
        except (tarfile.HeaderError, UnicodeError, ValueError):
            raise Refusal("sbom_archive_header") from None
        name = item.name
        directory = item.type == tarfile.DIRTYPE
        allowed_directory = directory and name in ("blobs", "blobs/sha256")
        allowed_file = item.type in (tarfile.REGTYPE, tarfile.AREGTYPE) and (
            name in ("oci-layout", "index.json") or BLOB.fullmatch(name) is not None)
        require((allowed_directory or allowed_file) and not item.linkname
                and type(item.size) is int and 0 <= item.size <= ARCHIVE_LIMIT,
                "sbom_archive_member")
        require(name not in names and len(names) < MEMBER_LIMIT, "sbom_archive_duplicate_or_count")
        names.add(name)
        if directory:
            require(item.size == 0, "sbom_archive_directory_size")
            continue
        offset, remaining = reader.count, item.size
        digest = hashlib.sha256()
        while remaining:
            data = reader.exact(min(65536, remaining))
            digest.update(data)
            remaining -= len(data)
        padding = reader.exact((-item.size) % 512)
        require(not any(padding), "sbom_archive_member_padding")
        hexdigest = digest.hexdigest()
        if name.startswith("blobs/"):
            require(name == "blobs/sha256/" + hexdigest, "sbom_archive_blob_digest")
        result[name] = {"offset": offset, "size": item.size, "sha256": hexdigest}


def json_member(reader: ArchiveReader, table: dict[str, dict], name: str, limit: int = JSON_LIMIT):
    require(name in table and 0 < table[name]["size"] <= limit, "sbom_archive_json_member")
    require(time.monotonic() < reader.deadline, "sbom_archive_deadline")
    item = table[name]
    reader.stream.seek(item["offset"])
    raw = reader.stream.read(item["size"])
    require(type(raw) is bytes and len(raw) == item["size"]
            and hashlib.sha256(raw).hexdigest() == item["sha256"], "sbom_archive_changed")
    require(time.monotonic() < reader.deadline, "sbom_archive_deadline")
    return parse(raw, limit)


def descriptor(value, media_type: str, table: dict[str, dict], platform: str | None = None,
               exporter_root: bool = False) -> str:
    allowed = {"mediaType", "digest", "size", "platform"}
    if exporter_root:
        allowed.add("annotations")
    require(type(value) is dict and set(value) <= allowed
            and value.get("mediaType") == media_type and is_digest(value.get("digest"))
            and type(value.get("size")) is int and 0 < value["size"] <= ARCHIVE_LIMIT,
            "sbom_archive_descriptor")
    if "annotations" in value:
        annotations = value["annotations"]
        require(type(annotations) is dict and set(annotations) == {"org.opencontainers.image.created"},
                "sbom_archive_export_annotation")
        created = annotations["org.opencontainers.image.created"]
        require(type(created) is str and re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z", created)
                is not None, "sbom_archive_export_annotation")
        try:
            datetime.strptime(created, "%Y-%m-%dT%H:%M:%SZ")
        except ValueError:
            raise Refusal("sbom_archive_export_annotation") from None
    if platform is not None and (not exporter_root or "platform" in value):
        selected = value.get("platform")
        require(type(selected) is dict and set(selected) <= {"os", "architecture", "variant"}
                and selected.get("os") == "linux" and selected.get("architecture") == platform.split("/")[1]
                and ("variant" not in selected or
                     (platform == "linux/arm64" and selected["variant"] == "v8")),
                "sbom_archive_descriptor_platform")
    elif platform is None:
        require("platform" not in value, "sbom_archive_descriptor_platform")
    name = "blobs/sha256/" + value["digest"][7:]
    require(name in table and table[name]["size"] == value["size"], "sbom_archive_descriptor_size")
    return name


def oci_archive_identity(stream: BinaryIO, expected_config: str, platform: str,
                         seconds: float = 30) -> dict:
    """Check a sealed seekable input against an independently smoked config.

    Use consume_owned_oci_export for on-disk custody. The expected config
    digest and platform come from the caller's independent smoke observation.
    """
    require(is_digest(expected_config) and platform in ("linux/amd64", "linux/arm64")
            and type(seconds) in (int, float) and 0 < seconds <= 30, "sbom_archive_inputs")
    require(stream.seekable() and stream.tell() == 0, "sbom_archive_stream")
    reader = ArchiveReader(stream, seconds)
    table = members(reader)
    require(json_member(reader, table, "oci-layout", 4096) == {"imageLayoutVersion": "1.0.0"},
            "sbom_archive_layout")
    index = json_member(reader, table, "index.json", 65536)
    require(type(index) is dict and set(index) == {"schemaVersion", "mediaType", "manifests"}
            and type(index["schemaVersion"]) is int and index["schemaVersion"] == 2
            and index["mediaType"] == "application/vnd.oci.image.index.v1+json"
            and type(index["manifests"]) is list and len(index["manifests"]) == 1, "sbom_archive_index")
    # BuildKit's OCI exporter adds only a presentation creation annotation and
    # can omit this optional platform field. The exact config below remains the
    # platform authority; neither annotation nor omission substitutes for it.
    manifest_name = descriptor(index["manifests"][0], MANIFEST_TYPE, table, platform, exporter_root=True)
    manifest = json_member(reader, table, manifest_name)
    require(type(manifest) is dict and set(manifest) == {"schemaVersion", "mediaType", "config", "layers"}
            and type(manifest["schemaVersion"]) is int and manifest["schemaVersion"] == 2
            and manifest["mediaType"] == MANIFEST_TYPE and type(manifest["layers"]) is list
            and 0 < len(manifest["layers"]) <= LAYER_LIMIT, "sbom_archive_manifest")
    config_name = descriptor(manifest["config"], CONFIG_TYPE, table)
    require(config_name == "blobs/sha256/" + expected_config[7:], "sbom_archive_smoked_config")
    config = json_member(reader, table, config_name)
    require(type(config) is dict and config.get("os") == "linux"
            and config.get("architecture") == platform.split("/")[1]
            and ("variant" not in config or (platform == "linux/arm64" and config["variant"] == "v8")),
            "sbom_archive_config_platform")
    rootfs = config.get("rootfs")
    require(type(rootfs) is dict and set(rootfs) == {"type", "diff_ids"} and rootfs["type"] == "layers"
            and type(rootfs["diff_ids"]) is list and len(rootfs["diff_ids"]) == len(manifest["layers"])
            and all(is_digest(value) for value in rootfs["diff_ids"]), "sbom_archive_rootfs")
    selected = {"oci-layout", "index.json", config_name, manifest_name}
    for layer, diff_id in zip(manifest["layers"], rootfs["diff_ids"]):
        name = descriptor(layer, LAYER_TYPE, table)
        require(name == "blobs/sha256/" + diff_id[7:], "sbom_archive_layer_diff_id")
        selected.add(name)
    require(set(table) == selected, "sbom_archive_unreferenced_member")
    return {"archive_sha256": reader.digest.hexdigest(), "archive_bytes": reader.count,
            "image_config_digest": expected_config, "platform": platform,
            "archive_manifest_digest": "sha256:" + table[manifest_name]["sha256"],
            "layer_count": len(manifest["layers"])}


def _file_identity(info: os.stat_result) -> tuple[int, ...]:
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid, info.st_nlink,
            info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _check_export_path(directory: Path, directory_fd: int, directory_identity: tuple[int, ...],
                       archive_identity: tuple[int, ...]) -> None:
    try:
        named_directory = directory.stat(follow_symlinks=False)
        named_archive = os.stat(EXPORT_NAME, dir_fd=directory_fd, follow_symlinks=False)
    except OSError:
        raise Refusal("sbom_export_path") from None
    require(_file_identity(named_directory) == directory_identity, "sbom_export_directory_changed")
    require(_file_identity(named_archive) == archive_identity, "sbom_export_file_changed")


def consume_owned_oci_export(directory: Path, expected_config: str, platform: str,
                            consume: Callable[[Path], ConsumerResult]) -> tuple[dict, ConsumerResult]:
    """Use the reviewed OCI export while retaining its original descriptor.

    The producer creates a fresh private output directory and finishes before
    this call. The trusted caller supplies a bounded synchronous consumer that
    mounts the returned path read-only, uses its nonroot owner UID, and waits
    for its reader to exit.
    """
    require(directory.is_absolute(), "sbom_export_directory")
    try:
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    except OSError:
        raise Refusal("sbom_export_directory") from None
    try:
        try:
            directory_info = os.fstat(directory_fd)
            require(directory_info.st_uid == os.geteuid() and stat.S_IMODE(directory_info.st_mode) == 0o700,
                    "sbom_export_directory")
            directory_identity = _file_identity(directory_info)
            info = os.stat(EXPORT_NAME, dir_fd=directory_fd, follow_symlinks=False)
            require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                    and info.st_nlink == 1 and not info.st_mode & 0o022
                    and 0 < info.st_size <= ARCHIVE_LIMIT, "sbom_export_file")
            fd = os.open(EXPORT_NAME, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory_fd)
        except OSError:
            raise Refusal("sbom_export_open") from None

        # Reread the descriptor after consumption without reusing cached bytes.
        with os.fdopen(fd, "rb", buffering=0) as stream:
            try:
                original = _file_identity(os.fstat(fd))
                require(original == _file_identity(info), "sbom_export_file_changed")
                _check_export_path(directory, directory_fd, directory_identity, original)

                # The scanner can read the bind mount as the nonroot owner.
                os.fchmod(fd, stat.S_IRUSR)
                sealed = _file_identity(os.fstat(fd))
                receipt = oci_archive_identity(stream, expected_config, platform)
                require(receipt["archive_bytes"] == info.st_size and _file_identity(os.fstat(fd)) == sealed,
                        "sbom_export_file_changed")
                _check_export_path(directory, directory_fd, directory_identity, sealed)
            except OSError:
                raise Refusal("sbom_export_read") from None

            result = consume(directory / EXPORT_NAME)

            try:
                _check_export_path(directory, directory_fd, directory_identity, sealed)
                stream.seek(0)
                reader = ArchiveReader(stream, 30)
                while reader.count < receipt["archive_bytes"]:
                    reader.exact(min(65536, receipt["archive_bytes"] - reader.count))
                require(not reader.read(1) and reader.digest.hexdigest() == receipt["archive_sha256"]
                        and _file_identity(os.fstat(fd)) == sealed, "sbom_export_file_changed")
                _check_export_path(directory, directory_fd, directory_identity, sealed)
            except OSError:
                raise Refusal("sbom_export_read") from None
            return receipt, result
    finally:
        os.close(directory_fd)


if __name__ == "__main__":
    raise SystemExit("native_sbom_archive is a source component; producer/scan authority is not wired")
