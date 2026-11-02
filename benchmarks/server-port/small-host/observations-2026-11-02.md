# Small-host observations, November 1–2, 2026

One batch on disposable Vultr hosts in one region (EWR), run by the Cloud
owner with [profile.json](profile.json) and [matched-4c.json](matched-4c.json).
This is a development comparison, not a publishable capacity claim.

## Identity

- Profile: `profile.json` at Server `5a82626c` for the first pass, re-run on
  `vc2` at `147a71ea` (#933). Every summary records its `profile_sha256`.
  All runs in this note were re-summarized with the #933 summarizer, so they
  carry `clock.cross_host_timing_reliable`.
- Native: `ghcr.io/durable-workflow/server-native@sha256:f54e89d775dbf7c6074f37836c6bb0b25f94869d23711b0706e9547c3edecb29`
  (rc.2).
- PHP reference: published Server 2.11.8,
  `durableworkflow/server@sha256:3848d5d5cd1b23bd2881e3fdecea64cc44b72e6fb812e577c2db6d0872d065dd`.
- MySQL 8.4 and Redis by the profile's digests. SDK PHP 2.3.9, lock
  `42e814ee…`.
- Targets ran only the server and its database, with no container limits.
  The driver was a separate `vhp-4c-8gb-amd` in the same private network.
  Each target published 8080 only on its private address, and the firewall
  allowed 8080 only from the driver's private address.

## Results

Sustained one-activity capacity (workflows/s) is the highest rate that
passed twice: at least 90% closed in the window, p95 ≤ 2 s, and a clean probe.

| Plan | native-mysql | native-sqlite | php-mysql |
| --- | ---: | ---: | --- |
| `vhp-1c-1gb-amd` | 4 | 8 | none |
| `vhp-1c-2gb-amd` | 4 | 4 | none |
| `vc2-1c-1gb` | 2 | 4 | none |

No run had an OOM kill, container restart or non-zero teardown. Each
`teardown.txt` reads `containers=0 volumes=0`.

Resources at the native-mysql sustained rate: about 52% host CPU busy on both
`vhp` plans and 77% on `vc2`. Peak swap was about 392 MiB on the 1 GB `vhp`
plan, about 379 MiB on `vc2`, and about 1 MiB on the 2 GB plan. Two
gigabytes removes swapping but does not add throughput: one vCPU is the limit.

The `vhp-1c-1gb` native-sqlite result of 8 against 4 on `vhp-1c-2gb` is
within the ladder's 4→8 doubling. Repeat before treating the difference as
real.

### What failed for PHP at the lowest rate (0.5/s)

No probe errors and no poll errors on any plan. All 30 starts were accepted
in every window.

- `vhp-1c-2gb-amd`: all 30/30 closed in the window, but p95 was 3.32 s
  and 3.31 s against the 2 s bound. It fails on latency only.
- `vhp-1c-1gb-amd`: 20/30 and 16/30 closed in the window (all 30 by the
  drain deadline). p95 was 26.9 s and 37.4 s.
- `vc2-1c-1gb`: 6/30 and 0/30 closed in the window, with only 13 and 2 by
  the drain deadline (pending work). p95 was 86 s and 102 s.

## Clock

`clock.cross_host_timing_reliable` requires each host's NTP offset plus
jitter to stay within 5 ms in every start and end capture.

- `vc2-1c-1gb` (Intel Broadwell, kvm-clock): **unreliable**. Target offset
  reached about 10 ms and jitter about 30 ms (an earlier read during the first
  pass showed +39 ms / 62 ms). Its driver-relative latency is excluded; only
  its capacity and resource use count. The first pass on `vc2` at `5a82626c`
  failed verification at every rate for this reason and is not included.
- `vhp-1c-1gb-amd` native-mysql and native-sqlite: reported unreliable
  because one driver capture had no jitter value, so the check fails closed.
  Every recorded offset and jitter in those runs is at most 1.3 ms. Their
  capacity stands, and latency is excluded under the rule.
- All other `vhp` runs: reliable, with all offsets and jitter under about
  1.3 ms.

## Four vCPU comparison (`matched-4c.json`)

On the `vhp-4c-8gb-amd` driver host, one PHP run and then one native-image
run, three repeats per rate. At 1 workflow/s, native closed 120/120 in every
repeat with p50 0.37 s and p95 0.48 s. Runtime CPU was 0.26 core-s per
workflow, MySQL 0.52, and runtime memory about 8 MiB. PHP 2.11.8 closed 41,
40 and 17 of 120 in the window, with p50 89–111 s. Its runtime CPU was
2.2–6.9 core-s per workflow, MySQL 0.78–2.4, and memory about 210 MiB. This
reproduces the rc.1 result in [../rc1/](../rc1/) on the October 20 host
type, against the current final PHP release.

## Cleanup

All hosts, the private network, the firewall group and the SSH key were
deleted after the batch, with absence verified by ID and by run tag. Raw
result directories stay private and are not committed.
