# Multi-node kill and restart qualification

This qualifies native multi-node behavior: no lost durable work, no duplicate
committed outcome, and no stale worker attempt accepted. It runs on each
required database family while server nodes are killed and restarted, and
while a worker loses its lease. It is a correctness check, not a capacity
measurement.

## Topology

[`server-port-multinode.compose.yml`](../../../scripts/perf/server-port-multinode.compose.yml)
runs these services:

- **Database.** One of MySQL 8.4, PostgreSQL 17 or SQLite. With SQLite, the
  three nodes share one database file on one host.
- **Native nodes.** Three nodes with the same binary and configuration, one
  CPU and 512 MiB each. All three start together against the empty
  database, so bootstrap races too.
- **Balancer.** HAProxy round-robins over the nodes. Health checks every
  second take a dead node out after two failures. Like a production
  balancer, it retries a request on another node only when it could not
  connect, so the request never reached a server. A request a node accepted
  and then dropped fails back to its client.
- **Clients.** Two SDK workers and the offered workload, from the
  [capacity bindings](../../capacity/v1/bindings/php) with the published PHP
  SDK. They reach the nodes only through the balancer. A worker that exits is
  restarted, as a supervisor would; `container-states.txt` records each
  restart. A restart is an SDK finding, not a server failure, but it is
  reported with the run.

## Procedure

[`run-server-port-multinode.sh`](../../../scripts/perf/run-server-port-multinode.sh)
`OUTPUT_DIR mysql|postgres|sqlite` offers one-activity workflows at
0.5/s in three 180-second windows. Each window drains for up to 420 s, longer
than the five-minute activity lease: an activity whose completion a killed node
dropped is redelivered only when that lease expires. Two
kinds of chaos run during the windows:

- **Node kills.** Every 15 s, one node in turn is killed with SIGKILL and
  restarted 4 s later.
- **Worker pauses.** Every 90 s, one SDK worker is frozen with `docker pause`
  for the workflow task lease plus 10 s, then resumed. By then the server has
  given its workflow tasks to the other worker, so its completions are stale.

[`multinode-workload.php`](../../../scripts/perf/multinode-workload.php)
starts each workflow under its own ID. It retries a start, describe or history
read that a dying node never answered, and a retried start finds its own run.
Each window passes only if every planned workflow completed, with:

- its original output;
- contiguous history sequences;
- exactly one `WorkflowStarted`, `ActivityScheduled`, `ActivityCompleted` and
  `WorkflowCompleted`, in that order;
- an `ActivityCompleted` from the last attempt that started.

Afterwards the database audit must find:

- every run completed;
- no run with two `WorkflowCompleted` events;
- no activity with two completed attempts;
- no duplicate history sequence.

The balancer log counts the stale completions the servers refused (`409`)
and the requests a killed node never answered (`5xx`). Node logs are checked
for panics.

```sh
# The locked SDK and an optimized binary, as the v2 README describes.
export DW_PORT_SOURCE_ROOT=/abs/source DW_PORT_SDK_ROOT=/abs/sdk DW_PORT_NATIVE_BINARY=/abs/native/server
for db in mysql postgres sqlite; do
  bash scripts/perf/run-server-port-multinode.sh "results/$db" "$db"
done
```

`--smoke` runs one 20-second window to check the setup. It is never a
qualification.
