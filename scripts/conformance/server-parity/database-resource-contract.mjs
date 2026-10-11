import assert from 'node:assert/strict';

export function checkDatabaseResourceLimits(limits) {
  if (limits === undefined) return;
  assert(limits && typeof limits === 'object' && !Array.isArray(limits), 'database limits are an object');
  assert.deepEqual(Object.keys(limits).sort(), ['memory_bytes', 'memory_swap_bytes'], 'complete inspected database memory limits');
  for (const value of Object.values(limits)) {
    assert(Number.isSafeInteger(value) && value > 0, 'database limits are positive exact byte counts');
  }
  assert.equal(limits.memory_swap_bytes, limits.memory_bytes, 'database swap is disabled');
}

export function parseDatabaseResourceLimits(value) {
  if (value === undefined) return undefined;
  const limits = JSON.parse(value);
  checkDatabaseResourceLimits(limits);
  return limits;
}
