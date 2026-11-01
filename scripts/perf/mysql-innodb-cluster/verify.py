#!/usr/bin/env python3
"""Summarize one failover run: load outcomes, readiness during failover, and
database integrity (one completion per run, no duplicated history, no
leftover leases). Usage: verify.py <run-dir> <db-rows.tsv>"""
import collections, json, sys

run, rows = sys.argv[1], sys.argv[2]
events = [json.loads(line) for line in open(f"{run}/load.jsonl") if line.startswith("{")]
kill = json.load(open(f"{run}/kill.json"))
probe = [json.loads(line) for line in open(f"{run}/probe.jsonl") if line.startswith("{")]

counts = collections.Counter(e["event"] + (":ok" if e.get("ok") else ":bad" if e["event"] == "completed" else "") for e in events)
secs = sorted(e["seconds"] for e in events if e["event"] == "completed")
print("load:", dict(counts))
if secs:
    print("completion seconds p50=%.2f p95=%.2f max=%.2f" % (secs[len(secs) // 2], secs[int(len(secs) * 0.95) - 1], secs[-1]))
errors = collections.Counter(e.get("error", "")[:90] for e in events if "error" in e)
for text, n in errors.most_common(5):
    print("  error x%d: %s" % (n, text))

k = kill["t"]
bad = [p for p in probe if p["t"] >= k and (p["ready"] != 200 or p["health"] != 200)]
first_bad = bad[0]["t"] - k if bad else None
recovered = None
if bad:
    after = [p for p in probe if p["t"] > bad[-1]["t"]]
    recovered = (after[0]["t"] - k) if after else None
print("readiness: killed %s; first non-200 at +%s s; last non-200 at +%s s; steady 200 from +%s s; non-200 samples %d"
      % (kill["primary"], round(first_bad, 2) if first_bad is not None else None,
         round(bad[-1]["t"] - k, 2) if bad else None, round(recovered, 2) if recovered is not None else None, len(bad)))
print("  probe states after kill:", dict(collections.Counter((p["ready"], p["health"]) for p in probe if p["t"] >= k)))

print("database:")
for line in open(rows):
    print("  " + line.rstrip())
