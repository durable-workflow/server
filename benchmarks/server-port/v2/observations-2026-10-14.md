# First matched development observations

These are diagnostic observations, not a qualified performance gain, sustained
capacity result or release/cutover qualification. PHP has two idle health-probe
timeouts and an unsuccessful scheduler shutdown. Native workflow tails vary
between repetitions. The original idle probe also rejects a protocol-permitted
native reply. Its failed exits remain recorded.

## Conditions and verification

The [measured profile 2.0.0](https://github.com/durable-workflow/server/blob/6ad7c2dfc6b66c1481f2714b62f331e2f0bc8019/benchmarks/server-port/v2/matched.json)
and source `6ad7c2df` freeze the artifacts, adapter and commands. Native Rust
tree is `696130d720bc93fd9b27dc0e97c6d45698283cdd`; optimized binary SHA-256 is
`bb061b549890a9ca1f75a3927541c1bbf331984476347a546fade1cf15961f95`.
Two clean builds reproduce that binary in 7m23s and 7m31s under one CPU,
1.5 GiB and no additional swap. Each disposable compiler/cache is removed
before measurement. The binary is 26,172,808 bytes.

PHP Server 2.6.2/Core 2.5.14 and the native process run sequentially with separate
fresh MySQL 8.4.5 databases. Both use normally installed PHP SDK 2.3.9 and the
same one-activity workload with a 1,024-byte logical value each direction.
Each runtime gets an aggregate two CPUs/two GiB. MySQL gets one CPU/one GiB,
and SDK/probe each get half a CPU/512 MiB. PHP runtime includes HTTP, queue,
scheduler and Redis. The development host has four CPUs, a 5.15 kernel and
SATA storage, outside the standard capacity topology.

Each implementation completes 720 measured workflows, plus 15 warmup and two
setup originals: 737 workflows and activities, 2,211 completed first-attempt
tasks, zero repairs/failed jobs/registrations and 4,422 history events. Every
completed output and ordered semantic history passes the unchanged probe.
This establishes durable completion counts, not exactly-once external effects.

Measured windows run from 19:26:04 through 20:33:53 UTC. Every runtime/database/
SDK/client component has cumulative CPU and memory samples from before admission
through the full offer window and verification. Largest sample gap is 1.260s.
No component restarts or OOMs occur during windows. Other local application/build
stacks are absent. Host counters record ten 4-KiB swap-ins and no swap-outs.
Those host pages are not attributed to an application.

## Offered work and latency

Each phase has three 120-second offer windows and the original 120-second drain
bound. Low is 0.25/s, medium 0.5/s and high 1/s. Low repeats before and after
higher rates check drift. Quantile cells are ranges of the three per-window
quantiles, not pooled quantiles. Latency runs from client start-request beginning
to Server `closed_at`, excluding describe collection delay.

| Runtime / phase | Accepted = verified | In-window completions (r1/r2/r3) | p50 seconds | p95 seconds | p99 seconds |
| --- | ---: | --- | --- | --- | --- |
| PHP / low-before | 90 | 30/30/30 | 1.315–1.440 | 1.887–2.045 | 2.058–2.423 |
| PHP / medium | 180 | 59/60/60 | 1.308–1.419 | 1.820–2.063 | 2.022–2.356 |
| PHP / high | 360 | 54/52/51 | 70.880–89.297 | 90.407–104.870 | 91.873–106.540 |
| PHP / low-after | 90 | 30/30/30 | 1.331–1.368 | 1.954–2.115 | 2.021–2.134 |
| Native / low-before | 90 | 30/30/30 | 0.330–0.335 | 1.203–5.215 | 5.069–9.113 |
| Native / medium | 180 | 60/60/60 | 0.310–0.313 | 0.554–5.629 | 2.932–11.302 |
| Native / high | 360 | 120/120/120 | 0.293–0.297 | 0.356–2.766 | 0.379–6.985 |
| Native / low-after | 90 | 30/30/30 | 0.301–0.317 | 3.263–4.686 | 7.146–8.565 |

The PHP high-rate observations require a substantial drain and do not sustain
the offered rate. Native's fast high-rate repetitions do not erase its slower
low-load tails. One slow native repetition places its large stage p95 before
activity scheduling; that observation does not establish the delay's cause.

## Component cost

CPU covers the complete observed window, including drain and verification.
Core-seconds/workflow divides those counters by verified completions. Runtime
memory is the maximum sampled aggregate Docker cgroup usage, including all four
PHP runtime roles or the one native process. MySQL is additional. These are
container-charged samples, not RSS, proportional physical RAM or kernel peaks.
Shared/cached executable pages can be charged elsewhere. Do not extrapolate the
small native cgroup figure into a full-host RAM or Cloud cost claim.

| Runtime / phase | Runtime core-s/workflow | MySQL core-s/workflow | Runtime charged MiB | MySQL charged MiB | SDK core-s/workflow | Probe core-s/workflow |
| --- | --- | --- | ---: | ---: | --- | --- |
| PHP / low-before | 2.671–2.797 | 0.670–0.700 | 195.8 | 621.4 | 0.031–0.035 | 0.115–0.123 |
| PHP / medium | 1.810–1.890 | 0.544–0.571 | 203.9 | 713.7 | 0.024–0.026 | 0.056–0.060 |
| PHP / high | 1.673–1.767 | 0.532–0.553 | 203.8 | 830.5 | 0.023–0.024 | 0.045–0.049 |
| PHP / low-after | 2.692–2.753 | 0.684–0.702 | 204.0 | 856.3 | 0.031–0.032 | 0.115–0.119 |
| Native / low-before | 0.297–0.303 | 0.632–0.668 | 6.9 | 541.0 | 0.028–0.030 | 0.155–0.157 |
| Native / medium | 0.163–0.165 | 0.370–0.379 | 6.7 | 589.3 | 0.021–0.022 | 0.079–0.089 |
| Native / high | 0.095–0.095 | 0.221–0.231 | 7.4 | 684.9 | 0.017–0.017 | 0.041–0.046 |
| Native / low-after | 0.302–0.304 | 0.689–0.693 | 7.0 | 714.8 | 0.030–0.031 | 0.168–0.173 |

The health/readiness observer sleeps after responses, so request counts differ
with response time. CPU includes that traffic. Host CPU, Docker observation
overhead and standing processes are outside component counters. Component sample
boundaries differ slightly because collection is sequential.

## Idle replies and API headroom

All workers deregister without receiving tasks. PHP's configured worker wait
pool is six and query pool two; the native profile does not apply an equivalent
limit. Different admission counts cannot establish increased capacity. PHP
workflow/activity waits last about ten seconds and query waits about five;
native waits last about ten seconds for all kinds.

| Runtime / count | Outcomes in each repetition | Probe exits | Health non-200 counts | Max observed API elapsed seconds |
| --- | --- | --- | --- | ---: |
| PHP / 6 | 6 empty | 0/0/0 | 0/0/0 | 1.043 |
| PHP / 12 | 8 empty, 4 backpressure | 0/0/0 | 0/0/0 | 2.034 |
| PHP / 24 | 8 empty, 16 backpressure | 0/0/0 | 0/1/1 | 5.003 |
| Native / 6 | 6 unexpected | 1/1/1 | 0/0/0 | 0.047 |
| Native / 12 | 12 unexpected | 1/1/1 | 0/0/0 | 0.223 |
| Native / 24 | 24 unexpected | 1/1/1 | 0/0/0 | 0.372 |

The two PHP health requests return no response within their five-second bound.
The table's 5.003s is timeout elapsed time. Native's `unexpected` observations
are probe false refusals: every reply is `task=null, poll_status=timeout`, and
the frozen worker OpenAPI `EmptyPoll` permits both `timeout` and `empty`.
Original exits remain 1. Profile 2.0.1 corrects only that classifier, still
rejecting unknown statuses and malformed task values. Separate controls accept
six actual native `timeout` replies and six actual PHP `empty` replies, with
zero delivered tasks and cleanup errors. No native wire correction is implied.

## Shutdown and next qualification

Original PHP SDK, HTTP, queue, MySQL and Redis roles exit 0. Its custom scheduler
requires the 30-second stop timeout and exits 137 without OOM. All original
native roles exit 0. Neither observation is relabelled by the subsequent fix.

The separate profile 2.0.1 scheduler handler completes a fresh two-workflow PHP
check with six first-attempt tasks, zero repairs/failed jobs/registrations and
all roles exiting 0. Its idle reader accepts the six PHP empty replies. These
controls qualify those local corrections, not a new full matched comparison.

Next: diagnose native tail variation with a bounded published-worker control,
then repeat a corrected matched profile before any gain claim. Full capacity,
other workloads/databases, migration, publication and cutover remain open under
#325. Keep this readable report and code; raw databases, resource samples,
dependency trees and compiler state are removed after their immediate audit.
