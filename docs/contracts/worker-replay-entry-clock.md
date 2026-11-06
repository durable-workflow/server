# Candidate worker replay-entry clock

The selected additive worker-task contract carries the effective workflow entry
instant in an optional `replay_clock` object. This source change follows
[central #215](https://github.com/durable-workflow/.github/issues/215#issuecomment-6678927650).
Producer implementation, source qualification and SDK adoption are tracked
separately from published native-image acceptance.

```json
{
  "replay_clock": {
    "schema": "durable-workflow.worker-replay-clock/v1",
    "workflow_run_id": "01J00000000000000000000000",
    "started_at": "2026-11-06T01:02:03.123456Z",
    "source": "run_started_at"
  }
}
```

The object has exactly these four keys. `source` is either `run_started_at` or
`redrive_replayed_started_at`. Availability comes from the valid per-task object;
there is no discovery Boolean or new worker input. The worker protocol remains
1.20. The object binds the issued task's canonical run and namespace, including
every run-ID alias actually present in that producer's task envelope.

Ordinary, continued and child entries use the selected current run's canonical
`started_at`. A redrive uses the retained replay anchor only after establishing
a unique correlated successor start and its accepted redrive command, source
run, resume sequence and link. A missing or invalid explicit redrive anchor
leaves the object absent. The successor's fresh start cannot replace it.
Duplicate or ambiguous start authority, foreign identities and unsupported
source shapes also leave the object absent.

The wire instant uses UTC RFC3339 with six fractional digits and `Z`. Conversion
preserves the represented instant. Historical whole-second anchors are padded
with zeros; discarded historical precision is not reconstructed. A value that
cannot be represented exactly is unavailable. History insertion, dispatch,
lease time and fresh server time are separate values.

A cached poll answer retains its original object or absence. A fresh lease on
the same run uses the same canonical entry instant. A genuine new run binds its
own identity and entry clock. Full and paginated history delivery share the same
task-level carrier. Existing history events, legacy clocks, outcomes and
cancellation behavior retain their contracts.

The native implementation needs an indexed run/type/sequence lookup to establish
start uniqueness without scanning an unbounded history. Its candidate schema
change adds that index through a new migration, preserving existing migration
bytes and checksums. Qualification must cover the supported database families,
exact index/catalog validation, upgrade resumption, and frozen schema
compatibility.

Source controls also cover ordinary entry distinct from history insertion and
lease time, correlated redrive, represented precision, malformed and foreign
authority, duplicate starts, cached presence and absence, cold replay and new-run
reset. Published SDK poll-reader source is inspected independently; actual
consumer adoption requires the accepted producer shape and its own immutable
base behavioral controls.
