# Real-backend Waterline activity-result acceptance

This optional fixture runs the unmodified published Waterline service against
genuine activity originals. It does not intercept browser routes or supply API
responses. It is separate from Waterline's mocked visual regression script.
Source preparation and syntax checks do not establish browser acceptance.

Use published Waterline 2.11.0, source
`8f03bd6250c08dd96c49a674ccb0752feb6fd9fe`, and its normally installed PHP
SDK 2.4.0. The previously qualified image index is
`sha256:55b2f75e2b1849defafa3a9525a2ff30159fe21177eef39bdce7aaea280d40bf`.
The separate [profile](profile.json) selects published PHP 2.8.5 as the current
reference, without changing the adjacent probe's frozen profile. Reuse its
unchanged PHP SDK 2.4.2 author code and lock. Resolve and verify the immutable image and shipped package identities before
executing a new cohort. Record the actual Server source/binary or published
image independently. This recipe does not select a new frozen parity tuple.

Run the image with a private task network, its own writable `/data`, the actual
Server URL/token/namespace and these inputs:

```text
WATERLINE_ACCESS_MODE=read_only
WATERLINE_ALLOW_UNAUTHENTICATED=true
```

Do not publish the fixture port. The image must reach `/up` before any original
is admitted. Keep its existing 128 MiB PHP limit and a 512 MiB container limit.
Run each browser profile in a separate process/container at 1 CPU, 1 GiB,
no swap and a 256 MiB shared-memory limit. This is correctness qualification,
not a performance comparison. A cumulative browser OOM is a failed observation,
not permission to raise limits or count unfinished profiles.

Install this directory normally with `npm ci --ignore-scripts`. Use the official
Playwright browser image matching the exact locked package version and record
its resolved digest. Mount source/dependencies read-only; keep browser state
disposable. Never run package tooling on the host or as root against the source.

Before each browser selection, use the adjacent
[`activity-result-inspection`](../server-parity/activity-result-inspection/README.md)
published SDK probe to author, actually execute, complete the parent and cold-read
a fresh original. Cover `encoded-null`, `encoded-false`, `typed-map`,
`external-result` and `retry-completed` in default and named namespaces.
Keep a second genuine original for wrong-run/activity selectors. Both identities
come from those actual authors; do not construct history or activity rows.

Start the observer and browser tooling before authorship. Each identity retains
the author's original 30-second observation deadline. The browser refuses an
expired budget and never starts, repairs or rebudgets the original. Use a fresh
original for each width/theme observation if the prior budget has expired.

```sh
DW_RESULT_BROWSER_TOKEN=activity-result-fixture-token \
node scripts/conformance/activity-result-browser/inspect.mjs \
  --waterline-url http://viewer:8080 --server-url http://server:8080 \
  --identity /state/selected.json --other-identity /state/other.json \
  --width 390 --theme light --summary /state/browser-summary.json
```

Repeat independently at widths 390 and 1440 with light and dark themes.
The fixture requires the canonical bounded page to load without failed API
requests, then uses its ordinary **Inspect full details** button to reveal the
activity metadata. Neither page load may select a targeted activity result.
One keyboard result selection must make one exact result request.
It checks null/false, explicit int64 preview, Unicode and the
complete large value, dialog geometry and restored focus. Both genuine wrong
selectors must return 404. Raw histories for both originals must remain
byte-identical. The summary contains only identities, counts, hashes, timings,
geometry and bounded failure text, without bodies, headers or tokens.

Discovery and the run page remain real product gates. A Server without
`capabilities.workflow_activity_result_inspection=true` cannot pass the
positive selection. An unavailable bounded run observation is also a failure.
Do not forge discovery, patch the SDK/UI, route around the canonical page or
replace these responses with fixtures. Current native source keeps result
discovery unadvertised and has no qualified bounded run-observation consumer;
its positive Waterline acceptance remains pending.

This browser fixture does not independently count backend provider reads or
prove unrelated-payload isolation. Keep those endpoint/transport gates separate.
After the immediate audit, publish a small owning GitHub outcome and remove all
task-owned containers, volumes, images, dependencies, browser state and raw
outputs. Preserve the code and readable outcome.
