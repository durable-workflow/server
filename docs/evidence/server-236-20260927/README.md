# Server #236 restore drill, 2026-09-27

This directory retains raw, sanitized outputs from an isolated restore of the
published Server image. All workflow inputs and credentials were synthetic.
The SQL file and object bodies are identified by digest below; they are not
published because a customer backup should remain protected. The same scripts
can generate new synthetic inputs for a repeat run.

## Frozen setup

| Component | Version or configuration |
| --- | --- |
| Server | `durableworkflow/server@sha256:8dcd89f57fbcf3ba6369f23ae815abe6087185602f904105fb67757ab5e5a3ed` (2.4.18) |
| MySQL | `mysql:8.4@sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` |
| Redis | `redis:7.2@sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| Object store | `chrislusf/seaweedfs@sha256:ce9e796f1fe6f06968f4c04bdaf8f678dad9c8acdfef3d244133d71bfa6bf882` (4.47), separate persistent volume in each Compose project |
| SDK runner | [Dockerfile.sdk](Dockerfile.sdk), published Python SDK 2.0.0, boto3 1.40.0, UID 1000 |
| Namespace | `default`, S3 policy enabled at 1,024 encoded bytes, prefix `namespaces/default/`, path-style bucket `restore-drill` |
| Source and replacement | Separate Compose projects, MySQL/Redis/S3 volumes, loopback API ports 18162 and 18163, same synthetic Server key and role tokens, no imposed container resource limits |

The source had a completed workflow, a waiting timer, a pending workflow on an
unserved queue, and a fourth pending workflow acknowledged after the hold was
acquired but before the SQL dump. Each had an externalized input. The published
Python SDK worker was stopped before the snapshot. The source and replacement
used separate object stores with the same logical bucket name and keys.

## Commands and recovery point

The Compose override is [compose.seaweed.yml](compose.seaweed.yml). The
published Compose file is `docker-compose.published.yml` from Server main at
`9176b08866e7ff5d79d065d7783908fbfd2d6986`. Synthetic environment files
followed the documented production example and set the pinned images, role
tokens, Server key, S3 credentials, bucket, region and path-style option.
Their values are omitted from this public evidence. `SERVER_PORT` was set to
`127.0.0.1:18162` for source and `127.0.0.1:18163` for replacement.

The exact database and hold operations were:

```bash
docker exec dw236weedsrc1820-server-1 php artisan external-payloads:backup-hold acquire \
  --owner=55de4d94-090b-4fd4-a06c-84f9aa7e8cbe --ttl=1800
docker exec dw236weedsrc1820-mysql-1 sh -lc \
  'mysqldump --single-transaction --quick --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > database.sql 2> database.stderr
docker exec dw236weedsrc1820-server-1 php artisan external-payloads:backup-hold status \
  --owner=55de4d94-090b-4fd4-a06c-84f9aa7e8cbe
docker exec dw236weedsrc1820-server-1 php artisan external-payloads:backup-hold release \
  --owner=55de4d94-090b-4fd4-a06c-84f9aa7e8cbe
```

The hold was acquired at 18:22:59 UTC and remained active through the dump
and object copy; it was released at 18:24:13 UTC. The 198,969-byte SQL file
had SHA-256 `83a4cad3eaac22df6ea6cf4bd397b26c78691083f006f5e52d8419bcf3c83f43`.
The dump and import returned 0; their raw stderr files contain only MySQL's
password-on-command-line warning. [drill.py](drill.py) listed, downloaded,
hashed and then read back all four S3 bodies under the hold. They totaled
43,824 encoded bytes. The [object manifest](object-manifest.json) had SHA-256
`148fde22cf8e8ded3f6a1b0cb7f2253bce7f4ed548d391ddbb0ba4072ef7923b`.

The replacement started only MySQL, Redis and its separate SeaweedFS store.
The SQL import completed with API, worker and scheduler stopped. The object
copy was uploaded to the replacement and every body read back and compared
before starting Server. The restored historical hold was released after
verification.

## Raw results

- [Source workflows before snapshot](source-before.json) and
  [replacement workflows before resuming](restore-before.json) are byte-identical.
  The separate [edge workflow before snapshot](edge-before.json) and
  [edge workflow after restore](edge-restore-before.json) are also byte-identical.
- [Pending workflow after recovery](restore-pending-after.json) and
  [edge workflow after recovery](edge-after.json) returned their original input
  SHA-256 as the result. The previously completed workflow's history digest
  stayed unchanged. [Event counts](event-counts-after.json) show exactly one
  `WorkflowCompleted` event each for completed, pending and edge workflows;
  scheduled, started and completed activity counts are one each.
- The restored operator credential returned [HTTP 200](valid-token.status);
  an invalid credential returned [HTTP 401](invalid-token.status).
- [Restore start](restore-start.epoch) was 18:24:21 UTC and
  [verified worker completion](restore-complete.epoch) was 18:26:53 UTC. The
  inclusive manual wall interval was **152 seconds**. It includes operator
  pauses and is one measured recovery, not an RTO commitment.
- The restored timer remained waiting with its pre-backup history unchanged at
  this checkpoint. Its real deadline was 19:22:33 UTC and is being checked
  separately. The [in-flight activity follow-up](inflight/README.md) records
  a missing automatic repair pass in the published Compose stack and a
  successful candidate Compose correction in Server PR #241. That correction
  is not yet a verified published release.

An earlier three-workflow trial used Adobe S3Mock in place of SeaweedFS. Its
restored timer fired at its original 19:06:21 UTC deadline and the published
Python SDK worker completed it with the expected payload digest. The
[post-timer result](first-pass-timer-after.json) and
[event counts](first-pass-event-counts-after-timer.json) show one `TimerFired`
and one `WorkflowCompleted` event for that run. This trial supports timer
recovery but does not replace the SeaweedFS result above.

The raw helpers are [drill.py](drill.py), [edge.py](edge.py),
[event-counts.py](event-counts.py), and [timer-resume.py](timer-resume.py). The SDK runner used
`DRILL_CONTROL_TOKEN` set to the synthetic operator token and
`DRILL_WORKER_TOKEN` set to the synthetic worker token. The S3 helper used
`DRILL_S3_ENDPOINT=http://seaweed:8333` and a mounted evidence directory.
All source project containers and volumes were removed after the copied
evidence was checked. The replacement remains isolated for the timer check.
