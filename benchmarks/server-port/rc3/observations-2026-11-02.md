# Release candidate 3 observations, November 2, 2026

One batch (`dw-bench-rc3-20261102`) on disposable Vultr hosts in EWR, run by
the Cloud owner on 13:34–16:49 UTC with [small-host.json](small-host.json) and
[matched-4c.json](matched-4c.json) at Server `8f5ac120`. It is the rc.3
performance qualification. Like the
[November 2 rc.2 note](../small-host/observations-2026-11-02.md), it is a
development comparison, not a publishable capacity claim.

## Identity

- Native: `ghcr.io/durable-workflow/server@sha256:899b404b02f856cb582c5e77a95882a3f9928781083d1e7a3fe569f3ff978d6b`
  (`3.0.0-rc.3`, source `04cad60e`). The only runtime change in rc.4 and in
  `3.0.0` (source `74145425`) is #939's schedule next-fire math, which this
  workload does not use.
- PHP reference: published Server 2.11.8,
  `durableworkflow/server@sha256:3848d5d5cd1b23bd2881e3fdecea64cc44b72e6fb812e577c2db6d0872d065dd`.
- MySQL and Redis come from the profiles' digests. SDK PHP 2.3.9, lock
  `42e814ee…`.
- Each small-host summary records `profile_sha256` `520f4157…`
  (`small-host.json`).
- Topology is as in the rc.2 note. Targets ran only the server and its
  database, with no container limits. A separate `vhp-4c-8gb-amd` driver sat
  in the same private network, and each target accepted 8080 only from the
  driver's private address.

## Results

Sustained one-activity capacity (workflows/s) is the highest rate that passed
twice: at least 90% closed in the window, p95 ≤ 2 s, and a clean probe.

| Plan | native-mysql | native-sqlite | php-mysql | rc.2 (Nov 2) |
| --- | ---: | ---: | --- | --- |
| `vhp-1c-1gb-amd` | 4 | 8 | none | 4 / 8 / none |
| `vhp-1c-2gb-amd` | 4 | 4 | none | 4 / 4 / none |
| `vc2-1c-1gb` | 1 (rerun 1) | 2 (rerun 2) | none | 2 / 4 / none |

No run had an OOM kill, container restart or non-zero teardown.

**rc.3 and rc.2 cost the same per workflow.** On `vhp-1c-1gb-amd` at
1 workflow/s:

| Mode | Service | rc.3 cores | rc.2 cores |
| --- | --- | ---: | ---: |
| MySQL | server | 0.099 | 0.096 |
| MySQL | MySQL | 0.183 | 0.180 |
| SQLite | server | 0.080 | 0.0795 |

The 2 workflows/s windows agree to within a few percent too. rc.3 does no
extra work and issues no extra queries.

**The lower `vc2-1c-1gb` ceilings come from that instance, not rc.3.** Both
native modes failed one ladder step below rc.2, and the reruns reproduced it
at low CPU steal. The target's 5-second `/proc/stat` samples show 1.0–1.2%
steal around the failing MySQL 2/s windows and 2.4–3.4% at the failing SQLite
4/s windows. The MySQL 2/s windows closed only 39 and 49 of 120. The SQLite
4/s windows closed 237 of 240 with p95 0.94 s but failed the probe's
late-offer check.

On this instance the unchanged MySQL image itself needed about 25% more CPU
for the same work. At 1/s it used 0.53–0.54 cores, against 0.43 on November
2's `vc2`. The server needed about 25% more as well: 0.30 against 0.24 for
MySQL mode, and 0.21–0.22 against 0.18 for SQLite. The host ran at about 97%
busy at MySQL 1/s, where November 2's `vc2` ran at 78%. On the AMD plans the
same image costs exactly what rc.2 does, so the `vc2` result reflects a slower
Broadwell instance, not the release. `vc2` stays capacity-only evidence; the
AMD plans are the reference.

### PHP at the lowest rate

PHP 2.11.8 again fails 0.5/s on every 1-vCPU plan, as on November 2.

## Clock

`clock.cross_host_timing_reliable` is false for every run in this batch. The
**driver**'s NTP offset reached 11–36 ms against the 5 ms tolerance.

- The `vhp` targets stayed in tolerance, at ≤ 5 ms offset and ≤ 3 ms jitter.
- The `vc2` target reached up to 58 ms offset and 96 ms jitter.

Driver-relative latency figures are therefore excluded. Capacity verdicts and
resource use stand: the offsets are small against the 2 s bound.

## Four vCPU comparison (`matched-4c.json`)

These ran on the `vhp-4c-8gb-amd` driver host after the small-host batch, PHP
first and then the rc.3 image, with three repeats per rate. Every window passed
for both runtimes. Per-window tables:

- [summary-matched-4c-php-2026-11-02.md](summary-matched-4c-php-2026-11-02.md)
- [summary-matched-4c-native-image-2026-11-02.md](summary-matched-4c-native-image-2026-11-02.md)

At 1 workflow/s, across the three repeats:

| Measure | rc.3 | PHP 2.11.8 |
| --- | ---: | ---: |
| p50 | 0.103–0.104 s | 0.552–0.560 s |
| p95 | 0.115–0.120 s | 0.697–0.719 s |
| Runtime CPU per workflow | 0.077–0.082 core-s | 0.785–0.805 core-s |
| MySQL CPU per workflow | 0.161–0.168 core-s | 0.267–0.271 core-s |
| Runtime memory | about 7 MiB | 192–215 MiB |

**Idle:** rc.3 answered every idle poll empty at 0.4–0.9 core-s. PHP returned
backpressure on 4 of 12 and 16 of 24 idle polls, at 3–5 core-s.

**This does not reproduce November 2's PHP result.** In the
[rc.2 note](../small-host/observations-2026-11-02.md), PHP closed only 17–41
of 120 at 1/s, and native's p50 was 0.37 s. Both runtimes ran much faster
here. No other job shared the driver in either batch, and the run identity
does not record the driver's CPU or host. Treat the November 2 PHP collapse at
4 vCPUs as unconfirmed, and use this same-session comparison.

## Cleanup

Raw result directories stay private and are not committed. After the batch,
all four hosts, the private network, the firewall group and the SSH key were
deleted. Absence was verified by ID and by run tag (`tagged_instances_remaining`
0).
