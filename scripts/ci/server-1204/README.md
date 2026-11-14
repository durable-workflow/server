# Server #1204 hosted context capture

This is a temporary diagnostic branch, not a merge proposal. Its branch-only
replacement of the existing `server-parity.yml` manual workflow selects one
MariaDB `native-tests-b` context. Do not merge that workflow replacement to main.
No dispatch is selected by preparing this source. Independent source/profile
review and a later explicit owner selection must precede an invocation.

The capture profile and implementation are `profile.json` and
`../server_1204_capture.py`. The observer checkout is fixed at
`688c1cddb1783529f8fc097ece08bebaeded3706`. The separate profile checkout must
equal both the reviewed `expected_profile_commit` input and `GITHUB_SHA`.
Only this branch's manual workflow and run attempt 1 are admitted. The existing
workflow ID is 379303598. There is no push, PR, schedule, retry or publication
trigger on the diagnostic workflow.

## Sequence and limits

1. Verify the source, exact selection, invocation identity and native amd64
   hosted runner. Capture the Docker/runner facts. The hosted label remains
   `ubuntu-latest`; the historical VM image is not reconstructed. Refuse a host
   with fewer than two CPUs or 9 GiB Docker memory.
2. Pull the three digest-pinned images once, at most 180 seconds per pull.
   Fetch locked Cargo inputs in a separate 2-CPU/2-GiB/512-PID container with
   public registry access and a 300-second command bound. No shared caches
   are restored or written. All subsequent containers have no public network.
3. Generate disposable TLS files and start the pinned MariaDB 10.11.19 fixture
   at 1 CPU/1 GiB/256 PIDs. Preserve the 128-MiB buffer pool, disabled binary
   log/performance schema and original TLS/SQL settings. Readiness is 60 seconds.
   Create the original empty `native_ref` database and bootstrap `php_ref`
   with the frozen PHP 2.5.14 layout image. That migration-only process runs as
   UID 1000 on the internal network, with 1 CPU/1 GiB/256 PIDs and a maximum
   195-second wait. No PHP web service or SDK worker starts. This historical
   comparator use does not patch or republish the retired PHP artifacts.
4. With the DB already running, compile the fixed observer source offline at
   2 CPUs/8 GiB/512 PIDs, two Cargo jobs and a 600-second command bound. This
   retains the original DB-before-compilation ordering. A cold dependency
   build differs from the original trusted-main cache and is reported as such.
5. List the newly built executable on network none. Require exactly 810 unique
   names and require the C-sorted second round-robin half to equal all 405
   checked-in original identities. The exact LF selection SHA-256 is
   `5e33a068f02bdef527417b5a604ea1eb459b5ba253dd853db73b2f03125d8c9a`.
6. Run that complete selection once at 2 CPUs/8 GiB/512 PIDs, two test threads
   and the existing 900-second process bound. Source and executable target
   mounts are read-only. Require the executable hash to match before/after.
   The original four-connection pool, acquisition/reset/lock deadlines,
   assertions, sleeps and worker fence are unchanged by the profile.
   Selected tests invoke the compiled native `backup-hold` executable as cold
   subprocesses. Both that ELF and the execution-test ELF are bound by hash
   and inode before/after; all children inherit the same container limits.
   These are the original native test paths, with synthetic fixture databases.
7. Retain merged Docker stdout/stderr with daemon timestamps, test/context and
   acquisition-phase observations, before/after DB counters, image/config IDs,
   observed container profiles, source/run identities and exact inventory.
   Cleanup reconciles full IDs using the run/attempt/job scope and nonce,
   including a create whose returned ID was lost. It removes only matching
   containers, their anonymous volumes and the owned empty internal network.
   Remove the private DB, TLS, Cargo, target and temporary directories.

The workload has a 2,700-second overall budget and cleanup a separate 120 seconds.
The test is refused before launch unless its unchanged 900 seconds plus 30
seconds of observation margin remain. The workflow retains the original
60-minute job ceiling, a 50-minute capture step and an unconditional bounded
cleanup step. Artifacts retain seven days; private fixture state is not uploaded.
The Python entry has a 2,880-second alarm backstop; fallback cleanup has a
140-second alarm. No shell wrapper hides the entry from the existing isolation
policy checker, whose source is unchanged.
Only public fixture authentication is passed to containers. Checkout credentials,
the Actions token, Docker configuration and host sockets are not mounted into them.

## Review and result

Source-only checks, without Docker, registry or application execution:

```bash
python3 -B -m unittest discover -s scripts/ci -p test_server_1204_capture.py
python3 -I -B scripts/ci/server_1204_capture.py check --source <observer-checkout> --evidence <unused-output>
python3 scripts/ci/check-docker-workflow-isolation.py
git diff --check
```

After review, the intended manual workflow input is the full accepted profile
commit. A later owner-selected dispatch must bind that exact commit in the
existing #1204 issue before execution. No invocation is made by this document.

`result.json` distinguishes a complete non-reproducing baseline, a test failure
and a blocked/incomplete runner. A successful 405-test baseline does not fix the
historical failure. `terminal.json`, successful primary/fallback cleanup and
independent retained-evidence review are also required. Missing cleanup, a
   timeout, missing test identities, absent target registration markers or a failed prelude cannot
be promoted to acceptance. This diagnostic has no #980, release or alias role.
