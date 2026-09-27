# Published Server 2.4.19 restore verification

This is the post-release check for the complete self-hosted restore drill in
[Server #236](https://github.com/durable-workflow/server/issues/236). All
identities, workflows, credentials, and payloads were synthetic. The protected
SQL dump, object bodies, and environment are local test artifacts and are not
published. The linked JSON and TSV files in this directory contain sanitized
observations.

## Exact artifacts and setup

- Server tag `2.4.19`, source commit
  `7cb4018276d4f69508dca6b2245415c2be2d7b83`, Docker Hub and GHCR
  multiarchitecture digest
  `sha256:77a61a9f46ca9765ee86174b9a50dab1b9e810250caa9e3551b800457a81862a`.
  The image's `/app/.package-provenance` identifies Workflow `2.2.11` at
  `853b7ecb445dc4bb2369e6708e5bd60a16e4a3fa`.
- Helm `0.1.115`, package digest
  `sha256:9e0f94affce795842fe5af2270f27ab18c3bab3ac60f7fa01fbad8036d1d84bd`,
  passed anonymous OCI install against the same image digest.
- [Release Action](https://github.com/durable-workflow/server/actions/runs/36348552039)
  passed exact image publication, bare first run, protocol catalog, and chart
  verification. The [published Compose smoke](https://github.com/durable-workflow/server/actions/runs/36348937681)
  passed local and production profiles on both amd64 and arm64.
- Source and replacement used separate published Compose projects and volumes,
  the same pinned Server image, MySQL 8.4, Redis 7.2, SeaweedFS 4.47, and
  published Python SDK 2.0.0. The `default` namespace used S3 with a 1,024-byte
  externalization threshold and a synthetic bucket. The source API and SDK
  worker were fenced before the replacement API started.

## Recovery point

The source had one completed, one pending, one waiting timer, and one waiting
workflow with an in-flight activity. The source and replacement inventories
are byte-identical before worker resumption:
[parent source](source-parent-before.json),
[parent replacement](restore-parent-before.json),
[in-flight source](source-inflight-before.json), and
[in-flight replacement](restore-inflight-before.json).

An owner-scoped external-payload reclamation hold was active from 20:45:34
through the accepted database snapshot and object copy. The MySQL dump finished
at 20:47:11 UTC with `--single-transaction --quick --no-tablespaces`, exit 0,
the completion footer, and only MySQL's password-on-command-line warning on
stderr. The 320,078-byte SQL dump had SHA-256
`cae547fbfc35387ffca6f3b77438bcc00eaef7e6003ff78a456a9477bb9e96d5`.
Three object bodies totaling 32,872 bytes were copied, hashed, and read back.
Their [manifest](object-manifest.json) had SHA-256
`d0faa64058791e8054df55cefa64a77257cbddff0002961dcbcbf47697b634f9`.
The hold was still active at 20:47:23 UTC and was then released at the source.

The replacement imported the dump into a fresh MySQL volume and verified every
object read-back in a separate SeaweedFS volume before Server processes began.
Its [pre-start task state](prestart-task.tsv) includes the activity leased until
20:50:09 UTC with `repair_count=0`.

## Published image result

The default published scheduler returned that expired activity to `ready`
without an operator repair command. The [task state after lease expiry](after-lease-task.tsv)
has `repair_count=1`. The published Python SDK worker then completed the same
run. [Final events](final-inflight-events.tsv) show two `ActivityStarted`, one
`ActivityCompleted`, and exactly one `WorkflowCompleted`. The
[completed workflow](restore-inflight-after.json) returned the expected
8,201-byte payload digest
`a2385794cb2492c67cdacea5ab7e819a1007d2d7d0550ebfbfb34edaa9e6517f`.
The first start was at 20:45:09, the retry at 20:51:10, and completion at
20:51:12 UTC. From the accepted SQL snapshot footer to restored completion was
four minutes and one second, including fresh import, startup, and lease wait.

The [pending workflow](restore-parent-after.json) also completed with its
expected 8,200-byte payload digest. The previously completed run remained
completed with the same run ID and output. The timer remained waiting with its
original run ID and history. All three pre-resume histories matched their
source copies. An unauthenticated namespace request returned `401` and an
operator-authenticated one returned `200`. The restored historical backup hold
was released, then the [S3 round-trip diagnostic](restore-storage-test.json)
passed for 512- and 8,192-byte payloads. The diagnostic returned `503` before
hold release because cleanup was blocked, so the documented restore sequence
now releases the hold before running that diagnostic.

The previous [full drill](../README.md) covers timer firing, post-snapshot
acknowledgement boundaries, and manual repair behavior of Server 2.4.18. This
post-release check specifically verifies automatic recovery and preserved
payloads with the exact published 2.4.19 digest.
