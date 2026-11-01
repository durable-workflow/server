#!/usr/bin/env python3
"""Summarize one small-host benchmark run (Server #325).

    summarize-server-port-small-host.py DRIVER_DIR TARGET_DIR

DRIVER_DIR is the driver's output and TARGET_DIR the target side's. Windows
come from the driver's windows.tsv; target CPU, memory, swap, OOM kills and
pressure come from the target's one-second host samples in the same window,
matched by wall clock (both hosts must be NTP-synchronized; their clock
records are included). Prints one JSON document.
"""

import json
import pathlib
import re
import sys


def read_tsv(path):
    if not path.exists():
        return []
    rows = [line.rstrip("\n").split("\t") for line in path.read_text().splitlines() if line.strip()]
    return rows


def windows(driver):
    marks = {}
    for row in read_tsv(driver / "windows.tsv"):
        if len(row) < 3:
            continue
        marks.setdefault(row[1], {})[row[2]] = float(row[0])
    return {name: m for name, m in marks.items() if "start" in m and "end" in m}


def host_samples(target):
    rows = read_tsv(target / "host-samples.tsv")
    if not rows:
        return []
    header, body = rows[0], rows[1:]
    samples = []
    for row in body:
        if len(row) != len(header):
            continue
        sample = {}
        for key, value in zip(header, row):
            try:
                sample[key] = float(value) if value != "" else None
            except ValueError:
                sample[key] = None
        samples.append(sample)
    return samples


def within(samples, start, end):
    return [s for s in samples if s["unix_seconds"] is not None and start <= s["unix_seconds"] <= end]


def delta(first, last, key):
    if first.get(key) is None or last.get(key) is None:
        return None
    return last[key] - first[key]


def target_window(samples, start, end):
    span = within(samples, start, end)
    if len(span) < 2:
        return {"samples": len(span)}
    first, last = span[0], span[-1]
    total = delta(first, last, "cpu_total_jiffies") or 0
    seconds = last["unix_seconds"] - first["unix_seconds"]

    def share(key):
        d = delta(first, last, key)
        return None if not total or d is None else round(d / total, 4)

    idle = share("cpu_idle_jiffies")
    used = [s["mem_total_kib"] - s["mem_available_kib"] for s in span if s.get("mem_available_kib") is not None]
    swap = [s["swap_total_kib"] - s["swap_free_kib"] for s in span if s.get("swap_free_kib") is not None]

    def pressure(key):
        d = delta(first, last, key)
        return None if d is None or seconds <= 0 else round(d / (seconds * 1_000_000), 4)

    return {
        "samples": len(span),
        "cpu_busy_fraction": None if idle is None else round(1 - idle, 4),
        "cpu_iowait_fraction": share("cpu_iowait_jiffies"),
        "cpu_steal_fraction": share("cpu_steal_jiffies"),
        "memory_used_max_mib": round(max(used) / 1024, 1) if used else None,
        "memory_available_min_mib": round(min(s["mem_available_kib"] for s in span) / 1024, 1),
        "swap_used_max_mib": round(max(swap) / 1024, 1) if swap else None,
        "swap_in_pages": delta(first, last, "pswpin"),
        "swap_out_pages": delta(first, last, "pswpout"),
        "oom_kills": delta(first, last, "oom_kill"),
        "pressure_cpu_some_fraction": pressure("psi_cpu_some_us"),
        "pressure_memory_some_fraction": pressure("psi_memory_some_us"),
        "pressure_memory_full_fraction": pressure("psi_memory_full_us"),
        "pressure_io_some_fraction": pressure("psi_io_some_us"),
    }


def service_window(target, start, end):
    """Average CPU cores and peak memory per target service in a window."""
    by_service = {}
    for row in read_tsv(target / "container-samples.tsv"):
        if len(row) < 4:
            continue
        when = float(row[0])
        if start <= when <= end:
            by_service.setdefault(row[1], []).append((when, float(row[2]), float(row[3])))
    result = {}
    for service, rows in sorted(by_service.items()):
        rows.sort()
        seconds = rows[-1][0] - rows[0][0]
        cores = None if seconds <= 0 else round((rows[-1][1] - rows[0][1]) / 1_000_000 / seconds, 4)
        result[service] = {"cpu_cores_average": cores, "memory_max_mib": round(max(r[2] for r in rows) / 1048576, 1)}
    return result or None


def driver_cpu(driver, start, end):
    host = [r for r in read_tsv(driver / "driver-samples.tsv") if len(r) >= 4 and r[1] == "host"]
    span = [r for r in host if start <= float(r[0]) <= end]
    if len(span) < 2:
        return None
    total = float(span[-1][2]) - float(span[0][2])
    idle = float(span[-1][3]) - float(span[0][3])
    return None if total <= 0 else round(1 - idle / total, 4)


def probes(driver, name):
    results = []
    for path in sorted(driver.glob(f"{name}-probe-*.json")):
        try:
            results.append(json.loads(path.read_text().strip().splitlines()[-1]))
        except (IndexError, json.JSONDecodeError):
            results.append(None)
    return results


def health(driver, start, end):
    path = driver / "health.log"
    if not path.exists():
        return None
    answers, failures, slowest = 0, 0, 0.0
    for line in path.read_text().splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        try:
            when = float(parts[0])
        except ValueError:
            continue
        if not start <= when <= end:
            continue
        answers += 1
        if parts[2] != "200":
            failures += 1
        try:
            slowest = max(slowest, float(parts[3]))
        except ValueError:
            pass
    return {"answers": answers, "non_200": failures, "slowest_seconds": round(slowest, 4)}


def main():
    driver, target = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
    samples = host_samples(target)
    marks = windows(driver)
    ladder = {}
    for row in read_tsv(driver / "ladder.tsv")[1:]:
        if len(row) >= 9:
            ladder[row[0]] = {
                "rate_per_second": float(row[1]), "probes": int(row[2]), "sdk_workers": int(row[3]),
                "verdict": row[4], "planned": int(row[5]), "closed_in_window": int(row[6]),
                "max_probe_p95_seconds": float(row[7]), "reasons": row[8],
            }
    steps = []
    for name, step in ladder.items():
        m = marks.get(name, {})
        results = [r for r in probes(driver, name) if r]
        p50s = [r["latency_p50_seconds"] for r in results if r.get("latency_p50_seconds") is not None]
        p99s = [r["latency_p99_seconds"] for r in results if r.get("latency_p99_seconds") is not None]
        steps.append({
            "name": name, **step,
            "accepted": sum(r.get("accepted_starts", 0) for r in results),
            "completed_by_deadline": sum(r.get("completed_by_observation_deadline", 0) for r in results),
            "errors": sum(len(r.get("errors", [])) for r in results),
            "latency_p50_seconds_median_of_probes": sorted(p50s)[len(p50s) // 2] if p50s else None,
            "latency_p99_seconds_max_of_probes": max(p99s) if p99s else None,
            "target": target_window(samples, m["start"], m["end"]) if m else None,
            "target_services": service_window(target, m["start"], m["end"]) if m else None,
            "driver_cpu_busy_fraction": driver_cpu(driver, m["start"], m["end"]) if m else None,
            "health": health(driver, m["start"], m["end"]) if m else None,
        })
    idle = []
    for name, m in sorted(marks.items(), key=lambda item: item[1]["start"]):
        if not name.startswith("idle-"):
            continue
        path = driver / f"{name}.json"
        try:
            result = json.loads(path.read_text().strip().splitlines()[-1])
        except (FileNotFoundError, IndexError, json.JSONDecodeError):
            result = None
        idle.append({"name": name, "probe": result, "target": target_window(samples, m["start"], m["end"]),
                     "target_services": service_window(target, m["start"], m["end"]),
                     "health": health(driver, m["start"], m["end"])})
    whole = target_window(samples, samples[0]["unix_seconds"], samples[-1]["unix_seconds"]) if samples else None

    def read_json(path):
        try:
            return json.loads(path.read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            return None

    def read_text(path):
        return path.read_text() if path.exists() else None

    clock = {}
    for side, folder, files in (("driver", driver, ("clock-start.txt", "clock-end.txt")),
                                ("target", target, ("clock-up.txt", "clock-down.txt"))):
        texts = [read_text(folder / f) or "" for f in files]
        clock[side] = {"ntp_synchronized_at_both_ends": all(re.search(r"NTPSynchronized=yes", t) for t in texts),
                       "records": texts}
    print(json.dumps({
        "schema": "durable-workflow.server-port-small-host-summary/v1",
        "driver": read_json(driver / "identity.json"),
        "target": read_json(target / "identity.json"),
        "capacity": read_json(driver / "capacity.json"),
        "steps": steps,
        "idle": idle,
        "target_whole_run": whole,
        "target_container_states": (read_text(target / "container-states.txt") or "").splitlines(),
        "target_database_audit": (read_text(target / "database-audit.tsv") or "").splitlines(),
        "target_teardown": (read_text(target / "teardown.txt") or "").strip(),
        "clock": clock,
    }, indent=2))


if __name__ == "__main__":
    main()
