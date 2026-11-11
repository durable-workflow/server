import test from 'node:test';
import assert from 'node:assert/strict';
import {scheduleCatalogWaitMs, MARGIN_MS} from '../../scripts/conformance/server-parity/schedule-occurrence-guard.mjs';

const at = iso => Date.parse(iso);

test('a recording that would cross the observed 09:00 UTC alpha occurrence waits it out (EST)', () => {
  // #1102: alpha's 04:00 America/New_York fired at 09:00:00Z on 2026-11-08.
  const now = at('2026-11-08T08:55:00Z');
  assert.equal(scheduleCatalogWaitMs(now), at('2026-11-08T09:00:00Z') + MARGIN_MS - now);
});

test('daylight saving moves the New York and Budapest occurrences', () => {
  assert.equal(scheduleCatalogWaitMs(at('2026-07-08T07:55:00Z')), 6 * 60 * 1000); // 04:00 EDT = 08:00Z
  assert.equal(scheduleCatalogWaitMs(at('2026-07-08T00:55:00Z')), 6 * 60 * 1000); // 03:00 CEST = 01:00Z
  assert.equal(scheduleCatalogWaitMs(at('2026-11-08T01:55:00Z')), 6 * 60 * 1000); // 03:00 CET = 02:00Z
});

test('UTC interval occurrences and the margin after them', () => {
  assert.equal(scheduleCatalogWaitMs(at('2026-11-08T23:58:00Z')), 3 * 60 * 1000);
  assert.equal(scheduleCatalogWaitMs(at('2026-11-08T05:59:30Z')), 90 * 1000);
  // Just past an occurrence, within the margin, still waits for the margin.
  assert.equal(scheduleCatalogWaitMs(at('2026-11-09T00:00:30Z')), 30 * 1000);
});

test('a recording far from every occurrence starts at once', () => {
  assert.equal(scheduleCatalogWaitMs(at('2026-11-08T12:00:00Z')), 0);
  assert.equal(scheduleCatalogWaitMs(at('2026-11-08T07:11:00Z')), 0);
});
