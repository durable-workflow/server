# Selected-run signal acceptance

[Server #325](https://github.com/durable-workflow/server/issues/325) owns this
separate PHP/Rust/embedded slice after the delivered #429 namespace profile.
The [reviewed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6136891726)
and [published PHP/PHP reference](https://github.com/durable-workflow/server/issues/325#issuecomment-6137058011)
define the observable behavior. This document records acceptance before
implementation; it does not qualify all directions or databases.

## Producers and original budgets

Use the frozen published Server 2.5.20 / Workflow 2.5.10 reference and existing
PHP 2.2.7, Python 2.5.4 and Rust 3.4.4 author identities. The selected-run clients
are PHP's normal `signalWorkflow(..., runId)` and Rust's `signal_workflow_run`.
Python 2.5.4 has no selected-run client API and participates only as an author.
Cover all six supported client/author directions, plus canonical embedded PHP.
Keep existing actor files unchanged and add separate selected-run adapters.

Each observation retains two independently admitted original runs in distinct
namespaces with the same workflow type and queue. Preserve their instance/run
identities, prefixes, cold author replacements and exact Avro int64 values.
Use the existing 35-second per-run, 70-second pair and 75-second outer ceilings,
one CPU / 512 MiB runtime caps and 35-minute expanded job ceiling. Qualification
must not rebase a deadline or replace a failed original with a new run.

## HTTP and persisted state

| Operation | Reference result | Required persisted behavior |
| --- | --- | --- |
| Select the neighboring run from the wrong namespace | `404/instance_not_found` | Both complete persisted snapshots remain unchanged. |
| Select a different run for the original instance | `409/historical_run_command_rejected`, `target_scope=run` | Both complete persisted snapshots remain unchanged. |
| Unknown signal on the selected current run | Named `404` refusal | Persist a rejected command and signal; advance command/message sequence without workflow history. |
| Omit the required signal argument on the selected current run | Named `422` refusal | Persist a rejected command and signal; preserve validation diagnostics and original workflow history. |
| Two valid selected-current-run signals | Accepted | Preserve instance-scoped commands with null requested run and the exact resolved original run; finish once through cold authors. |
| Signal the selected run after completion | Named `409` refusal | Persist a rejected command and signal without new workflow history or task repair. |

The PHP/Python-author invalid-input case omits the required argument. Frozen Python 2.5.4
declares its signal parameter without a type and legitimately admits integer
`17`; the initial HTTP recording preserves that admission and stops. The earlier
PHP/PHP typed mismatch remains a distinct reference case. Do not infer a typed
Python declaration or alter its frozen author to obtain a refusal.
Frozen Rust 3.4.4 declares an optional, variadic, untyped argument vector. Its two
directions explicitly lack an invalid-argument refusal gate; their embedded
counterparts use the same selected recipe without that extra command. All six
still check unknown/inactive durable rejections and zero-mutation scope guards.
Typed Rust signal admission needs a separately qualified author contract; it is
not inferred from these fixtures.

The successful HTTP selected-current-run guard does not convert an accepted
command to run scope. Embedded uses the public selected `WorkflowStub` directly;
its canonical run-scoped command metadata must be retained and qualified
separately. HTTP refusals alone are insufficient: the reader must check durable
commands, signals, sequence counters, events, tasks and unchanged neighboring
state. Rejected operations may advance their documented counters; they must not
be normalized into no-op snapshots or contaminate unrelated runs.

For each original, command sequences are start `1`, unknown `2`, invalid `3`,
accepted `4` and `5`, inactive `6`. Signal message sequences are `1` through `5`;
start has none. The published PHP/PHP reference pair has 12 commands, 10 signals
(four applied / six rejected), 26 events, six completed tasks at attempt one and
zero repairs. These are proving expectations for that recipe, not fabricated
results for other directions.
The Rust-author recipe omits invalid `3`: accepted commands use `3` and `4`,
inactive uses `5`, and message positions are `1` through `4`. Its pair must have
10 commands, eight signals (four applied / four rejected) and six completed
attempt-one tasks, with the same complete history inventory for its mode.

## Qualification gates

1. Record all six actual published SDK reference directions and canonical
   embedded counterparts with original sidecars and persisted relationships.
2. Add a strict reader and independent corruption guards for selected paths,
   target scope, run identity, typed values, durable rejections, sequence counters,
   unchanged history/neighbor state and final completion/task counts.
3. Implement native behavior only after the reference fixtures establish the
   exact fields. Preserve existing ordinary namespace fixtures and refused
   historical records.
4. Qualify the exact candidate and fresh main on SQLite, PostgreSQL 16/17,
   MySQL 8.0/8.4 and MariaDB 10.11, auditing original artifacts and source identities.

Older-run continuation races, broader authentication, external payloads and
current-release consumers remain separate gates. Passing this slice does not
establish migration, matched performance, full parity or release readiness.

The six frozen PHP HTTP directions and six canonical embedded counterparts
passed on isolated SQLite at `e077ee4e`. Their original records pass 300
corruption guards and an independent byte audit of 116 full sidecars and 588
wire exchanges, covering 24 original runs. The
[reference receipt](https://github.com/durable-workflow/server/pull/434#issuecomment-6138791924)
preserves earlier failed records and their narrower conclusions.

The next native candidate enables named-namespace selected-run signals and
commits unknown, invalid and inactive rejections atomically with their original
Avro arguments and diagnostics. Refusals advance only command/message counters;
they add no history, task, wait reservation or wake notification. Encoding uses
the existing bounded official-codec worker. Wrong namespaces and mismatched
selected runs remain zero-mutation guards. A real HTTP/physical-storage test
fails on the previous native runtime, then passes after the correction. Local
locked tests pass 33 unit cases and 79 execution cases; the 18 existing
backend-dependent unit cases remain explicitly reserved for the hosted database
jobs. Shared native recordings, all six exact candidate jobs and fresh main
qualification are pending.
