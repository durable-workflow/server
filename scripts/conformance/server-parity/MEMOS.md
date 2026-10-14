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

`DW_PARITY_MEMO_PYTHON=1` adds a separate Python 2.5.13 author and cold
successor around the normal PHP activity worker. It uses the same committed
recipe, an independent workflow ID prefix and output directory, the existing
byte-preserving HTTP recorder, exact package source hashes and all completion
metadata. The embedded counterpart remains the published PHP recipe. Local
PHP and native SQLite originals pass all 23 corruption controls and have one
activity, two memo events, three first-attempt completed tasks and zero repairs.
Python omits sticky metadata in these actual completions; the omission control
removes a present captured field, preserving the strict complete-body equality
check and the existing PHP sticky-metadata control. Frozen tuples are unchanged.

`DW_PARITY_MEMO_RUST=1` adds the normal locked Rust 3.5.4 author and cold
reader around the same PHP activity. The observer verifies the compiled binary
against the normal build identity before each worker executes, then records
the exact registry archive/source identity and lock hash. Its public
`SDK_VERSION` constant is the exact string `durable-workflow-rust/3.5.4`.
Actual PHP and native SQLite originals pass the same eight-event recipe,
all 23 corruption controls and stopped SQL task/memo guards. This uses a new
test adapter module, with no SDK code/version or native-source change. The
shared candidate now runs PHP, Python and Rust author families separately and
compares each with the embedded PHP counterpart; full hosted acceptance remains
pending, along with external envelopes and continuation inheritance.

The authoring candidate integrates #446's continuation transition. A separate
`continue-upserted-memo-activity-replay` recipe upserts after one actual activity,
then continues in the same completion. It checks replacement with false,
deletion, an empty list and untouched int64/null/Unicode values before the cold
successor claims work. `DW_PARITY_CONTINUE_MEMO_UPSERTS=1` adds this case while
keeping the original initial-memo continuation case. The native batch accepts
validated memo commands before a terminal continuation; other transfer and
routing boundaries retain their explicit refusals. Actual three-runtime
acceptance of the new recipe is pending.

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
