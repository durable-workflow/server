#!/usr/bin/env node
import {readFileSync, writeFileSync, readdirSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {dirname, resolve} from 'node:path';
import {parseArgs} from 'node:util';
import {checkObservation, compareRecords} from './server-parity/contract.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const {values, positionals} = parseArgs({allowPositionals: true, options: {
  mode: {type: 'string'}, url: {type: 'string'}, 'application-root': {type: 'string'},
  target: {type: 'string'}, output: {type: 'string'}, 'runner-revision': {type: 'string', default: process.env.DW_PARITY_RUNNER_REVISION ?? ''},
  artifacts: {type: 'string'}, prefix: {type: 'string', default: 'parity-v1'}, help: {type: 'boolean'},
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
consumer tuple; the default is tests/Fixtures/ServerParity/php-baseline.json.`);
  process.exit(0);
}
const json = path => JSON.parse(readFileSync(path, 'utf8'));
try {
  const directory = resolve(root, 'tests/Fixtures/ServerParity');
  const fixtures = readdirSync(directory).filter(name => name !== 'php-baseline.json' && name.endsWith('.json')).sort();
  const hashes = Object.fromEntries(fixtures.map(name => [name, createHash('sha256').update(readFileSync(resolve(directory, name))).digest('hex')]));
  if (command === 'compare') {
    compareRecords(files.map(json), hashes);
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
      runner_revision: values['runner-revision'], artifacts: json(values.artifacts ?? resolve(directory, 'php-baseline.json')),
      started_at: new Date().toISOString(), outcome: 'runner-blocked', fixture_hashes: hashes, cases: [],
    };
    for (const name of fixtures) {
      const path = resolve(directory, name);
      const fixture = json(path);
      const workflowId = `${values.prefix}-${fixture.id}`;
      const arguments_ = [resolve(root, 'scripts/conformance/server-parity/probe.php'), '--mode', values.mode, '--fixture', path, '--workflow-id', workflowId];
      for (const flag of ['url', 'application-root']) {
        if (values[flag]) arguments_.push(`--${flag}`, values[flag]);
      }
      const probe = spawnSync('php', arguments_, {encoding: 'utf8', timeout: 45000, maxBuffer: 4 * 1024 * 1024});
      const item = {fixture_id: fixture.id, fixture, outcome: 'runner-blocked'};
      try {
        if (probe.error || probe.status !== 0) throw new Error(probe.error?.message ?? probe.stderr.trim());
        item.observation = JSON.parse(probe.stdout);
        if (item.observation.sdk_php !== record.artifacts.sdk_php || values.mode === 'embedded' && item.observation.workflow_package !== record.artifacts.workflow) {
          throw new Error('Installed SDK/Workflow packages do not match the frozen tuple');
        }
        item.outcome = 'product-fail';
        item.projection = checkObservation(fixture, item.observation, workflowId);
        item.outcome = 'pass';
      } catch (error) {
        item.error = error.message;
        record.cases.push(item);
        record.outcome = item.outcome;
        record.finished_at = new Date().toISOString();
        writeFileSync(values.output, JSON.stringify(record, null, 2) + '\n');
        throw error;
      }
      record.cases.push(item);
    }
    if (record.cases.length === 0) throw new Error('No fixtures found');
    record.outcome = 'pass';
    record.finished_at = new Date().toISOString();
    writeFileSync(values.output, JSON.stringify(record, null, 2) + '\n');
    console.log(JSON.stringify({outcome: record.outcome, target: record.target, cases: record.cases.map(c => c.fixture_id)}));
  } else {
    throw new Error('Expected record or compare; see --help');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
