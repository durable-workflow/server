# Release candidate 1 observations, October 30

Three alternating PHP/native-image pairs on one host, against the published
native release candidate. Host, profile and the sampler fallback are in the
[README](README.md). These are matched observations on one host and one
database family (MySQL 8.4.5); they are not a multi-host release claim.

## Conditions

- **Native.** The published image
  `ghcr.io/durable-workflow/server-native@sha256:43718810…0add6c86`
  (`3.0.0-rc.1`, source `03a3e7b7`), run as an operator runs it: its own
  entrypoint, user and maintenance loop, with the profile's limits.
- **PHP.** The profile's recorded baseline, published Server 2.6.2, with its
  scheduler, worker and Redis inside the same two-CPU, two-GiB budget.
- **Procedure.** A native-image smoke check, then PHP, native-image, PHP,
  native-image, PHP, native-image full runs in that order, each with its own
  Compose project and fresh database. Each full run is the profile's: warmup,
  three 120 s windows at 0.25, 0.5 and 1 workflow/s, the 0.25/s windows
  repeated after as a drift check, then mixed idle long polls. Pairs 1 and 2
  ran from 15:52 to 18:04 UTC and pair 3 from 18:12 to 19:18 UTC.

All six runs finished with status 0. Every window's workflows were verified;
no container restarted or was OOM-killed. No other container ran on the host.
Load before native run 2 was 2.6 (one minute) after PHP run 2's teardown; it
fell before its first window.

## Offered work and latency

Each cell gives the three per-window values or their range. "Verified" is
workflows completed by the observation deadline; the rest were still pending.

| Run / rate | In-window completions | Verified | p50 s | p95 s | p99 s |
| --- | --- | --- | --- | --- | --- |
| PHP 1 / 0.25 before | 30/30/30 | 30/30/30 | 1.701–1.802 | 2.438–2.768 | 2.439–4.720 |
| PHP 1 / 0.5 | 53/56/55 of 60 | 60/60/60 | 4.577–14.503 | 9.737–17.112 | 10.015–17.526 |
| PHP 1 / 1 | 38/23/4 of 120 | 82/64/45 | 87.848–149.307 | 150.592–182.851 | 150.895–187.304 |
| PHP 1 / 0.25 after | 22/30/30 of 30 | 30/30/30 | 1.827–45.901 | 2.050–80.142 | 2.401–83.016 |
| Native 1 / 0.25 before | 30/30/30 | 30/30/30 | 0.291–0.308 | 0.374–0.492 | 0.421–0.539 |
| Native 1 / 0.5 | 60/60/60 | 60/60/60 | 0.280–0.290 | 0.388–0.496 | 0.503–0.717 |
| Native 1 / 1 | 120/120/120 | 120/120/120 | 0.273–0.283 | 0.359–0.466 | 0.420–0.776 |
| Native 1 / 0.25 after | 30/30/30 | 30/30/30 | 0.280–0.302 | 0.360–0.455 | 0.450–0.501 |
| PHP 2 / 0.25 before | 30/30/30 | 30/30/30 | 1.822–2.047 | 2.324–2.959 | 2.336–3.031 |
| PHP 2 / 0.5 | 50/46/56 of 60 | 60/60/60 | 9.396–14.978 | 10.397–30.533 | 10.824–31.577 |
| PHP 2 / 1 | 37/33/26 of 120 | 95/93/70 | 105.751–115.712 | 144.846–167.472 | 145.809–168.259 |
| PHP 2 / 0.25 after | 30/30/30 | 30/30/30 | 1.617–7.811 | 2.113–41.805 | 2.550–44.966 |
| Native 2 / 0.25 before | 30/30/30 | 30/30/30 | 0.277–0.282 | 0.428–0.515 | 0.462–0.764 |
| Native 2 / 0.5 | 60/60/60 | 60/60/60 | 0.264–0.281 | 0.331–0.377 | 0.471–0.996 |
| Native 2 / 1 | 120/120/120 | 120/120/120 | 0.266–0.284 | 0.329–0.543 | 0.350–1.076 |
| Native 2 / 0.25 after | 30/30/30 | 30/30/30 | 0.281–0.308 | 0.369–0.395 | 0.406–0.495 |
| PHP 3 / 0.25 before | 30/30/30 | 30/30/30 | 1.704–2.059 | 2.316–4.646 | 2.379–4.803 |
| PHP 3 / 0.5 | 52/58/59 of 60 | 60/60/60 | 2.454–12.777 | 5.371–16.106 | 5.535–16.499 |
| PHP 3 / 1 | 40/37/36 of 120 | 109/109/104 | 107.790–114.626 | 135.828–140.305 | 136.382–141.266 |
| PHP 3 / 0.25 after | 30/30/30 | 30/30/30 | 1.767–1.833 | 2.254–3.210 | 2.403–6.416 |
| Native 3 / 0.25 before | 30/30/30 | 30/30/30 | 0.264–0.285 | 0.326–0.422 | 0.368–0.913 |
| Native 3 / 0.5 | 60/60/60 | 60/60/60 | 0.265–0.287 | 0.355–0.462 | 0.419–0.815 |
| Native 3 / 1 | 120/120/120 | 120/120/120 | 0.269–0.292 | 0.334–0.397 | 0.365–0.544 |
| Native 3 / 0.25 after | 30/30/30 | 30/30/30 | 0.284–0.298 | 0.369–0.740 | 0.379–0.782 |

PHP does not sustain 1/s on this host. In its 1/s windows it closed only 4–40
of 120 workflows inside the window, verified 45–109 by the deadline, and its
median latency was 88–150 s; its 0.5/s windows already queue (median up to
15 s). Native closed every workflow inside every window at every rate, with a
median of 0.26–0.31 s and p95 at most 0.74 s. PHP's capacity is lower here
than on the October 20 host; native's is unchanged.

## CPU and memory

Pair 3, the only pair with samples. Core-seconds per verified workflow, and
the highest container-charged memory sampled for the runtime.

| Run / rate | Runtime core-s/wf | MySQL core-s/wf | Runtime max MiB |
| --- | --- | --- | --- |
| PHP 3 / 0.25 before | 3.435–3.671 | 1.028–1.191 | 202.6–210.8 |
| PHP 3 / 0.5 | 2.331–2.515 | 0.825–0.890 | 197.2–211.3 |
| PHP 3 / 1 | 2.571–2.697 | 0.918–0.959 | 201.6–211.4 |
| PHP 3 / 0.25 after | 3.392–3.539 | 1.017–1.080 | 197.7–209.5 |
| Native 3 / 0.25 before | 0.366–0.405 | 0.727–0.799 | 6.7–7.4 |
| Native 3 / 0.5 | 0.253–0.284 | 0.516–0.573 | 7.0–7.8 |
| Native 3 / 1 | 0.204–0.222 | 0.418–0.452 | 7.6–7.9 |
| Native 3 / 0.25 after | 0.401–0.433 | 0.823–0.895 | 7.4–7.7 |

At 1/s, native uses about a twelfth of PHP's runtime CPU per workflow and
about half of its database CPU. Its peak memory is under 8 MiB against PHP's
197–211 MiB.

## Idle mixed long polls

Pair 3. Runtime core-seconds per idle window, three windows each, and health
failures per window.

| Run | 6 polls | 12 polls | 24 polls | Health failures |
| --- | --- | --- | --- | --- |
| PHP 3 | 8.321–8.901 | 9.281–10.672 | 11.053–12.755 | 0/0/0, 2/1/1, 2/2/3 |
| Native 3 | 1.030–1.114 | 1.374–1.534 | 2.143–2.281 | 0/0/0, 0/0/0, 0/0/0 |


All three pairs agree on the answers: PHP answers 4 of its 12 polls and 16 of
its 24 polls with backpressure, and its health checks fail in those windows.
Native answers every poll empty and never fails a health check. Native's idle
cost here includes the image's own maintenance loop, which the October 20
binary did not run.

## Against the acceptance line

"Rust meets or beats the recorded PHP baseline for throughput, memory and
idle long polls":

- **Throughput.** Met. Native sustains 1/s with every workflow closed inside
  the window; PHP does not.
- **Memory.** Met. Under 8 MiB against 197–211 MiB.
- **Idle long polls.** Met. About a fifth to an eighth of PHP's idle CPU,
  every poll answered, no health failure.
- **CPU.** About a twelfth of PHP's runtime CPU and half its database CPU at
  1/s.

## What this does not show

This is one host type, one database family and the published image's amd64
build. It is not PostgreSQL or SQLite, not multi-node, and not the CPU-heavy
deadline profile, which the codec deadline-health CI job covers. Pairs 1 and
2 have no CPU or memory samples (see the README); their latency and
throughput agree with pair 3.
