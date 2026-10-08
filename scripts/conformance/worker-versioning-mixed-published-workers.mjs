#!/usr/bin/env node
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { createHash, randomUUID } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const languages = [['rust', 'php'], ['php', 'rust'], ['rust', 'python'], ['python', 'rust']];
const directory = process.env.DW_WV_RESULT_DIR ?? '/result';
const fixture = path.dirname(fileURLToPath(import.meta.url));
const binary = path.join(fixture, 'worker-versioning-rust-probe');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const identity = (task) => ({ workflow_id:task?.workflow_id ?? task?.workflow_instance_id,
  run_id:task?.run_id ?? task?.workflow_run_id });
const sameRun = (a, b) => a?.workflow_id === b?.workflow_id && a?.run_id === b?.run_id;

export function mixedVersioningPasses(report, versions) {
  try {
    assert.equal(report.schema, 'durable-workflow.conformance.mixed-worker-versioning');
    assert.equal(report.version, 1);
    assert.equal(report.outcome, 'pass');
    assert.equal(report.worker_execution, 'managed_php_python_rust_workers');
    assert.equal(report.local_product_source_checkouts_used, false);
    for (const name of ['server', 'sdk-php', 'sdk-python', 'sdk-rust']) {
      assert.ok(/^\d+\.\d+\.\d+$/.test(versions[name] ?? ''));
      assert.equal(report.artifact_versions[name], versions[name]);
    }
    assert.ok(/^durableworkflow\/server@sha256:[a-f0-9]{64}$/.test(report.server_image));
    const packages = report.registry_packages;
    assert.equal(packages.php.version, versions['sdk-php']);
    assert.ok(packages.php.dist?.url && packages.php.dist.reference);
    assert.equal(packages.python.metadata.version, versions['sdk-python']);
    assert.ok(/^[a-f0-9]{64}$/.test(packages.python.download_info?.archive_info?.hashes?.sha256 ?? ''));
    assert.equal(packages.rust.version, versions['sdk-rust']);
    assert.equal(packages.rust.source, 'registry+https://github.com/rust-lang/crates.io-index');
    assert.ok(/^[a-f0-9]{64}$/.test(packages.rust.checksum));
    assert.equal(report.cells.length, languages.length);
    for (const [v1, v2] of languages) {
      const cell = report.cells.find((row) => row.key === `${v1}_v1_${v2}_v2`);
      assert.equal(cell.outcome, 'pass');
      assert.equal(cell.v1_language, v1);
      assert.equal(cell.v2_language, v2);
      assert.ok(cell.old.workflow_id && cell.old.run_id && cell.new.workflow_id && cell.new.run_id);
      assert.notEqual(cell.old.run_id, cell.new.run_id);
      assert.ok(cell.v1_build && cell.v2_build && cell.v1_build !== cell.v2_build);
      assert.equal(cell.original.run_id, cell.old.run_id);
      assert.equal(cell.original.compatibility, cell.v1_build);
      assert.equal(cell.registered.length, 2);
      [v1, v2].forEach((language, index) => {
        assert.ok(cell.registered[index].registered && cell.registered[index].pid > 0);
        assert.ok(cell.registry.workers.some((worker) => worker.worker_id === cell.worker_ids[index]
          && worker.runtime === language && worker.build_id === cell[`v${index + 1}_build`]
          && worker.sdk_version.endsWith(`/${versions[`sdk-${language}`]}`)));
      });
      for (const [name, language] of [['old', v2], ['new', v1]]) {
        const polls = cell[`incompatible_${name}_polls`];
        assert.ok(polls.length >= 2);
        assert.ok(polls.every((poll) => language === 'rust' ? poll.processed === 0
          : poll.kind === 'poll' && poll.task === null));
      }
      assert.equal(cell.completed.length, 2);
      for (const [index, run] of [cell.old, cell.new].entries()) {
        const language = [v1, v2][index];
        const waiting = cell[index === 0 ? 'old_waiting' : 'new_waiting'];
        const completion = cell.completed.find((row) => sameRun(row.run, run));
        assert.equal(completion.language, language);
        for (const [observation, status] of [[waiting, 'waiting'], [completion, 'completed']]) {
          assert.equal(observation.description.run_id, run.run_id);
          assert.equal(observation.description.compatibility, cell[`v${index + 1}_build`]);
          assert.equal(observation.description.status, status);
        }
        assert.equal(completion.result.producer, `${language}-v${index + 1}`);
        for (const type of ['SideEffectRecorded', 'WorkflowCompleted']) {
          assert.equal(completion.history.events.filter((event) => event.event_type === type).length, 1);
        }
        if (language === 'rust') {
          assert.ok(waiting.observation.processed > 0);
          assert.ok(waiting.observation.callbacks.some((callback) => sameRun(callback, run)));
          assert.ok(completion.observation.processed > 0);
          assert.equal(completion.observation.side_effect_calls, 1);
          assert.ok(completion.observation.callbacks.length > 0);
          assert.ok(completion.observation.callbacks.every((callback) => sameRun(callback, run)));
          assert.equal(completion.observation.callbacks.at(-1).boundary, 'completed');
        } else {
          const records = completion.observation;
          const tasks = records.filter((row) => row.kind === 'poll' && row.task !== null).map((row) => identity(row.task));
          assert.ok(tasks.length > 0 && tasks.every((task) => sameRun(task, run)));
          assert.equal(records.filter((row) => row.kind === 'producer' && row.workflow_id === run.workflow_id).length, 1);
          assert.ok(!records.some((row) => row.kind === 'error'));
        }
      }
    }
    return true;
  } catch { return false; }
}

class Process {
  constructor(language, args, label) {
    this.language = language;
    this.tracePath = path.join(directory, `${label}.jsonl`);
    fs.writeFileSync(this.tracePath, '');
    const command = language === 'rust' ? binary : language === 'php' ? 'php' : 'python';
    const script = path.join(fixture, `worker-versioning-mixed-${language}-worker.${language === 'php' ? 'php' : 'py'}`);
    this.child = spawn(command, language === 'rust' ? args : [script, ...args, this.tracePath],
      { stdio:['pipe', 'pipe', fs.openSync(path.join(directory, `${label}.log`), 'w')] });
    this.lines = [];
    readline.createInterface({ input:this.child.stdout }).on('line', (line) => this.lines.push(line));
    this.exited = new Promise((resolve) => this.child.on('exit', (code, signal) => resolve({ code, signal })));
  }
  async response() {
    const deadline = Date.now() + 20000;
    while (!this.lines.length) {
      assert.equal(this.child.exitCode, null, 'SDK worker exited before its response');
      assert.equal(this.child.signalCode, null, 'SDK worker was killed before its response');
      assert.ok(Date.now() < deadline, 'SDK worker response deadline expired');
      await sleep(20);
    }
    return JSON.parse(this.lines.shift());
  }
  async poll() {
    assert.equal(this.language, 'rust');
    this.child.stdin.write(`${JSON.stringify({ action:'poll' })}\n`);
    return this.response();
  }
  records() {
    const text = fs.readFileSync(this.tracePath, 'utf8');
    const rows = text.slice(0, text.lastIndexOf('\n') + 1).split('\n').filter(Boolean).map((line) => JSON.parse(line));
    assert.ok(!rows.some((row) => row.kind === 'error'), `SDK worker errors: ${JSON.stringify(rows.filter((row) => row.kind === 'error'))}`);
    return rows;
  }
  async pause() {
    if (!fs.existsSync(`${this.tracePath}.enabled`)) {
      await until(() => this.records().some((row) => row.kind === 'paused'));
      return;
    }
    const offset = this.records().length;
    fs.unlinkSync(`${this.tracePath}.enabled`);
    await until(() => this.records().slice(offset).some((row) => row.kind === 'paused'));
  }
  enable() { fs.writeFileSync(`${this.tracePath}.enabled`, 'poll'); }
  async stop() {
    if (this.language === 'rust') {
      this.child.stdin.write(`${JSON.stringify({ action:'stop' })}\n`);
      await this.response();
    } else {
      this.enable();
      this.child.kill('SIGTERM');
    }
    const status = await Promise.race([this.exited, sleep(15000).then(() => null)]);
    assert.ok(status && status.code === 0, `SDK worker did not shut down normally: ${JSON.stringify(status)}`);
  }
  kill() { if (this.child.exitCode === null && this.child.signalCode === null) this.child.kill('SIGKILL'); }
}

async function until(condition) {
  const deadline = Date.now() + 30000;
  while (!await condition()) {
    assert.ok(Date.now() < deadline, 'mixed worker observation deadline expired');
    await sleep(100);
  }
}

async function api(method, endpoint, body) {
  const response = await fetch(`${process.env.DW_WV_SERVER_URL}${endpoint}`, {
    method, signal:AbortSignal.timeout(10000),
    headers:{ Authorization:`Bearer ${process.env.DW_WV_AUTH_TOKEN ?? 'dev-token'}`,
      'X-Namespace':process.env.DW_WV_NAMESPACE, 'X-Durable-Workflow-Control-Plane-Version':'2',
      Accept:'application/json', 'Content-Type':'application/json' },
    ...(body ? { body:JSON.stringify(body) } : {}),
  });
  const result = await response.json();
  assert.ok(response.ok, `${endpoint}: HTTP ${response.status}, ${JSON.stringify(result)}`);
  return result;
}
const show = (run) => api('GET', `/api/workflows/${run.workflow_id}/runs/${run.run_id}`);
const history = (run) => api('GET', `/api/workflows/${run.workflow_id}/runs/${run.run_id}/history`);

function client(language, request) {
  const script = path.join(fixture, `worker-versioning-mixed-${language}-worker.${language === 'php' ? 'php' : 'py'}`);
  const result = spawnSync(language === 'rust' ? binary : language === 'php' ? 'php' : 'python',
    language === 'rust' ? ['--start', request.task_queue, request.workflow_id] : [script, '--client'],
    { input:JSON.stringify(request), encoding:'utf8', timeout:30000, maxBuffer:1024 * 1024 });
  assert.equal(result.status, 0, `${language} SDK client failed: ${result.stderr}`);
  return JSON.parse(result.stdout);
}

async function wrongPoll(worker) {
  if (worker.language === 'rust') {
    const observations = [await worker.poll(), await worker.poll()];
    assert.ok(observations.every((poll) => poll.processed === 0), 'incompatible Rust worker executed a task');
    return observations;
  }
  const offset = worker.records().length;
  worker.enable();
  await until(() => worker.records().slice(offset).filter((row) => row.kind === 'poll').length >= 2);
  await worker.pause();
  const observations = worker.records().slice(offset).filter((row) => row.kind === 'poll');
  assert.ok(observations.every((poll) => poll.task === null), 'incompatible PHP/Python worker received a task');
  return observations;
}

async function drive(worker, run, status) {
  if (worker.language !== 'rust') worker.enable();
  let observation;
  let description;
  await until(async () => {
    observation = worker.language === 'rust' ? await worker.poll() : worker.records();
    description = await show(run);
    assert.ok(!['failed', 'terminated', 'cancelled'].includes(description.status), `workflow ended unexpectedly: ${JSON.stringify(description)}`);
    const callbacks = worker.language === 'rust' ? observation.callbacks
      : observation.filter((row) => row.kind === 'callback');
    return description.status === status && callbacks.some((row) => row.workflow_id === run.workflow_id);
  });
  return { description, observation };
}

function observedTask(row) {
  if (row.kind !== 'poll' || row.task === null) return null;
  const run = identity(row.task);
  assert.ok(run.workflow_id && run.run_id, 'SDK poll returned an unidentified task');
  return run;
}

async function cell(v1Language, v2Language) {
  const key = `${v1Language}_v1_${v2Language}_v2`;
  const suffix = randomUUID().replaceAll('-', '');
  const queue = `mixed-build-${suffix}`;
  const builds = [`v1-${suffix}`, `v2-${suffix}`];
  const ids = [`worker-v1-${suffix}`, `worker-v2-${suffix}`];
  const workers = [v1Language, v2Language].map((language, index) => new Process(language,
    ['--worker', queue, ids[index], builds[index], `${language}-v${index + 1}`], `${key}-v${index + 1}`));
  const peer = workers.find((worker) => worker.language !== 'rust');
  try {
    const registered = await Promise.all(workers.map((worker) => worker.response()));
    registered.forEach((row, index) => {
      assert.equal(row.registered, true);
      assert.equal(row.pid, workers[index].child.pid, 'registration came from a different process');
    });
    await peer.pause();
    const registry = await api('GET', `/api/workers?task_queue=${queue}`);
    [v1Language, v2Language].forEach((language, index) => {
      assert.ok(registry.workers.some((row) => row.worker_id === ids[index]
        && row.runtime === language && row.build_id === builds[index]
        && row.sdk_version.endsWith(`/${process.env[`DW_${language.toUpperCase()}_SDK_VERSION`]}`)), 'published worker/build/SDK identity missing');
    });
    await api('POST', `/api/task-queues/${queue}/build-ids/promote`, { build_id:builds[0] });
    const old = client(v1Language, { action:'start', task_queue:queue, workflow_id:`mixed-old-${suffix}` });
    const original = await show(old);
    assert.equal(original.compatibility, builds[0]);
    assert.equal(original.run_id, old.run_id);
    const incompatibleOld = await wrongPoll(workers[1]);
    const oldWaiting = await drive(workers[0], old, 'waiting');
    await peer.pause();
    const promotion = await api('POST', `/api/task-queues/${queue}/build-ids/promote`, { build_id:builds[1] });
    const newer = client(v2Language, { action:'start', task_queue:queue, workflow_id:`mixed-new-${suffix}` });
    assert.equal((await show(newer)).compatibility, builds[1]);
    assert.equal((await show(old)).compatibility, builds[0]);
    const incompatibleNew = await wrongPoll(workers[0]);
    const newWaiting = await drive(workers[1], newer, 'waiting');
    const completed = [];
    for (const [worker, run] of [[workers[1], newer], [workers[0], old]]) {
      if (worker.language === 'rust') {
        for (const signal of ['ready', 'finish', 'settle']) client('python', { action:'signal', ...run, signal });
      } else client('python', { action:'signal', ...run, signal:'finish' });
      const completion = await drive(worker, run, 'completed');
      const result = client('python', { action:'result', ...run });
      const durable = await history(run);
      const index = workers.indexOf(worker);
      assert.deepEqual(result, { producer:`${worker.language}-v${index + 1}` });
      assert.equal(completion.description.run_id, run.run_id);
      assert.equal(completion.description.compatibility, builds[index]);
      for (const type of ['SideEffectRecorded', 'WorkflowCompleted']) {
        assert.equal(durable.events.filter((event) => event.event_type === type).length, 1, `duplicate ${type}`);
      }
      if (worker.language !== 'rust') {
        const records = worker.records();
        assert.ok(records.some((row) => sameRun(observedTask(row), run)), 'compatible SDK never received this run');
        assert.equal(records.filter((row) => row.kind === 'producer' && row.workflow_id === run.workflow_id).length, 1);
        assert.ok(records.filter((row) => observedTask(row)).every((row) => sameRun(observedTask(row), run)), 'foreign SDK received the opposite cohort');
      } else {
        assert.equal(completion.observation.side_effect_calls, 1);
        assert.ok(completion.observation.callbacks.every((callback) => sameRun(callback, run)), 'Rust executed the opposite cohort');
      }
      completed.push({ language:worker.language, run, result, history:durable, ...completion });
    }
    await workers[0].stop();
    await workers[1].stop();
    return { key, outcome:'pass', task_queue:queue, worker_ids:ids, v1_language:v1Language, v2_language:v2Language,
      v1_build:builds[0], v2_build:builds[1], registry, registered, old, new:newer,
      original, promotion, incompatible_old_polls:incompatibleOld, incompatible_new_polls:incompatibleNew,
      old_waiting:oldWaiting, new_waiting:newWaiting, completed };
  } finally { workers.forEach((worker) => worker.kill()); }
}

async function main() {
  fs.mkdirSync(directory, { recursive:true });
  const startedAt = new Date().toISOString();
  const cells = [];
  try {
    for (const pair of languages) cells.push(await cell(...pair));
    const phpLock = JSON.parse(fs.readFileSync('/opt/php/composer.lock', 'utf8'));
    const pythonInstall = JSON.parse(fs.readFileSync('/opt/python/install-report.json', 'utf8'));
    fs.copyFileSync('/opt/php/composer.lock', path.join(directory, 'worker-versioning-mixed-composer.lock'));
    fs.copyFileSync('/opt/python/install-report.json', path.join(directory, 'worker-versioning-mixed-python-install.json'));
    const report = {
      schema:'durable-workflow.conformance.mixed-worker-versioning', version:1, outcome:'pass',
      started_at:startedAt, finished_at:new Date().toISOString(),
      worker_execution:'managed_php_python_rust_workers', local_product_source_checkouts_used:false,
      runner_commit:process.env.DW_WV_RUNNER_COMMIT, artifact_versions:{ server:process.env.DW_SERVER_VERSION,
        'sdk-php':process.env.DW_PHP_SDK_VERSION, 'sdk-python':process.env.DW_PYTHON_SDK_VERSION,
        'sdk-rust':process.env.DW_RUST_SDK_VERSION }, server_image:process.env.DW_SERVER_IMAGE,
      registry_packages:{ php:phpLock.packages.find((pkg) => pkg.name === 'durable-workflow/sdk'),
        python:pythonInstall.install.find((pkg) => pkg.metadata.name === 'durable-workflow'),
        rust:JSON.parse(fs.readFileSync(path.join(directory, 'worker-versioning-rust-result.json'), 'utf8')).registry_package },
      runtimes:Object.fromEntries(['php', 'python', 'node'].map((language) => [language,
        spawnSync(language, ['--version'], { encoding:'utf8' }).stdout.trim()])),
      fixture_sha256:Object.fromEntries(['worker-versioning-mixed-published-workers.mjs',
        'worker-versioning-mixed-php-worker.php', 'worker-versioning-mixed-python-worker.py',
        'worker-versioning-rust-probe'].map((file) => [file,
        createHash('sha256').update(fs.readFileSync(path.join(fixture, file))).digest('hex')])), cells,
    };
    assert.ok(mixedVersioningPasses(report, report.artifact_versions), 'mixed observations do not prove all four selected pairs');
    fs.writeFileSync(path.join(directory, 'worker-versioning-mixed-result.json'), `${JSON.stringify(report, null, 2)}\n`);
  } catch (error) {
    fs.writeFileSync(path.join(directory, 'worker-versioning-mixed-result.json'), `${JSON.stringify({
      schema:'durable-workflow.conformance.mixed-worker-versioning', version:1, outcome:'fail',
      started_at:startedAt, finished_at:new Date().toISOString(), error:error.message, cells,
    }, null, 2)}\n`);
    throw error;
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((error) => { console.error(error); process.exitCode = 1; });
}
