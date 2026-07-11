#!/usr/bin/env python3
"""Resolve public CLI and Rust artifacts with auditable HTTP provenance."""

from __future__ import annotations

import json
import re
import urllib.error
import urllib.request
from typing import Any, Callable


FetchJson = Callable[[str], tuple[dict[str, Any], str, dict[str, Any]]]
FetchText = Callable[[str], tuple[str, dict[str, Any]]]


class PublicArtifactResolutionError(RuntimeError):
    """An exact public artifact could not be resolved."""

    def __init__(self, message: str, commands: list[dict[str, Any]] | None = None):
        super().__init__(message)
        self.commands = commands or []


class HttpFetchError(PublicArtifactResolutionError):
    """A public HTTP endpoint failed with recorded request provenance."""

    def __init__(self, message: str, status: int | None, command: dict[str, Any]):
        super().__init__(message, [command])
        self.status = status


def fetch_bytes(url: str, accept: str = "application/octet-stream") -> tuple[bytes, dict[str, Any]]:
    request = urllib.request.Request(
        url,
        headers={"Accept": accept, "User-Agent": "durable-workflow-conformance"},
    )
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            body = response.read()
            status = int(getattr(response, "status", 200))
    except urllib.error.HTTPError as exc:
        body = exc.read()
        output = body.decode("utf-8", errors="replace")[-4000:]
        command = {
            "argv": ["GET", url],
            "exit_code": 1,
            "http_status": exc.code,
            "output": output,
        }
        raise HttpFetchError(f"GET {url} returned HTTP {exc.code}", exc.code, command) from exc
    except urllib.error.URLError as exc:
        command = {
            "argv": ["GET", url],
            "exit_code": 1,
            "output": str(exc.reason)[-4000:],
        }
        raise HttpFetchError(f"GET {url} failed: {exc.reason}", None, command) from exc

    output = body.decode("utf-8", errors="replace")[-4000:]
    return body, {
        "argv": ["GET", url],
        "exit_code": 0,
        "http_status": status,
        "output": output,
    }


def fetch_json(url: str) -> tuple[dict[str, Any], str, dict[str, Any]]:
    body, command = fetch_bytes(url, "application/json")
    output = body.decode("utf-8", errors="replace")[-4000:]
    try:
        value = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        command["exit_code"] = 1
        raise PublicArtifactResolutionError(f"{url} returned invalid JSON: {exc}", [command]) from exc
    if not isinstance(value, dict):
        command["exit_code"] = 1
        raise PublicArtifactResolutionError(f"{url} returned a non-object response", [command])
    return value, output, command


def fetch_text(url: str) -> tuple[str, dict[str, Any]]:
    body, command = fetch_bytes(url, "text/plain")
    return body.decode("utf-8"), command


def resolve_cli_release(
    version: str,
    fetch_json_fn: FetchJson = fetch_json,
) -> tuple[str, list[dict[str, Any]]]:
    """Resolve install.sh from the exact release, accepting public tag conventions."""

    normalized = version.removeprefix("v")
    commands: list[dict[str, Any]] = []
    release: dict[str, Any] | None = None
    candidates = list(dict.fromkeys([normalized, f"v{normalized}"]))

    for tag in candidates:
        candidate_url = f"https://api.github.com/repos/durable-workflow/cli/releases/tags/{tag}"
        try:
            release, _, command = fetch_json_fn(candidate_url)
            commands.append(command)
            break
        except HttpFetchError as exc:
            commands.extend(exc.commands)
            if exc.status == 404:
                continue
            raise PublicArtifactResolutionError(str(exc), commands) from exc
        except PublicArtifactResolutionError as exc:
            commands.extend(exc.commands)
            raise PublicArtifactResolutionError(str(exc), commands) from exc

    if release is None:
        raise PublicArtifactResolutionError(
            f"CLI release {normalized} was not found under the supported exact tag conventions",
            commands,
        )

    observed_tag = str(release.get("tag_name") or "").removeprefix("v")
    if observed_tag != normalized:
        raise PublicArtifactResolutionError(
            f"CLI release resolved tag {observed_tag!r}, expected {normalized!r}",
            commands,
        )
    assets = release.get("assets") if isinstance(release.get("assets"), list) else []
    installer_url = next(
        (
            str(asset.get("browser_download_url"))
            for asset in assets
            if isinstance(asset, dict) and asset.get("name") == "install.sh"
        ),
        "",
    )
    if not installer_url:
        raise PublicArtifactResolutionError(
            f"CLI release {observed_tag} does not publish install.sh",
            commands,
        )
    return installer_url, commands


def _validate_crates_api_version(crate: dict[str, Any], version: str) -> str:
    version_record = crate.get("version") if isinstance(crate.get("version"), dict) else {}
    observed = str(version_record.get("num") or "")
    if observed != version:
        raise RuntimeError(f"crates.io resolved durable-workflow {observed!r}, expected {version}")
    if version_record.get("yanked") is True:
        raise RuntimeError(f"crates.io reports durable-workflow {version} as yanked")
    return json.dumps(version_record, sort_keys=True)


def _sparse_registry_record(body: str, version: str) -> dict[str, Any] | None:
    for line in body.splitlines():
        try:
            record = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(record, dict) and str(record.get("vers") or "") == version:
            return record
    return None


def resolve_rust_crate(
    version: str,
    fetch_json_fn: FetchJson = fetch_json,
    fetch_text_fn: FetchText = fetch_text,
) -> tuple[list[dict[str, Any]], str]:
    """Verify an exact crate via the API, falling back to the sparse index."""

    commands: list[dict[str, Any]] = []
    api_url = f"https://crates.io/api/v1/crates/durable-workflow/{version}"
    try:
        crate, _, command = fetch_json_fn(api_url)
        commands.append(command)
        return commands, _validate_crates_api_version(crate, version)
    except HttpFetchError as exc:
        commands.extend(exc.commands)
    except PublicArtifactResolutionError as exc:
        commands.extend(exc.commands)
        raise PublicArtifactResolutionError(str(exc), commands) from exc
    except RuntimeError as exc:
        raise PublicArtifactResolutionError(str(exc), commands) from exc

    sparse_url = "https://index.crates.io/du/ra/durable-workflow"
    try:
        body, command = fetch_text_fn(sparse_url)
        commands.append(command)
    except PublicArtifactResolutionError as exc:
        commands.extend(exc.commands)
        raise PublicArtifactResolutionError(
            f"could not verify durable-workflow {version} through crates.io API or sparse index: {exc}",
            commands,
        ) from exc

    record = _sparse_registry_record(body, version)
    if record is None:
        raise PublicArtifactResolutionError(
            f"crates.io sparse index does not contain durable-workflow {version}",
            commands,
        )
    if record.get("yanked") is not False:
        raise PublicArtifactResolutionError(
            f"crates.io sparse index does not confirm durable-workflow {version} as not yanked",
            commands,
        )
    checksum = str(record.get("cksum") or "")
    if re.fullmatch(r"[0-9a-f]{64}", checksum) is None:
        raise PublicArtifactResolutionError(
            f"crates.io sparse index omitted a valid checksum for durable-workflow {version}",
            commands,
        )
    return commands, json.dumps(record, sort_keys=True)
