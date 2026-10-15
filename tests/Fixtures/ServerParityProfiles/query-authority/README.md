# Current query authority profile

This separate profile uses normally installed PHP SDK 2.4.2 and published Server
2.8.0. It does not replace the frozen query actors. The four published PHP cases
pass locally on isolated MySQL 8.4. Native comparison and all-six qualification
remain pending. Main does not advertise native query authority. The unpublished
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

Both services must use separate disposable databases, shared process-visible
polling caches and `DW_QUERY_TASK_TIMEOUT=4`. The caller owns bootstrap,
readiness and cleanup. Run each declared case once against each target. Every
original has one unchanged 30-second observation budget covering setup, query,
Worker drain and deregistration. The caller must also bound container lifetime
and remove its resources after success or failure.
The Worker and workflow stop within each original budget. The caller destroys
the entire isolated database afterward, including its task namespaces. This
profile does not qualify namespace DELETE, which is still absent in native
source. It makes the same cleanup calls on both targets.
Startup waits for the installed Worker's `worker.registered` diagnostic, emitted
after its actual registration acknowledgement. It does not depend on worker
listing, which is also outside the current native slice.

The four deadline cases use completed workflows in distinct named namespaces.
The additional timely-active case uses a genuine waiting timer workflow in the
default namespace. Native named-timer authoring still refuses explicitly and is
not qualified by this profile. Earlier waiting named originals remain failed.
Timely 1.20 and 1.19 controls retain the exact int64/null/Unicode result.
Separate late controls hold a genuine poll reply for six seconds or let a
synchronous callback return after six seconds. They require the original caller
timeout, no late completion submission and no callback dispatch after an expired
poll reply. Query status preserves the original claim and timing without
renewal. Protocol 1.19 makes no status request. Every query preserves the exact
workflow history and the Worker must exit zero.
The raw delivery is retained. PHP may omit its namespace member, as supported
by the published SDK, in which case the authenticated Client namespace applies.
An explicit conflicting namespace still fails.

External query loading, Python/Rust consumers, remaining route/pressure and
continuation boundaries, all-six integration and capability qualification remain
ahead. These are correctness controls and establish no performance result.
