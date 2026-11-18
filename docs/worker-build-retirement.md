# Worker build and task queue retirement

Promotion moves *new* starts to a build. It does not move runs that are already
pinned to an older build, or activities already recorded on an older queue.
Those keep needing the old fleet until they close. Native Server answers "can
this build or queue be retired safely?" with a read-only inventory, and offers a
guarded retire action that refuses while any live dependency remains
([Server #519](https://github.com/durable-workflow/server/issues/519)).

Discover support by requiring `capabilities.worker_build_retirement` to be
exactly `true` on `GET /api/cluster/info`. Published PHP Server does not serve
these routes. Use an operator or admin token and the selected namespace, like
the other build-id and deployment routes.

## Routes

| Route | Scope |
| --- | --- |
| `GET /api/deployments/{namespace}/{task_queue}@{build_id}/retirement` | One build on one queue (`@unversioned` for the unversioned cohort). Read-only. |
| `POST /api/deployments/{namespace}/{task_queue}@{build_id}/retire` | Guarded build retirement. |
| `GET /api/task-queues/{task_queue}/retirement` | Every cohort on one queue. Read-only. |
| `POST /api/task-queues/{task_queue}/retire` | Guarded queue retirement. |

The retire body is optional. It may name `expected_inventory_revision`, the
`observation.inventory_revision` from an inspection. If anything the
inspection observed has changed, the action is refused with
`inventory_changed`.

## What counts as a live dependency

The inventory is scoped to the namespace and the task queue, and for a build to
runs pinned to that build id (an unversioned cohort is a run with no pin):

- `open_runs`: pending, running and waiting runs, including passive parked
  runs. Detail fields count `waiting_runs`, `continued_runs` (continue-as-new
  successors, which keep their pin) and `child_runs`.
- `ready_tasks`, `leased_tasks` and `delayed_tasks`: open workflow, activity
  and timer tasks. `delayed_tasks` details `activity_retries` and `timers`
  whose backoff or fire time has not come yet. An empty ready-task count alone
  is never enough.
- `open_activities`: pending or running activities recorded on the queue, or
  belonging to a run on it, with their `recorded_task_queues`.
- `update_validation_tasks`: pending or leased update validations.
- `schedules` (queue scope only): schedules whose action starts runs on the
  queue (a blank queue is `default`).
- `unattributed_runs`: open runs in the namespace with no recorded task queue.
  They could depend on any queue, so any of them makes `observation.status`
  `incomplete`, and retirement is unavailable.

Each blocker gives its exact `count`, up to 20 sample identities with
`sample_truncated`, and a `recovery` action. `counts` lists every category,
including zeros.

## Refusals

A refused retire answers HTTP 409 and changes nothing. The body has the same
inventory as the inspection, plus `reason`, `rejection_reason`, `outcome`
(`rejected_<reason>`), `message`, `expected_resolution`, `command_status:
"rejected"` and `changed: false`. The first applicable reason is reported:

| `reason` | Meaning |
| --- | --- |
| `unknown_deployment` / `unknown_task_queue` | No worker registered and no rollout was recorded for the target. |
| `selected_for_new_starts` | The build is the one new starts on the queue are pinned to. Promote the replacement first. |
| `inventory_incomplete` | Unattributed open runs exist. Missing inventory is never permission to retire. |
| `live_dependencies` | At least one blocker above has a nonzero count. |
| `inventory_changed` | The request's `expected_inventory_revision` no longer matches. |

A malformed body answers 400 `malformed_json`; a non-string revision answers
422 `validation_failed`.

## Enforcement and what retirement records

The inspection is a preview (`observation.atomic_with_action: false`). The
retire action takes native's serialized transition, the same one starts,
continue-as-new, child starts, activity retries and task creation take on
every backend, observes the inventory again inside it, and writes only when it
is empty (`atomic_with_action: true`). A dependency created concurrently is
either seen by that count or created after retirement is recorded, when the
build no longer accepts it.

On success (HTTP 200, `outcome: "retired"`, `changed: true`) native records the
build's rollout intent as the terminal `drained` state. For a queue, every
cohort known on the queue is recorded. `retired_at` and `retired_by` (the
authenticated principal) are returned. Then:

- The build is never selected for new starts and cannot be promoted or resumed
  through the deployment lifecycle.
- Its registered workers are marked `draining`; they claim nothing, and stay
  draining when they register or heartbeat again.
- When every cohort on a queue is retired and no active worker remains there,
  new starts on the queue are refused with `task_queue_draining` and
  `routing_status: "retired"`, until a new active worker cohort registers.
- Recorded run pins, activity queues, history and deadlines are unchanged.
  Nothing is re-pinned or rerouted.

`POST /api/task-queues/{queue}/build-ids/resume` still sets a build back to
`active`; retirement guaranteed it had no work at that time.

## Promotion, retirement and recovery journey

1. Register workers for the new build and promote it
   (`POST /api/task-queues/{queue}/build-ids/promote`). New starts pin to it.
2. Keep the old build's workers running. They finish the runs still pinned to
   it, delayed retries, timers and continued chains.
3. Inspect `GET /api/deployments/{namespace}/{queue}@{old}/retirement` until
   `retirable` is `true`. Each blocker's `recovery` names what to do: let the
   work finish, or cancel or terminate runs you choose to abandon.
4. Retire with `POST .../retire`, optionally bound to the inspected revision.
   On a 409, nothing changed; follow `expected_resolution` and inspect again.
5. Stop the old build's processes after the retire succeeds.

The existing `drain` action is unchanged and remains the emergency stop: it
refuses the build's polls immediately, whether or not work is still pinned to
it. Use it only when you deliberately want pinned work to wait. The build-id
view (`GET /api/task-queues/{queue}/build-ids`) and run `BuildId` filters stay
manual inspection; they do not enforce anything.
