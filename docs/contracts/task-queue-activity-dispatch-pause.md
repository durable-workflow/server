# Task queue activity dispatch pause

An operator can pause activity dispatch on one task queue of one namespace,
and later resume it (Server #577). This is a native capability. Published PHP
Server 2.x has no such route.

## Routes

All three take the control-plane version header and the request's namespace
(`X-Namespace`, else `?namespace=`, else `default`), in `default` or any named
namespace. They need the operator or admin role, like the build-id routes; a
worker token gets `403`. A credential bound to one tenant namespace is refused
(`403`) for any other, before anything is read or written. A request only
ever reads or changes the pause of its own namespace's queue.

- `GET /api/task-queues/{task_queue}/activity-dispatch` reads the queue's
  state.
- `POST /api/task-queues/{task_queue}/activity-dispatch/pause` pauses it. The
  body may carry `reason`, a string of at most 1000 characters.
- `POST /api/task-queues/{task_queue}/activity-dispatch/resume` resumes it.
  It takes no fields.

`task_queue` is the exact name workers register, at most 255 characters.
Names are compared byte for byte: pausing `Orders` does not pause `orders`.
A queue need not exist yet; pausing an unused queue holds the first activity
scheduled on it.

Each answer is `200` with `namespace`, `task_queue` and `activity_dispatch`.
Pause and resume also report `outcome`:

| Route  | `outcome`        | Meaning                                                   |
|--------|------------------|-----------------------------------------------------------|
| pause  | `paused`         | The queue was active and is now paused.                   |
| pause  | `already_paused` | Nothing changed; the original pause is reported.          |
| resume | `resumed`        | The queue was paused and is now active.                   |
| resume | `not_paused`     | Nothing changed; the last record, if any, is reported.    |

`activity_dispatch` is:

```json
{
  "state": "paused",
  "paused": true,
  "reason": "payments vendor outage",
  "paused_at": "2026-11-12T10:00:00.123456Z",
  "paused_by": {"type": "auth:token", "id": "role:operator", "label": "Operator"},
  "resumed_at": null,
  "resumed_by": null
}
```

`state` is `paused` or `active`. The other fields describe the last pause and,
once it ended, its resume. They stay after a resume until the next pause
replaces them; a queue that was never paused has them all `null`.
`paused_by` and `resumed_by` are the authenticated principal, in the shape a
command context records (`type`, `id`, optional `label`). A request cannot
name another one. An invalid `reason` or queue name is `422` with Laravel
validation `errors`, and nothing is recorded.

`GET /api/task-queues` and `GET /api/task-queues/{task_queue}` include the
same `activity_dispatch` object for each queue. A paused queue is listed even
when it has no tasks or registered workers.

## What a pause holds

While a queue is paused:

- No activity task on it is leased, by an activity poll, a poll carried on a
  workflow task completion or any other claim. Ready activity tasks stay
  ready. No attempt is started or counted, so after a resume the same task
  is leased as the attempt it would have been.
- A held activity poll waits for its timeout and answers
  `{"task": null, "poll_status": "empty"}`, as an empty queue does. The worker
  protocol does not change.
- Activity deadlines keep running and are not extended. A schedule-to-start
  or schedule-to-close deadline that passes while the task waits times the
  activity out, as it would without the pause; resuming does not revive it.
- Activities already leased keep running. Their heartbeats, completions and
  failures are accepted. A retry they schedule waits for the resume.
- Workflow tasks, signals, queries and updates are delivered as before. A
  workflow poll is not woken by a paused queue's activity tasks.

Resume wakes held activity polls on the node that served it at once. Polls
held on other nodes see it at their next readiness read.

The pause is durable admission state in the native table
`dw_task_queue_pauses`, not history. It holds across restarts and for every
node sharing the database. Pause and resume take the same transition lock as
a claim, so a claim either commits before the pause or sees it. Deleting the
namespace removes its pauses.

A pause is not a build drain, which refuses one build's workers and is
resumed with `POST /api/task-queues/{task_queue}/build-ids/resume`, and it is
not a concurrency cap. Native enforces no server-side lease or dispatch caps,
and a cap setting of `0` is never a pause: published PHP reads `0` as no cap,
and native accepts only an unset, empty or non-positive value for those
settings and enforces none.

## Advertisement

Native advertises `task_queue_activity_dispatch_pause: true` in cluster info
`capabilities`. A server that does not advertise it has no pause routes;
clients must check it before offering a pause.

## Schema

The table is added by native schema SQLite 12, PostgreSQL 13, MariaDB 13 and
MySQL 14. An existing database needs the stopped-node backup and explicit
`schema-upgrade` before the new binary starts; startup refuses an older
schema with `native_schema_upgrade_required`.

`tests/execution/task_queue_pauses.rs` covers named-namespace isolation
(a pause in one namespace neither shows in nor holds another's same-named
queue, and another namespace's resume cannot lift it), worker-role and
tenant-bound refusals, that a principal named in the body is ignored, the
held immediate and long polls, the unchanged ready task and attempt count, workflow delivery while
paused, the pause across a runtime restart, the resume waking a held poll and
leasing the first attempt, idempotent pause and resume, a schedule-to-start
timeout while paused, validation, exact queue names and listing.
