# Activity workflow continuation v2 benchmark

This runner preserves the reconstructed load, latency and SQL measurements
used for Server #1181. It uses the workflow shape recorded in #1078. The
original November 12 scripts were temporary. The source here reproduces the
November 28 workload, including its PHP runtime and resource limits. [Original workload](https://github.com/durable-workflow/server/issues/1078#issuecomment-6827690149)
[Published v2 measurements](https://github.com/durable-workflow/server/issues/1181#issuecomment-7234206731)

Run from a Linux Docker host with cgroup v2, a kernel with netem support, Bash,
jq and Python 3. Docker needs registry access for the pinned images and the
locked PHP installation. The containers use an internal network and publish no
ports. Delayed workers get the capability to configure netem on their own
interfaces, then run PHP as an unprivileged user. Each cell has a fresh database
and explicit CPU and memory limits. Server and MySQL each have three CPUs.
Six PHP workers and the client each have one CPU. Budget roughly 45 minutes
for the full cohort on a comparable machine.

```sh
bash benchmarks/server-port/continuation-v2/run.sh /path/to/new/results
```

Add `--smoke` to exercise every combination once with six batch workflows and
three sequential workflows. Use a new output directory for each run. The
runner publishes a cell only after its workload and container cleanup finish.
An interrupted run can leave partial output for diagnosis.

The default cohort runs three rotated repetitions of eight cells. Each starts
with 20 warmup workflows. The measured load is either 200 workflows submitted
back to back or 30 started and awaited one at a time. Each executes five
20 ms activities that echo a 1 KiB value. The delay is 20 ms on worker egress
only. MySQL runs with `sync_binlog=1` and
`innodb_flush_log_at_trx_commit=1`. The PHP driver uses installed SDK 3.33.0
and protocol 1.20. Its option selects v2 negotiation for each on cell.

`run.json` records the image identities, installed SDK source, lock and runner hashes,
and host runtime. In `cohort/`, each case retains the wall interval between the
first recorded create and last recorded close, per-workflow latencies, CPU
deltas, SQL and COMMIT deltas from the Server's MySQL account and its `statement/sql` counters,
and continuation receipt counts. `summary.md` reports medians and ranges and evaluates the
criteria in the [continuation contract](../../../docs/contracts/activity-workflow-continuation-v2.md#benchmark).
An analysis with overlapping loaded wall-time ranges or a slower sequential
median reports that result alongside the evidence.

Recreate the report from retained cell files with
`python3 benchmarks/server-port/continuation-v2/summarize.py /path/to/results/cohort`.
