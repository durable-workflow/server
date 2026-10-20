# Matched development observations, October 20

One PHP/native pair on a dedicated experiment host. These are development
observations, not a qualified performance gain, capacity result or release
claim. The workload, artifacts, limits and commands are the [v2 profile](../v2/README.md)
unchanged; [matched.json](matched.json) records this run's native build and machine.

## Conditions

- **Source.** Server main `e5bf7e2b`, native tree `2b0c5198`, after its
  13-job shared matrix passed. Optimized binary SHA-256 `cf18b714…07ca1`
  (33,628,936 bytes), built with `cargo build --release --locked` in the
  pinned Rust 1.99.0 container with one job and one CPU. The profile's 1.5 GiB
  build limit OOM-killed rustc compiling the library; 3 GiB built it in 559 s.
- **References.** PHP Server 2.6.2 / Workflow 2.5.14 image and the normally
  installed PHP SDK 2.3.9 (lock `42e814…87ca`) from the v2 profile. Probe,
  adapter and idle-probe hashes match the profile.
- **Host.** One Vultr `vhp-4c-8gb-amd` VM (4 vCPU AMD EPYC-Rome, 7.7 GiB,
  Ubuntu kernel 6.8, Docker 29.1.3, cgroup v2 with systemd, no swap), created
  for this run and deleted after it. No other workload ran. Host load, swap
  and container counts were recorded around every window. Swap-in and
  swap-out stayed at zero.
- **Limits.** Each runtime gets two CPUs and 2 GiB in aggregate: PHP's HTTP,
  queue worker, scheduler and Redis, or the one native process. MySQL 8.4.5 gets
  one CPU and 1 GiB. The SDK worker and probe each get half a CPU and 512 MiB.
  PHP ran first, then native, each in its own Compose project with a fresh
  database.
- **Procedure.** [`run-server-port-matched.sh`](../../../scripts/perf/run-server-port-matched.sh)
  samples every container's cgroup CPU and memory each second. It runs a
  setup check, a 60 s warmup, then three 120 s offer windows each at 0.25, 0.5
  and 1 workflow/s, with the 0.25/s windows repeated before and after as a
  drift check. It then stops the SDK worker and repeats mixed idle polls
  (6, 12 and 24, three times each), and finishes with a database and container
  audit. [`summarize-server-port-matched.py`](../../../scripts/perf/summarize-server-port-matched.py)
  produced the tables below and the per-window summaries
  ([PHP](summary-php-2026-10-20.json), [native](summary-native-2026-10-20.json)).

Both implementations complete all 737 workflows (2 setup, 15 warmup, 720
measured), with 1,474 completed workflow tasks and 737 completed activities.
Every output and ordered history passes the unchanged probe. Afterwards no
worker registration remains. No container restarts or is OOM-killed, and the
SDK worker exits 0 on SIGTERM.

## Offered work and latency

Latency runs from the client's start request to the server's `closed_at`.
Each cell gives the range of the three per-window values.

| Runtime / rate | In-window completions | p50 s | p95 s | p99 s |
| --- | --- | --- | --- | --- |
| PHP / 0.25 before | 30/30/30 | 0.985–1.025 | 1.329–1.434 | 1.428–1.510 |
| PHP / 0.5 | 60/60/60 | 0.967–0.975 | 1.237–1.318 | 1.346–1.406 |
| PHP / 1 | 93/93/96 of 120 | 14.689–18.053 | 26.134–28.804 | 27.304–29.768 |
| PHP / 0.25 after | 30/30/30 | 0.975–1.018 | 1.258–1.356 | 1.322–1.385 |
| Native / 0.25 before | 30/30/30 | 0.821–0.879 | 1.434–1.524 | 1.453–1.658 |
| Native / 0.5 | 60/60/60 | 0.689–0.890 | 1.197–1.364 | 1.489–1.923 |
| Native / 1 | 120/120/120 | 0.545–0.581 | 1.110–1.339 | 1.371–1.871 |
| Native / 0.25 after | 30/30/30 | 0.837–0.962 | 1.432–1.632 | 1.531–1.856 |

PHP does not sustain 1/s. Every workflow still verifies within the drain bound,
but only 93–96 of 120 close inside each window. Native sustains all three
rates. The drift checks before and after agree for both implementations.

Native's low-load latency is close to PHP's and higher than its own at 1/s.
The lower October 14 low-rate tails (p95 up to 5 s) are gone. The probe's
history stages show where the 0.25/s median (0.82–0.96 s) goes. In the first
low-rate window, the client start alone takes 0.210 s to `WorkflowStarted`
(0.008 s at 1/s, 0.065 s for PHP at 0.25/s). Activity scheduled to started
takes 0.183 s (0.023 s at 1/s), and activity completed to workflow completed
takes 0.297 s (0.173 s at 1/s). Each transition gets slower when the server
is nearly idle, which points at waiting behind the SDK worker's idle poll
transactions rather than at the work itself. That has not been measured
directly yet.

## Component cost

CPU covers each window's offer, drain and verification, divided by verified
workflows. Memory is the highest sampled cgroup charge, not RSS; the 0.25/s
row includes the later drift-check windows, after MySQL's buffer pool has grown.

| Runtime / rate | Runtime core-s/workflow | MySQL core-s/workflow | Runtime max MiB | MySQL max MiB |
| --- | --- | --- | ---: | ---: |
| PHP / 0.25 | 2.438–2.511 | 0.602–0.621 | 213.0 | 851.9 |
| PHP / 0.5 | 1.623–1.661 | 0.485–0.500 | 205.8 | 708.6 |
| PHP / 1 | 1.268–1.296 | 0.438–0.444 | 202.8 | 825.9 |
| Native / 0.25 | 0.295–0.305 | 0.643–0.704 | 6.8 | 765.1 |
| Native / 0.5 | 0.158–0.164 | 0.357–0.370 | 6.7 | 618.0 |
| Native / 1 | 0.097–0.100 | 0.230–0.242 | 7.0 | 741.6 |

Native's runtime uses 8–13 times less CPU per workflow and about 30 times less
memory. MySQL work per workflow is similar at 0.25/s, slightly higher for
native, and about half of PHP's at 1/s.

## Idle long polls

With no work and no SDK worker, the mixed idle probe holds 6, 12 or 24 long
polls for 10 s. The figures are average cores over each idle window, so the
two implementations' different window lengths do not matter.

| Runtime / polls | Outcomes | Runtime cores | MySQL cores | Health failures |
| --- | --- | --- | --- | --- |
| PHP / 6 | 6 empty | 0.40–0.51 | 0.071–0.075 | 0/50 |
| PHP / 12 | 8 empty, 4 backpressure | 0.46–0.49 | 0.083–0.086 | 3/52 |
| PHP / 24 | 8 empty, 16 backpressure | 0.49–0.51 | 0.100–0.104 | 6/53 |
| Native / 6 | 6 empty | 0.097–0.111 | 0.206–0.220 | 0/57 |
| Native / 12 | 12 empty | 0.154–0.178 | 0.316–0.351 | 0/54 |
| Native / 24 | 24 empty | 0.272–0.304 | 0.537–0.603 | 0/56 |

PHP admits eight waiting polls and refuses the rest with backpressure. Its
health checks fail while 12 or 24 are held. Native admits all of them and
answers every health check. But native's idle cost grows by about
0.035 cores per waiting poll, two-thirds of it in MySQL. Each waiting poll
claims under the transition lock every 50 ms. At 24 waiting polls native
uses about 0.86 cores against PHP's 0.60. This misses the gate that native
idle long-poll cost meets or beats PHP's, and it is the next performance
correction: cheaper waiting that does not take the transition lock or a
locked transaction until work exists.

## Limitations

- One pair on one shared-vCPU VM type. No repeated pair, no matched host
  comparison with October 14, and no standard capacity topology.
- The single-client, 120 s windows do not establish maximum sustained
  capacity.
- Probe CPU is excluded from these figures; its per-window containers reset
  their counters.
- No multi-node, failure-injection or other database family is covered.
