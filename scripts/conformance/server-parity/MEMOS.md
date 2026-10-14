# Memo parity

The pending `memo-activity-replay` fixture exercises the existing
`x-durable-workflow-workflow-memo-update-contract` in the worker OpenAPI spec.
It has not passed the three-runtime acceptance gate and is excluded from the
default fixture set.

The authored candidate explicitly selects it with
`DW_PARITY_MEMO_FIXTURE_ID=memo-activity-replay` in its shared matrix. The
runner defaults to the initial-only case and accepts only those two committed
fixture IDs. HTTP and embedded producers, comparison and corruption controls
use the same selection without changing any frozen package identity. Local
native SQLite author/activity/cold-successor acceptance passes; the new
all-database comparison remains pending.

The separate `memo-initial-activity-replay` case proves the initial storage/read
foundation without issuing an upsert. It runs the same original through one
actual activity and a cold workflow process, checking the authored start-history
memo separately from its visible run values. It is selected by the existing
corrected cohort's `memos` family on all six database cells. Passing it does not
qualify authored upserts or change the memo-update capability.

The first case starts with an initial memo, then executes two authored upserts
around one real activity. Separate normal installed SDK worker processes author
the pending checkpoint, execute the activity and replay the original workflow.
The first upsert replaces a value, adds a typed list and deletes a named key.
The second deletes that list and preserves untouched values. Big integers,
nested null, Unicode, false and an empty list retain their types. The same first
memo completion is submitted again while the parent is waiting. Its durable
history and merged memo must remain unchanged. Cold replay must preserve the
original prefix and produce two memo events, one activity and one completion.
The retry includes every captured completion field, including sticky-cache
and stream metadata. A separate changed completion must refuse without changing
memo or history. The earlier probe omitted sticky metadata and its native
`conflicting_completion` observation remains a failed duplicate comparison;
the correction changes the probe, not the runtime receipt boundary.

The actor records normal CLI exits. This case does not claim a killed-worker
takeover. The original 30-second phase limits and 90-second overall observation
budget are fixed before execution. Existing run and activity deadlines are
never rebased.

Use a copied frozen artifact profile and normally installed packages. Run the
HTTP probe inside the repository runtime with `DW_PARITY_SDK_AUTOLOAD` and
`DW_PARITY_TOKEN` set, passing URL, unique workflow ID, fixture path and output
path. Server bootstrap, database isolation and cleanup belong to the caller.
No database is shared concurrently between PHP, Rust and embedded mode.

The native draft foundation persists initial memos and reads the host's
sequence-bound portable values. It keeps the legacy JSON projection separately
and uses it when the portable value has no current authority. Official Avro
work runs outside executor threads with a held concurrency permit. Authored
memo updates remain unsupported and unadvertised. The native initial-memo
HTTP/storage tests are preparatory source controls, with three-runtime and
all-database acceptance still pending.

Further native implementation follows the actual published PHP and embedded baseline.
The separate initial JSON boundary is recorded in #444: empty objects and
contiguous numeric-key objects become lists, sparse or mixed canonical numeric
keys refuse, and leading-zero string keys remain maps. Seven raw published
HTTP controls supply the native regression vectors. This conversion applies
only to initial JSON and legacy projections. Current typed Avro memo maps retain
their original union branch and keys. It does not relax the typed memo reader.
Additional required memo gates include malformed input and structural limits,
protocol/capability enforcement and replay routing, unchanged state after
refusal, stale-task fencing, namespace/auth checks, continue-as-new inheritance
and external envelope resolution. Passing this first case alone does not
establish complete memo support or permit advertising that capability.
