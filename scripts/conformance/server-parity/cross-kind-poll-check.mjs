import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCrossKindPollWake} from './cross-kind-poll-contract.mjs';

const [profilePath, observationPath] = process.argv.slice(2);
if (!profilePath || !observationPath || process.argv.length !== 4) {
  throw new Error('Usage: cross-kind-poll-check.mjs PROFILE_JSON OBSERVATIONS_NDJSON');
}
const profile = JSON.parse(readFileSync(profilePath, 'utf8'));
const records = readFileSync(observationPath, 'utf8').trim().split('\n').map(line => JSON.parse(line));
assert.deepEqual(records.map(record => record.kind), ['activity', 'query']);
const cases = records.map(record => checkCrossKindPollWake(record, profile));
console.log(JSON.stringify({outcome: 'pass', cases}));
