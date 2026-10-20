"""Summarize run-server-port-matched.sh output directories.

Usage: python -I summarize-server-port-matched.py OUTPUT_DIR [OUTPUT_DIR ...]

For every offer window it joins the probe result with the component CPU used
between the window's start and end markers (offer, drain and verification),
the highest sampled memory, and health/readiness replies inside the window.
CPU per workflow divides by verified completions. Memory is container-charged
cgroup usage, not RSS. It prints a Markdown table and writes summary.json in
each directory. It never discards a failed or missing window.
"""
import json
import sys
from collections import defaultdict
from pathlib import Path

RUNTIME = {"php": ["php-server", "php-worker", "php-scheduler", "redis"], "native": ["native-server"]}


def load_samples(path):
    rows = []
    for line in path.read_text().splitlines():
        at, name, usage, memory, oom = line.split("\t")
        rows.append((float(at), name, int(usage), int(memory), int(oom or 0)))
    return rows


def windows(path):
    marks = defaultdict(dict)
    for line in path.read_text().splitlines():
        at, name, edge = line.split("\t")
        marks[name][edge] = float(at)
    return {name: (edges["start"], edges["end"]) for name, edges in marks.items() if "start" in edges and "end" in edges}


def usage_between(samples, name, start, end):
    """Cumulative CPU microseconds at the first sample at/after start and the last at/before end."""
    series = [(at, usage) for at, n, usage, _, _ in samples if n == name]
    before = [usage for at, usage in series if at <= start]
    inside = [usage for at, usage in series if start <= at <= end]
    if not inside:
        return None
    first = before[-1] if before else inside[0]
    return (inside[-1] - first) / 1_000_000


def health_between(path, start, end):
    replies, failures, slowest = 0, 0, 0.0
    if not path.exists():
        return None
    for line in path.read_text().splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        try:
            at = float(parts[0])
        except ValueError:
            continue
        if not start <= at <= end:
            continue
        replies += 1
        if parts[2] != "200":
            failures += 1
        else:
            slowest = max(slowest, float(parts[3]))
    return {"replies": replies, "failures": failures, "slowest_ok_seconds": round(slowest, 3)}

OFFER_FIELDS = (
    "offered_rate_per_second", "planned_starts", "accepted_starts", "skipped_offer_slots",
    "late_starts_over_one_slot", "completed_by_observation_deadline", "pending_at_deadline",
    "closed_during_offer_window", "latency_p50_seconds", "latency_p95_seconds",
    "latency_p99_seconds", "poll_errors")
IDLE_FIELDS = ("requested_polls", "all_registered", "cleanup_errors", "outcomes")


def resources(samples, by_time, impl, start, end):
    """Component CPU, memory and OOM kills for one window."""
    components = sorted({n for _, n, _, _, _ in samples})
    inside = [mem for at, mem in by_time.items() if start <= at <= end]
    return {
        "cpu_core_seconds": {n: usage_between(samples, n, start, end) for n in components},
        "max_memory_bytes": {n: max((m.get(n, 0) for m in inside), default=None) for n in components},
        "max_runtime_memory_bytes": max((sum(m.get(n, 0) for n in RUNTIME[impl]) for m in inside), default=None),
        "oom_kills": {n: max((o for _, nn, _, _, o in samples if nn == n), default=0) for n in components},
    }


def add_idle_probe(entry, probe):
    try:
        idle = json.loads(probe.read_text())
        entry.update({k: idle.get(k) for k in IDLE_FIELDS})
    except (OSError, ValueError):
        entry["probe"] = "missing_or_invalid"


def add_offer_probe(entry, probe, impl):
    if not probe.exists():
        entry["probe"] = "missing"
        return
    try:
        observed = json.loads(probe.read_text())
    except ValueError:
        entry["probe"] = "invalid"
        return
    entry.update({k: observed.get(k) for k in OFFER_FIELDS})
    entry["errors"] = len(observed.get("errors") or [])
    done = observed.get("completed_by_observation_deadline") or 0
    runtime = [entry["cpu_core_seconds"].get(n) for n in RUNTIME[impl]]
    if done and all(v is not None for v in runtime):
        entry["runtime_core_seconds_per_workflow"] = round(sum(runtime) / done, 4)
        mysql = entry["cpu_core_seconds"].get("mysql")
        entry["mysql_core_seconds_per_workflow"] = round(mysql / done, 4) if mysql is not None else None


def summarize(directory):
    identity = json.loads((directory / "identity.json").read_text())
    impl = identity["implementation"]
    samples = load_samples(directory / "samples.tsv") if (directory / "samples.tsv").exists() else []
    marks = windows(directory / "windows.tsv")
    by_time = defaultdict(dict)
    for at, name, _, memory, _ in samples:
        by_time[at][name] = memory
    result = {"identity": identity, "windows": {}, "idle": {}}
    for name, (start, end) in sorted(marks.items(), key=lambda item: item[1][0]):
        if name == "sdk-worker-stop":
            continue
        entry = {"seconds": round(end - start, 1), "health": health_between(directory / "health.log", start, end)}
        entry.update(resources(samples, by_time, impl, start, end))
        probe = directory / f"{name}.json"
        if name.startswith("idle-"):
            add_idle_probe(entry, probe)
            result["idle"][name] = entry
        else:
            add_offer_probe(entry, probe, impl)
            result["windows"][name] = entry
    (directory / "summary.json").write_text(json.dumps(result, indent=1) + "\n")
    return impl, result


def fmt(value):
    if value is None:
        return "–"
    if isinstance(value, float):
        return f"{value:.3f}"
    return str(value)


def table(rows):
    print("| Runtime / window | Accepted | Verified | Pending | In-window | p50 s | p95 s | p99 s | Runtime core-s/wf | MySQL core-s/wf | Runtime max MiB | Health fail/replies |")
    print("| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |")
    for impl, name, w in rows:
        mib = w.get("max_runtime_memory_bytes")
        health = w.get("health") or {}
        print(f"| {impl} / {name} | {fmt(w.get('accepted_starts'))} | {fmt(w.get('completed_by_observation_deadline'))} | "
              f"{fmt(w.get('pending_at_deadline'))} | {fmt(w.get('closed_during_offer_window'))} | "
              f"{fmt(w.get('latency_p50_seconds'))} | {fmt(w.get('latency_p95_seconds'))} | {fmt(w.get('latency_p99_seconds'))} | "
              f"{fmt(w.get('runtime_core_seconds_per_workflow'))} | {fmt(w.get('mysql_core_seconds_per_workflow'))} | "
              f"{'–' if mib is None else round(mib / 1048576, 1)} | {health.get('failures', '–')}/{health.get('replies', '–')} |")


def result_directory(argument):
    """Only an existing driver output directory is read and written."""
    directory = Path(argument).resolve(strict=True)
    if not directory.is_dir() or not (directory / "identity.json").is_file():
        sys.exit(f"{argument} is not a run-server-port-matched.sh output directory.")
    return directory


def main():
    rows, idle_rows = [], []
    for argument in sys.argv[1:]:
        impl, result = summarize(result_directory(argument))
        rows += [(impl, name, w) for name, w in result["windows"].items()]
        idle_rows += [(impl, name, w) for name, w in result["idle"].items()]
    table(rows)
    print()
    print("| Runtime / idle | Polls | Outcomes | Runtime core-s | Health fail/replies, slowest ok s |")
    print("| --- | ---: | --- | ---: | --- |")
    for impl, name, w in idle_rows:
        runtime = [w["cpu_core_seconds"].get(n) for n in RUNTIME[impl]]
        core = round(sum(runtime), 3) if all(v is not None for v in runtime) else "–"
        health = w.get("health") or {}
        print(f"| {impl} / {name} | {w.get('requested_polls')} | {json.dumps(w.get('outcomes'))} | {core} | "
              f"{health.get('failures', '–')}/{health.get('replies', '–')}, {health.get('slowest_ok_seconds', '–')} |")


main()
