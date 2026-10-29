# October 29, descriptions after more than 64 updates

- **Before.** The October 23 change removed native's 64-update admission
  cap ([update lifetime cap](2026-10-23-update-lifetime-cap.md)). The
  update summary that describes a run's waits still stopped at 64 updates
  (`update_summary.rs`): it read every update wait over the run's life,
  `LIMIT 65`, and gave up past 64. A run that had taken 65 updates was
  described with no wait (`wait_kind` null), though its signal wait was
  still open.
- **Contract** (published 2.11.5; `WorkflowController::show`, Workflow 2.15.1
  `RunWaitView`, `StructuralLimits::pendingUpdateCountLimit`):
  - only pending updates are bounded (500 by default);
  - a run is described by its open waits, however many updates it has
    taken;
  - an accepted update that is still open is the run's wait
    ("Waiting for update set").
- **Fixture.** `many-updates`, raw worker HTTP on protocol 1.20 in a fresh
  namespace. A run opens a signal wait and takes 65 updates. Each is
  accepted (202), delivered on its own workflow task and completed (200,
  run waiting). The probe records:
  - the description before and after the updates (`signal`);
  - with a 66th update left accepted (`update`);
  - the history counts (66 `UpdateAccepted`, 65 `UpdateCompleted`).

  The worker heartbeats every 20 seconds, since the loop outlasts the
  worker staleness window. The run is terminated after recording, so its
  never-polled update task does not stay for later fixtures. The probe declares a 180-second budget; locally it took
  about 80 seconds against published PHP.
- **Native.** The summary keeps every accepted update and reads every
  update wait. Only one open update at a time is described, as before.
- **Not covered here.** The child summary still stops at 100 children
  over a run's life (`child_summary.rs`); that needs its own fixture.
- **Tests.** `many_updates.rs` fails on the previous source (`wait_kind`
  null after 65 updates) and passes now.
