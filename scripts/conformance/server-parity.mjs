#!/usr/bin/env node
import {mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {basename, dirname, join, resolve} from 'node:path';
import {parseArgs} from 'node:util';
import {checkObservation, compareRecords} from './server-parity/contract.mjs';
import {checkPublishedPatchArtifacts, checkPatchPackageObservation} from './server-parity/patch-deployment-contract.mjs';
import {checkLegacyMarkerArtifacts, checkLegacyMarkerPackageObservation} from './server-parity/legacy-marker-contract.mjs';
import {checkRangeArtifacts, checkRangePackages} from './server-parity/range-repair-contract.mjs';
import {checkSdkDirectionArtifacts, checkSdkDirectionPackages} from './server-parity/sdk-direction-contract.mjs';
import {parseDatabaseResourceLimits} from './server-parity/database-resource-contract.mjs';
import {checkSdkChildDirectionArtifacts, checkSdkChildDirectionPackages} from './server-parity/sdk-child-direction-contract.mjs';
import {checkSdkTimerAuthorArtifacts, checkSdkTimerAuthorPackages} from './server-parity/sdk-timer-author-contract.mjs';
import {checkSdkSignalDirectionArtifacts, checkSdkSignalDirectionPackages} from './server-parity/sdk-signal-direction-contract.mjs';
import {captureProbe, probeNotes} from './server-parity/probe-capture.mjs';
import {selectFixturePaths} from './server-parity/fixture-selection.mjs';
import {writeRecord} from './server-parity/record-file.mjs';
import {checkCorrectedMarkerArtifacts, checkCorrectedMarkerPackages} from './server-parity/corrected-marker-contract.mjs';
import {checkCorrectedRangeArtifacts, checkCorrectedRangePackages} from './server-parity/corrected-range-contract.mjs';
import {checkActivityDeadlinePackages} from './server-parity/activity-deadline-contract.mjs';
import {checkActivityDeadlineReceiptPackages} from './server-parity/activity-deadline-receipts-contract.mjs';
import {contractFailureLocation} from './server-parity/failure-location.mjs';
import {failureExcerpt} from './server-parity/failure-excerpt.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const {values, positionals} = parseArgs({allowPositionals: true, options: {
  mode: {type: 'string'}, url: {type: 'string'}, 'application-root': {type: 'string'},
  target: {type: 'string'}, output: {type: 'string'}, 'runner-revision': {type: 'string', default: process.env.DW_PARITY_RUNNER_REVISION ?? ''},
  artifacts: {type: 'string'}, prefix: {type: 'string', default: 'parity-v1'}, help: {type: 'boolean'},
  fixture: {type: 'string', multiple: true},
  'database-resource-limits': {type: 'string'},
  'include-fixture': {type: 'string', multiple: true},
  'exclude-fixture': {type: 'string', multiple: true},
}});
const [command, ...files] = positionals;
if (values.help) {
  console.log(`Usage:
  node scripts/conformance/server-parity.mjs record --mode http --url http://server:8080 --target php --runner-revision SHA --output php.json
  node scripts/conformance/server-parity.mjs record --mode embedded --application-root /app --target embedded --runner-revision SHA --output embedded.json
  node scripts/conformance/server-parity.mjs compare php.json embedded.json [rust.json]

Requires Node 20+ and PHP 8.3+ with the locked adapter installed. Each target
must have its own isolated, already bootstrapped database. Set DW_PARITY_TOKEN
for the HTTP target; it is never recorded. Use the same --prefix on all targets
and a fresh database or prefix for a new run. --artifacts selects the exact
consumer tuple; the default is tests/Fixtures/ServerParityBaselines/php-2.11.8.json.
Repeat --fixture PATH to record or compare an explicitly selected candidate
set. --database-resource-limits accepts inspected memory_bytes and
memory_swap_bytes as a JSON object; comparisons require equal effective
limits. Frozen manifests keep their original qualification envelopes.
Default commands still use only the reviewed ServerParity corpus. Compare
selected records with the same --fixture paths so current source bytes remain
authoritative; selected candidates do not enlarge the default passing corpus.
--include-fixture PATH adds an explicit candidate to the reviewed default set;
use that same option when comparing the combined recordings. It cannot be
combined with --fixture and does not change default fixture discovery.
--exclude-fixture PATH removes one fixture of that set because a separate,
explicitly selected record covers it. It must name a selected fixture, the
comparison must use the same option, and it cannot be combined with --fixture.`);
  process.exit(0);
}
const json = path => JSON.parse(readFileSync(path, 'utf8'));
try {
  const directory = resolve(root, 'tests/Fixtures/ServerParity');
  const paths = selectFixturePaths({root, directory, fixture: values.fixture, include: values['include-fixture'], exclude: values['exclude-fixture']});
  if (paths.some(path => !path.endsWith('.json')) || new Set(paths.map(path => basename(path))).size !== paths.length) throw new Error('Selected fixtures require distinct JSON filenames');
  const byName = Object.fromEntries(paths.map(path => [basename(path), path]));
  const fixtures = Object.keys(byName).sort();
  const hashes = Object.fromEntries(fixtures.map(name => [name, createHash('sha256').update(readFileSync(byName[name])).digest('hex')]));
  if (command === 'compare') {
    const expectedFixtures = Object.fromEntries(fixtures.map(name => [name, json(byName[name])]));
    compareRecords(files.map(json), hashes, expectedFixtures, values.artifacts ? json(values.artifacts) : undefined);
    console.log(JSON.stringify({outcome: 'pass', recordings: files.length}));
  } else if (command === 'record') {
    if (!['http', 'embedded'].includes(values.mode) || !values.output || !values.target || !/^[a-f0-9]{40}$/.test(values['runner-revision'] ?? '')) {
      throw new Error('record requires --mode, --target, --output and a full --runner-revision SHA');
    }
    if (values.mode === 'http' && !values.url || values.mode === 'embedded' && !values['application-root']) {
      throw new Error('HTTP mode requires --url; embedded mode requires --application-root');
    }
    const record = {
      schema: 'durable-workflow.server-parity-record/v1', target: values.target, mode: values.mode,
      runner_revision: values['runner-revision'], artifacts: json(values.artifacts ?? resolve(root, 'tests/Fixtures/ServerParityBaselines/php-2.11.8.json')),
      started_at: new Date().toISOString(), outcome: 'runner-blocked', fixture_hashes: hashes, cases: [],
      database_resource_limits: parseDatabaseResourceLimits(values['database-resource-limits']),
    };
    const sourceDirectory = mkdtempSync(join(dirname(resolve(values.output)), '.record-sources-'));
    const originals = new Map();
    try {
      for (const name of fixtures) {
        const path = byName[name];
        const fixture = json(path);
        checkPublishedPatchArtifacts(fixture, record.artifacts);
        checkRangeArtifacts(fixture, record.artifacts);
        checkCorrectedMarkerArtifacts(fixture, record.artifacts);
        checkCorrectedRangeArtifacts(fixture, record.artifacts);
        checkSdkDirectionArtifacts(fixture, record.artifacts);
        checkSdkChildDirectionArtifacts(fixture, record.artifacts);
        checkSdkTimerAuthorArtifacts(fixture, record.artifacts);
        checkSdkSignalDirectionArtifacts(fixture, record.artifacts);
        if (fixture.patch_deployment?.legacy_marker_history) checkLegacyMarkerArtifacts(fixture, record.artifacts);
        const workflowId = `${values.prefix}-${fixture.id}`;
        const arguments_ = [resolve(root, 'scripts/conformance/server-parity/probe.php'), '--mode', values.mode, '--fixture', path, '--workflow-id', workflowId];
        for (const flag of ['url', 'application-root']) {
          if (values[flag]) arguments_.push(`--${flag}`, values[flag]);
        }
        const probe = captureProbe('php', arguments_, {directory: dirname(resolve(values.output)), timeout: fixture.memo_replay ? fixture.memo_replay.overall_budget_seconds * 1000 + 5000 : fixture.corrected_markers ? fixture.corrected_markers.overall_budget_seconds * 1000 + 5000 : fixture.namespace_signal_pair || fixture.selected_run_signal_pair || fixture.cancellation_scopes ? 75000 : fixture.update_validation ? 90000 : fixture.worker_poll_fence ? fixture.worker_poll_fence.overall_budget_seconds * 1000 + 5000 : fixture.worker_sessions ? fixture.worker_sessions.overall_budget_seconds * 1000 + 5000 : fixture.worker_session_activities ? fixture.worker_session_activities.overall_budget_seconds * 1000 + 5000 : fixture.large_command_batches ? fixture.large_command_batches.overall_budget_seconds * 1000 + 5000 : fixture.many_updates ? fixture.many_updates.overall_budget_seconds * 1000 + 5000 : 45000,
          env: {...process.env, DW_PARITY_FAILURE_EVIDENCE_DIR: dirname(resolve(values.output))},
          preserveStdout: join(sourceDirectory, name)});
        const item = {fixture_id: fixture.id, fixture, outcome: 'runner-blocked'};
        // Recognized diagnostics are kept even when the probe succeeds.
        const notes = probeNotes(probe.stderr);
        if (notes.length > 0) {
          item.probe_notes = notes;
          for (const note of notes) console.error(`${fixture.id}: ${note}`);
        }
        originals.set(item, {fixture: path, observation: probe.stdoutPath});
        try {
          if (probe.error || probe.status !== 0) throw new Error(probe.error?.message ?? probe.stderr.trim());
          item.observation = JSON.parse(probe.stdout);
          if (item.observation.mode !== values.mode) throw new Error('Observation does not use the selected recording adapter');
          if (item.observation.sdk_php !== record.artifacts.sdk_php || values.mode === 'embedded' && item.observation.workflow_package !== record.artifacts.workflow) {
            throw new Error('Installed SDK/Workflow packages do not match the frozen tuple');
          }
          if (record.artifacts.sdk_php_source_commit && item.observation.sdk_php_source !== record.artifacts.sdk_php_source_commit) {
            throw new Error('Installed SDK source does not match the selected source-feature tuple');
          }
          if (fixture.patch_deployment?.legacy_marker_history) checkLegacyMarkerPackageObservation(fixture, item.observation, record.artifacts);
          else checkPatchPackageObservation(fixture, item.observation, record.artifacts);
          checkRangePackages(fixture, item.observation, record.artifacts);
          checkCorrectedMarkerPackages(fixture, item.observation, record.artifacts);
          checkCorrectedRangePackages(fixture, item.observation, record.artifacts);
          checkActivityDeadlinePackages(fixture, item.observation, record.artifacts);
          checkActivityDeadlineReceiptPackages(fixture, item.observation, record.artifacts);
          checkSdkDirectionPackages(fixture, item.observation, record.artifacts);
          checkSdkChildDirectionPackages(fixture, item.observation, record.artifacts);
          checkSdkTimerAuthorPackages(fixture, item.observation, record.artifacts);
          checkSdkSignalDirectionPackages(fixture, item.observation, record.artifacts);
          item.outcome = 'product-fail';
          item.projection = checkObservation(fixture, item.observation, workflowId, record.artifacts);
          item.outcome = 'pass';
        } catch (error) {
          item.error = error.message;
          record.cases.push(item);
          // A product failure leaves the next fixtures meaningful: each uses
          // its own workflow. Report it and keep recording, so one run shows
          // every difference. A runner-blocked case still stops the record.
          if (item.outcome === 'product-fail') {
            const location = contractFailureLocation(error);
            console.error(`${fixture.id}: ${error.message}${location ? `\n  at ${location}` : ''}`);
            continue;
          }
          record.outcome = item.outcome;
          record.finished_at = new Date().toISOString();
          writeRecord(values.output, record, originals);
          // The artifact summary keeps this outline, not the record, so a
          // blocked probe still names its fixture and error.
          writeFileSync(values.output.replace(/\.json$/, '') + '.failure-excerpt.txt',
            failureExcerpt(record.cases.filter(entry => entry.outcome !== 'pass')));
          throw error;
        }
        record.cases.push(item);
      }
      if (record.cases.length === 0) throw new Error('No fixtures found');
      const failed = record.cases.filter(item => item.outcome !== 'pass').map(item => item.fixture_id);
      if (failed.length > 0) {
        record.outcome = 'product-fail';
        record.finished_at = new Date().toISOString();
        writeRecord(values.output, record, originals);
        // The artifact summary keeps this outline; it does not keep the record.
        writeFileSync(values.output.replace(/\.json$/, '') + '.failure-excerpt.txt',
          failureExcerpt(record.cases.filter(item => item.outcome !== 'pass')));
        throw new Error(`${failed.length} fixture(s) differ: ${failed.join(', ')}`);
      }
      record.outcome = 'pass';
      record.finished_at = new Date().toISOString();
      writeRecord(values.output, record, originals);
      console.log(JSON.stringify({outcome: record.outcome, target: record.target, cases: record.cases.map(c => c.fixture_id)}));
    } finally { rmSync(sourceDirectory, {recursive: true, force: true}); }
  } else {
    throw new Error('Expected record or compare; see --help');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
