import argparse
import json
from collections import Counter, defaultdict
from pathlib import Path
from statistics import median


def read_result(path, round_number, shape, delay, arm, count):
    row = json.loads(path.read_text(encoding="utf-8"))
    expected = {"shape": shape, "delay_ms": delay, "arm": arm, "workflows": count, "activity_completions": 5 * count}
    if any(row[key] != value for key, value in expected.items()):
        raise ValueError(f"{path.name}: unexpected cell or completion count in round {round_number}")
    metrics = ("wall_us", "server_cpu_us", "mysql_cpu_us", "statements", "commits")
    if any(type(row[key]) is not int or row[key] <= 0 for key in metrics) or row["commits"] > row["statements"]:
        raise ValueError(f"{path.name}: invalid measured counters")
    latencies = [int(line) for line in path.with_suffix(".latencies").read_text(encoding="utf-8").splitlines()]
    if len(latencies) != count or min(latencies) < 0:
        raise ValueError(f"{path.name}: incomplete workflow latencies")
    row["latencies_ms"] = [value / 1000 for value in latencies]
    receipts = Counter()
    for line in path.with_suffix(".receipts").read_text(encoding="utf-8").splitlines():
        outcome, reason, amount = line.split("\t")
        value = int(amount)
        if value <= 0 or outcome not in {"accepted", "declined"}:
            raise ValueError(f"{path.name}: invalid continuation receipt")
        receipts[(outcome, reason)] += value
    total = sum(receipts.values())
    if ((arm == "off" and total) or (arm == "on" and not receipts[("accepted", "-")]) or total > 5 * count):
        raise ValueError(f"{path.name}: continuation receipt evidence does not match its arm")
    row["receipts"] = receipts
    return row


def load_results(root, mode):
    rounds, batch, sequential = (1, 6, 3) if mode == "smoke" else (3, 200, 30)
    results = defaultdict(list)
    expected_paths = set()
    for round_number in range(1, rounds + 1):
        for shape, count in (("batch", batch), ("sequential", sequential)):
            for delay in (0, 20):
                for arm in ("off", "on"):
                    path = root / f"{round_number}-{shape}-{delay}-{arm}.json"
                    expected_paths.add(path)
                    results[(shape, delay, arm)].append(read_result(path, round_number, shape, delay, arm, count))
    if set(root.glob("*.json")) != expected_paths:
        raise ValueError("Unexpected result files in the cohort")
    return results


def span(values):
    return f"{median(values):.2f} ({min(values):.2f}–{max(values):.2f})"


def metric(runs, name, denominator=None):
    return span([row[name] / (row[denominator] if denominator else 1e6) for row in runs])


def print_table(results):
    print("| Shape | Delay | Arm | Wall s | Workflow p50 ms | Server CPU s | MySQL CPU s | SQL/workflow | COMMIT/workflow | Receipts |")
    print("| --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |")
    for (shape, delay, arm), runs in sorted(results.items()):
        latencies = [latency for run in runs for latency in run["latencies_ms"]]
        receipts = Counter()
        for run in runs:
            receipts.update(run["receipts"])
        labels = ", ".join(f"{outcome}/{reason} {count}" for (outcome, reason), count in sorted(receipts.items())) or "0"
        print(
            f"| {shape} | {delay} ms | {arm} | {metric(runs, 'wall_us')} | {median(latencies):.2f} | "
            f"{metric(runs, 'server_cpu_us')} | {metric(runs, 'mysql_cpu_us')} | "
            f"{metric(runs, 'statements', 'workflows')} | {metric(runs, 'commits', 'workflows')} | {labels} |"
        )


def print_qualification(results):
    qualifies = True
    for shape in ("batch", "sequential"):
        for delay in (0, 20):
            off = results[shape, delay, "off"]
            on = results[shape, delay, "on"]
            fewer_commits = median(row["commits"] / row["workflows"] for row in on) < median(
                row["commits"] / row["workflows"] for row in off
            )
            if shape == "batch":
                faster = max(row["wall_us"] for row in on) < min(row["wall_us"] for row in off)
            else:
                faster = median(value for row in on for value in row["latencies_ms"]) <= median(
                    value for row in off for value in row["latencies_ms"]
                )
            qualifies = qualifies and faster and fewer_commits
            print(f"{shape} {delay} ms: time criterion {faster}, commit criterion {fewer_commits}")
    print(f"Benchmark criteria met: {qualifies}")


def main():
    parser = argparse.ArgumentParser(description="Validate and summarize the continuation v2 benchmark")
    parser.add_argument("cohort", type=Path, help="Directory holding the captured cell files")
    args = parser.parse_args()
    try:
        manifest = args.cohort.parent / "run.json"
        mode = json.loads(manifest.read_text(encoding="utf-8"))["mode"] if manifest.is_file() else "cohort"
        if mode not in {"cohort", "smoke"}:
            raise ValueError("Unknown benchmark mode")
        results = load_results(args.cohort, mode)
    except (OSError, KeyError, TypeError, ValueError) as error:
        parser.exit(1, f"Invalid benchmark evidence: {error}\n")
    print_table(results)
    if mode == "smoke":
        print("Completed all eight smoke cells with reduced workflow counts.")
    else:
        print_qualification(results)


if __name__ == "__main__":
    main()
