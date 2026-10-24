#!/usr/bin/env node
// The Shared Server fixtures result. NEEDS is the result job's `needs`
// context as JSON.
//
// Passes only when every fixture job passed, or when the classifier reported
// that no fixture input changed and every fixture job was skipped for that
// reason. A failed, cancelled or unexpectedly skipped job fails the result,
// and so does anything that cannot be read.
import {pathToFileURL} from 'node:url';

export function decide(needs) {
  if (needs === null || typeof needs !== 'object' || Array.isArray(needs)) {
    return {pass: false, reason: 'The needs context is not an object.'};
  }
  const {changes, ...fixtureJobs} = needs;
  const results = Object.entries(fixtureJobs).map(([job, value]) => [job, value?.result]);
  const summary = results.map(([job, result]) => `${job}=${result}`).join(', ');
  if (changes?.result !== 'success') {
    return {pass: false, reason: `The change classifier did not succeed (${changes?.result}).`};
  }
  if (results.length === 0) {
    return {pass: false, reason: 'No fixture jobs reported.'};
  }
  const run = changes.outputs?.run;
  const expected = {true: 'success', false: 'skipped'}[run];
  if (expected === undefined) {
    return {pass: false, reason: `The change classifier reported no decision (${run}).`};
  }
  const unexpected = results.filter(([, result]) => result !== expected);
  if (unexpected.length > 0) {
    return {
      pass: false,
      reason: `Every fixture job must be ${expected} when run=${run}: ${unexpected.map(([job, result]) => `${job}=${result}`).join(', ')}.`,
    };
  }
  return {
    pass: true,
    reason: run === 'true' ? `Every fixture job passed: ${summary}.` : `No fixture input changed; every fixture job was skipped: ${summary}.`,
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  let decision;
  try {
    decision = decide(JSON.parse(process.env.NEEDS ?? ''));
  } catch (error) {
    decision = {pass: false, reason: `The needs context could not be read: ${error.message}`};
  }
  (decision.pass ? console.log : console.error)(decision.reason);
  process.exitCode = decision.pass ? 0 : 1;
}
