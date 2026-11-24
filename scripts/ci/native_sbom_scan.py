"""Produce raw SPDX from the held native OCI export for separate validation."""
from __future__ import annotations

import os
from pathlib import Path
import re

from native_alias_transport import Commands
from native_qualification import OBJECT_ID, OWNER, VOLUME_NAME, inspect, lines, owned_mounts
from native_sbom_archive import consume_owned_oci_export
from native_sbom_policy import PLATFORMS, RAW_LIMIT, ROOT, is_digest, parse, require

CONFIG = ROOT / "syft.yaml"
CONFIG_TARGET = "/syft.yaml"
ARCHIVE_TARGET = "/input.oci.tar"
CONFIG_BYTES = b"check-for-app-update: false\n"
SCANNER_ENV = ("HOME=/tmp", "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
SCANNER_TMPFS = "/tmp:rw,noexec,nosuid,nodev,size=512m,mode=1777"
SBOM_SCOPE = re.compile(r"(?:dw|general)-native-sbom-[a-z0-9][a-z0-9-]{0,94}")


def _scanner_inventory(commands: Commands, scope: str) -> set[str]:
    owner = "label=" + OWNER + "=" + scope
    containers = ["container", "ls", "-aq", "--no-trunc", "--filter"]
    owned = lines(commands, [*containers, owner], OBJECT_ID)
    named = lines(commands, [*containers, f"name=^/{scope}-(version|scan)$"], OBJECT_ID)
    require(owned == named and len(owned) <= 2, "sbom_cleanup_container_inventory")
    # The scanner creates neither networks nor labelled volumes.
    require(not lines(commands, ["network", "ls", "-q", "--no-trunc", "--filter", owner], OBJECT_ID)
            and not lines(commands, ["volume", "ls", "-q", "--filter", owner], VOLUME_NAME),
            "sbom_cleanup_unexpected_resource")
    return owned


def _cleanup_scanner(commands: Commands, scope: str) -> dict:
    require(SBOM_SCOPE.fullmatch(scope), "sbom_scanner_scope")
    containers = _scanner_inventory(commands, scope)
    names = {f"/{scope}-version", f"/{scope}-scan"}
    volumes = set()
    for identity in sorted(containers):
        observed = inspect(commands, "container", identity)
        name = observed.get("Name")
        config = observed.get("Config")
        labels = config.get("Labels") if type(config) is dict else None
        mounts = observed.get("Mounts")
        require(observed.get("Id") == identity and type(name) is str and name in names
                and type(labels) is dict and labels.get(OWNER) == scope
                and type(mounts) is list and all(type(mount) is dict for mount in mounts),
                "sbom_cleanup_container_owner")
        names.remove(name)
        volumes |= owned_mounts(commands, mounts, "sbom", scope)
    require(len(volumes) <= 64, "sbom_cleanup_volume_bound")
    if containers:
        commands.run(["docker", "container", "rm", "-f", "-v", *sorted(containers)])
    require(not _scanner_inventory(commands, scope), "sbom_cleanup_not_absent")
    # Docker removes the inspected anonymous mounts with the container. Read
    # back their exact names even if Docker returned success from removal.
    for name in sorted(volumes):
        require(not lines(commands, ["volume", "ls", "-q", "--filter", f"name=^{re.escape(name)}$"],
                          VOLUME_NAME), "sbom_cleanup_not_absent")
    return {"api_ok": True, "verified_absent": True, "container_count": 0,
            "network_count": 0, "volume_count": 0}


def _mounted_file(path: Path, target: str) -> str:
    require(path.is_absolute() and re.fullmatch(r"/[A-Za-z0-9_.\-/]+", str(path)),
            "sbom_mount_path")
    return f"type=bind,src={path},dst={target},readonly"


def _scanner_image(commands: Commands, platform: str, tool: dict) -> tuple[str, str]:
    name = tool["image"]
    child = tool["platforms"][platform]["child_manifest_digest"]
    config = tool["platforms"][platform]["image_config_digest"]
    require(name == "docker.io/anchore/syft" and is_digest(child) and is_digest(config),
            "sbom_scanner_pin")
    reference = f"{name}@{child}"
    images = parse(commands.run(["docker", "image", "inspect", "--platform", platform, reference]))
    require(type(images) is list and len(images) == 1 and type(images[0]) is dict,
            "sbom_scanner_image")
    image = images[0]
    identity = image.get("Id")
    names = image.get("RepoDigests")
    metadata = image.get("Config")
    require(type(names) is list and (reference in names or f"anchore/syft@{child}" in names)
            and image.get("Os") == "linux" and image.get("Architecture") == platform.split("/")[1]
            and type(metadata) is dict and metadata.get("Volumes") in (None, {})
            and metadata.get("Entrypoint") == ["/syft"], "sbom_scanner_image")
    if identity == child:
        descriptor = image.get("Descriptor")
        require(type(descriptor) is dict and descriptor.get("digest") == child,
                "sbom_scanner_manifest")
    else:
        require(identity == config, "sbom_scanner_config")
    return reference, identity


def _run_scanner(commands: Commands, scope: str, platform: str, reference: str, identity: str,
                 archive: Path | None, output_limit: int) -> bytes:
    user = f"{os.geteuid()}:{os.getegid()}"
    files = {CONFIG_TARGET: CONFIG}
    operation = "version"
    arguments = ["--config", CONFIG_TARGET, operation, "-o", "json"]
    if archive is not None:
        operation = "scan"
        files[ARCHIVE_TARGET] = archive
        arguments = ["--config", CONFIG_TARGET, operation, "--from", "oci-archive",
                     "--platform", platform, "--scope", "squashed", "-o", "spdx-json@2.3", ARCHIVE_TARGET]
    mounts = [arg for target, path in files.items() for arg in ("--mount", _mounted_file(path, target))]
    environment = [arg for setting in SCANNER_ENV for arg in ("--env", setting)]
    output = commands.run([
        "docker", "create", "--name", f"{scope}-{operation}", "--label", f"{OWNER}={scope}",
        "--platform", platform, "--pull=never", "--network=none", "--ipc=none", "--read-only",
        "--user", user, "--cap-drop=ALL", "--security-opt=no-new-privileges",
        *environment, "--tmpfs", SCANNER_TMPFS, "--workdir=/tmp",
        "--cpus=1", "--memory=1536m", "--memory-swap=1536m", "--pids-limit=64", "--log-driver=none",
        "--entrypoint=/syft", *mounts, reference, *arguments,
    ], 128)
    container_id = output.decode("ascii", errors="strict").removesuffix("\n")
    require(re.fullmatch(OBJECT_ID, container_id) is not None, "sbom_scanner_container_id")
    observed = inspect(commands, "container", container_id)
    config = observed.get("Config")
    labels = config.get("Labels") if type(config) is dict else None
    env = config.get("Env") if type(config) is dict else None
    require(observed.get("Id") == container_id and observed.get("Name") == f"/{scope}-{operation}"
            and observed.get("Image") == identity
            and type(config) is dict and config.get("Image") == reference
            and config.get("User") == user and config.get("Entrypoint") == ["/syft"]
            and config.get("Cmd") == arguments and type(labels) is dict and labels.get(OWNER) == scope,
            "sbom_scanner_container")
    require(type(env) is list and len(env) == len(SCANNER_ENV) and all(setting in env for setting in SCANNER_ENV)
            and config.get("WorkingDir") == "/tmp", "sbom_scanner_environment")
    host = observed.get("HostConfig")
    require(type(host) is dict and host.get("NetworkMode") == "none"
            and host.get("IpcMode") == "none"
            and host.get("ReadonlyRootfs") is True and host.get("Privileged") is False
            and host.get("CapDrop") == ["ALL"] and host.get("SecurityOpt") == ["no-new-privileges"]
            and not host.get("PortBindings") and host.get("PublishAllPorts") is False
            and host.get("Memory") == host.get("MemorySwap") == 1536 * 1024 * 1024
            and host.get("NanoCpus") == 1_000_000_000 and host.get("PidsLimit") == 64
            and host.get("Tmpfs") == {"/tmp": SCANNER_TMPFS.removeprefix("/tmp:")}
            and host.get("LogConfig") == {"Type": "none", "Config": {}},
            "sbom_scanner_confinement")
    reads = observed.get("Mounts")
    require(type(reads) is list and len(reads) == len(files)
            and all(type(mount) is dict and mount.get("Type") == "bind" and mount.get("RW") is False
                    for mount in reads)
            and {mount.get("Destination"): mount.get("Source") for mount in reads}
            == {target: str(path) for target, path in files.items()}, "sbom_scanner_mounts")
    return commands.run(["docker", "container", "start", "--attach", container_id], output_limit)


def collect_spdx(directory: Path, expected_config: str, platform: str, scope: str,
                 commands: Commands, cleanup_commands: Commands) -> tuple[dict, bytes]:
    """Return bytes only after both the scanner and the owned-resource check succeed."""
    require(platform in PLATFORMS and SBOM_SCOPE.fullmatch(scope), "sbom_scanner_scope")
    require(os.geteuid() > 0 and os.getegid() > 0, "sbom_scanner_nonroot")
    require(CONFIG.is_file() and not CONFIG.is_symlink(), "sbom_scanner_configuration")
    with CONFIG.open("rb") as config:
        require(config.read(len(CONFIG_BYTES) + 1) == CONFIG_BYTES, "sbom_scanner_configuration")
    tool = parse((ROOT / "tool-pins.v1.json").read_bytes())["generator"]
    reference, identity = _scanner_image(commands, platform, tool)

    def scan(held_path: Path) -> bytes:
        version = parse(_run_scanner(commands, scope, platform, reference, identity, None, 4096), 4096)
        require(type(version) is dict and version.get("application") == "syft"
                and version.get("version") == tool["version"]
                and version.get("gitCommit") == tool["upstream_source_commit"]
                and version.get("platform") == platform, "sbom_scanner_version")
        raw = _run_scanner(commands, scope, platform, reference, identity, held_path, RAW_LIMIT)
        require(0 < len(raw) <= RAW_LIMIT, "spdx_size")
        return raw

    try:
        export, raw = consume_owned_oci_export(directory, expected_config, platform, scan)
    except BaseException as error:
        try:
            _cleanup_scanner(cleanup_commands, scope)
        except Exception:
            error.add_note("sbom_cleanup_failed")
        raise
    absence = _cleanup_scanner(cleanup_commands, scope)
    return {"export": export, "scanner_image": reference, "scanner_version": tool["version"],
            "cleanup": absence}, raw


if __name__ == "__main__":
    raise SystemExit("native_sbom_scan is a source component; release scan authority is not wired")
