# Local external-payload consumers

The profile reuses the normal PHP SDK and immutable PHP Server selected by
`benchmarks/server-port/v2/matched.json`. It runs five independent originals on
each of separate PHP/native databases: external workflow input, workflow result,
activity result and one/two external activity argument batches. Separate client
processes perform authorship, actual activity callbacks and cold result reads.
The existing recipes keep their original 30-second bounds and exact typed
int64/null/Unicode values. Explicit reference submissions remain explicit;
automatic upload policy is not implied by these observations.

Run `bash scripts/conformance/server-parity/external-consumer-profile.sh BACKEND`
inside an existing isolated parity job, with its `RESOURCE_SCOPE` network,
database image variables, normally installed `corrected-marker-adapters/php`
and previously built `rust-target/debug/durable-workflow-server`. `BACKEND` is
`sqlite`, `mysql` or `pgsql`. The profile creates its own databases, provider
directories and servers. It never shares a database between implementations.
Cleanup stops those servers, drops only those databases and removes provider
bytes immediately. Small result summaries and diagnostic logs enter the
existing bounded artifact exporter.

The independent 64-bit PHP reader checks original identities, typed values,
encoded digests, activity counts, fixed bounds and unchanged cold-read history.
Seven corruptions of the actual summaries must refuse. These reader controls
do not execute another business original or establish a database-fault claim.

Embedded execution has no service HTTP payload-reference carrier in this
profile; its workflow semantics remain covered by the independent embedded
suite. Server restart, S3, automatic result policy, missing ingress fields,
completion-upload authority and cleanup/backup fault qualification remain
separate gates. Full native external transport discovery stays unadvertised.
Qualification of this profile is pending in its owning #325 pull request.
