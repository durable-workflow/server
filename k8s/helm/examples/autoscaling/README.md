# Scale workers from zero on the Server's backlog

Workers poll for tasks, so a worker Deployment at zero replicas emits no
metrics. The native Server's `GET /metrics` (Server #534) exposes each
namespace's task queue backlog in Prometheus text format, read from the
Server's own task rows, so the first started workflow is visible while no
worker runs. The full series list, labels and semantics are in the
[Server reference](../../../../docs/server-reference.md#prometheus-scrape-and-autoscaling).

Requires a native Server release that serves `GET /metrics`: its
`/api/cluster/info` reports `capabilities.prometheus_text_metrics: true`.

## 1. A scrape-only credential

[`values-metrics.yaml`](values-metrics.yaml) sets `DW_METRICS_TOKEN` through
the chart's `auth.extra`. That token reads `GET /metrics` for any namespace
and reaches no other route, read or write. It must differ from every other
token. For a credential bound to one namespace, create a runtime credential
with `"roles":["metrics"]` and that namespace as its `tenant`
(`DW_RUNTIME_CREDENTIALS_ENABLED=true`).

```bash
curl -H "Authorization: Bearer $DW_METRICS_TOKEN" \
  "http://durable-workflow-server:8080/metrics?namespace=default"
```

## 2. Scrape it

- Prometheus Operator: [`servicemonitor.yaml`](servicemonitor.yaml), one
  endpoint per Durable Workflow namespace (`params.namespace`).
- Plain Prometheus: [`prometheus-scrape-config.yaml`](prometheus-scrape-config.yaml).

A failed Server read answers 503 with no samples: the target is down
(`up == 0`) rather than reporting an empty queue.

## 3. Scale on it

[`keda-scaledobject.yaml`](keda-scaledobject.yaml) scales a worker
Deployment between 0 and 20 replicas on

```promql
sum(dw_task_queue_dispatchable_tasks{namespace="default",task_queue="orders"})
  + sum(dw_task_queue_leased_tasks{namespace="default",task_queue="orders"})
```

- **0 -> 1.** Starting a workflow creates a due workflow task. With no worker,
  `dw_task_queue_due_tasks` and `dw_task_queue_dispatchable_tasks` are `1` and
  `dw_task_queue_active_pollers` is `0`; `activationThreshold: "0"` wakes one
  replica.
- **Holding.** Leased tasks keep the worker while it runs them.
- **1 -> 0.** When every task is completed the queue has no due or leased
  tasks; the query reads `0` and KEDA scales to zero after `cooldownPeriod`.
- **Delayed work.** Tasks waiting for a retry backoff are
  `dw_task_queue_delayed_tasks`, not due, and add no replicas.
- **Throttled queues.** While a queue's admission is throttled (native: an
  [activity dispatch pause](../../../../docs/contracts/task-queue-activity-dispatch-pause.md)),
  `dw_task_queue_admission_state{state="throttled"}` is `1`, remaining
  capacity is `0` and its dispatchable tasks stay `0` however large the due
  backlog grows, so KEDA adds no worker that cannot lease work. When the
  queue resumes, its due tasks become dispatchable again.
- **Unavailable data.** `ignoreNullValues: "false"` and `fallback` make a
  missing scrape an error that holds `fallback.replicas`, not a zero.

HPA with a Prometheus adapter can use the same query as an External metric
with `type: AverageValue`; HPA alone cannot scale from zero.

### Build-id cohorts

Add `build_id="<build>"` to scale one cohort's Deployment. Workflow tasks
carry their effective build id; activity tasks carry `build_id=""` because
they are not routed by build. Do not scale the last compatible cohort to zero
because the queue total looks idle: pending work pinned to an older build
needs a compatible worker. See the
[build-id rollout guidance](https://durable-workflow.github.io/docs/2.0/polyglot/worker-build-id-rollout#pending-workflow-tasks-by-cohort).
