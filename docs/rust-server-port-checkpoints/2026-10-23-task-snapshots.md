# October 23, task snapshots on history events

- **The gap.** Published PHP's `WorkflowHistoryEvent::record` adds a `task`
  snapshot to every event it records with the task model in hand. Native
  added snapshots event by event and missed several. Driving one workflow
  through a side effect, an activity, a timer, a child, a signal wait and a
  condition wait against both servers showed that native had no snapshot on:
  - `TimerFired`, `ChildWorkflowScheduled`, `ChildRunStarted`;
  - `SignalWaitOpened`, `ConditionWaitOpened`, `ConditionWaitSatisfied`;
  - `SignalApplied` and the instance `MessageCursorAdvanced`.

  Native's own snapshots on `TimerScheduled`, `ActivityStarted`,
  `ActivityCompleted` and `ActivityFailed` also left out fields that PHP
  keeps, such as `queue`, `connection`, `available_at` and `repair_count`.
- **What native does now.**
  - When an event of a type PHP records with the task model is appended with
    a task and no snapshot of its own, native adds the task's snapshot. The
    list of those event types comes from the Workflow package's source.
  - `TimerScheduled` uses the full snapshot. The activity events' snapshots
    keep PHP's fields.
  - As `RunTimerTask` does, a timer task is leased to itself while its timer
    fires: status leased, `leased_at`, `lease_owner` set to the task id, a
    workflow-task lease, and one more attempt. Its lease is cleared once the
    timer has fired. `TimerFired` therefore carries the leased timer task.
- **How it was checked.** The same probe now finds the same events with
  snapshots on both servers, with the same fields. A new integration test
  checks the timer, child, signal-wait, signal and condition-wait events.
- **Left as they are.**
  - PHP's command snapshots carry `request_route_name`, a name Laravel
    generates at random for the deployment's cached routes. Native has no
    such name.
  - Native stamps a dispatch on every new task, so the snapshot of a task
    created inside a completion keeps `last_dispatch_attempt_at` and
    `last_dispatched_at`, which PHP's does not (Server #325 follow-up).
  - A message stream's `SignalApplied` and the condition wait it satisfies
    follow the message streams change (#709).
