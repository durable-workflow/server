import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

// The reviewed native-only behavior differences (native-divergences.json).
// A contract applies one only through these helpers, so every native
// difference names its registry entry instead of branching per backend.
const registry = JSON.parse(readFileSync(new URL('./native-divergences.json', import.meta.url), 'utf8'));

/// Whether a reviewed native divergence applies to this recording target.
export function nativeDivergence(target, id) {
  assert(registry.divergences.some(divergence => divergence.id === id), `unregistered native divergence ${id}`);
  return target === 'rust';
}

const plain = value => value !== null && typeof value === 'object' && !Array.isArray(value);

function overlay(base, change) {
  if (!plain(base) || !plain(change)) return change;
  const merged = {...base};
  for (const [key, value] of Object.entries(change)) {
    assert(Object.hasOwn(base, key), `native_expected names ${key}, which the published expectation lacks`);
    merged[key] = overlay(base[key], value);
  }
  return merged;
}

/// A fixture section's expectation for this target. For the native target,
/// the section's `native_expected` overlays its published `expected` under
/// the divergence the section names: objects merge key by key, and arrays
/// and values replace. Every overlaid key must exist in `expected`, so the
/// overlay can only change reviewed steps, never add unreviewed ones.
export function expectedFor(section, target) {
  if (section.native_expected === undefined) return section.expected;
  assert(typeof section.native_divergence === 'string', 'native_expected names its native_divergence');
  if (!nativeDivergence(target, section.native_divergence)) return section.expected;
  return overlay(section.expected, section.native_expected);
}
