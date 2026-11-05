# Native publication and rolling alias promotion

This source contract applies to future release tags containing the #1020
workflows and receipt producers. It does not change immutable older tag
workflows or qualify historical runs. An old green run without these receipts
cannot be annotated into eligibility. Release execution, registry writes and
any frozen release remain subject to their separate operator and Security gates.

## When the rolling tags move

`native-release.yml` publishes and attests exact version tags only. It never
moves `X.Y`, `X` or `latest`. A final release follows this order:

1. Publish both architecture children and the same index in both registries.
2. Run the existing native-image corpus on that exact GHCR index from the release
   tag, then the existing published-Compose four-cell matrix on that same index
   from that tag. These are separately admitted invocations, not automatic
   promotion workflow dispatches.
3. Review the exact current run attempts, provider job identities and five
   canonical receipt artifacts. Dispatch `native-alias-promotion.yml` from the
   exact release tag, a matching `tag` input and that data-only selection manifest.
4. An unconditional, unprivileged plan first binds ref, tag, manifest source and
   protected-main ancestry. The read-only job then verifies the selection. After environment approval, the
   mutation job repeats the entire verification while holding
   `durable-workflow-server-rolling-aliases`, then calls the existing monotonic
   alias helper. Only this job receives packages-write and existing Docker Hub
   credentials; it has no contents-write.
5. Create the GitHub Release from protected main using
   `native-github-release.yml`, the exact publication run attempt and the same
   qualification manifest. Final Release creation verifies qualification again
   and requires each relevant live alias in both registries to name this exact
   index or a strictly newer final release. It cannot perform promotion.

Candidates keep their existing publication/prerelease behavior and never move
aliases. A final Release that already exists before promotion causes an explicit
refusal. Escalate the exact legacy case to General; the promotion phase never
edits a Release or treats it as qualification. Missing historical receipts refuse.

## Evidence selection and authority

The input is at most 8,192 UTF-8 bytes of JSON with the closed schema
`durable-workflow.native-alias-promotion-evidence.v1`. Unknown/duplicate keys,
coerced IDs, extra/missing/duplicate cells and nonfinal tags refuse.

| Object | Required fields |
| --- | --- |
| Root | `schema`, `release`, `corpus`, `compose` |
| `release` | `tag`, `source_commit`, `index_digest`, `run_id`, `run_attempt` |
| `corpus` | `run_id`, `run_attempt`, `runner_commit`, `workflow_blob`, `artifact_id`, `artifact_digest` |
| `compose` | `run_id`, `run_attempt`, `runner_commit`, `workflow_blob`, `cells` |
| Each Compose cell | `platform`, `profile`, `job_id`, `artifact_id`, `artifact_digest` |

IDs/attempts are positive actual integers no greater than 2^53−1. Commits and Git
blob identities are lowercase full SHA-1; digests are `sha256:` plus 64 lowercase
hex digits. The three run IDs and five artifact IDs are distinct. Compose cells
are exactly `linux/amd64` and `linux/arm64`, each with `local` and `production`.
Both qualifier runner commits equal the release source commit, with workflow
blobs read at that exact commit; being another ancestor of main is insufficient.
No alternative runner allowlist exists in this version.

Selection is not proof. Fixed `durable-workflow/server` provider reads establish
repository/head-repository IDs, numeric workflow identity/path, dispatch event,
tag/ref/source, exact current attempt, terminal successful jobs and every
critical step. All attempts' jobs are enumerated with a finite complete inventory;
newer/ambiguous attempts refuse. Trusted workflow run titles bind the original
canonical `ghcr.io/durable-workflow/server@sha256:...` dispatch input because the
run API does not expose dispatch inputs. Qualifiers must start after publication
completes. Tag resolution, protected-main ancestry, exact version tags and
provenance for the exact publication attempt are reverified in both registries.
There is no newest-run search, polling, automatic retry or invented age cutoff.

The manifest selects exact artifact IDs and provider archive digests, never the
newest artifact by name. The verifier bounds each archive to 65,536 bytes and
accepts only one regular `receipt.json`, at most 32,768 expanded bytes, with a
bounded compression ratio. It refuses links, special files, directories, extra
members, prefixes/trailing carriers, encryption, duplicate/noncanonical JSON and
unknown ZIP extra fields other than a bounded standard timestamp. It verifies
downloaded SHA-256 before parsing and never extracts or executes the archive.
Artifacts must be nonexpired, belong to the selected run/source and have been
created within the selected attempt/job window. Current run and artifact metadata
are read again before verification returns; unavailable APIs are failures.

## Producer receipts and cleanup

The existing corpus job retains its broad comparison artifact and adds
`native-corpus-promotion-receipt`. Each existing Compose job adds
`native-compose-promotion-receipt-<architecture>-<profile>`. Each promotion artifact
contains only the canonical JSON receipt. PR/default/local image contexts emit a
separate diagnostic schema with `eligible=false`, never positive evidence.

A positive `durable-workflow.native-qualification-receipt.v1` binds repository,
run/attempt/job, runner/workflow blob, original requested index, platform/profile,
resolved child manifest and image config, actual container config ID,
architecture/OS, source/release labels, whitelisted observations and cleanup.
The index, child manifest and config are separate identities. Registry membership
and both registries' source labels are checked independently by the consumer.

Corpus observations require all six existing partition/status values to be
integer zero. Compose observations require serving health, readiness, exact
reported release version, worker registration, loopback binding, unset-token
refusal and, for production, default-token refusal. No token, registration body,
raw environment or host configuration enters a promotion receipt. Compose HTTP
responses use private temporary files and bounded requests; failures print a
static reason rather than authorization arguments or server logs.

Provider CLI reads have a 30-second per-command deadline within a 180-second
read budget, bounded stdout and 4 KiB stderr. The synchronous helper owns each
direct child with default `SIGCHLD` handling. It observes exit using
[`waitid(WNOWAIT)`](https://man7.org/linux/man-pages/man2/wait.2.html), retaining
the child's kernel PID reservation until process-group cleanup finishes, then
reaps with at most one second of cleanup grace. It never reaps first and signals
the released number afterward. Missing non-reaping primitives or an automatic
reaper refuse before process creation. Unknown child authority refuses without
a numeric PID signal; signal/reap failure cannot return a successful read.

Cleanup retains the original body result. It removes only exact owned Compose
project resources or containers carrying the exact corpus scope OWNER label,
owned network and observed owned volumes, including labelled standalone helper
containers. Names and network attachment discover candidates but never authorize
removal. Every discovered corpus container must have the exact OWNER label
before any removal; a foreign collision stops with zero removals. Successful independent Docker inventory reads must then show zero
owned containers, networks and volumes. API failure is unknown, never absence.
An anonymous volume must be mounted by an inspected owned container and have
either no labels or only the exact Engine anonymous marker. Foreign labels
refuse. Cleanup logs only a bounded static reason code when refusing.
There is no raw by-name fallback. The isolation checker recognizes only this
closed always-run kind/scope invocation bound to job identity and reviewed
helper/transport/predicate source bytes. Unknown ownership/API state retains
any remaining resource for escalation and never broadens deletion.
Only after successful body and cleanup steps does the existing job finalize its
receipt, repeating provider job and immutable workflow ownership. An `always()`
upload, failed/cancelled job or diagnostic receipt cannot satisfy promotion.
Arm64 Compose retains its existing QEMU scope, without a native-hardware claim.

## Monotonic writes and recovery

The alias helper preserves Git-version selection and native/PHP latest
arbitration. It reads current release labels and digests, then immediately before
each registry write re-reads both exact tags and every selected alias. A changed
or unreadable state stops. Absence is recognized only for the exact single-line
Buildx diagnostic `ERROR: <requested-ref>: not found` for that requested tag.
Credential-helper, auth, transport, rate-limit, mixed/extra or unknown diagnostic
formats refuse. This is an explicit fail-closed client-format contract, not an
HTTP-status parser; a future client format requires separate source review. Previously confirmed writes must still name the
qualified index. Each write is verified before proceeding to the other registry.
Superseded aliases remain in place; a same-index recovery is idempotent.

The shared lock coordinates repository-owned writers. It is not registry CAS:
an external actor can race after the last read. A write failure, timeout or
post-write mismatch stops with an explicit attempted/confirmed partial state;
there is no automatic rollback. General owns any concrete retry decision with
unchanged qualified evidence, including uncertain cross-registry outcomes.
Cloud retains its separate immutable-artifact/profile/installed-closure and
deployment gates; alias qualification does not grant deployment admission.
No production execution, release dispatch or registry mutation is admitted by
source tests or by merging these workflows.
