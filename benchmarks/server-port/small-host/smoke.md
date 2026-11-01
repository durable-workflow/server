# Stand-in check, November 1

A setup check of the profile and scripts, not a measurement. It ran on the
Rust development host's isolated Docker daemon, shared with other work, so
its numbers say nothing about any plan.

- **Target stand-in:** native rc.2 (`f54e89d7…`), capped at 1 CPU / 1 GiB
  through `DW_SMALL_TARGET_COMPOSE_OVERRIDE`, in `native-sqlite` and then
  `native-mysql` mode (MySQL also capped at 1 CPU / 1 GiB).
- **Driver:** SDK 2.3.9 installed from the committed lock, client containers
  capped at 0.5 CPU / 512 MiB, reaching the target over the daemon's bridge
  gateway as a stand-in for a private address.
- **`--smoke` in both modes:** setup, warmup, 0.5/s and 1/s windows and the
  drift check passed; both idle windows passed; the cluster info identified
  `3.0.0-rc.2`. In `native-mysql` the audit after teardown read 26 completed
  runs, 52 workflow and 26 activity tasks completed, no registrations left,
  and teardown left no containers or volumes.
- **Multi-probe ladder** (a copy of the profile with rates 5 and 8 on the
  SQLite stand-in): 5/s ran 2 probes and 4 SDK workers and passed. 8/s failed
  and then failed its confirmation (in-window completion 63/80 and 54/80, p95
  2.6 s and 4.1 s), so the ladder stopped and reported 5/s as sustained. The
  low-rate drift check passed after it. With client containers capped at
  0.5 CPU each on a shared host, the failure says nothing about native's
  capacity; it shows the stop rule and the scaling work.
- **Not covered here:** per-service target samples. This daemon's cgroup
  files are not visible from the shell that ran the target script, so
  `container-samples.tsv` stayed empty; on a plan host they are readable.
  `php-mysql` mode was checked only as Compose configuration, plus the PHP
  2.11.8 image listing every scheduler command the target runs.
