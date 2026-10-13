import {appendFileSync, writeFileSync} from 'node:fs';

// Serialize one case at a time: full wire records must not require another
// complete in-memory copy of the whole multi-case recording.
export function writeRecord(path, record) {
  writeFileSync(path, '{\n');
  const entries = Object.entries(record).filter(([, value]) => value !== undefined);
  for (const [index, [key, value]] of entries.entries()) {
    appendFileSync(path, `${JSON.stringify(key)}: `);
    if (key === 'cases') {
      appendFileSync(path, '[\n');
      for (const [caseIndex, item] of value.entries()) {
        appendFileSync(path, JSON.stringify(item, null, 2));
        appendFileSync(path, caseIndex + 1 === value.length ? '\n' : ',\n');
      }
      appendFileSync(path, ']');
    } else appendFileSync(path, JSON.stringify(value, null, 2));
    appendFileSync(path, index + 1 === entries.length ? '\n}\n' : ',\n');
  }
}
