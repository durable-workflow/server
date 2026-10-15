# Current query authority profile

This separate profile uses normally installed PHP SDK 2.4.2 and published Server
2.8.0. It does not replace the frozen query actors. The earlier five-case profile
passes on both targets using separate SQLite databases. A fresh six-case cohort,
including the additional named waiting-workflow case, also passes on both
SQLite targets. Earlier four-case
MySQL observations are separate. The earlier active native termination-cleanup
failure and PHP acknowledgement-observer race remain failed. All-six
qualification is pending.
Main does not advertise native query authority. The unpublished
candidate advertises it for installed-consumer qualification before promotion.

The owning contract is [Server #433](https://github.com/durable-workflow/server/issues/433).
`artifacts.json` freezes the consumer, reference image and original budgets.
Install the adjacent `scripts/conformance/server-parity/query-authority`
Composer project normally. Run its `worker.php URL MODE PROTOCOL` in a Unix PHP
container with `DW_PARITY_SDK_AUTOLOAD` pointing at that installation and
`DW_PARITY_TOKEN` set to the isolated Server token.
The host wrapper `scripts/conformance/server-parity/query-authority-profile.sh`
takes `NETWORK SERVER_CONTAINER URL SDK_DIRECTORY OUTPUT_DIRECTORY TARGET`,
where `TARGET` is `php` or `rust`. Set a unique `RESOURCE_SCOPE` and the isolated
token before invoking it. It refuses an existing case output instead of
overwriting a failed original.

The hosted entry point `query-authority-cohort.sh BACKEND` creates separate PHP
and native databases, installs the locked SDK normally, and runs every declared case
against each target. `BACKEND` is `sqlite`, `mysql` or `pgsql`; the existing six
database jobs provide their pinned database images and isolated network. It
reuses the job's compiled native binary. Its separate always-step invokes
`query-authority-cleanup.sh BACKEND`, which checks ownership, stops both APIs,
records their actual exit states, and removes the two databases and SDK state.
The small comparison keeps original run identities and history hashes; raw
query snapshots are excluded from retained job summaries.

Both services must use separate disposable databases, shared process-visible
polling caches and `DW_QUERY_TASK_TIMEOUT=4`. The caller owns bootstrap,
readiness and cleanup. Run each declared case once against each target. Every
original has one unchanged 30-second observation budget covering setup, query,
Worker drain and deregistration. The caller must also bound container lifetime
and remove its resources after success or failure.
The Worker stops within each original budget. The caller destroys the entire
isolated database afterward, including its active workflow and task namespaces.
This profile does not qualify workflow termination or namespace DELETE, which
are still absent in native source. It makes the same cleanup calls on both targets.
Startup waits for the installed Worker's `worker.registered` diagnostic, emitted
after its actual registration acknowledgement. It does not depend on worker
listing, which is also outside the current native slice.

The four deadline cases use completed workflows in distinct named namespaces.
The timely-active and timely-named-active cases use genuine waiting timer
workflows in the default namespace and a distinct named namespace respectively.
The latter covers named-timer admission and query delivery together. Earlier
waiting named originals remain failed; this new case cannot rescue them.
Timely 1.20 and 1.19 controls retain the exact int64/null/Unicode result.
Separate late controls hold a genuine poll reply for six seconds or let a
synchronous callback return after six seconds. They require the original caller
timeout, no late completion submission and no callback dispatch after an expired
poll reply. Query status preserves the original claim and timing without
renewal. Protocol 1.19 makes no status request. Every query preserves the exact
workflow history and the Worker must exit zero.
The raw delivery is retained. PHP may omit its namespace member, as supported
by the published SDK, in which case the authenticated Client namespace applies.
An explicit conflicting namespace still fails. The earlier failed originals and
the exact four-case match are recorded in
[the owning query checkpoint](https://github.com/durable-workflow/server/issues/433#issuecomment-6184509976).

The separate installed Python 2.5.15 and Rust 3.5.5 consumers pass twenty actual
SQLite originals across PHP and native targets at runner `a72c63277`. Both
languages cover timely 1.20/1.19, a six-second held poll response, async expiry
and blocking-handler expiry. Original query/whole budgets remain four/thirty
seconds. Typed results, unchanged history, original claim/timing, no late
submission and successful Worker/observer shutdown are checked. The
[local outcome](https://github.com/durable-workflow/server/issues/433#issuecomment-6185525047)
preserves the earlier failed observer control and exact installed identities.

`query-authority-adapters.sh` installs the hash-locked Python wheel and builds
the normal registry Rust consumer once. It shares only checked runtime files
with the database jobs. `query-authority-clients-profile.sh` executes both
languages through a bounded byte-preserving observer; its per-case observers
stop after observation and the workflow's always-step removes them. Changes
after the local outcome still require an exact combined hosted qualification.

External query loading, remaining route/pressure and continuation boundaries,
all-six consumer integration and capability qualification remain ahead. These
are correctness controls and establish no performance result. Completed raw
observations and build caches are deleted after audit; readable outcomes and
reproduction code remain.
