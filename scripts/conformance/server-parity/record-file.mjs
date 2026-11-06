import {appendFileSync, closeSync, openSync, readSync, writeFileSync} from 'node:fs';

function appendOriginal(path, source) {
  const descriptor = openSync(source, 'r');
  const buffer = Buffer.alloc(64 * 1024);
  try {
    let size;
    while ((size = readSync(descriptor, buffer)) !== 0) appendFileSync(path, buffer.subarray(0, size));
  } finally { closeSync(descriptor); }
}

function appendCase(path, item, originals) {
  const fields = Object.entries(item).filter(([, value]) => value !== undefined);
  appendFileSync(path, '{\n');
  for (const [index, [key, value]] of fields.entries()) {
    appendFileSync(path, `${JSON.stringify(key)}: `);
    const source = (key === 'fixture' || key === 'observation') ? originals?.[key] : null;
    if (source) appendOriginal(path, source);
    else appendFileSync(path, JSON.stringify(value, null, 2));
    appendFileSync(path, index + 1 === fields.length ? '\n' : ',\n');
  }
  appendFileSync(path, '}');
}

// Serialize one case at a time: full wire records must not require another
// complete in-memory copy of the whole multi-case recording.
export function writeRecord(path, record, originals = new Map()) {
  writeFileSync(path, '{\n');
  const entries = Object.entries(record).filter(([, value]) => value !== undefined);
  for (const [index, [key, value]] of entries.entries()) {
    appendFileSync(path, `${JSON.stringify(key)}: `);
    if (key === 'cases') {
      appendFileSync(path, '[\n');
      for (const [caseIndex, item] of value.entries()) {
        // Parsed JavaScript numbers cannot reproduce original int64 literals.
        // Validated source JSON is copied without parsing or extra body copies.
        appendCase(path, item, originals.get(item));
        appendFileSync(path, caseIndex + 1 === value.length ? '\n' : ',\n');
      }
      appendFileSync(path, ']');
    } else appendFileSync(path, JSON.stringify(value, null, 2));
    appendFileSync(path, index + 1 === entries.length ? '\n}\n' : ',\n');
  }
}

// Retain the validated fixture inventory without raw observations or payloads.
export function writeFixtureSummary(path, record) {
  const summary = {
    schema: 'durable-workflow.server-parity-fixture-summary.v1',
    outcome: record.outcome, target: record.target, mode: record.mode,
    runner_revision: record.runner_revision,
    fixture_hashes: record.fixture_hashes,
    cases: record.cases.map(item => ({fixture_id: item.fixture_id, outcome: item.outcome})),
  };
  writeFileSync(path.replace(/\.json$/, '') + '-fixtures.json', `${JSON.stringify(summary, null, 2)}\n`);
}
