# Durable Workflow Capacity

Capacity results use public, versioned workloads so a number such as
"workflows per second" has a reproducible meaning. The headline comparison
unit is **DW Standard Workflow v1**.

## DW Standard Workflow v1

One DW Standard Workflow is one completed root workflow run that:

1. accepts a 1,024-byte Avro workflow input;
2. schedules one external `capacity.v1.echo` activity;
3. sends that activity a 1,024-byte Avro input;
4. performs no external I/O in the activity and returns its input;
5. receives a 1,024-byte activity result; and
6. completes with a 1,024-byte workflow result.

Its durable history contains exactly one each of `WorkflowStarted`,
`ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, and
`WorkflowCompleted`, in that order. Workflow-task rows are retained as timing
evidence but do not inflate the event count.

The versioned measurement contract uses 100 concurrent open workflows, client
concurrency 16, worker concurrency 32, a 60-second discarded warmup, a
300-second measurement window, and a bounded drain. PHP, Python, and Rust run
as separate result cells; their measurements are never pooled.

The machine-readable authority is the
[`capacity.v1.one_activity` cell](v1/suite.json). A result is publishable only
when the complete measurement window satisfies the suite's completion,
delivery, error, throttle, p99 latency, CPU, memory, queue-backlog, and drain
rules. Every published number must identify the suite version, exact Server
and SDK artifacts, SDK binding, infrastructure profile, architecture, source
revision, and run time.

## Other Workload Shapes

DW Standard Workflow v1 is a comparison baseline, not a conversion rate for
all durable work. Timer-heavy, signal-heavy, query, replay, child-fanout,
multi-activity, and mixed workloads have independent suite cells and results.
Durable Workflow does not turn those operations into an invented equivalent
number of standard workflows or billable semantic actions.

See the [capacity suite](v1/README.md) for the full workload matrix, operating
point rules, schemas, validation commands, and result contract.
