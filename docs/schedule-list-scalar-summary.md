# Schedule list scalar summary draft

This is the smaller source slice selected in Server #1014 comment 6644878850.
It is not the full last-dispatch outcome contract or published capability. Waterline
#222 keeps the existing describe fallback. No faster polling is justified by this
partial projection. Describe's selected last-skip timestamp preserves microseconds
as specified below; its other fields keep their existing behavior.

Each list item adds:

| Field | Complete value | Unavailable or malformed storage |
| --- | --- | --- |
| `failures_count` | Nonnegative stored signed-64-bit-decodable integer | Decode/negative value fails the request |
| `skipped_trigger_count` | Same counter contract | Same failure |
| `latest_workflow_instance_id` | Stored identity or null, at most 191 Unicode scalars / 764 UTF-8 bytes | Empty/oversized/invalid UTF-8 fails; a pruned instance preserves this identity |
| `current_run` | Nullable `{run_id,status,status_bucket,is_terminal}` from that instance's current run | Missing/pruned/wrong-namespace/wrong-instance target is null, meaning unknown |
| `last_skip_reason` | Nullable complete stored text, at most 64 Unicode scalars / 256 UTF-8 bytes | Invalid UTF-8 or an oversized value fails the request |
| `last_skipped_at` | Nullable stored UTC instant, formatted as `YYYY-MM-DDTHH:MM:SS.ffffffZ` | Invalid, non-UTC, out-of-range or submicrosecond values fail the request |

The instance and run joins require exact identities and exact schedule namespace
on both sides. MySQL/MariaDB retain a candidate equality usable by each existing index, then
apply an exact binary residual to avoid case/accent/trailing-space collation
matches. The page namespace predicate uses the same candidate/residual pattern. A current run ID and the instance's complete current-run
pointer have at most 26 Unicode scalars / 104 UTF-8 bytes. Status is a complete
value at most 32 UTF-8 bytes and must be one of the existing statuses:
`pending`, `running`, `waiting` map to running/nonterminal; `completed` maps to
completed/terminal; `failed`, `cancelled`, `terminated` map to failed/terminal.
An unknown or empty status is invalid storage, never a default healthy state.
The dispatch count is not a run status and the current run is not a last-dispatch
outcome. Missing current-run information does not imply completion or no action.

For newly projected scalars, SQL transfers the **whole** value only within its
byte bound and returns an independent invalid flag otherwise. Rust checks the
complete decoded scalar count and enum before returning a response. No prefix
can become a plausible identity or status. NUL and astral scalars remain exact in SQLite/MySQL. PostgreSQL text cannot
store NUL, which is an unsupported storage-domain cell, not a decoder pass.
Invalid UTF-8 setup limitations remain distinct from SQLite decoder refusal.
Malformed/oversized scalar metadata refuses the page with the existing typed
HTTP 503 `storage_unavailable` envelope; values are not included in diagnostics.
Backend decode failures retain the existing storage-unavailable response.

| SQL boundary | SQLite | PostgreSQL | MySQL / MariaDB |
| --- | --- | --- | --- |
| Byte size including NUL | `length(CAST(column AS BLOB))` | `OCTET_LENGTH(column)` | `OCTET_LENGTH(column)` |
| Scalar projection | `CASE WHEN bytes<=bound THEN column ELSE NULL END` | Same | Same |
| Invalid flag | Non-null and empty or over-bound, separately from SQL null | Same | Same |
| Namespace / join identity | Exact text comparison | Exact text comparison | Candidate `left=right` plus `BINARY left=BINARY right` |

The page selects explicit existing list columns and bounded scalar expressions.
It excludes `recent_actions` and `buffered_actions` entirely. Recent/last-action
and buffer fields remain **deferred**, including their malformed-member and
timestamp semantics.
No SQL date conversion, substring preview, JSON prefix or invented event ordering
is introduced. The existing legacy action/input/spec/note/search-attribute fields
keep their previous size surface; this does not claim a bounded whole response
or constant database bytes/CPU.

The last-skip pair describes the most recent stored skip, which can precede a
later successful fire. It does not describe the latest overall dispatch. Both
keys are always present in each new list item. Null, empty text, literal `NULL`,
whitespace and letter case remain distinct; no reason is trimmed, normalized or
shortened. A null value does not prove that a legacy schedule has never skipped.
The same reason and timestamp appear in describe's `info` object. Only this
timestamp changes from the previous whole-second rendering to six fractional
digits and UTC `Z`; no clock sample replaces its stored value. Timestamp text is
bounded to 64 bytes in the list SQL before decoding, while its decoded value
must have a year from 1 through 9999 and exact microsecond precision. The
existing typed SQL timestamp columns and SQLite UTC reader retain their stored
domain restrictions.

Consumers can recognize only this pair by checking that every returned item has
both keys with the complete nullable types and timestamp format. Both absent
means the legacy shape; one absent or either malformed means a partial or
invalid projection. Mixed legacy and new item shapes are also partial. An empty
page supplies no evidence of support. This is not
detection of the full outcome contract and does not remove describe fallback or
justify faster polling.

The one-to-one joins add no row-level calls. Existing request normalization,
closed grammar, bound values, filter fingerprint, signed cursor, stale-anchor
check, order, page size and plus-one lookahead stay intact. Cursor probes select
only schedule ID/created-at and retain the same filters. A plain page uses one
query; either cursor presence or registered-definition lookup adds one; both use
three. Registered attributes still have the existing native unsupported-value
behavior, outside this slice.

Source vectors cover a 50-row page and its continuation with/without a system
query (1/2/2/3 calls), malformed excluded arrays, independent counters, missing
and wrong-namespace links, all seven statuses, pruned targets, exact NUL/astral
identities and empty/scalar-count/byte-count failures. The existing MySQL/MariaDB and PostgreSQL disposable source suites invoke the
same complete decoder/join controls, including maximum legal unsigned counters
and exact HTTP 503/no-value responses on MySQL/MariaDB. The shared unsigned
decoder remains unchanged with its original 409 behavior. MySQL/MariaDB EXPLAIN
controls retain actual primary-key/access-type output for each referenced join
after seeding 256 unrelated instances/runs. Those controls require successful
normal backend qualification; source authorship alone is not plan evidence.
The existing HTTP schedule-catalog corpus retains its frozen published-PHP
fixture and expected responses. Native recordings require all six added fields
on every list row. Before comparing the older shared response, the comparator
checks counters and the latest identity against preceding backfill responses,
the unclaimed current-run state, and the retained trigger-audit identity pair.
The last-skip cause and precision are checked before comparison. The retained
skip audit checks its cause and whole-second instant, while the backend suites
compare exact stored microseconds between list and describe. Later successful
fires must preserve that skip. Only those checked additions are removed from that comparison; raw observations
and every existing field remain intact. Older published PHP recordings can
retain their original list shape.
Normal source checks and Server review must qualify the exact SQL/decoder on each backend before this
can be called contract-compliant. No new runtime/image/qualification dispatch,
schema/UDF, persisted summary, release or frozen-artifact admission follows.
