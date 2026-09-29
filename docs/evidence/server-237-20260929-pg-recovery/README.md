# Published PostgreSQL mixed-history recovery and payload retention, 2026-09-29

This Server #237 slice uses the unchanged published Server 2.4.26 image and published Python SDK 2.3.7 against PostgreSQL 16.15. It checks two customer outcomes in an isolated local stack: an acknowledged long mixed workflow survives a PostgreSQL outage and worker replacement, and retention cleanup of a completed run preserves external payload bytes still referenced by an active run.

The exact image digests, synthetic [stack environment](../server-237-20260929-pg-threshold/stack.env.example), host, resource limits, database versions and probe build are in the [PostgreSQL threshold record](../server-237-20260929-pg-threshold/README.md). This slice reused that Compose project, `dw237pgthreshold0929`, on loopback port 18253 with the same Server image, Python probe image `sha256:b59f56c1da03f26643ff0b69be0c51b1dc9fd70d555cf73bbab3c17aa5d77c82`, PostgreSQL and Redis digests. The SDK worker containers retained one CPU and 1 GiB limits. No customer or paid infrastructure was used.

## PostgreSQL and worker interruption

The committed [signal-history probe](../server-237-20260927/signal_history_probe.py) first opened a durable wait, stopped its first worker, and acknowledged 4,000 numbered signals without processing them. Its `PROBE_OFFER_ONLY=1` mode recorded the run ID. A new worker began replaying those signals and recording 4,000 side effects, with an activity and timer every 500 side effects. At 4,508 first-run events, PostgreSQL was stopped. The worker received Server HTTP 503 for blocked database readiness and exited with code 1, without an OOM kill. PostgreSQL was restored and another fresh worker resumed the same run.

From the Server checkout, with `RUN_ENV` set to the task-local copy of the linked environment and the published stack already started, the commands were:

```bash
compose=(docker compose --env-file "$RUN_ENV" -p dw237pgthreshold0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml)

"${compose[@]}" run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=published237-pg-backend-interrupt-4000 \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_SIDE_EFFECTS=4000 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=3600 -e PROBE_OFFER_ONLY=1 \
  probe /probe/signal_history_probe.py 4000

"${compose[@]}" run -d --name dw237pg-recovery-first --no-deps \
  --entrypoint python -e PROBE_RUN_ID=published237-pg-backend-interrupt-4000 \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_SIDE_EFFECTS=4000 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=3600 \
  -e PROBE_RESUME_INITIAL_RUN_ID=01m3nxfv2rjrgqjpsrdbq1xt1j \
  probe /probe/signal_history_probe.py 4000

"${compose[@]}" stop pgsql
# The database remained stopped until the first SDK worker exited on HTTP 503.
"${compose[@]}" up -d --wait pgsql

"${compose[@]}" run -d --name dw237pg-recovery-fresh --no-deps \
  --entrypoint python -e PROBE_RUN_ID=published237-pg-backend-interrupt-4000 \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_SIDE_EFFECTS=4000 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=3600 \
  -e PROBE_RESUME_INITIAL_RUN_ID=01m3nxfv2rjrgqjpsrdbq1xt1j \
  probe /probe/signal_history_probe.py 4000

docker exec -i dw237pgthreshold0929-pgsql-1 \
  psql -U workflow -d durable_workflow \
  -v workflow_id=history-qualification-signals-4000-published237-pg-backend-interrupt-4000 \
  -At -f - < docs/evidence/server-237-20260929-pg-recovery/collect-pg-recovery.sql
```

The [offer log](recovery-offer4000.log) records 4,000 acknowledged signals in 339.211 seconds, with signal API p50/p95/p99 0.405/0.717/0.835 seconds. Before the new worker, [PostgreSQL](recovery-pre-worker.tsv) had 4,003 contiguous first-run events and all 4,000 signals, with one ready workflow task. Immediately before the outage, the [snapshot](recovery-before-outage.tsv) had 4,508 contiguous events and 500 side effects. The database was stopped at [06:33:48](outage-start-time.txt) and reported healthy again at [06:34:53](outage-restored-time.txt), a 65-second interval. The first worker exited at 06:33:58 on [HTTP 503](recovery-first-worker.log.gz). Its state reports exit 1 and no OOM kill. The Server access log has one HTTP 500 and five HTTP 503 responses during the outage, with no later 5xx in this recorded interval. Inside the Server container, `/api/ready` returned [HTTP 200](readiness-after-restore.txt) after PostgreSQL restored.

The [post-restore state](recovery-after-restore.tsv) retained 4,510 contiguous events, including all 4,000 signals, and one ready workflow task. The replacement worker started at 06:36:00 and exited cleanly at 06:46:10. Its [result](recovery-fresh-worker.log) was exactly `{count: 4000, total: 7998000}` with zero activity retry starts. The [final durable state](recovery-final.tsv) has 8,046 contiguous first-run events and two completed successor events, 4,000 each of `SignalReceived` and `SideEffectRecorded`, eight completed activities and timers, one continuation and one completion. All recorded task attempts were one; no task remained ready or leased. The [initial](recovery-initial-snapshot.json) and [successor](recovery-successor-snapshot.json) snapshots agree. The [final backlog](recovery-final-backlog.tsv) has zero failed jobs, open tasks or open runs.

The first SDK worker's sampled container-memory peak was 72.88 MiB and the replacement worker's was 88.64 MiB in the [first](recovery-first-stats.psv) and [replacement](recovery-fresh-stats.psv) sample files. The replacement process reported peak RSS of 112,308 KiB and 607.49 seconds from resume to terminal completion. The internal Server queue worker restarted five times during database loss and was [running and healthy](recovery-container-outcome.txt) afterward. Server, scheduler and PostgreSQL also reported healthy with no OOM kill. These are one controlled recovery observation, not an availability or throughput estimate.

## Retention preserves a shared external payload

The same published SDK then created an active workflow with two signals carrying 2,048 payload bytes each and no running SDK worker. A separate one-signal workflow with the same payload bytes completed. The [pre-prune query](payload-before-prune.tsv) decoded their external references: the completed run and one signal in the active run shared the exact content-addressed SHA-256 and URI `7b88dee8776e3365f13047fa38c9e1efe43db0fd9d73612e68f54e39d5acf7c2`. The active run referenced one other object. Both objects were recorded as ready with 2,756 encoded bytes each. The [active offer](payload-active-offer.log) and [completed run](payload-completed.log) are retained.

```bash
"${compose[@]}" run --rm --no-deps --entrypoint python \
  -e DRILL_ADMIN_TOKEN=pgthreshold-probe-admin \
  -e PROBE_RUN_ID=payload-retained-active -e PROBE_SIGNAL_CONCURRENCY=2 \
  -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 -e PROBE_OFFER_ONLY=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=300 \
  probe /probe/signal_history_probe.py 2

"${compose[@]}" run --rm --no-deps --entrypoint python \
  -e DRILL_ADMIN_TOKEN=pgthreshold-probe-admin \
  -e PROBE_RUN_ID=payload-prune-completed -e PROBE_SIGNAL_CONCURRENCY=1 \
  -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 -e PROBE_WORKER_WINDOW_SECONDS=300 \
  probe /probe/signal_history_probe.py 1

docker exec dw237pgthreshold0929-server-1 curl --silent --show-error \
  --fail-with-body --header 'Authorization: Bearer pgthreshold-probe-admin' \
  --header 'X-Namespace: default' \
  --header 'X-Durable-Workflow-Control-Plane-Version: 2' \
  --header 'Content-Type: application/json' \
  --data '{"run_ids":["01m3nyqbvcr15g2tqnnyrpc67a"]}' \
  http://127.0.0.1:8080/api/system/retention/pass

"${compose[@]}" run --rm --no-deps --entrypoint python \
  -e DRILL_ADMIN_TOKEN=pgthreshold-probe-admin \
  -e PROBE_RUN_ID=payload-retained-active -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 \
  -e PROBE_WORKER_WINDOW_SECONDS=300 \
  -e PROBE_RESUME_INITIAL_RUN_ID=01m3nypyk37hn42dxy53p6hke6 \
  probe /probe/signal_history_probe.py 2
```

The [retention API response](payload-prune-response.txt) was HTTP 200: one completed run pruned, seven history events and two tasks deleted, zero external payloads deleted, zero failures. The [post-prune query](payload-after-prune.tsv) shows the completed run's history at zero rows while the active run retained five events and both object references. A fresh worker then fetched and verified both original payloads and returned [exactly](payload-active-resume.log) `{count: 2, total: 1}` with eight ordered events. The [final query](payload-final.tsv) shows the active run completed with its history and both external objects still ready.

This was an explicit, targeted admin retention pass before the 30-day age cutoff. It verifies the supported pass and local external storage driver, not automatic time-based expiry or S3 cleanup. Further repetitions and a final safe operating recommendation remain in Server #237. This PR remains draft until those results are complete.
