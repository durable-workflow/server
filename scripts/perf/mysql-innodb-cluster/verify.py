#!/usr/bin/env python3
"""Summarize one failover run: load outcomes, readiness during failover, and
database integrity (one completion per run, no duplicated history, no
leftover leases).

Reads one stream on stdin, sections introduced by marker lines:

    { echo '#kill'; cat kill.json; echo '#load'; cat load.jsonl;
      echo '#probe'; cat probe.jsonl; echo '#db'; cat db.tsv; } | verify.py
"""
import collections
import json
import sys

sections = collections.defaultdict(list)
current = None
for raw in sys.stdin:
    line = raw.rstrip("\n")
    if line in ("#kill", "#load", "#probe", "#db"):
        current = line[1:]
    elif current and line:
        sections[current].append(line)

kill = json.loads(sections["kill"][0])
events = [json.loads(line) for line in sections["load"] if line.startswith("{")]
probe = [json.loads(line) for line in sections["probe"] if line.startswith("{")]

def outcome(event):
    if event["event"] != "completed":
        return event["event"]
    return "completed:ok" if event.get("ok") else "completed:bad"

counts = collections.Counter(outcome(e) for e in events)
secs = sorted(e["seconds"] for e in events if e["event"] == "completed")
print("load:", dict(counts))
if secs:
    print("completion seconds p50=%.2f p95=%.2f max=%.2f"
          % (secs[len(secs) // 2], secs[max(0, int(len(secs) * 0.95) - 1)], secs[-1]))
errors = collections.Counter(e.get("error", "")[:90] for e in events if "error" in e)
for text, n in errors.most_common(5):
    print("  error x%d: %s" % (n, text))

k = kill["t"]
bad = [p for p in probe if p["t"] >= k and (p["ready"] != 200 or p["health"] != 200)]
if bad:
    after = [p for p in probe if p["t"] > bad[-1]["t"]]
    steady = round(after[0]["t"] - k, 2) if after else None
    print("readiness: failed %s; first non-200 at +%.2f s; last non-200 at +%.2f s; steady 200 from +%s s; non-200 samples %d"
          % (kill["primary"], bad[0]["t"] - k, bad[-1]["t"] - k, steady, len(bad)))
else:
    print("readiness: failed %s; no non-200 sample" % kill["primary"])
print("  probe states after failure:",
      dict(collections.Counter((p["ready"], p["health"]) for p in probe if p["t"] >= k)))

print("database:")
for line in sections["db"]:
    print("  " + line)
