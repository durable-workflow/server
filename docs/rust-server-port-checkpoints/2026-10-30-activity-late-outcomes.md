# October 30, activity outcomes after a deadline

- **The gap as recorded.** Written October 21 against the reference of the
  time: "Published PHP records a completion that arrives after an activity
  deadline has passed when its separate timeout command has not yet closed
  the attempt. Native refuses it."
- **Published 2.11.7, measured.** Over raw worker HTTP, each case scheduled
  an activity with a 2-second deadline, claimed it, waited 3.5 seconds and
  sent its outcome.
  - With the maintenance loop running:
    - start-to-close complete and fail: the timeout was already recorded,
      and the answer was `409 stale_attempt`;
    - schedule-to-close and heartbeat: the timeout was not yet recorded,
      and the answer was `409 schedule_to_close_timeout` and `409
      heartbeat_timeout`.
  - With the maintenance loop stopped, no timeout ran, and all four were
    refused `409 <kind>_timeout`.
  - Nothing was recorded in any case.

  So 2.11.7 refuses a late outcome by the deadline itself, before any
  timeout runs, as native's outcome fence (`activity_deadlines::fence`)
  does.
- **Fixture.** `activity-late-outcomes` has four cases: start-to-close
  complete and fail, schedule-to-close complete, and heartbeat complete. The
  refusal is recorded by class (`deadline_reason` or `after_timeout`). Which
  one a run sees races the timeout sweep on both servers, so the contract
  accepts either and nothing else. It checks the `409`, `recorded: false`,
  exactly one `ActivityTimedOut` of the deadline's kind, and the parent's
  resumption. Two fresh PHP recordings passed with different refusal mixes.
- **Native.** Unchanged.
