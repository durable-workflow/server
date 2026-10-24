# October 23, task snapshots on history events and described commands

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
- **Described commands.** A field-by-field diff of the same workflow's
  description on both servers found two more gaps. Each command PHP
  describes names the run's `workflow_class` (`WorkflowCommand::record` keeps
  it), and says whether its payload preview is omitted
  (`payload_preview_omitted`, true only for a stored external payload over the
  size limit). Native now sends both. With fresh workers on both sides, the
  descriptions then differ only in `request_route_name` and the
  `request_fingerprint` hashed from it.
- **Security review corrections.**
  - The size check read only PHP's `external_storage` envelope. Native's
    stored envelope names its reference `external_payload`, so the flag was
    always false. It now reads both, which also corrects the run's input and
    output previews. As PHP does, an omitted preview also omits the
    command's `target_name`.
  - The query read any command payload starting with `dw-`, and SQLite's
    `LIKE` and MySQL's case-insensitive collation ignore case. It also read the
    wrong column for signals. Native keeps a signal's stored input reference
    in its signal record (`workflow_signal_records.arguments`) and a start's
    in the command row (`workflow_commands.payload`). Other commands keep
    none. The query now reads only that column for each of the two command
    types, and only when its first 30 characters equal native's stored prefix
    exactly: `COLLATE BINARY` on SQLite, a binary cast on MySQL and MariaDB,
    and PostgreSQL's case-sensitive text comparison. On MySQL 8.0 and MariaDB
    10.11 the binary cast refuses the upper-case prefix that the column's
    collation would match.
  - A task snapshot is read only from a task of the event's own run.
  - Tests:
    - a real uploaded reference on a signal, found in the signal record and
      not in the command row;
    - that signal record rewritten with the same reference at the 2 MiB limit
      (not omitted) and one byte over (omitted, with no target name). The
      described command never includes the reference;
    - counterfactuals that are not native references: an upper-case or v2
      prefix, the prefix with no valid reference after it, and a large inline
      payload starting with `dw-`. An oversized reference in the signal's
      command row is not counted either;
    - the same sizes and counterfactuals in a start command's row;
    - on SQLite, the query itself returns only the exact prefix in the
      command type's own column;
    - an event recorded on one run with another run's task carries no
      snapshot.

    Each test fails without its correction.
- **Left as they are.**
  - PHP's command snapshots carry `request_route_name`, a name Laravel
    generates at random for the deployment's cached routes. Native has no
    such name.
  - Native stamps a dispatch on every new task, so the snapshot of a task
    created inside a completion keeps `last_dispatch_attempt_at` and
    `last_dispatched_at`, which PHP's does not (Server #325 follow-up).
  - A message stream's `SignalApplied` and the condition wait it satisfies
    follow the message streams change (#709).
