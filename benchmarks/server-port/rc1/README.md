# Release candidate 1 against the published native image

[matched.json](matched.json) is the [v3 profile](../v3/README.md) with
`artifacts.native_image` set to the published release candidate,
`ghcr.io/durable-workflow/server-native@sha256:43718810bb9d1657aefe5fa157dfdd894c8f2a6d3e7edd6449a2ae3d0add6c86`
(tag `3.0.0-rc.1`, source `03a3e7b7c02281ee8ff58fb53180e428e866ad39`). The
workload, limits, probe, SDK lock and the PHP reference (published Server
2.6.2, the recorded baseline) are unchanged. Its SHA-256 is the
`profile_sha256` in every summary.

This profile is frozen with those summaries. It still pins the probe as it was
on October 30, before #933 moved history verification to the server clock, so
re-run it from commit `a0f7af002a6af96b219125c54d1ceb1a2be165e5`. On later
commits the runner refuses it because the probe's hash differs.

The [October 30 observations](observations-2026-10-30.md) are three
alternating PHP/native-image pairs on one host, run with
`scripts/perf/run-server-port-matched.sh` and summarized with
`scripts/perf/summarize-server-port-matched.py`. The summaries are
`summary-{php,native}-{1,2,3}-2026-10-30.json` and
`summary-idle-native-2026-10-30.json`.

## Host

This is not the October 20 host type. The Cloud agent was paused, so no
disposable dedicated VM was available, and the runs used the Rust
development VPS itself: 6 vCPU Intel Broadwell (no TSX, IBRS) under a
Microsoft hypervisor, 15.6 GiB, kernel 6.8.0-146, Docker 27.5.1 with its own
isolated daemon (cgroupfs, overlay2), Compose 2.40.3. No other container ran
during any run, and each run's `host-state.tsv` records load before and after
every window. The comparison is matched (both implementations, same host,
alternating), but absolute numbers are not comparable with October 20's.

## Sampler fallback

This host's Docker daemon runs in another PID and cgroup namespace, so the
driver's one-second sampler could not read container cgroup files, and pairs 1
and 2 recorded no CPU or memory samples. Their offered work, latency,
verification, idle-poll answers and health are complete. The driver now falls
back to the daemon's stats endpoint (`one-shot=true`) for the same cumulative
CPU (`cpu_usage.total_usage`) and current cgroup memory
(`memory_stats.usage`) when the cgroup files are not readable. Pair 3 ran with
that fallback and has complete CPU and memory. Its identity records the tag's
source revision; the only difference from the tag's driver is this fallback.
