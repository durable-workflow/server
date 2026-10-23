# Local external-payload consumers

The profile reuses the normal PHP SDK and immutable PHP Server selected by
`benchmarks/server-port/v2/matched.json`. It runs five independent originals on
each of separate PHP/native databases: external workflow input, workflow result,
activity result and one/two external activity argument batches. A sixth original
uses the published raw HTTP version-1 activity-result completion-upload context,
then completes the activity and its cold parent. It checks duplicate/conflicting
uploads, real UTF-8 context JSON, foreign ownership, unchanged lease expiry and matching upload
reconciliation after activity closure. This sixth case uses the installed Core
Avro serializer rather than claiming an SDK worker callback.
A seventh original lets the installed SDK decide by itself. It reads the
namespace's storage policy from cluster info, uploads a start input over the
16-byte threshold and starts with the reference. Each server must also report
the same available, unconfigured, disabled and missing policies. Separate client
processes perform authorship, actual activity callbacks and cold result reads.
The existing recipes keep their original 30-second bounds and exact typed
int64/null/Unicode values. Explicit reference submissions remain explicit; only the seventh original
qualifies the SDK's automatic upload policy, for one client start input.

Run `bash scripts/conformance/server-parity/external-consumer-profile.sh BACKEND`
inside an existing isolated parity job, with its `RESOURCE_SCOPE` network,
database image variables, normally installed `corrected-marker-adapters/php`
and previously built `rust-target/debug/durable-workflow-server`. `BACKEND` is
`sqlite`, `mysql` or `pgsql`. The profile creates its own databases, provider
directories and servers. It never shares a database between implementations.
The separate `external-consumer-cleanup.sh BACKEND` always-step stops those
servers, drops only databases with this execution's ownership receipt and
removes provider bytes immediately. A local caller must likewise run that
cleanup after success or failure, before retiring the database/network. Small
result summaries and diagnostic logs enter the existing bounded artifact exporter.

The independent 64-bit PHP reader checks original identities, typed values,
encoded digests, activity counts, fixed bounds and unchanged cold-read history.
References compare every member and type exactly; JSON object member order is
observed separately and cannot change identity. Sixteen corruptions of the actual summaries must refuse. These reader controls
do not execute another business original or establish a database-fault claim.

Embedded execution has no service HTTP payload-reference carrier in this
profile; its workflow semantics remain covered by the independent embedded
suite. Server restart, S3, automatic result policy, missing ingress fields,
broader workflow/query/prepared completion uploads and cleanup/backup fault qualification remain
separate gates. Native advertises runtime external payload discovery with three reviewed
differences from Server 2.11.1, listed in the `cluster-info` fixture.
Qualification of this profile is pending in its owning #325 pull request.
