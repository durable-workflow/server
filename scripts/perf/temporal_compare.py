#!/usr/bin/env python3
"""Temporal comparison race (Server #1081).

Races the native Server on SQLite, MySQL 8.0 and PostgreSQL 16 against
Temporal's server with the same workflow and activity code, worker count,
race size and repetitions. The target host runs only the system under test
and its database; the driver host runs the workers and the load generator,
and every start and close time is taken on the driver's clock.

Subcommands:

  check      verify image pins, Dockerfile bases and Compose durability
             without starting anything
  run        build the images, run every race on the target and driver Docker
             hosts, and write summary.json and summary.md
  race       (inside the load generator container) start one batch of
             workflows and report when each one closed
  summarize  rebuild summary.json and summary.md from a run's rep files

`run` and `check` use only the standard library, so they run with the host's
python3. `race` needs the locked `temporalio` client and runs under uv in the
load generator image (benchmarks/temporal-compare/loadgen/Dockerfile).
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import statistics
import subprocess
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

sys.path.insert(0, str(Path(__file__).resolve().parent))

from server_soak import (  # noqa: E402  (sibling module, stdlib only)
    auth_headers,
    command_output,
    file_sha256,
    github_actions_provenance_present,
    http_json,
    percentile,
    run_provenance,
)

REPO_ROOT = Path(__file__).resolve().parents[2]
HARNESS = REPO_ROOT / "benchmarks" / "temporal-compare"
IMAGES_LOCK = HARNESS / "images.lock.json"
TARGET_COMPOSE = HARNESS / "target.compose.yml"
DRIVER_COMPOSE = HARNESS / "driver.compose.yml"
LOCAL_TARGET_COMPOSE = HARNESS / "local-target.compose.yml"
LOCAL_DRIVER_COMPOSE = HARNESS / "local-driver.compose.yml"

RACES = {
    "five-steps": "race.five_steps",
    "five-local-steps": "race.five_local_steps",
}
BACKENDS = ("sqlite", "mysql", "pgsql")
TEMPORAL = "temporal"
NATIVE_TOKEN = "race-token"
# Each system starts this many workflows at once, through the same bounded
# pool on both sides.
DEFAULT_START_CONCURRENCY = 16
# The native Server has no long poll for a workflow's close, so the load
# generator lists running race workflows at this interval. Temporal's client
# waits on its close-event long poll. The interval can only delay a native
# close, never a Temporal one.
DEFAULT_NATIVE_POLL_INTERVAL_MS = 50
# Provisional until calibration (#1081 stage 2) sets the drift threshold from
# two weeks of runs.
DEFAULT_DRIFT_THRESHOLD = 0.10
DEFAULT_IDLE_CPU_THRESHOLD = 0.10
DIGEST_REF = re.compile(r"^[a-z0-9][a-z0-9._/:-]*@sha256:[0-9a-f]{64}$")
FROM_LINE = re.compile(r"^\s*FROM\s+(?:--platform=\S+\s+)?(?P<ref>\S+)(?:\s+AS\s+(?P<alias>\S+))?\s*$", re.IGNORECASE)
HARNESS_DOCKERFILES = (
    "Dockerfile.native",
    "benchmarks/temporal-compare/workers/sdk-php/Dockerfile",
    "benchmarks/temporal-compare/workers/roadrunner/Dockerfile",
    "benchmarks/temporal-compare/loadgen/Dockerfile",
)
# Settings that weaken commit durability. Every database keeps its default
# (fsync on commit); the harness refuses a configuration that names any of
# these in a command, entrypoint or environment value.
NON_DEFAULT_DURABILITY = (
    re.compile(r"synchronous\s*[=(]\s*['\"]?(off|0|normal|1)\b", re.IGNORECASE),
    re.compile(r"innodb[-_]flush[-_]log[-_]at[-_]trx[-_]commit\s*=\s*[02]\b", re.IGNORECASE),
    re.compile(r"sync[-_]binlog\s*=\s*0\b", re.IGNORECASE),
    re.compile(r"\bfsync\s*=\s*(off|false|0)\b", re.IGNORECASE),
    re.compile(r"synchronous_commit\s*=\s*(off|local)\b", re.IGNORECASE),
    re.compile(r"full_page_writes\s*=\s*(off|false|0)\b", re.IGNORECASE),
    re.compile(r"journal_mode\s*[=(]\s*['\"]?(off|memory)\b", re.IGNORECASE),
    re.compile(r"--sqlite-pragma\b", re.IGNORECASE),
    re.compile(r"\bmode\s*=\s*memory\b", re.IGNORECASE),
    re.compile(r"skip[-_]log[-_]bin|disable[-_]log[-_]bin", re.IGNORECASE),
)


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def log(message: str) -> None:
    print(f"[temporal-compare] {utc_now()} {message}", file=sys.stderr, flush=True)


# ---------------------------------------------------------------------------
# Pins and durability (checked before anything starts)


def load_images_lock(path: Path = IMAGES_LOCK) -> dict[str, dict[str, str]]:
    lock = json.loads(path.read_text())
    images = lock.get("images")
    if not isinstance(images, dict) or not images:
        raise ValueError(f"{path} must map image names to pinned references")
    for name, entry in images.items():
        ref = entry.get("ref", "") if isinstance(entry, dict) else ""
        if not DIGEST_REF.match(ref):
            raise ValueError(f"{path}: image {name} is not pinned by sha256 digest: {ref!r}")
    return images


def locked_digests(images: dict[str, dict[str, str]]) -> set[str]:
    return {entry["ref"].rsplit("@", 1)[1] for entry in images.values()}


def dockerfile_pin_errors(path: Path, images: dict[str, dict[str, str]]) -> list[str]:
    """Every FROM is a digest the lock names, or an earlier stage of the same file."""
    errors = []
    stages: set[str] = set()
    digests = locked_digests(images)
    for number, line in enumerate(path.read_text().splitlines(), start=1):
        match = FROM_LINE.match(line)
        if not match:
            continue
        ref = match.group("ref")
        if ref in stages:
            pass
        elif not DIGEST_REF.match(ref):
            errors.append(f"{path.name}:{number}: base image {ref} is not pinned by digest")
        elif ref.rsplit("@", 1)[1] not in digests:
            errors.append(f"{path.name}:{number}: base image {ref} is not in images.lock.json")
        if match.group("alias"):
            stages.add(match.group("alias"))
    return errors


def compose_image_errors(config: dict[str, Any], images: dict[str, dict[str, str]], built: set[str]) -> list[str]:
    """Each service runs a locked image or an image this harness just built."""
    errors = []
    refs = {entry["ref"] for entry in images.values()}
    for name, service in sorted((config.get("services") or {}).items()):
        image = str(service.get("image", ""))
        if image in refs or image in built:
            continue
        errors.append(f"service {name} image {image or '(none)'} is neither locked nor built by the harness")
    return errors


def _strings(value: Any) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        return [value]
    if isinstance(value, dict):
        return [f"{key}={item}" for key, item in value.items()]
    if isinstance(value, list):
        return [str(item) for item in value]
    return [str(value)]


def durability_errors(config: dict[str, Any], profile: str) -> list[str]:
    """Refuse non-default durability, in-memory databases and plaintext MySQL."""
    errors = []
    services = config.get("services") or {}
    for name, service in sorted(services.items()):
        values = _strings(service.get("command")) + _strings(service.get("entrypoint")) + _strings(service.get("environment"))
        for value in values:
            for pattern in NON_DEFAULT_DURABILITY:
                if pattern.search(value):
                    errors.append(f"service {name} sets non-default durability: {value}")
        if service.get("tmpfs"):
            errors.append(f"service {name} mounts tmpfs; databases must live on disk")
        for volume in service.get("volumes") or []:
            if isinstance(volume, dict) and volume.get("type") == "tmpfs":
                errors.append(f"service {name} mounts tmpfs; databases must live on disk")
    if profile == "native-mysql":
        mysql = services.get("mysql", {})
        native = services.get("native-mysql", {})
        if "--require-secure-transport=ON" not in _strings(mysql.get("command")):
            errors.append("mysql must refuse connections without TLS (--require-secure-transport=ON)")
        if str((native.get("environment") or {}).get("DB_SSLMODE", "")).lower() not in ("require", "required"):
            errors.append("native-mysql must connect with DB_SSLMODE=require")
    for name, service in services.items():
        if name in ("mysql", "postgres", "temporal", "native-sqlite"):
            if not any(isinstance(v, dict) and v.get("type") == "volume" for v in service.get("volumes") or []):
                errors.append(f"service {name} must keep its database on a named volume")
    return errors


def mysql_durability_errors(row: str) -> list[str]:
    """Runtime check of `SELECT @@innodb_flush_log_at_trx_commit, @@sync_binlog, @@require_secure_transport`."""
    fields = row.split()
    if len(fields) != 3:
        return [f"unexpected MySQL durability answer: {row!r}"]
    expected = ("1", "1", "1")
    names = ("innodb_flush_log_at_trx_commit", "sync_binlog", "require_secure_transport")
    return [f"MySQL {name} is {value}, expected {want}" for name, value, want in zip(names, fields, expected) if value != want]


def postgres_durability_errors(row: str) -> list[str]:
    """Runtime check of fsync, synchronous_commit and full_page_writes."""
    fields = row.split("|")
    if len(fields) != 3:
        return [f"unexpected PostgreSQL durability answer: {row!r}"]
    names = ("fsync", "synchronous_commit", "full_page_writes")
    return [f"PostgreSQL {name} is {value.strip()}, expected on" for name, value in zip(names, fields) if value.strip() != "on"]


def busy_host_errors(preflight: dict[str, Any], threshold: float) -> list[str]:
    errors = []
    if preflight.get("cpu_busy_fraction") is None:
        errors.append(f"{preflight.get('role')} host idle check did not measure CPU")
    elif preflight["cpu_busy_fraction"] > threshold:
        errors.append(
            f"{preflight.get('role')} host is busy: {preflight['cpu_busy_fraction']:.1%} CPU over the idle window (limit {threshold:.0%})"
        )
    if preflight.get("running_containers", 0):
        errors.append(f"{preflight.get('role')} host runs {preflight['running_containers']} other containers")
    return errors


def system_order(backends: list[str]) -> list[str]:
    """Temporal, every native backend in order, then Temporal again for drift."""
    return [TEMPORAL, *[f"native-{backend}" for backend in BACKENDS if backend in backends], TEMPORAL]


# ---------------------------------------------------------------------------
# Results


def stats(values: list[float]) -> dict[str, float | None]:
    if not values:
        return {"count": 0, "min": None, "p50": None, "p95": None, "max": None, "mean": None}
    return {
        "count": len(values),
        "min": round(min(values), 6),
        "p50": percentile(values, 0.50),
        "p95": percentile(values, 0.95),
        "max": round(max(values), 6),
        "mean": round(statistics.fmean(values), 6),
    }


def system_summary(reps: list[dict[str, Any]]) -> dict[str, Any]:
    clean = [rep for rep in reps if rep.get("ok")]
    race = [float(rep["race_seconds"]) for rep in clean]
    median = round(statistics.median(race), 6) if race else None
    return {
        "reps": len(reps),
        "clean_reps": len(clean),
        "median_race_seconds": median,
        "min_race_seconds": round(min(race), 6) if race else None,
        "max_race_seconds": round(max(race), 6) if race else None,
        "spread_fraction": round((max(race) - min(race)) / median, 6) if race and median else None,
        "median_p50_workflow_seconds": round(statistics.median([rep["workflow_seconds"]["p50"] for rep in clean]), 6) if clean else None,
        "median_p95_workflow_seconds": round(statistics.median([rep["workflow_seconds"]["p95"] for rep in clean]), 6) if clean else None,
    }


def race_headline(slots: list[dict[str, Any]], drift_threshold: float) -> dict[str, Any]:
    """The slowest native backend against Temporal, for one race.

    SQLite is never the headline on its own: the headline needs all three
    backends, and it is the slowest of them. Two Temporal results that differ
    by more than the drift threshold make the race unusable.
    """
    reasons = []
    temporal = [slot["summary"]["median_race_seconds"] for slot in slots if slot["system"] == TEMPORAL]
    native = {slot["system"].removeprefix("native-"): slot["summary"]["median_race_seconds"] for slot in slots if slot["system"] != TEMPORAL}
    for slot in slots:
        if slot["summary"]["clean_reps"] != slot["summary"]["reps"] or slot["summary"]["reps"] == 0:
            reasons.append(f"{slot['system']} (slot {slot['slot']}) has failed reps")
    drift = None
    if len(temporal) == 2 and None not in temporal:
        drift = round(abs(temporal[1] - temporal[0]) / temporal[0], 6)
        if drift > drift_threshold:
            reasons.append(f"Temporal drifted {drift:.1%} between its two runs (limit {drift_threshold:.0%})")
    else:
        reasons.append("Temporal did not complete both of its runs")
    missing = [backend for backend in BACKENDS if native.get(backend) is None]
    if missing:
        reasons.append("the headline needs SQLite, MySQL and PostgreSQL; missing " + ", ".join(missing))
    reference = round(statistics.fmean(temporal), 6) if len(temporal) == 2 and None not in temporal else None
    slowest = None
    ratio = None
    if not missing and reference:
        slowest = max(BACKENDS, key=lambda backend: native[backend])
        ratio = round(native[slowest] / reference, 4)
    return {
        "usable": not reasons,
        "reasons": reasons,
        "temporal_runs_seconds": temporal,
        "temporal_reference_seconds": reference,
        "temporal_drift_fraction": drift,
        "drift_threshold_fraction": drift_threshold,
        "native_seconds": native,
        "slowest_native_backend": slowest,
        "slowest_native_seconds": native.get(slowest) if slowest else None,
        "ratio_slowest_native_to_temporal": ratio,
    }


def build_summary(config: dict[str, Any], provenance: dict[str, Any], slots: list[dict[str, Any]], failures: list[str]) -> dict[str, Any]:
    races = {}
    for race in config["races"]:
        race_slots = [slot for slot in slots if slot["race"] == race]
        for slot in race_slots:
            slot["summary"] = system_summary(slot["reps"])
        races[race] = {
            "workflow_type": RACES[race],
            "slots": race_slots,
            "headline": race_headline(race_slots, config["drift_threshold"]),
        }
    usable = not failures and all(race["headline"]["usable"] for race in races.values())
    publishable = usable and not config["local_stand_in"] and config["idle_preflight_enforced"]
    return {
        "schema": "durable-workflow.temporal-compare.summary.v1",
        "generated_at": utc_now(),
        "usable": usable,
        "publishable": publishable,
        "failures": failures,
        "config": config,
        "provenance": provenance,
        "races": races,
    }


def _fmt(value: Any, suffix: str = "") -> str:
    if value is None:
        return "n/a"
    if isinstance(value, float):
        return f"{value:.3f}{suffix}"
    return f"{value}{suffix}"


def render_summary(summary: dict[str, Any]) -> str:
    config = summary["config"]
    provenance = summary["provenance"]
    hosts = provenance.get("hosts", {})
    if summary["usable"]:
        status = "USABLE" if summary["publishable"] else "USABLE (not publishable: local stand-in or idle check not enforced)"
    else:
        reasons = list(summary["failures"]) + [reason for race in summary["races"].values() for reason in race["headline"]["reasons"]]
        status = "UNUSABLE: " + "; ".join(reasons)
    lines = [
        "## Temporal comparison race",
        "",
        status,
        "",
        f"Target `{config.get('target_plan') or 'unspecified'}`, driver `{config.get('driver_plan') or 'unspecified'}`, region `{config.get('region') or 'unspecified'}`.",
        f"Native Server source `{provenance.get('native_source_sha', 'unknown')}`; harness `{provenance.get('sha', 'unknown')}`.",
        f"Durable Workflow PHP SDK {provenance.get('sdks', {}).get('durable-workflow/sdk', 'unknown')}; "
        f"Temporal PHP SDK {provenance.get('sdks', {}).get('temporal/sdk', 'unknown')} on RoadRunner {provenance.get('roadrunner_version', 'unknown')}; "
        f"{provenance.get('temporal_version', 'Temporal version unknown')}.",
        f"Each race: {config['workflows']} workflows of five sequential {config['step_milliseconds']} ms steps, "
        f"{config['workers']} workers per system, {config['reps']} reps after a {config['warmup_workflows']}-workflow warmup. "
        f"Order per race: {', '.join(config['system_order'])}; fresh database volumes for each.",
        "",
        "### Slowest native backend against Temporal",
        "",
        "| Race | Slowest backend | Native median s | Temporal median s | Ratio | Temporal drift | Usable |",
        "| --- | --- | ---: | ---: | ---: | ---: | --- |",
    ]
    for race, result in summary["races"].items():
        headline = result["headline"]
        lines.append(
            f"| {race} | {headline['slowest_native_backend'] or 'n/a'} | {_fmt(headline['slowest_native_seconds'])} | "
            f"{_fmt(headline['temporal_reference_seconds'])} | {_fmt(headline['ratio_slowest_native_to_temporal'])} | "
            f"{_fmt(None if headline['temporal_drift_fraction'] is None else headline['temporal_drift_fraction'] * 100, '%')} | "
            f"{'yes' if headline['usable'] else 'no'} |"
        )
    lines.extend([
        "",
        "A ratio below 1 means the slowest native backend finished the race faster than Temporal. "
        "The Temporal median is the mean of its two runs, before and after the native backends.",
        "",
        "### Every system",
        "",
        "| Race | Slot | System | Median race s | Min s | Max s | Spread | Median p50 workflow s | Median p95 workflow s | Clean reps |",
        "| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |",
    ])
    for race, result in summary["races"].items():
        for slot in result["slots"]:
            row = slot["summary"]
            spread = None if row["spread_fraction"] is None else row["spread_fraction"] * 100
            lines.append(
                f"| {race} | {slot['slot']} | {slot['system']} | {_fmt(row['median_race_seconds'])} | {_fmt(row['min_race_seconds'])} | "
                f"{_fmt(row['max_race_seconds'])} | {_fmt(spread, '%')} | {_fmt(row['median_p50_workflow_seconds'])} | "
                f"{_fmt(row['median_p95_workflow_seconds'])} | {row['clean_reps']}/{row['reps']} |"
            )
    lines.extend(["", "### Hosts", "", "| Role | CPU | vCPUs | Memory GiB | OS | Kernel | Docker |", "| --- | --- | ---: | ---: | --- | --- | --- |"])
    for role, host in hosts.items():
        memory = host.get("memory_bytes")
        lines.append(
            f"| {role} | {host.get('cpu_model', 'unknown')} | {host.get('cpus', 'n/a')} | "
            f"{_fmt(None if memory is None else memory / 1073741824)} | {host.get('os', 'unknown')} | {host.get('kernel', 'unknown')} | {host.get('docker', 'unknown')} |"
        )
    lines.extend([
        "",
        "Race time runs from the first start request to the last observed close, on the driver's clock. "
        f"Temporal closes are observed through its close-event long poll; native closes by listing running workflows every {config['native_poll_interval_ms']} ms, "
        "which can only delay a native close. Every database keeps default durability (fsync on commit); MySQL refuses connections without TLS.",
        "Every rep is in summary.json. A single run is not a publishable claim; calibration (#1081 stage 2) sets the noise band.",
        "",
    ])
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Docker hosts


class DockerHost:
    """One Docker engine: local, or reached as ssh://user@address."""

    def __init__(self, role: str, host: str | None) -> None:
        self.role = role
        self.host = host or None

    @property
    def env(self) -> dict[str, str]:
        env = dict(os.environ)
        if self.host:
            env["DOCKER_HOST"] = self.host
        return env

    def run(self, *args: str, timeout: float = 600, check: bool = True, capture: bool = True, input_text: str | None = None) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            ["docker", *args],
            env=self.env,
            text=True,
            input=input_text,
            stdout=subprocess.PIPE if capture else None,
            stderr=subprocess.PIPE if capture else None,
            timeout=timeout,
            check=False,
        )
        if check and result.returncode != 0:
            raise RuntimeError(f"{self.role}: docker {' '.join(shlex.quote(a) for a in args)} failed ({result.returncode}): {(result.stderr or '').strip()[-2000:]}")
        return result

    def same_engine(self, other: "DockerHost") -> bool:
        return self.host == other.host


class Compose:
    def __init__(self, docker: DockerHost, project: str, files: list[Path], env: dict[str, str], profile: str | None = None) -> None:
        self.docker = docker
        self.project = project
        self.files = files
        self.extra_env = env
        self.profile = profile

    def command(self, *args: str) -> list[str]:
        command = ["compose", "-p", self.project]
        for path in self.files:
            command += ["-f", str(path)]
        if self.profile:
            command += ["--profile", self.profile]
        return [*command, *args]

    def run(self, *args: str, timeout: float = 900, check: bool = True, capture: bool = True) -> subprocess.CompletedProcess[str]:
        env = {**self.docker.env, **self.extra_env}
        result = subprocess.run(["docker", *self.command(*args)], env=env, text=True,
                                stdout=subprocess.PIPE if capture else None, stderr=subprocess.PIPE if capture else None,
                                timeout=timeout, check=False)
        if check and result.returncode != 0:
            raise RuntimeError(f"{self.docker.role}: docker {' '.join(self.command(*args))} failed ({result.returncode}): {(result.stderr or '').strip()[-2000:]}")
        return result

    def config(self) -> dict[str, Any]:
        return json.loads(self.run("config", "--format", "json").stdout)

    def volumes(self) -> list[str]:
        result = self.docker.run("volume", "ls", "-q", "--filter", f"label=com.docker.compose.project={self.project}")
        return [line for line in result.stdout.splitlines() if line.strip()]


def preflight(docker: DockerHost, image: str, name: str, seconds: int, own_projects: tuple[str, ...]) -> dict[str, Any]:
    """CPU use over an idle window, CPU model and other running containers."""
    script = (
        "head -n1 /proc/stat; sleep {s}; head -n1 /proc/stat; cat /proc/loadavg; "
        "grep -m1 'model name' /proc/cpuinfo || true"
    ).format(s=seconds)
    result = docker.run("run", "--rm", "--init", "--name", name, "--network", "none", "--entrypoint", "sh", image, "-c", script,
                        timeout=seconds + 120)
    lines = result.stdout.splitlines()
    busy = None
    steal = None
    if len(lines) >= 2 and lines[0].startswith("cpu ") and lines[1].startswith("cpu "):
        first = [int(value) for value in lines[0].split()[1:]]
        second = [int(value) for value in lines[1].split()[1:]]
        delta = [b - a for a, b in zip(first, second)]
        total = sum(delta[:8])
        idle = delta[3] + delta[4]
        if total > 0:
            busy = round((total - idle) / total, 4)
            steal = round(delta[7] / total, 4)
    containers = docker.run("ps", "--format", "{{.Names}}").stdout.split()
    others = [c for c in containers if c != name and not c.startswith(own_projects)]
    return {
        "role": docker.role,
        "measured_at": utc_now(),
        "window_seconds": seconds,
        "cpu_busy_fraction": busy,
        "cpu_steal_fraction": steal,
        "loadavg": lines[2] if len(lines) > 2 else None,
        "cpu_model": lines[3].split(":", 1)[1].strip() if len(lines) > 3 and ":" in lines[3] else None,
        "running_containers": len(others),
    }


def host_facts(docker: DockerHost) -> dict[str, Any]:
    info = json.loads(docker.run("info", "--format", "{{json .}}").stdout)
    compose = docker.run("compose", "version", "--short", check=False).stdout.strip()
    return {
        "docker": info.get("ServerVersion"),
        "compose": compose,
        "os": info.get("OperatingSystem"),
        "kernel": info.get("KernelVersion"),
        "architecture": info.get("Architecture"),
        "cpus": info.get("NCPU"),
        "memory_bytes": info.get("MemTotal"),
        "cgroup_driver": info.get("CgroupDriver"),
    }


def composer_versions(lock_path: Path) -> dict[str, str]:
    lock = json.loads(lock_path.read_text())
    return {package["name"]: package["version"] for package in lock.get("packages", [])}


def uv_lock_version(lock_path: Path, package: str) -> str | None:
    text = lock_path.read_text()
    match = re.search(r'\[\[package\]\]\nname = "' + re.escape(package) + r'"\nversion = "([^"]+)"', text)
    return match.group(1) if match else None


# ---------------------------------------------------------------------------
# run


def parse_list(value: str, allowed: tuple[str, ...] | list[str], name: str) -> list[str]:
    items = [item.strip() for item in value.split(",") if item.strip()]
    unknown = [item for item in items if item not in allowed]
    if not items or unknown or len(set(items)) != len(items):
        raise SystemExit(f"--{name} must be a comma-separated subset of {', '.join(allowed)}")
    return [item for item in allowed if item in items]


def bounded_int(value: str, name: str, low: int, high: int) -> int:
    if not re.fullmatch(r"\d+", value or "") or not low <= int(value) <= high:
        raise SystemExit(f"--{name} must be an integer from {low} to {high}")
    return int(value)


def run_command(args: argparse.Namespace) -> int:
    output = Path(args.output)
    if output.exists():
        raise SystemExit(f"Refusing to overwrite {output}.")
    races = parse_list(args.races, tuple(RACES), "races")
    backends = parse_list(args.backends, BACKENDS, "backends")
    workflows = bounded_int(str(args.workflows), "workflows", 1, 2000)
    reps = bounded_int(str(args.reps), "reps", 1, 15)
    workers = bounded_int(str(args.workers), "workers", 1, 32)
    step_ms = bounded_int(str(args.step_ms), "step-ms", 0, 1000)
    warmup = bounded_int(str(args.warmup_workflows), "warmup-workflows", 0, 2000)
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,40}", args.project_prefix):
        raise SystemExit("--project-prefix must be lowercase letters, digits and dashes")
    if bool(args.native_image) == bool(args.native_source):
        raise SystemExit("Give exactly one of --native-image or --native-source.")
    output.mkdir(parents=True)
    (output / "reps").mkdir()
    (output / "logs").mkdir()

    images = load_images_lock()
    errors = [error for dockerfile in HARNESS_DOCKERFILES for error in dockerfile_pin_errors(REPO_ROOT / dockerfile, images)]
    if errors:
        for error in errors:
            log(error)
        return 2

    driver = DockerHost("driver", args.driver_docker_host)
    target = DockerHost("target", args.target_docker_host)
    run_id = args.run_id or datetime.now(timezone.utc).strftime("%Y%m%dt%H%M%S")
    prefix = f"{args.project_prefix}-{run_id}"
    image_prefix = args.image_prefix
    stand_in = bool(args.local_stand_in)
    config = {
        "races": races,
        "backends": backends,
        "system_order": system_order(backends),
        "workflows": workflows,
        "reps": reps,
        "workers": workers,
        "step_milliseconds": step_ms,
        "warmup_workflows": warmup,
        "start_concurrency": args.start_concurrency,
        "native_poll_interval_ms": args.native_poll_interval_ms,
        "drift_threshold": args.drift_threshold,
        "idle_cpu_threshold": args.idle_cpu_threshold,
        "idle_preflight_enforced": not args.allow_busy_hosts,
        "local_stand_in": stand_in,
        "target_plan": args.target_plan,
        "driver_plan": args.driver_plan,
        "region": args.region,
        "temporal_port": args.temporal_port,
        "native_port": args.native_port,
    }
    (output / "config.json").write_text(json.dumps(config, indent=2) + "\n")

    native_source = Path(args.native_source).resolve() if args.native_source else None
    provenance: dict[str, Any] = {
        **run_provenance(),
        "images_lock_sha256": file_sha256(IMAGES_LOCK),
        "images": {name: entry["ref"] for name, entry in images.items()},
        "sdks": {
            "durable-workflow/sdk": composer_versions(HARNESS / "workers/sdk-php/composer.lock").get("durable-workflow/sdk"),
            "temporal/sdk": composer_versions(HARNESS / "workers/roadrunner/composer.lock").get("temporal/sdk"),
            "temporalio (load generator)": uv_lock_version(REPO_ROOT / "scripts/perf/uv.lock", "temporalio"),
        },
        "lock_files_sha256": {
            "workers/sdk-php/composer.lock": file_sha256(HARNESS / "workers/sdk-php/composer.lock"),
            "workers/roadrunner/composer.lock": file_sha256(HARNESS / "workers/roadrunner/composer.lock"),
            "scripts/perf/uv.lock": file_sha256(REPO_ROOT / "scripts/perf/uv.lock"),
        },
        "github_actions": github_actions_provenance_present(run_provenance()),
    }
    if native_source:
        provenance["native_source_sha"] = command_output(["git", "-C", str(native_source), "rev-parse", "HEAD"]) or "unknown"
    failures: list[str] = []
    slots: list[dict[str, Any]] = []

    try:
        built = build_images(driver, target, images, image_prefix, native_source, args.native_image, args.cargo_build_jobs, provenance)
        provenance["built_images"] = built
        provenance["hosts"] = {"target": host_facts(target), "driver": host_facts(driver)}
        for name in ("temporal", "mysql", "postgres"):
            target.run("pull", "-q", images[name]["ref"], timeout=900)
        provenance["temporal_version"] = target.run(
            "run", "--rm", "--init", "--name", f"{prefix}-temporal-version", "--network", "none", images["temporal"]["ref"], "--version",
        ).stdout.strip()
        provenance["roadrunner_version"] = driver.run(
            "run", "--rm", "--init", "--name", f"{prefix}-rr-version", "--network", "none", "--entrypoint", "rr", built["rr-worker"], "--version",
        ).stdout.strip()
        provenance["preflight"] = []
        compose_env = {
            "DW_RACE_TEMPORAL_IMAGE": images["temporal"]["ref"],
            "DW_RACE_MYSQL_IMAGE": images["mysql"]["ref"],
            "DW_RACE_POSTGRES_IMAGE": images["postgres"]["ref"],
            "DW_RACE_NATIVE_IMAGE": built["native"],
            "DW_RACE_SDK_PHP_WORKER_IMAGE": built["sdk-php-worker"],
            "DW_RACE_RR_WORKER_IMAGE": built["rr-worker"],
            "DW_RACE_LOADGEN_IMAGE": built["loadgen"],
            "DW_RACE_TARGET_BIND": args.target_bind,
            "DW_RACE_TARGET_ADDRESS": args.target_address,
            "DW_RACE_TEMPORAL_PORT": str(args.temporal_port),
            "DW_RACE_NATIVE_PORT": str(args.native_port),
            "DW_RACE_NATIVE_TOKEN": NATIVE_TOKEN,
            "DW_RACE_STEP_MILLISECONDS": str(step_ms),
        }
        built_refs = set(built.values())
        for race in races:
            for slot, system in enumerate(config["system_order"]):
                record = {"race": race, "slot": slot, "system": system, "reps": [], "warmup": None}
                slots.append(record)
                try:
                    run_slot(record, args, config, driver, target, images, built_refs, compose_env, prefix, output, provenance)
                except Exception as error:  # noqa: BLE001 - recorded and the next system still runs
                    failures.append(f"{race} slot {slot} {system}: {error}")
                    log(f"{race} slot {slot} {system} failed: {error}")
    except Exception as error:  # noqa: BLE001
        failures.append(str(error))
        log(f"run failed: {error}")

    summary = build_summary(config, provenance, slots, failures)
    (output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    (output / "summary.md").write_text(render_summary(summary))
    log(f"summary written to {output}/summary.json; usable={summary['usable']} publishable={summary['publishable']}")
    return 1 if failures else 0


def build_images(driver: DockerHost, target: DockerHost, images: dict[str, dict[str, str]], prefix: str,
                 native_source: Path | None, native_image: str | None, cargo_jobs: int, provenance: dict[str, Any]) -> dict[str, str]:
    """Build the workers and load generator on the driver, and the native image from source."""
    tag = provenance.get("sha", "local")[:12] or "local"
    built = {
        "sdk-php-worker": f"{prefix}/sdk-php-worker:{tag}",
        "rr-worker": f"{prefix}/rr-worker:{tag}",
        "loadgen": f"{prefix}/loadgen:{tag}",
    }
    log("building the worker and load generator images on the driver")
    driver.run("build", "-q", "-t", built["sdk-php-worker"], str(HARNESS / "workers/sdk-php"), timeout=1800)
    driver.run("build", "-q", "-t", built["rr-worker"], str(HARNESS / "workers/roadrunner"), timeout=1800)
    driver.run("build", "-q", "-t", built["loadgen"], "-f", str(HARNESS / "loadgen/Dockerfile"), str(REPO_ROOT / "scripts/perf"), timeout=1800)
    if native_source:
        sha = provenance.get("native_source_sha", "source")[:12]
        built["native"] = f"{prefix}/native:{sha}"
        errors = dockerfile_pin_errors(native_source / "Dockerfile.native", images)
        if errors:
            raise RuntimeError("; ".join(errors))
        log(f"building the native Server image from {sha} on the driver")
        driver.run("build", "-q", "--build-arg", f"CARGO_BUILD_JOBS={cargo_jobs}", "-f", str(native_source / "Dockerfile.native"),
                   "-t", built["native"], str(native_source), timeout=5400)
    else:
        if not re.fullmatch(r"durableworkflow/server@sha256:[0-9a-f]{64}", native_image or ""):
            raise RuntimeError("--native-image must be an exact durableworkflow/server@sha256 digest")
        built["native"] = native_image
        driver.run("pull", "-q", native_image, timeout=900)
    if not target.same_engine(driver):
        log("copying the native image to the target")
        save = subprocess.Popen(["docker", "save", built["native"]], env=driver.env, stdout=subprocess.PIPE)
        load = subprocess.run(["docker", "load", "-q"], env=target.env, stdin=save.stdout, text=True, capture_output=True, timeout=1800)
        save.wait(timeout=60)
        if save.returncode != 0 or load.returncode != 0:
            raise RuntimeError(f"copying the native image to the target failed: {load.stderr.strip()}")
    provenance["built_image_ids"] = {
        name: driver.run("image", "inspect", "--format", "{{.Id}}", ref).stdout.strip() for name, ref in built.items()
    }
    return built


def run_slot(record: dict[str, Any], args: argparse.Namespace, config: dict[str, Any], driver: DockerHost, target: DockerHost,
             images: dict[str, dict[str, str]], built_refs: set[str], compose_env: dict[str, str],
             prefix: str, output: Path, provenance: dict[str, Any]) -> None:
    race, slot, system = record["race"], record["slot"], record["system"]
    label = f"{race}-{slot}-{system}"
    profile = system
    target_project = f"{prefix}-t{slot}-{race}"[:60]
    driver_project = f"{prefix}-d{slot}-{race}"[:60]
    task_queue = f"race-{race}-{slot}"
    env = {**compose_env, "DW_RACE_TASK_QUEUE": task_queue}
    stand_in = config["local_stand_in"]
    target_compose = Compose(target, target_project, [TARGET_COMPOSE, *([LOCAL_TARGET_COMPOSE] if stand_in else [])], env, profile)
    driver_compose = Compose(driver, driver_project, [DRIVER_COMPOSE, *([LOCAL_DRIVER_COMPOSE] if stand_in else [])], env)
    worker_service = "rr-worker" if system == TEMPORAL else "dw-worker"

    for check in (target_compose, driver_compose):
        rendered = check.config()
        image_errors = compose_image_errors(rendered, images, built_refs)
        if image_errors:
            raise RuntimeError("; ".join(image_errors))
    durability = durability_errors(target_compose.config(), profile)
    if durability:
        raise RuntimeError("; ".join(durability))
    if target_compose.volumes():
        raise RuntimeError(f"target project {target_project} already has volumes; every system needs a fresh database")

    own = (args.project_prefix,)
    checks = [
        preflight(target, images["temporal"]["ref"], f"{prefix}-idle-t{slot}-{race}", args.idle_seconds, own),
        preflight(driver, compose_env["DW_RACE_LOADGEN_IMAGE"], f"{prefix}-idle-d{slot}-{race}", args.idle_seconds, own),
    ]
    for check in checks:
        check["slot"] = label
        provenance["preflight"].append(check)
        if check.get("cpu_model"):
            provenance["hosts"][check["role"]].setdefault("cpu_model", check["cpu_model"])
    busy = [error for check in checks for error in busy_host_errors(check, args.idle_cpu_threshold)]
    if busy:
        if not args.allow_busy_hosts:
            raise RuntimeError("; ".join(busy))
        log("idle check not enforced: " + "; ".join(busy))

    try:
        log(f"{label}: starting the target")
        target_compose.run("up", "-d", "--wait", "--wait-timeout", "300", timeout=600)
        if system == "native-mysql":
            row = target_compose.run("exec", "-T", "mysql", "sh", "-c",
                                     'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "SELECT @@innodb_flush_log_at_trx_commit, @@sync_binlog, @@require_secure_transport"').stdout.strip()
            errors = mysql_durability_errors(row)
            if errors:
                raise RuntimeError("; ".join(errors))
        if system == "native-pgsql":
            row = target_compose.run("exec", "-T", "postgres", "psql", "-U", "workflow", "-d", "durable_workflow", "-At", "-c",
                                     "SELECT current_setting('fsync') || '|' || current_setting('synchronous_commit') || '|' || current_setting('full_page_writes')").stdout.strip()
            errors = postgres_durability_errors(row)
            if errors:
                raise RuntimeError("; ".join(errors))
        log(f"{label}: starting {config['workers']} {worker_service} workers")
        driver_compose.run("up", "-d", "--scale", f"{worker_service}={config['workers']}", worker_service, timeout=600)
        time.sleep(5)
        running = driver_compose.run("ps", "-q", "--status", "running", worker_service).stdout.split()
        if len(running) != config["workers"]:
            raise RuntimeError(f"{len(running)}/{config['workers']} {worker_service} containers are running; see the driver log")

        def batch(name: str, count: int) -> dict[str, Any]:
            command = [
                "run", "--rm", "-T", "--no-deps", "loadgen", "race",
                "--system", "temporal" if system == TEMPORAL else "native",
                "--address", f"{args.target_address}:{args.temporal_port if system == TEMPORAL else args.native_port}",
                "--race", race, "--workflows", str(count), "--task-queue", task_queue,
                "--id-prefix", f"{prefix}-{label}-{name}", "--start-concurrency", str(config["start_concurrency"]),
                "--native-poll-interval-ms", str(config["native_poll_interval_ms"]), "--timeout", str(args.rep_timeout),
            ]
            result = driver_compose.run(*command, timeout=args.rep_timeout + 180, check=False)
            (output / "logs" / f"{label}-{name}.stderr").write_text(result.stderr or "")
            try:
                rep = json.loads(result.stdout)
            except json.JSONDecodeError:
                rep = {"ok": False, "errors": [f"load generator exited {result.returncode} without a result"]}
            rep["name"] = name
            (output / "reps" / f"{label}-{name}.json").write_text(json.dumps(rep, indent=2) + "\n")
            return rep

        if config["warmup_workflows"]:
            record["warmup"] = batch("warmup", config["warmup_workflows"])
            if not record["warmup"].get("ok"):
                raise RuntimeError("warmup failed: " + "; ".join(record["warmup"].get("errors", [])[:3]))
        for rep in range(1, config["reps"] + 1):
            result = batch(f"rep{rep}", config["workflows"])
            record["reps"].append(result)
            log(f"{label} rep {rep}: {'ok' if result.get('ok') else 'FAILED'} race {result.get('race_seconds')} s")
            if not result.get("ok"):
                # One failed rep fails the system; later reps would only spend VM time.
                raise RuntimeError(f"rep {rep} failed: " + "; ".join(result.get("errors", [])[:3]))
    finally:
        for compose, side in ((driver_compose, "driver"), (target_compose, "target")):
            logs = compose.run("logs", "--no-color", "--timestamps", check=False, timeout=300)
            (output / "logs" / f"{label}-{side}.log").write_text((logs.stdout or "")[-5_000_000:])
            compose.run("down", "--volumes", "--remove-orphans", "-t", "30", check=False, timeout=600)
        leftover = target_compose.volumes()
        if leftover:
            raise RuntimeError(f"target volumes survived teardown: {', '.join(leftover)}")


# ---------------------------------------------------------------------------
# race (load generator, runs on the driver inside the loadgen image)


def race_result(system: str, race: str, workflows: list[dict[str, Any]], errors: list[str], observation: str) -> dict[str, Any]:
    started = [w for w in workflows if w.get("start_sent") is not None]
    closed = [w for w in workflows if w.get("closed") is not None]
    completed = [w for w in closed if w.get("status") == "completed"]
    durations = [w["closed"] - w["start_sent"] for w in completed]
    starts = [w["start_acked"] - w["start_sent"] for w in started if w.get("start_acked") is not None]
    first = min((w["start_sent"] for w in started), default=None)
    last = max((w["closed"] for w in closed), default=None)
    ok = not errors and len(completed) == len(workflows) and bool(workflows)
    if len(completed) != len(workflows):
        errors = [*errors, f"{len(completed)}/{len(workflows)} workflows completed"]
    return {
        "ok": ok,
        "system": system,
        "race": race,
        "workflow_type": RACES[race],
        "workflows": len(workflows),
        "completed": len(completed),
        "errors": errors[:50],
        "observation": observation,
        "first_start_sent_at": first,
        "last_close_observed_at": last,
        "race_seconds": round(last - first, 6) if ok and first is not None and last is not None else None,
        "workflow_seconds": stats(durations),
        "start_seconds": stats(starts),
    }


def native_race(args: argparse.Namespace) -> dict[str, Any]:
    base = f"http://{args.address}"
    headers = auth_headers(NATIVE_TOKEN, "default")
    workflow_type = RACES[args.race]
    workflows = [{"id": f"{args.id_prefix}-{index:05d}"} for index in range(args.workflows)]
    errors: list[str] = []
    lock = threading.Lock()
    deadline = time.monotonic() + args.timeout

    def start(item: dict[str, Any]) -> None:
        item["start_sent"] = time.time()
        try:
            status, body = http_json("POST", f"{base}/api/workflows", headers,
                                     {"workflow_id": item["id"], "workflow_type": workflow_type, "task_queue": args.task_queue})
        except Exception as error:  # noqa: BLE001
            status, body = 0, {"exception": repr(error)}
        if status in (200, 201):
            item["start_acked"] = time.time()
        else:
            with lock:
                errors.append(f"start {item['id']}: HTTP {status} {json.dumps(body)[:300]}")

    def running_ids() -> set[str]:
        ids: set[str] = set()
        token = None
        while True:
            query = f"status=running&workflow_type={workflow_type}&page_size=200"
            if token:
                query += f"&next_page_token={token}"
            status, body = http_json("GET", f"{base}/api/workflows?{query}", headers)
            if status != 200:
                raise RuntimeError(f"list running workflows: HTTP {status} {json.dumps(body)[:300]}")
            for row in body.get("workflows", []):
                ids.add(str(row.get("workflow_id") or row.get("id")))
            token = body.get("next_page_token")
            if not token:
                return ids

    interval = args.native_poll_interval_ms / 1000
    with ThreadPoolExecutor(max_workers=args.start_concurrency) as pool:
        futures = [pool.submit(start, item) for item in workflows]
        open_items = {item["id"]: item for item in workflows}
        while open_items and time.monotonic() < deadline:
            sweep_started = time.monotonic()
            asked_at = time.time()
            try:
                running = running_ids()
            except Exception as error:  # noqa: BLE001
                errors.append(str(error))
                break
            seen_at = time.time()
            for workflow_id, item in list(open_items.items()):
                acked = item.get("start_acked")
                if acked is not None and acked < asked_at and workflow_id not in running:
                    item["closed"] = seen_at
                    del open_items[workflow_id]
                elif item.get("start_sent") is not None and acked is None and all(f.done() for f in futures):
                    del open_items[workflow_id]  # the start failed and is already an error
            remaining = interval - (time.monotonic() - sweep_started)
            if remaining > 0:
                time.sleep(remaining)
        for future in futures:
            future.result()
    if open_items:
        errors.append(f"{len(open_items)} workflows were still running after {args.timeout}s")
    # After timing: confirm each observed close is a completion.
    for item in workflows:
        if item.get("closed") is None:
            continue
        status, body = http_json("GET", f"{base}/api/workflows/{item['id']}", headers)
        item["status"] = str(body.get("status", "")).lower() if status == 200 else f"http-{status}"
        if item["status"] != "completed":
            errors.append(f"{item['id']} closed as {item['status']}")
    return race_result("native", args.race, workflows, errors, f"running-workflow list every {args.native_poll_interval_ms} ms")


def temporal_race(args: argparse.Namespace) -> dict[str, Any]:
    import asyncio

    from temporalio.client import Client  # locked in scripts/perf/uv.lock

    async def main() -> dict[str, Any]:
        client = await Client.connect(args.address, namespace="default")
        semaphore = asyncio.Semaphore(args.start_concurrency)
        workflows = [{"id": f"{args.id_prefix}-{index:05d}"} for index in range(args.workflows)]
        errors: list[str] = []

        async def one(item: dict[str, Any]) -> None:
            async with semaphore:
                item["start_sent"] = time.time()
                try:
                    handle = await client.start_workflow(RACES[args.race], id=item["id"], task_queue=args.task_queue)
                except Exception as error:  # noqa: BLE001
                    errors.append(f"start {item['id']}: {error!r}"[:400])
                    return
                item["start_acked"] = time.time()
            try:
                result = await handle.result()
            except Exception as error:  # noqa: BLE001
                item["closed"] = time.time()
                item["status"] = "failed"
                errors.append(f"{item['id']}: {error!r}"[:400])
                return
            item["closed"] = time.time()
            item["status"] = "completed" if result == 10 else f"unexpected result {result!r}"

        try:
            await asyncio.wait_for(asyncio.gather(*(one(item) for item in workflows)), timeout=args.timeout)
        except asyncio.TimeoutError:
            errors.append(f"workflows were still running after {args.timeout}s")
        return race_result("temporal", args.race, workflows, errors, "close-event long poll (WorkflowHandle.result)")

    return asyncio.run(main())


def race_command(args: argparse.Namespace) -> int:
    if args.race not in RACES:
        raise SystemExit(f"--race must be one of {', '.join(RACES)}")
    result = temporal_race(args) if args.system == "temporal" else native_race(args)
    print(json.dumps(result))
    return 0 if result["ok"] else 1


# ---------------------------------------------------------------------------
# check and summarize


def check_command(args: argparse.Namespace) -> int:
    images = load_images_lock()
    errors = [error for dockerfile in HARNESS_DOCKERFILES for error in dockerfile_pin_errors(REPO_ROOT / dockerfile, images)]
    for error in errors:
        print(error, file=sys.stderr)
    if not errors:
        print(f"{len(images)} locked images; every harness Dockerfile base is pinned by a locked digest.")
    return 1 if errors else 0


def summarize_command(args: argparse.Namespace) -> int:
    output = Path(args.output)
    previous = json.loads((output / "summary.json").read_text())
    slots = []
    for race in previous["config"]["races"]:
        for slot, system in enumerate(previous["config"]["system_order"]):
            label = f"{race}-{slot}-{system}"
            reps = [json.loads(path.read_text()) for path in sorted((output / "reps").glob(f"{label}-rep*.json"))]
            slots.append({"race": race, "slot": slot, "system": system, "reps": reps, "warmup": None})
    summary = build_summary(previous["config"], previous["provenance"], slots, previous["failures"])
    (output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    (output / "summary.md").write_text(render_summary(summary))
    return 0


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)

    run = commands.add_parser("run", help="run the races and write summary.json")
    run.add_argument("--output", required=True)
    run.add_argument("--races", default=",".join(RACES))
    run.add_argument("--backends", default=",".join(BACKENDS))
    run.add_argument("--workflows", default="200")
    run.add_argument("--reps", default="5")
    run.add_argument("--workers", default="6")
    run.add_argument("--step-ms", default="20")
    run.add_argument("--warmup-workflows", default="20")
    run.add_argument("--start-concurrency", type=int, default=DEFAULT_START_CONCURRENCY)
    run.add_argument("--native-poll-interval-ms", type=int, default=DEFAULT_NATIVE_POLL_INTERVAL_MS)
    run.add_argument("--drift-threshold", type=float, default=DEFAULT_DRIFT_THRESHOLD)
    run.add_argument("--idle-cpu-threshold", type=float, default=DEFAULT_IDLE_CPU_THRESHOLD)
    run.add_argument("--idle-seconds", type=int, default=5)
    run.add_argument("--allow-busy-hosts", action="store_true", help="record but do not enforce the idle check (local stand-in only)")
    run.add_argument("--local-stand-in", action="store_true", help="apply the local-*.compose.yml container limits; never publishable")
    run.add_argument("--target-docker-host", default=None, help="DOCKER_HOST for the target, e.g. ssh://perf@10.0.0.2")
    run.add_argument("--driver-docker-host", default=None)
    run.add_argument("--target-bind", required=True, help="address the target publishes its port on")
    run.add_argument("--target-address", required=True, help="address the driver reaches the target at")
    run.add_argument("--temporal-port", type=int, default=7233)
    run.add_argument("--native-port", type=int, default=8080)
    run.add_argument("--native-source", default=None, help="Server checkout to build Dockerfile.native from")
    run.add_argument("--native-image", default=None, help="exact durableworkflow/server@sha256 image instead of a source build")
    run.add_argument("--cargo-build-jobs", type=int, default=4)
    run.add_argument("--project-prefix", default="dw-race")
    run.add_argument("--image-prefix", default="dw-race")
    run.add_argument("--run-id", default=None)
    run.add_argument("--rep-timeout", type=int, default=300)
    run.add_argument("--target-plan", default="")
    run.add_argument("--driver-plan", default="")
    run.add_argument("--region", default="")
    run.set_defaults(handler=run_command)

    race = commands.add_parser("race", help="load generator: one batch of workflows")
    race.add_argument("--system", choices=("temporal", "native"), required=True)
    race.add_argument("--address", required=True)
    race.add_argument("--race", required=True)
    race.add_argument("--workflows", type=int, required=True)
    race.add_argument("--task-queue", required=True)
    race.add_argument("--id-prefix", required=True)
    race.add_argument("--start-concurrency", type=int, default=DEFAULT_START_CONCURRENCY)
    race.add_argument("--native-poll-interval-ms", type=int, default=DEFAULT_NATIVE_POLL_INTERVAL_MS)
    race.add_argument("--timeout", type=int, default=900)
    race.set_defaults(handler=race_command)

    check = commands.add_parser("check", help="verify pins without starting anything")
    check.set_defaults(handler=check_command)

    summarize = commands.add_parser("summarize", help="rebuild the summary from rep files")
    summarize.add_argument("--output", required=True)
    summarize.set_defaults(handler=summarize_command)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    handler: Callable[[argparse.Namespace], int] = args.handler
    return handler(args)


if __name__ == "__main__":
    sys.exit(main())
