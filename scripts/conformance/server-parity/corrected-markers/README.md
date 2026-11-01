# Corrected marker actors

The optional `deadlines` family uses the existing pending
`activity-deadline-authority.json` fixture with its unchanged 35-second observer
and original one-second authored boundaries. It records all four independent
originals through HTTP or the embedded queue/public worker bridges. The shared
profile compares their timeout histories, full policies and denied late
authority, then runs actual-record mutation checks. Adapter receipt formats
remain explicit. These calls retain the parent timeout delivery rather than
authoring a workflow result; retry, committed-duplicate and live-renewal
follow-through remain separate gates. Completed local bulk is removed after
inspection rather than archived.

This is a separate installed cohort. Existing frozen actors and manifests stay
unchanged. The machine-owned manifest is
`tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts.json`.

`corrected-marker-adapters.sh` installs the locked PHP reader, the hashed genuine
historical Python producer and the locked Rust public worker. Rust builds use
one CPU, 1536 MiB RAM, no swap, debug=0 and no incremental artifacts. The build
cache and target are retired after copying the runnable adapter and lock.
Hosted jobs can reuse their existing Rust 1.86 cache by setting the relative
`DW_PARITY_CORRECTED_CARGO` and `DW_PARITY_CORRECTED_TARGET` paths. These external
job caches remain owned by the caller and are excluded from the adapter bundle.
Registry source identity is still checked before the artifact is produced.

After installing the adapters, `corrected-marker-http-profile.sh TARGET URL`
runs the six marker/policy cases, both original-range repairs and both Python
clock cases against one already bootstrapped isolated HTTP server. Set
`RESOURCE_SCOPE` to its Docker network and `DW_PARITY_TOKEN` to the fixture
credential. Each family retains its record and exit status independently; any
failure makes the command fail. The caller owns bootstrap, readiness and
cleanup. This command does not change the frozen default corpus or qualify
the embedded profile or database matrix by itself.
An optional third argument selects `markers`, `range` or `clock` for a scoped
diagnosis; leaving it absent runs all three families.
Selecting `portable` records the eight marker/policy/range cases together,
using the same selected manifest and prefix as the embedded launcher's
`portable` mode. The two service-only Python clock cases remain in their own
HTTP record. Existing family invocations retain their original case sets.
Native-only edits can reuse the installed adapter bundle when every actor
source hash still matches. The evidence keeps its original build revision and
binary hash separately from the current recorder revision.

The HTTP recorder executes a producer, a separate activity process and a cold
Rust successor. Each phase has an original 30-second observation timeout inside
a 90-second scenario budget; durable execution/run bounds are 1200 seconds.
The genuine Python case kills the real author after the committed activity
checkpoint, then retires that registration through the published API before
the activity resumes. This fences an already forwarded poll belonging to the
dead author. Its cancelled polls are explicit evidence, never outcome receipts.
The reader requires the actual retirement receipt and still refuses an extra
repair event. Full response bytes, deliveries, histories, clocks and exact typed
values remain in recorder sidecars. `check-readers.mjs` rejects mutated copies
of actual observations, including missing bounds, rewritten prefixes, truncated
retry lists and false completion.

The embedded profile uses distinct normal Composer role locks. Only the genuine
duplicate producer uses the historical package; other originals and every cold
reader use the current package. Public clocks must match original marker
positions and cached reuse, and original task/run relationships remain exact.

The separately named `artifacts-workflow-2.5.16.json` selects the normally
resolved policy correction in `embedded-workflow-2.5.16/`. Only Workflow changes
in that complete Composer lock. The earlier 2.5.14 manifest, locks and failed
policy recording remain unchanged apart from Server #820's bounded security
refresh, which moved only `symfony/http-foundation` and `symfony/yaml` to
7.4.21 in all three role locks (GHSA-r925-mgvr-h9cc, GHSA-2v3c-qxhr-7vpv) and
kept every Workflow package and loaded-source hash. A later Server #325
refresh moved only `symfony/mime` to 7.4.22 in the same locks
(CVE-2026-45070), on the same terms. The published Server
reference still carries its own declared 2.5.14 package. Set `DW_PARITY_CORRECTED_ARTIFACTS` to the new
manifest for this explicit embedded cohort, with separate
`DW_PARITY_CORRECTED_STATE`, `DW_PARITY_CORRECTED_EVIDENCE` and
`DW_PARITY_CORRECTED_PREFIX` values. The embedded launcher accepts `markers`
(the original five positive cases), `policy` (strict full-policy refusal and
same-run restoration), or `range` (both original-definition repairs) as its
second argument. It retains the completed SQLite database before retiring the
runtime. Separate SQLite recordings at `a4c43d26c` pass all eight applicable
marker/policy/range cases, with 176 actual-record controls and independent SQL
readback. All 931 installed runtime/resource files match the published tag.
The same manifest option applies to adapter installation and HTTP recording;
all three modes can declare one exact cohort without editing the earlier
manifest.

`corrected-marker-shared-profile.sh BACKEND NATIVE_URL` is the hosted wrapper
for that explicitly selected cohort. The caller supplies its isolated database
server/network, installed adapter bundle and running native target. It creates
separate published PHP and embedded databases, records the same eight portable
cases in all three modes and the two clock cases on both HTTP targets, compares
the complete originals, then runs the existing mutation readers on each record.
Database cleanup requires a creation receipt for that resource scope; the
native database remains owned by the caller. Use `corrected-marker-cleanup.sh BACKEND` in the
workflow's always-run cleanup before removing its database server/network.
All six database configurations run this wrapper. The expanded jobs declare
a prospective 75-minute whole-job limit; every individual scenario and phase
budget stays unchanged. Earlier 45/55-minute jobs keep their original limits.
The existing Rust 1.86 job cache is reused, and only installed actors and their
identity/lock receipts are shared with the matrix. No compiler cache or target
tree is uploaded. Expanded hosted qualification is pending.
Embedded actors execute PHP
backend handlers through real independent `queue:work --once` database workers;
they do not claim a Rust SDK consumer or sustained queue-worker drain test.

The additional Rust modes `range_completed` and `range_pending` register the
original `parity.v1.range_repair` and `parity.v1.range_pending_repair` types and
queues. Their retry-policy argument is the actual original snapshot JSON. They
author every known field and interval explicitly, refuse unsupported input,
restore range `1..1`, and exclude the recorded decision with `2..2`. Pending
repair replays the original three-second timer winner and awaits its retained
activity handle. The separate published PHP-reference directions pass actual
admission, refusal and original-task repair. Both native SQLite range cases now
also pass at `4f5d813a8`, with 56 actual-record controls and independent SQL.
The matched cohort and all six databases remain required.

`rust-range-completed.json` and `rust-range-timer-pending.json` add that Rust
direction to the original PHP 2.2.7 recipes. They preserve the original fixture
definitions, typed values and whole 35-second scenario budget. A normal PHP
2.3.9 observer performs the one explicit repair; separate Rust processes reject
the incompatible range, restore the original bounds and finish the original
run. A genuine legacy absent retry snapshot remains absent; no current class
defaults supply missing fields. Every present snapshot still preserves all
known fields and the full list. Two separately queued real neighboring runs must retain their original
histories and tasks. The embedded adapter executes the original PHP definitions
and declares the Rust author direction inapplicable; it still checks the same
durable refusal and repair boundary. Actual recordings remain required before
the whole corrected cohort is qualified. The two PHP-reference recordings and
the original-definition embedded counterparts pass with independent database
readback and 100 actual-record negative controls. Those independent local
observations precede a matched native/six-database cohort; their recorded source
revisions, manifests and original failures remain distinct.

The five positive HTTP and embedded recordings are documented on the draft PR.
The service-only `python-clock-zero.json` and `python-clock-genuine-one.json`
use the agreed Python 2.5.13 fixed workflow-start clock, public UUIDv7 calls and
two genuine activity/cold-worker boundaries. The historical producer is really
killed, then its uniquely named registration is retired through the public API
before activities resume; the unfenced-kill case above stays separate. Exact
raw marker/activity clocks, source hashes, prefixes, typed values and UUID
prefixes pass the PHP reference, with 42 actual-record controls. Initial wrong
clock-anchor and delivery-mode expectations and actual poll-based repair
records remain failed originals. Native SQLite now passes all ten corrected
HTTP cases at `dbe6ff3a3`, including both clock cases, with 194 actual-record
controls and independent SQL readback. Embedded UUID side effects remain ahead.

The separate published embedded policy-refusal original remains failed; its
coordinated package correction is Workflow #776, now normally published as
2.5.16 and qualified in the separate SQLite embedded cohort. The new profile's
matched six-database/final/main matrix remains
pending. The first killed-author reader failures remain separate from fresh
confirming runs. Frozen actors and earlier fixture definitions stay unchanged.
