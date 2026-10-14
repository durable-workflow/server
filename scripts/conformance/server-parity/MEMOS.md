# Memo parity

The pending `memo-activity-replay` fixture exercises the existing
`x-durable-workflow-workflow-memo-update-contract` in the worker OpenAPI spec.
It has not passed the three-runtime acceptance gate and is excluded from the
default fixture set.

The first case starts with an initial memo, then executes two authored upserts
around one real activity. Separate normal installed SDK worker processes author
the pending checkpoint, execute the activity and replay the original workflow.
The first upsert replaces a value, adds a typed list and deletes a named key.
The second deletes that list and preserves untouched values. Big integers,
nested null, Unicode, false and an empty list retain their types. The same first
memo completion is submitted again while the parent is waiting. Its durable
history and merged memo must remain unchanged. Cold replay must preserve the
original prefix and produce two memo events, one activity and one completion.

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
Additional required memo gates include malformed input and structural limits,
protocol/capability enforcement and replay routing, unchanged state after
refusal, stale-task fencing, namespace/auth checks, continue-as-new inheritance
and external envelope resolution. Passing this first case alone does not
establish complete memo support or permit advertising that capability.
