# Published PHP development reference observations

These are bounded diagnostic observations for [Server #325](https://github.com/durable-workflow/server/issues/325),
not a capacity qualification or a PHP/Rust improvement claim. They use profile
`1.0.0`, source `092eecba2ab2a9d7f2c4af72df1b9fa16a8db8a3`, published
Server 2.5.13, Workflow 2.5.4 and PHP SDK 2.2.6. Exact artifacts, resource limits,
host facts and commands are in [the profile](php-baseline.json) and [README](README.md).
The workload is one external echo activity with a 1,024-byte Avro value each way.
There is one client and one SDK worker. Every recorded offered workflow completed
and passed output, ordered history and timestamp verification.

## Offered rate and completion

Each rate has three 120-second offer windows. Counts below are **in-window**
completions; final completion includes the separately observed bounded drain.

| Offered starts/s | Planned per window | In-window completions, each repetition | Completion p50, seconds | Completion p99, seconds |
| --- | --- | --- | --- | --- |
| 0.25 | 30 | 30 / 30 / 30 | 1.416 / 1.517 / 1.493 | 2.140 / 2.182 / 2.073 |
| 0.5 | 60 | 60 / 60 / 60 | 1.365 / 1.489 / 1.448 | 2.145 / 2.618 / 2.394 |
| 1.0 | 120 | 49 / 51 / 48 | 80.942 / 80.994 / 84.408 | 101.653 / 101.895 / 104.221 |

The 1/s rows are runs 1, 3 and the replacement for run 2. They completed only
0.400–0.425 workflows/s during the offer window. The remaining 69–72 workflows
drained afterwards, within the declared 120-second drain bound. Accepting all
120 starts does not establish sustained 1/s completed throughput.

The original second 1/s run also completed and verified all 120 workflows,
51 in-window. Its PHP queue worker reached the configured 3,600-second runtime
limit and exited cleanly. Its observer flagged the state change; that run is
excluded from the resource-consistent group, with its raw results retained.
The experiment's worker was manually started before the replacement. The
replacement returned one readiness 503 out of 146 probes, reporting
`queue.probe_child_failed`; all 146 health probes passed. This result does not
establish a Redis outage and does not qualify API headroom at that offered rate.
The other selected offered-rate runs had no API errors, missed offers, poll
errors, verification errors or pending work after drain.

Two following 0.25/s drift windows each completed all 30 in-window. Their p50
values were 1.426 and 1.474 seconds, or 0.955 and 0.987 times the median initial
p50. Both passed API checks; the last capture began before admission. These
controls do not remove the changing background-load limitation below.

## Sampled resource observations

HTTP means cover the PHP HTTP container. Runtime means include HTTP, queue
worker, scheduler, MySQL and Redis; SDK worker, client and collector are separate.
CPU is the sampled cumulative-counter delta divided by elapsed time, expressed
in CPU cores. Memory is Docker working set (`usage - inactive_file`), not RSS.

| Offered starts/s | HTTP mean CPU cores, range | HTTP mean working set, MiB | Complete runtime mean working set, MiB |
| --- | --- | --- | --- |
| 0.25 | 0.514–0.526 | 92.6–93.6 | 732.2–803.4 |
| 0.5 | 0.755–0.798 | 96.0–98.2 | 815.6–830.1 |
| 1.0 | 0.841–0.883 | 96.2–99.5 | 840.3–878.6 |

The final drift capture observed 0.522 HTTP CPU cores and 97.3 MiB mean HTTP
working set, with a sampled peak of 131.0 MiB. Complete runtime mean/peak were
884.8/933.0 MiB. MySQL's cache and retained dataset grew during the sequence;
a fresh Rust database must not be compared with late PHP memory as a runtime gain.

Early resource observers missed 1.284–2.765 seconds at admission. The replacement
1/s run missed 0.221 seconds; the last drift capture covered admission and the
execution tail. Client and collector resources were not fully sampled. These
means and sampled peaks are diagnostics, not complete execution CPU costs,
true peaks or end-to-end resource totals. No runtime OOM, automatic restart or
swap use was observed. The explicit worker actions above remain part of the record.

## Mixed idle long polls

The SDK worker was intentionally stopped while the PHP worker and scheduler
remained running. Each count has three repetitions, requesting ten-second waits.
Workflow/activity waits share six admission slots; query waits have two.

| Concurrent mixed polls | Outcome in every repetition | Ordinary API observation |
| --- | --- | --- |
| 6 | 6 empty waits | Health/readiness passed |
| 12 | 8 empty waits, 4 explicit capacity rejections | Health/readiness passed |
| 24 | 8 empty waits, 16 explicit capacity rejections | At least one two-second API timeout in all three repetitions |

Across the 24-poll bursts there were three health and two readiness timeouts.
An Apache `MaxRequestWorkers` warning was observed. Registration correctness
does not establish API headroom: the failed burst results remain evidence.
Query waits were clamped to about five seconds; workflow/activity waits lasted
about ten seconds. There were no claimed tasks or leftover registrations.
The PHP HTTP mean working-set ranges during held polls were 95.4–97.3,
110.1–112.4 and 128.8–134.5 MiB at 6, 12 and 24 polls respectively. These are
sampled diagnostics with the same comparison limitations.

## Evidence limits and next qualification

All 810 offered workflows completed and verified, including the original
resource-invalid run and both drift windows. Final synthetic database counts
were 846 completed runs (including fixtures and warmups), 2,530 completed tasks,
zero failed jobs and zero idle registrations. Raw outcomes, API intervals,
resource counters, failed observations, input hashes, installed versions and
the synthetic history snapshot are retained for 90 days in the owning evidence
record. These records do not qualify backup/restore or migration. Disposable
experiment resources and dependencies were removed after retention.

Background workload changed on the shared development host. Its kernel,
runtime and SATA storage also differ from the standard capacity topology. The
120-second, one-client profile does not satisfy that suite's duration or client
contract. Matched-condition PHP/Rust repetition, full resource sampling, CPU-heavy
deadline tests and the complete capacity/conformance/upgrade gates remain open.
The recorded failures supply concrete reference cases for the Rust implementation.
