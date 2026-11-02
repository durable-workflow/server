# November 2, schedules across daylight-saving changes

Server #938. Native now applies the daylight-saving rule that Core 2.15.13
publishes for embedded schedules (Workflow #882, source 42be7560).

## Why

A daily cron schedule in a zone with daylight saving was admitted twice on
the day clocks go back. The cron library repeats the wall-clock time, and the
schedule then computes its next fire from now. Native's cron search is a
faithful port of that library (`schedule_cron.rs`), so native 3.0.0-rc.3
behaved like PHP Server 2.11.8. General fixed it in Core and handed the
contract to native on #325.

## Rule

`schedule_dst.rs`, applied in `schedule_spec::next_occurrence`, which is the
path for fire execution, storage and backfill:

- If the hour field names specific hours and the library's next occurrence
  falls in the second pass of a repeated wall-clock range, search again from
  the end of that range. The time has already fired on its first pass.
- An hour field that selects every hour (`*`, `*/1`, `0/1`, `0-23`, a full
  list, `@hourly`) is classified with Core's own field matcher and keeps
  elapsed-time behavior.
- A skipped wall-clock time already fires once, shifted by the gap, in the
  library search (02:30 becomes 03:30 for a one-hour gap), so it is unchanged.
- UTC and fixed-offset zones never repeat.

## Verified

`cargo test --lib schedule_`: 19 pass. New cases:

- New York, 2026-11-01: `30 1 * * *` after the first 01:30 EDT, and from
  inside the repeated hour, next fires on November 2. Before the change, the
  first pass is chosen.
- Kyiv, 2026-10-25: `30 3 * * *` fires once.
- `*/15 * * * *`, `30 0-23 * * *` and `@hourly` keep both passes.
- New York, 2026-03-08: `30 2 * * *` fires at 03:30 EDT, then on March 9.
- The repeated-window helper: first pass, second pass, exact transition
  instant, a forward change, UTC and `+02:00`.

The 600-case PHP 2.5.14 next-fire corpus still matches; none of its cases
fall in a repeated hour with specific hours.

## Recorded difference

PHP Server 2.11.8 (Core 2.15.12) answers the repeated-hour cases with the
second pass. Native follows the published Core 2.15.13 contract instead.
