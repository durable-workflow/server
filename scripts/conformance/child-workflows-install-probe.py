#!/usr/bin/env python3
"""Install/resolve the exact public artifacts used by child conformance."""

from __future__ import annotations

import importlib.metadata
import json
import os
import shutil
import stat
import subprocess
import sys
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from child_workflows_artifact_resolver import (
    PublicArtifactResolutionError,
    fetch_bytes,
    resolve_cli_release,
    resolve_rust_crate,
)
from version_identities import same_python_release


RESULT_DIR = Path(sys.argv[1])
RUN_ROOT = Path(sys.argv[2])
REPO_ROOT = Path(sys.argv[3])


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def fetch_json(url: str) -> tuple[dict[str, Any], str]:
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "durable-workflow-conformance"})
    with urllib.request.urlopen(request, timeout=60) as response:
        body = response.read().decode("utf-8")
    value = json.loads(body)
    if not isinstance(value, dict):
        raise RuntimeError(f"{url} returned a non-object response")
    return value, body[-4000:]


def run(command: list[str], *, env: dict[str, str] | None = None, timeout: int = 300) -> tuple[int, str]:
    completed = subprocess.run(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        env=env,
        timeout=timeout,
        check=False,
    )
    return completed.returncode, completed.stdout[-8000:]


def entry(artifact: str, version: str, source: str, commands: list[dict[str, Any]], output: str, status: str = "pass") -> dict[str, Any]:
    return {
        "artifact": artifact,
        "version": version,
        "source": source,
        "status": status,
        "local_product_source_checkouts_used": False,
        "commands": commands,
        "command": commands[-1]["argv"] if commands else [],
        "output_sample": output[-4000:],
        "observed_at": now(),
    }


def require_version(name: str) -> str:
    value = os.environ.get(name, "").strip().removeprefix("v")
    if not value:
        raise RuntimeError(f"{name} is required")
    return value


server_version = require_version("DW_SERVER_VERSION")
cli_version = require_version("DW_CLI_VERSION")
python_version = require_version("DW_PYTHON_SDK_VERSION")
rust_version = require_version("DW_RUST_SDK_VERSION")
workflow_version = require_version("DW_WORKFLOW_PHP_VERSION")
waterline_version = require_version("DW_WATERLINE_VERSION")
server_image = os.environ.get("DW_SERVER_IMAGE", "").strip() or f"durableworkflow/server:{server_version}"

artifacts: list[dict[str, Any]] = []

try:
    if REPO_ROOT != Path("/app") or (REPO_ROOT / ".git").exists():
        raise RuntimeError("server probe is not executing from a source-free published image root")
    docker_url = f"https://hub.docker.com/v2/repositories/durableworkflow/server/tags/{server_version}"
    docker_tag, docker_output = fetch_json(docker_url)
    digest = str(docker_tag.get("digest") or "")
    if not digest.startswith("sha256:"):
        raise RuntimeError("Docker Hub tag response omitted an immutable digest")
    artifacts.append(entry(
        "server",
        server_version,
        f"docker://durableworkflow/server:{server_version}@{digest}",
        [{"argv": ["GET", docker_url], "exit_code": 0}],
        docker_output,
    ))
except Exception as exc:
    artifacts.append(entry("server", server_version, f"docker://durableworkflow/server:{server_version}", [], str(exc), "fail"))

try:
    installer_url, cli_commands = resolve_cli_release(cli_version)
    installer = RUN_ROOT / "cli-install.sh"
    try:
        installer_body, installer_fetch = fetch_bytes(installer_url)
    except PublicArtifactResolutionError as exc:
        raise PublicArtifactResolutionError(str(exc), cli_commands + exc.commands) from exc
    cli_commands.append(installer_fetch)
    installer.write_bytes(installer_body)
    installer.chmod(installer.stat().st_mode | stat.S_IXUSR)
    cli_bin_dir = RUN_ROOT / "cli-bin"
    cli_bin_dir.mkdir(parents=True, exist_ok=True)
    install_env = dict(os.environ)
    install_env.update({
        "VERSION": cli_version,
        "DURABLE_WORKFLOW_INSTALL_DIR": str(cli_bin_dir),
        "DURABLE_WORKFLOW_BIN_NAME": "dw",
    })
    install_env["PATH"] = os.pathsep.join(
        part for part in (str(cli_bin_dir), install_env.get("PATH", "")) if part
    )
    install_code, install_output = run(["sh", str(installer)], env=install_env)
    dw = cli_bin_dir / "dw"
    version_code, version_output = run([str(dw), "--version"]) if dw.exists() else (127, "dw binary missing")
    status = "pass" if install_code == 0 and version_code == 0 and cli_version in version_output else "fail"
    artifacts.append(entry(
        "cli",
        cli_version,
        installer_url,
        [
            *cli_commands,
            {"argv": ["sh", str(installer)], "exit_code": install_code, "output": install_output[-2000:]},
            {"argv": [str(dw), "--version"], "exit_code": version_code, "output": version_output[-2000:]},
        ],
        install_output + "\n" + version_output,
        status,
    ))
except Exception as exc:
    commands = exc.commands if isinstance(exc, PublicArtifactResolutionError) else []
    artifacts.append(entry("cli", cli_version, f"https://github.com/durable-workflow/cli/releases/tag/{cli_version}", commands, str(exc), "fail"))

python_venv = RUN_ROOT / "python-venv"
try:
    create_code, create_output = run([sys.executable, "-m", "venv", str(python_venv)])
    python_bin = python_venv / "bin" / "python"
    install_command = [str(python_bin), "-m", "pip", "install", "--disable-pip-version-check", "--no-input", f"durable-workflow=={python_version}"]
    install_code, install_output = run(install_command) if create_code == 0 else (1, create_output)
    probe_command = [str(python_bin), "-c", "import importlib.metadata as m; print(m.version('durable-workflow'))"]
    probe_code, probe_output = run(probe_command) if install_code == 0 else (1, install_output)
    status = "pass" if probe_code == 0 and same_python_release(python_version, probe_output.strip()) else "fail"
    artifacts.append(entry(
        "sdk-python",
        python_version,
        f"https://pypi.org/project/durable-workflow/{python_version}/",
        [
            {"argv": [sys.executable, "-m", "venv", str(python_venv)], "exit_code": create_code, "output": create_output[-1000:]},
            {"argv": install_command, "exit_code": install_code, "output": install_output[-3000:]},
            {"argv": probe_command, "exit_code": probe_code, "output": probe_output[-1000:]},
        ],
        install_output + "\n" + probe_output,
        status,
    ))
except Exception as exc:
    artifacts.append(entry("sdk-python", python_version, f"https://pypi.org/project/durable-workflow/{python_version}/", [], str(exc), "fail"))

try:
    rust_commands, crate_output = resolve_rust_crate(rust_version)
    artifacts.append(entry(
        "sdk-rust",
        rust_version,
        f"https://crates.io/crates/durable-workflow/{rust_version}",
        rust_commands,
        crate_output,
    ))
except Exception as exc:
    commands = exc.commands if isinstance(exc, PublicArtifactResolutionError) else []
    artifacts.append(entry("sdk-rust", rust_version, f"https://crates.io/crates/durable-workflow/{rust_version}", commands, str(exc), "fail"))


def packagist_entry(artifact: str, package: str, version: str, require_installed: bool) -> dict[str, Any]:
    url = f"https://repo.packagist.org/p2/{package}.json"
    payload, output = fetch_json(url)
    versions = payload.get("packages", {}).get(package, []) if isinstance(payload.get("packages"), dict) else []
    resolved = next(
        (item for item in versions if isinstance(item, dict) and str(item.get("version") or "").removeprefix("v") == version),
        None,
    )
    if resolved is None:
        raise RuntimeError(f"Packagist did not resolve {package}:{version}")
    commands: list[dict[str, Any]] = [{"argv": ["GET", url], "exit_code": 0, "output": output[-1500:]}]
    probe_output = json.dumps({"name": package, "version": resolved.get("version"), "dist": resolved.get("dist")}, sort_keys=True)
    if require_installed:
        installed_path = REPO_ROOT / "vendor" / "composer" / "installed.json"
        installed = json.loads(installed_path.read_text(encoding="utf-8"))
        packages = installed.get("packages", []) if isinstance(installed, dict) else installed
        installed_package = next(
            (item for item in packages if isinstance(item, dict) and item.get("name") == package),
            None,
        )
        installed_version = str((installed_package or {}).get("version") or "").removeprefix("v")
        commands.append({
            "argv": ["read", str(installed_path), package],
            "exit_code": 0 if installed_version == version else 1,
            "output": json.dumps(installed_package, sort_keys=True)[-2000:],
        })
        if installed_version != version:
            raise RuntimeError(f"published server bundles {package}:{installed_version}, expected {version}")
        probe_output += "\n" + json.dumps(installed_package, sort_keys=True)
    return entry(artifact, version, f"https://packagist.org/packages/{package}#{version}", commands, probe_output)


try:
    artifacts.append(packagist_entry("workflow-php", "durable-workflow/workflow", workflow_version, True))
except Exception as exc:
    artifacts.append(entry("workflow-php", workflow_version, f"https://packagist.org/packages/durable-workflow/workflow#{workflow_version}", [], str(exc), "fail"))

try:
    artifacts.append(packagist_entry("waterline", "durable-workflow/waterline", waterline_version, False))
except Exception as exc:
    artifacts.append(entry("waterline", waterline_version, f"https://packagist.org/packages/durable-workflow/waterline#{waterline_version}", [], str(exc), "fail"))

evidence = {
    "schema": "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
    "generated_at": now(),
    "execution_source": "published_server_image_install_probe",
    "server_image": server_image,
    "local_product_source_checkouts_used": False,
    "artifacts": artifacts,
}
RESULT_DIR.mkdir(parents=True, exist_ok=True)
(RESULT_DIR / "artifact-install-evidence.json").write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps({"status": "pass" if all(item["status"] == "pass" for item in artifacts) else "fail", "artifacts": {item["artifact"]: item["status"] for item in artifacts}}, sort_keys=True))
raise SystemExit(0 if all(item["status"] == "pass" for item in artifacts) else 1)
