#!/usr/bin/env python3
"""Build and verify immutable Durable Workflow Helm chart releases."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path
from typing import Any


REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CHART_PATH = REPOSITORY_ROOT / "k8s/helm/durable-workflow"
DEFAULT_SOURCE_RELEASE_PATH = REPOSITORY_ROOT / "resources/release/source-release.json"
DEFAULT_OCI_REPOSITORY = "oci://ghcr.io/durable-workflow/charts/durable-workflow"
SOURCE_REVISION_ANNOTATION = "dev.durable-workflow.source-revision"
IMAGE_REFERENCE_ANNOTATION = "dev.durable-workflow.image-reference"
SEMVER_PATTERN = re.compile(
    r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
    r"(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$"
)
DIGEST_PATTERN = re.compile(r"sha256:[0-9a-f]{64}")


class ReleaseError(RuntimeError):
    """A chart release invariant failed."""


class ImageNotFoundError(ReleaseError):
    """The registry positively reported that the exact image does not exist."""


def run(
    arguments: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
    check: bool = True,
) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        arguments,
        cwd=cwd,
        env=env,
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise ReleaseError(f"{' '.join(arguments)} failed: {detail}")
    return result


def unquote(value: str) -> str:
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
        return value[1:-1]
    return value


def top_level_scalar(source: str, key: str) -> str:
    pattern = re.compile(rf"^{re.escape(key)}:\s*(.+?)\s*$")
    for line in source.splitlines():
        match = pattern.match(line)
        if match:
            return unquote(match.group(1))
    raise ReleaseError(f"Chart metadata is missing top-level {key}")


def mapping_scalars(source: str, key: str) -> dict[str, str]:
    lines = source.splitlines()
    start = next(
        (index for index, line in enumerate(lines) if line == f"{key}:"),
        None,
    )
    if start is None:
        raise ReleaseError(f"YAML is missing the {key} mapping")

    values: dict[str, str] = {}
    for line in lines[start + 1 :]:
        if line and not line.startswith(" "):
            break
        match = re.match(r"^  ([A-Za-z0-9_.-]+):\s*(.*?)\s*$", line)
        if match and match.group(2) not in {"", "|", ">"}:
            values[match.group(1)] = unquote(match.group(2))
    return values


def chart_metadata(chart_path: Path = DEFAULT_CHART_PATH) -> dict[str, Any]:
    chart_source = (chart_path / "Chart.yaml").read_text()
    values_source = (chart_path / "values.yaml").read_text()
    annotations = mapping_scalars(chart_source, "annotations")
    image = mapping_scalars(values_source, "image")

    name = top_level_scalar(chart_source, "name")
    version = top_level_scalar(chart_source, "version")
    app_version = top_level_scalar(chart_source, "appVersion")
    registry = image.get("registry", "")
    repository = image.get("repository", "")
    tag = image.get("tag", "") or app_version
    digest = image.get("digest", "")

    if not registry or not repository:
        raise ReleaseError("values.yaml image.registry and image.repository are required")
    if digest:
        image_reference = f"{registry}/{repository}@{digest}"
    else:
        image_reference = f"{registry}/{repository}:{tag}"

    return {
        "name": name,
        "version": version,
        "app_version": app_version,
        "annotations": annotations,
        "image_reference": image_reference,
        "image_tag": tag,
        "release_image_reference": f"{registry}/{repository}:{app_version}",
    }


def source_release_metadata(
    record_path: Path = DEFAULT_SOURCE_RELEASE_PATH,
) -> dict[str, str]:
    try:
        record = json.loads(record_path.read_text())
        schema = record["schema"]
        server_version = record["server"]["version"]
        chart_version = record["helm_chart"]["version"]
    except (OSError, json.JSONDecodeError, KeyError, TypeError) as error:
        raise ReleaseError(f"source release manifest is invalid: {error}") from error
    if schema != "durable-workflow.server.source-release/v1":
        raise ReleaseError(f"source release manifest uses unsupported schema {schema}")
    if not isinstance(server_version, str) or SEMVER_PATTERN.fullmatch(server_version) is None:
        raise ReleaseError("source release Server version is not SemVer")
    if not isinstance(chart_version, str) or SEMVER_PATTERN.fullmatch(chart_version) is None:
        raise ReleaseError("source release Helm chart version is not SemVer")
    return {"server_version": server_version, "chart_version": chart_version}


def onboarding_server_version() -> str:
    return source_release_metadata()["server_version"]


def validate_source(chart_path: Path = DEFAULT_CHART_PATH) -> dict[str, Any]:
    metadata = chart_metadata(chart_path)
    if SEMVER_PATTERN.fullmatch(metadata["version"]) is None:
        raise ReleaseError(f"chart version is not SemVer: {metadata['version']}")
    if SEMVER_PATTERN.fullmatch(metadata["app_version"]) is None:
        raise ReleaseError(f"chart appVersion is not SemVer: {metadata['app_version']}")
    onboarding_version = onboarding_server_version()
    if metadata["image_tag"] != onboarding_version:
        raise ReleaseError(
            "the default image tag must equal the selected onboarding Server version; "
            f"got {metadata['image_tag']} and {onboarding_version}"
        )
    annotated_image = metadata["annotations"].get(IMAGE_REFERENCE_ANNOTATION)
    release_image = metadata["release_image_reference"]
    if annotated_image != release_image:
        raise ReleaseError(
            f"{IMAGE_REFERENCE_ANNOTATION} must retain the chart release image "
            f"{release_image}; got {annotated_image or '<missing>'}"
        )
    if SOURCE_REVISION_ANNOTATION not in metadata["annotations"]:
        raise ReleaseError(f"Chart.yaml is missing {SOURCE_REVISION_ANNOTATION}")
    source_release = source_release_metadata()
    if metadata["version"] != source_release["chart_version"]:
        raise ReleaseError(
            "Chart.yaml.version must match the authoritative source release Helm chart version; "
            f"got {metadata['version']} and {source_release['chart_version']}. "
            "Run node scripts/ci/sync-source-release.mjs --write"
        )
    if metadata["app_version"] != source_release["server_version"]:
        raise ReleaseError(
            "Chart.yaml.appVersion must match the authoritative Server source release; "
            f"got {metadata['app_version']} and {source_release['server_version']}. "
            "Run node scripts/ci/sync-source-release.mjs --write"
        )
    return metadata


def semver_key(version: str) -> tuple[int, int, int]:
    match = SEMVER_PATTERN.fullmatch(version)
    if match is None or match.group(4):
        raise ReleaseError(f"chart version must be a stable numeric SemVer: {version}")
    return tuple(int(match.group(index)) for index in range(1, 4))


def git_output(arguments: list[str]) -> str:
    return run(["git", *arguments], cwd=REPOSITORY_ROOT).stdout.strip()


def check_version(base_ref: str, chart_path: Path = DEFAULT_CHART_PATH) -> None:
    metadata = validate_source(chart_path)
    relative_chart = chart_path.relative_to(REPOSITORY_ROOT)
    changed = git_output(
        ["diff", "--name-only", f"{base_ref}...HEAD", "--", str(relative_chart)]
    ).splitlines()
    if not changed:
        print("No packaged Helm chart files changed.")
        return

    previous_source = run(
        ["git", "show", f"{base_ref}:{relative_chart}/Chart.yaml"],
        cwd=REPOSITORY_ROOT,
    ).stdout
    previous_version = top_level_scalar(previous_source, "version")
    if semver_key(metadata["version"]) <= semver_key(previous_version):
        raise ReleaseError(
            "every packaged Helm chart change must advance Chart.yaml.version; "
            f"{previous_version} -> {metadata['version']} is not an increase"
        )
    print(
        f"Packaged Helm chart changes advance version "
        f"{previous_version} -> {metadata['version']}."
    )


def replace_source_revision(chart_yaml: Path, source_revision: str) -> None:
    if re.fullmatch(r"[0-9a-f]{40}", source_revision) is None:
        raise ReleaseError(f"source revision must be a lowercase 40-character SHA: {source_revision}")

    source = chart_yaml.read_text()
    pattern = re.compile(
        rf"^(  {re.escape(SOURCE_REVISION_ANNOTATION)}:\s*).+$",
        re.MULTILINE,
    )
    replaced, count = pattern.subn(rf'\g<1>"{source_revision}"', source)
    if count != 1:
        raise ReleaseError(
            f"expected exactly one {SOURCE_REVISION_ANNOTATION} annotation, found {count}"
        )
    chart_yaml.write_text(replaced)


def helm_value_arguments() -> list[str]:
    return [
        "--namespace",
        "durable-workflow",
        "--set-string",
        "externalDatabase.host=database.example.invalid",
        "--set-string",
        "externalDatabase.auth.username=workflow",
        "--set-string",
        "externalDatabase.auth.password=not-a-secret",
        "--set-string",
        "externalRedis.host=redis.example.invalid",
        "--set-string",
        "auth.serverKey=base64:bm90LWEtc2VjcmV0",
        "--set-string",
        "auth.workerToken=not-a-secret",
        "--set-string",
        "auth.operatorToken=not-a-secret",
        "--set-string",
        "auth.adminToken=not-a-secret",
    ]


def helm_fixture_value_arguments() -> list[str]:
    return [
        "--namespace",
        "durable-workflow",
        "--set-string",
        "externalDatabase.host=mysql.durable-workflow.svc.cluster.local",
        "--set-string",
        "externalDatabase.auth.username=durable_workflow",
        "--set-string",
        "externalDatabase.auth.password=durable_workflow",
        "--set-string",
        "externalRedis.host=redis.durable-workflow.svc.cluster.local",
        "--set-string",
        "auth.serverKey=base64:bm90LWEtc2VjcmV0",
        "--set-string",
        "auth.workerToken=not-a-secret",
        "--set-string",
        "auth.operatorToken=not-a-secret",
        "--set-string",
        "auth.adminToken=not-a-secret",
        "--set",
        "server.replicaCount=1",
        "--set",
        "server.pdb.enabled=false",
        "--set",
        "worker.replicaCount=1",
    ]


def helm_template_arguments(
    reference: str, version: str, release_name: str
) -> list[str]:
    return [
        "template",
        release_name,
        reference,
        "--version",
        version,
        *helm_value_arguments(),
    ]


def helm_install_arguments(
    reference: str, version: str, release_name: str
) -> list[str]:
    return [
        "install",
        release_name,
        reference,
        "--version",
        version,
        "--create-namespace",
        "--wait",
        "--timeout",
        "5m",
        *helm_fixture_value_arguments(),
    ]


def write_output(name: str, value: str) -> None:
    output = os.environ.get("GITHUB_OUTPUT")
    if output:
        with open(output, "a", encoding="utf-8") as stream:
            stream.write(f"{name}={value}\n")


def package_chart(
    source_revision: str,
    output_directory: Path,
    chart_path: Path = DEFAULT_CHART_PATH,
    helm: str = "helm",
) -> Path:
    metadata = validate_source(chart_path)
    output_directory.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="durable-workflow-chart-stage-") as temporary:
        staged_chart = Path(temporary) / metadata["name"]
        shutil.copytree(chart_path, staged_chart)
        replace_source_revision(staged_chart / "Chart.yaml", source_revision)
        run([helm, "lint", str(staged_chart)])
        run([helm, "package", str(staged_chart), "--destination", str(output_directory)])

    package = output_directory / f"{metadata['name']}-{metadata['version']}.tgz"
    if not package.is_file():
        raise ReleaseError(f"helm package did not create {package}")
    run(
        [
            helm,
            *helm_template_arguments(
                str(package), metadata["version"], "source-package-check"
            ),
        ]
    )
    write_output("chart_package", str(package))
    write_output("chart_version", metadata["version"])
    write_output("chart_app_version", metadata["app_version"])
    write_output("chart_image_reference", metadata["release_image_reference"])
    return package


def extract_package(package: Path, destination: Path) -> Path:
    with tarfile.open(package, mode="r:gz") as archive:
        for member in archive.getmembers():
            member_path = Path(member.name)
            if member_path.is_absolute() or ".." in member_path.parts:
                raise ReleaseError(f"{package} contains an unsafe path: {member.name}")
            target = destination / member_path
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            if not member.isfile():
                raise ReleaseError(f"{package} contains an unsupported entry: {member.name}")
            source = archive.extractfile(member)
            if source is None:
                raise ReleaseError(f"cannot extract {member.name} from {package}")
            target.parent.mkdir(parents=True, exist_ok=True)
            with source, target.open("wb") as output:
                shutil.copyfileobj(source, output)
    roots = [entry for entry in destination.iterdir() if entry.is_dir()]
    if len(roots) != 1:
        raise ReleaseError(f"{package} must contain exactly one chart root directory")
    return roots[0]


def content_manifest(package: Path) -> dict[str, str]:
    with tempfile.TemporaryDirectory(prefix="durable-workflow-chart-compare-") as temporary:
        root = extract_package(package, Path(temporary))
        return {
            str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in sorted(root.rglob("*"))
            if path.is_file()
        }


def fresh_helm_environment(root: Path) -> dict[str, str]:
    environment = os.environ.copy()
    environment.update(
        {
            "XDG_CACHE_HOME": str(root / "cache"),
            "XDG_CONFIG_HOME": str(root / "config"),
            "XDG_DATA_HOME": str(root / "data"),
            "HELM_REGISTRY_CONFIG": str(root / "config" / "helm" / "registry.json"),
            "HELM_REPOSITORY_CACHE": str(root / "cache" / "helm" / "repository"),
            "HELM_REPOSITORY_CONFIG": str(root / "config" / "helm" / "repositories.yaml"),
        }
    )
    return environment


def pull_chart(
    destination: Path,
    metadata: dict[str, Any],
    oci_repository: str,
    *,
    helm: str = "helm",
    env: dict[str, str] | None = None,
    check: bool = True,
) -> subprocess.CompletedProcess[str]:
    destination.mkdir(parents=True, exist_ok=True)
    return run(
        [
            helm,
            "pull",
            oci_repository,
            "--version",
            metadata["version"],
            "--destination",
            str(destination),
        ],
        env=env,
        check=check,
    )


def preflight(
    package: Path,
    oci_repository: str = DEFAULT_OCI_REPOSITORY,
    helm: str = "helm",
) -> bool:
    metadata = validate_source()
    with tempfile.TemporaryDirectory(prefix="durable-workflow-chart-existing-") as temporary:
        destination = Path(temporary)
        result = pull_chart(
            destination,
            metadata,
            oci_repository,
            helm=helm,
            check=False,
        )
        if result.returncode != 0:
            detail = f"{result.stdout}\n{result.stderr}".lower()
            missing_markers = ("not found", "manifest unknown", "no results found")
            if not any(marker in detail for marker in missing_markers):
                raise ReleaseError(
                    "cannot establish whether the chart version already exists: "
                    f"{result.stderr.strip() or result.stdout.strip()}"
                )
            write_output("chart_should_push", "true")
            print(f"Chart {metadata['version']} is not present and may be published.")
            return True

        existing = destination / package.name
        if content_manifest(existing) != content_manifest(package):
            raise ReleaseError(
                f"OCI chart version {metadata['version']} already exists with changed content; "
                "advance Chart.yaml.version before publishing"
            )
        shutil.copyfile(existing, package)
        write_output("chart_should_push", "false")
        print(
            f"OCI chart version {metadata['version']} already contains the exact chart content; "
            "the existing immutable package will be verified without another push."
        )
        return False


def package_metadata(package: Path) -> dict[str, Any]:
    with tempfile.TemporaryDirectory(prefix="durable-workflow-chart-metadata-") as temporary:
        root = extract_package(package, Path(temporary))
        return chart_metadata(root)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return f"sha256:{digest.hexdigest()}"


def resolve_image_digest(image_reference: str, docker: str = "docker") -> str:
    result = run(
        [docker, "buildx", "imagetools", "inspect", image_reference],
        check=False,
    )
    if result.returncode != 0:
        detail = f"{result.stdout}\n{result.stderr}".strip()
        normalized = detail.lower()
        normalized_reference = image_reference.lower()
        image_is_missing = (
            "manifest unknown" in normalized
            or f"{normalized_reference}: not found" in normalized
        )
        if image_is_missing:
            raise ImageNotFoundError(
                f"default Server image is not published: {image_reference}"
            )
        raise ReleaseError(
            "cannot establish whether the default Server image is published: "
            f"{detail or 'registry inspection returned no diagnostic output'}"
        )

    match = re.search(r"^Digest:\s*(sha256:[0-9a-f]{64})\s*$", result.stdout, re.MULTILINE)
    if match is None:
        raise ReleaseError(
            f"anonymous image inspection did not return a digest for {image_reference}"
        )
    digest = match.group(1)
    write_output("chart_image_digest", digest)
    return digest


def verify_oci(
    package: Path,
    source_revision: str,
    image_digest: str,
    evidence_path: Path,
    oci_repository: str = DEFAULT_OCI_REPOSITORY,
    helm: str = "helm",
) -> None:
    source = validate_source()
    if DIGEST_PATTERN.fullmatch(image_digest) is None:
        raise ReleaseError(f"image digest is invalid: {image_digest}")

    with tempfile.TemporaryDirectory(prefix="durable-workflow-chart-public-") as temporary:
        root = Path(temporary)
        environment = fresh_helm_environment(root / "helm-home")
        destination = root / "oci"
        pull_chart(
            destination,
            source,
            oci_repository,
            helm=helm,
            env=environment,
        )
        pulled = destination / package.name
        expected_digest = sha256_file(package)
        pulled_digest = sha256_file(pulled)
        if pulled_digest != expected_digest:
            raise ReleaseError(
                f"anonymous OCI pull returned {pulled_digest}, expected {expected_digest}"
            )
        run(
            [helm, *helm_install_arguments(oci_repository, source["version"], "public-oci-check")],
            env=environment,
        )
        published = package_metadata(pulled)

    if published["version"] != source["version"]:
        raise ReleaseError("published OCI chart version differs from Chart.yaml")
    if published["app_version"] != source["app_version"]:
        raise ReleaseError("published OCI chart appVersion differs from Chart.yaml")
    if published["image_reference"] != source["image_reference"]:
        raise ReleaseError("published OCI chart default image differs from values.yaml")
    if published["annotations"].get(SOURCE_REVISION_ANNOTATION) != source_revision:
        raise ReleaseError("published OCI chart source revision differs from the release source")

    evidence = {
        "schema": "durable-workflow-helm-release/v1",
        "chart": {
            "name": source["name"],
            "version": source["version"],
            "app_version": source["app_version"],
            "source_revision": source_revision,
            "package_digest": expected_digest,
        },
        "image": {
            "reference": source["release_image_reference"],
            "digest": image_digest,
        },
        "channels": {
            "oci": {
                "repository": oci_repository,
                "package_digest": expected_digest,
                "anonymous_install": "pass",
            },
        },
    }
    evidence_path.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n")
    print(
        f"Anonymous OCI install passed for {source['name']} {source['version']} "
        f"({expected_digest})."
    )


def default_source_revision() -> str:
    return git_output(["log", "-1", "--format=%H", "--", str(DEFAULT_CHART_PATH.relative_to(REPOSITORY_ROOT))])


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    source_parser = subparsers.add_parser("validate-source")
    source_parser.add_argument("--chart-path", type=Path, default=DEFAULT_CHART_PATH)

    version_parser = subparsers.add_parser("check-version")
    version_parser.add_argument("--base-ref", required=True)
    version_parser.add_argument("--chart-path", type=Path, default=DEFAULT_CHART_PATH)

    package_parser = subparsers.add_parser("package")
    package_parser.add_argument("--source-revision", default="")
    package_parser.add_argument("--output-directory", type=Path, default=REPOSITORY_ROOT / "dist/helm")
    package_parser.add_argument("--helm", default="helm")

    preflight_parser = subparsers.add_parser("preflight")
    preflight_parser.add_argument("--package", type=Path, required=True)
    preflight_parser.add_argument("--oci-repository", default=DEFAULT_OCI_REPOSITORY)
    preflight_parser.add_argument("--helm", default="helm")

    image_parser = subparsers.add_parser("resolve-image")
    image_parser.add_argument("--docker", default="docker")

    verify_parser = subparsers.add_parser("verify-oci")
    verify_parser.add_argument("--package", type=Path, required=True)
    verify_parser.add_argument("--source-revision", required=True)
    verify_parser.add_argument("--image-digest", required=True)
    verify_parser.add_argument("--evidence", type=Path, default=REPOSITORY_ROOT / "helm-release-evidence.json")
    verify_parser.add_argument("--oci-repository", default=DEFAULT_OCI_REPOSITORY)
    verify_parser.add_argument("--helm", default="helm")

    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.command == "validate-source":
        metadata = validate_source(args.chart_path)
        print(json.dumps(metadata, indent=2, sort_keys=True))
    elif args.command == "check-version":
        check_version(args.base_ref, args.chart_path)
    elif args.command == "package":
        revision = args.source_revision or default_source_revision()
        package_chart(revision, args.output_directory, helm=args.helm)
        write_output("chart_source_revision", revision)
    elif args.command == "preflight":
        preflight(args.package, args.oci_repository, args.helm)
    elif args.command == "resolve-image":
        resolve_image_digest(
            validate_source()["release_image_reference"],
            args.docker,
        )
    elif args.command == "verify-oci":
        verify_oci(
            args.package,
            args.source_revision,
            args.image_digest,
            args.evidence,
            args.oci_repository,
            args.helm,
        )


if __name__ == "__main__":
    try:
        main()
    except ImageNotFoundError as error:
        print(f"helm chart release deferred: {error}", file=sys.stderr)
        raise SystemExit(3)
    except ReleaseError as error:
        print(f"helm chart release failed: {error}", file=sys.stderr)
        raise SystemExit(1)
