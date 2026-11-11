// Server #1102: the schedule-catalog fixture keeps live, unpaused schedules
// with real daily occurrences. A recording that spans one lets the target's
// maintenance evaluator fire a schedule, and every response then differs from
// the reviewed expectation. Before that fixture records, wait out any of its
// natural occurrences that falls inside the recording window.

// Each schedule's daily occurrence as a local wall time in its zone
// (tests/Fixtures/ServerParity/schedule-catalog.json): P1D intervals at
// 00:00 UTC, bf's PT6H offset at 06:00 UTC, alpha's `0 4 * * *` in
// America/New_York and bf's `0 3 * * *` in Europe/Budapest.
export const SCHEDULE_CATALOG_OCCURRENCES = [
  {zone: 'UTC', hour: 0},
  {zone: 'UTC', hour: 6},
  {zone: 'America/New_York', hour: 4},
  {zone: 'Europe/Budapest', hour: 3},
];
export const RECORDING_WINDOW_MS = 10 * 60 * 1000;
export const MARGIN_MS = 60 * 1000;

// The zone's UTC offset in milliseconds at `instant`.
function offsetMs(zone, instant) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {timeZone: zone, hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'})
    .formatToParts(new Date(instant)).filter(part => part.type !== 'literal').map(part => [part.type, Number(part.value)]));
  return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - instant;
}

// The UTC instant of `hour`:00 local time in `zone` on the given UTC date.
function occurrenceOn(zone, hour, year, month, day) {
  const guess = Date.UTC(year, month, day, hour);
  return guess - offsetMs(zone, guess - offsetMs(zone, guess));
}

// Milliseconds to wait before recording at `now`, 0 when no occurrence falls
// within the window.
export function scheduleCatalogWaitMs(now, occurrences = SCHEDULE_CATALOG_OCCURRENCES, windowMs = RECORDING_WINDOW_MS) {
  let wait = 0;
  for (let delta = -1; delta <= 1; delta++) {
    const date = new Date(now + delta * 86400000);
    for (const {zone, hour} of occurrences) {
      const at = occurrenceOn(zone, hour, date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
      if (at + MARGIN_MS > now && at <= now + windowMs) {
        wait = Math.max(wait, at + MARGIN_MS - now);
      }
    }
  }
  return wait;
}

// Block until no occurrence falls within the window, and say why.
export function waitOutScheduleOccurrences(label, now = Date.now()) {
  const wait = scheduleCatalogWaitMs(now);
  if (wait > 0) {
    console.error(`${label}: waiting ${Math.ceil(wait / 1000)}s for a schedule occurrence to pass before recording (Server #1102)`);
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, wait);
  }
  return wait;
}
