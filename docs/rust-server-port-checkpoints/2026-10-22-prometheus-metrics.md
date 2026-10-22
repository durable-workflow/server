# October 22, Prometheus metrics summary

- **Source.** Server 2.11.1 `SystemController::prometheusMetrics` and
  `PrometheusMetricsSummary::snapshot`, with `BoundedMetricPolicy` and the
  declared dimensions in `config/dw-bounded-growth.php`.
- **Served.** Native answers admin-only `GET /api/system/prometheus-metrics`
  in the default or a named namespace:
  - workflow series by task queue and workflow type: started, completed,
    failed, cancelled and terminated totals, running runs and a latency
    histogram from run summaries;
  - activity series by task queue, workflow type and activity type, with the
    same counters and a latency histogram from activity executions;
  - task-queue runtime state: ready, due, delayed, leased and expired tasks,
    tasks added and dispatched in the last minute, and fresh worker pollers,
    slot capacity and available slots.

  Each family keeps the first 100 series in PHP's order. Native reads at
  most 101 candidate label sets, then aggregates only the series up to the
  last candidate. The disclosure reports observed, reported and suppressed
  series and the suppressed starts, with `lower_bound` precision once the
  limit is reached. Native refuses the series-limit settings, so the limit is
  always PHP's default of 100.
- **Times.** Task and worker comparisons bind whole seconds, as Laravel does
  and as native's operator metrics already do. A task made available within
  the current second still counts as delayed. Workers are fresh within
  `DW_WORKER_STALE_AFTER_SECONDS`, by default `max(3 × heartbeat, 30)`.
- **Decision: cumulative latency buckets.** Published PHP binds each bucket
  bound as text. SQLite orders every number before every text value, so PHP
  on SQLite counts every duration in every bucket
  ([server#683](https://github.com/durable-workflow/server/issues/683)).
  Native compares durations with numeric bounds and reports the cumulative
  histogram on every backend. The fixture accepts PHP's SQLite shape as a
  recorded reference defect; native's tests pin the cumulative buckets with
  fixed durations on SQLite, PostgreSQL and MySQL.
- **Correction: undispatched activity tasks.** PHP's worker completion
  publishes only the timer tasks it creates. An activity task scheduled by a
  workflow task therefore keeps null `last_dispatched_at` and
  `last_dispatch_attempt_at` until a poller claims it, and its queue's
  dispatched-in-the-last-minute counter stays 0. Native stamped it
  dispatched. It now leaves those columns null for that path, as PHP does.
  Other task paths keep their stamps.
- **Fixture.** `prometheus-metrics` reads a fresh namespace, then:
  - registers a worker with four workflow and three activity slots on the
    first queue;
  - completes a run there after one activity;
  - terminates a second run there;
  - starts a run on a second queue that has no worker.

  Counters, task-queue state, worker capacity and the disclosure are exact.
  Each counted run and activity lasts at least 1.1 seconds, so latency sums
  and the empty low buckets are checked against that bound.
- **Evidence.** Native tests pass on SQLite, PostgreSQL and MySQL, and the
  native suite with `--all-targets` passes on SQLite (649 tests).
  A local copy of the SQLite parity step passed all 53 fixtures on embedded
  PHP and native, including `prometheus-metrics`. Published PHP passed
  `prometheus-metrics` too; its only difference was `storage-test`, an
  intermittent 503 from PHP's external payload registration on that loaded
  host, which passed in the previous local run.
