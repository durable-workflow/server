import assert from 'node:assert/strict';

// Native describe actions also report Core's redrive decision (server#980,
// Core workflow#990): `can_redrive` and `redrive_blocked_reason`. Published
// PHP 2.x has neither. For the native target each actions object must carry
// both, consistently, and they are then projected away so the rest compares
// exactly with the published recording. Exact values are pinned by the
// native redrive tests.
export function withoutNativeRedriveActions(value, target, label) {
  if (Array.isArray(value)) return value.map((item) => withoutNativeRedriveActions(item, target, label));
  if (!value || typeof value !== 'object') return value;
  const result = {};
  for (const [key, item] of Object.entries(value)) {
    if (key === 'actions' && item && typeof item === 'object' && Object.hasOwn(item, 'can_archive')) {
      const {can_redrive: can, redrive_blocked_reason: reason, ...rest} = item;
      if (target === 'rust') {
        assert.equal(typeof can, 'boolean', `${label}: native can_redrive`);
        assert.ok(reason === null || (typeof reason === 'string' && reason !== ''), `${label}: native redrive_blocked_reason`);
        assert.equal(can, reason === null, `${label}: can_redrive exactly when nothing blocks redrive`);
        result[key] = rest;
      } else {
        result[key] = item;
      }
      continue;
    }
    result[key] = withoutNativeRedriveActions(item, target, label);
  }
  return result;
}
