#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';

const requiredCells = ['registration_build_ids', 'pinned_delivery_and_promotion',
  'cache_eviction_replay', 'no_compatible_worker', 'sigkill_cold_replay', 'drain_resume'];

// Check the observations, not just a caller-supplied pass label.
export function rustVersioningPasses(report, version) {
  if (report?.schema !== 'durable-workflow.conformance.rust-worker-versioning'
      || report.version !== 1 || report.outcome !== 'pass' || report.sdk_version !== version
      || report.worker_execution !== 'managed_rust_sdk_workers'
      || report.local_product_source_checkouts_used !== false
      || report.registry_package?.version !== version
      || report.registry_package.source !== 'registry+https://github.com/rust-lang/crates.io-index'
      || !/^[a-f0-9]{64}$/.test(report.registry_package.checksum ?? '')
      || !requiredCells.every((cell) => report.cells?.[cell])) return false;
  const cells = report.cells;
  const pin = cells.pinned_delivery_and_promotion;
  const cold = cells.sigkill_cold_replay;
  const drain = cells.drain_resume;
  const original = pin.old;
  const sameRun = (value) => value?.workflow_id === original?.workflow_id
    && value?.run_id === original?.run_id;
  const deliveredAt = (poll, identity, boundary) => poll?.processed > 0
    && poll.callbacks?.at(-1)?.workflow_id === identity?.workflow_id
    && poll.callbacks.at(-1).run_id === identity?.run_id
    && poll.callbacks.at(-1).boundary === boundary;
  const registration = cells.registration_build_ids;
  const drainRun = drain.original;
  const drainEntry = (snapshot) => snapshot?.build_ids?.find((row) => row.build_id === drain.v1_build);
  const signalCount = (snapshot, type) => snapshot?.events?.filter((event) => event.event_type === type).length;
  if (!drainRun?.workflow_id || !drainRun.run_id || !drain.v1_build || !drain.v2_build
      || drain.v1_build === drain.v2_build || !deliveredAt(drain.first, drainRun, 'ready')
      || drain.promotion?.build_id !== drain.v2_build || drain.promotion.new_start_selected !== true
      || drain.first.side_effect_calls !== 1 || !Number.isInteger(drain.initial?.pid)
      || drain.initial.pid <= 0 || drain.first.pid !== drain.initial.pid
      || !(drainEntry(drain.before)?.pending_workflow_tasks?.ready_count > 0)
      || drain.drain?.build_id !== drain.v1_build || drain.drain.drain_intent !== 'draining'
      || !drain.drain.drained_at || drain.duplicate_drain?.drained_at !== drain.drain.drained_at
      || !(drain.repeated_after_ms >= 1000)
      || drainEntry(drain.blocked_rollout)?.drain_intent !== 'draining'
      || drainEntry(drain.blocked_rollout)?.active_worker_count !== 0
      || drainEntry(drain.blocked_rollout)?.draining_worker_count !== 1
      || !(drainEntry(drain.blocked_rollout)?.pending_workflow_tasks?.ready_count > 0)
      || drainEntry(drain.blocked_rollout)?.pending_workflow_tasks?.leased_count !== 0
      || drain.blocked_show?.run_id !== drainRun.run_id || drain.blocked_show?.compatibility !== drain.v1_build
      || drain.blocked_show?.status !== 'waiting' || signalCount(drain.blocked_history, 'SignalReceived') !== 1
      || signalCount(drain.blocked_history, 'SignalApplied') !== 0
      || signalCount(drain.blocked_history, 'SideEffectRecorded') !== 1
      || signalCount(drain.blocked_history, 'WorkflowCompleted') !== 0
      || !Array.isArray(drain.drained_polls) || drain.drained_polls.length < 2
      || !drain.drained_polls.every((poll) => poll.processed === 0 && poll.pid === drain.initial.pid
        && poll.side_effect_calls === 1 && JSON.stringify(poll.callbacks) === JSON.stringify(drain.first.callbacks))
      || !Array.isArray(drain.incompatible_polls) || drain.incompatible_polls.length < 3
      || !drain.incompatible_polls.every((poll) => poll.processed === 0)
      || drain.shutdown?.pid !== drain.initial.pid || drain.shutdown.exit_code !== 0 || drain.shutdown.signal !== null
      || drain.shutdown.response?.processed !== 0 || drain.shutdown.response?.side_effect_calls !== 1
      || JSON.stringify(drain.shutdown.response.callbacks) !== JSON.stringify(drain.first.callbacks)
      || drainEntry(drain.absent_rollout)?.active_worker_count !== 0
      || drain.resume?.build_id !== drain.v1_build || drain.resume.drain_intent !== 'active'
      || drain.resume.drained_at !== null || drain.duplicate_resume?.drain_intent !== 'active'
      || drainEntry(drain.resumed_rollout)?.drain_intent !== 'active'
      || drainEntry(drain.resumed_rollout)?.active_worker_count !== 0
      || !Number.isInteger(drain.replacement?.pid) || drain.replacement.pid <= 0
      || drain.replacement.pid === drain.shutdown.pid || drain.replacement.metrics?.entries !== 0
      || drain.replacement.metrics?.hit !== 0 || drain.resumed?.pid !== drain.replacement.pid
      || !deliveredAt(drain.resumed, drainRun, 'finish') || drain.resumed.side_effect_calls !== 0
      || !deliveredAt(drain.completed, drainRun, 'completed') || drain.completed.side_effect_calls !== 0
      || drain.show?.run_id !== drainRun.run_id || drain.show?.compatibility !== drain.v1_build
      || drain.show?.status !== 'completed' || drain.result?.producer !== 'original-drain-v1'
      || signalCount(drain.history, 'SideEffectRecorded') !== 1
      || signalCount(drain.history, 'WorkflowCompleted') !== 1) return false;
  if (!['v1', 'v2'].every((cohort) => drain.workers?.workers?.some((worker) =>
    worker.worker_id === drain[`${cohort}_worker_id`] && worker.build_id === drain[`${cohort}_build`]
    && worker.runtime === 'rust' && worker.sdk_version === `durable-workflow-rust/${version}`))
    || !drain.restored_workers?.workers?.some((worker) => worker.worker_id === drain.v1_worker_id
      && worker.build_id === drain.v1_build && worker.runtime === 'rust'
      && worker.sdk_version === `durable-workflow-rust/${version}`)) return false;
  if (registration.v1_build !== pin.v1_build || registration.v2_build !== pin.v2_build
      || !Array.isArray(registration.workers?.workers)
      || !['v1', 'v2'].every((cohort) => registration.workers.workers.some((worker) =>
        worker.worker_id === registration[`${cohort}_worker_id`]
        && worker.build_id === pin[`${cohort}_build`] && worker.runtime === 'rust'
        && worker.sdk_version === `durable-workflow-rust/${version}`))) return false;
  if (!original?.workflow_id || !original.run_id || !pin.new?.run_id
      || pin.new.run_id === original.run_id || !pin.v1_build || !pin.v2_build
      || pin.v1_build === pin.v2_build || pin.wrong_old_poll?.processed !== 0
      || pin.wrong_new_poll?.processed !== 0 || pin.first_v1_poll?.side_effect_calls !== 1
      || pin.new_completed?.side_effect_calls !== 1
      || !deliveredAt(pin.first_v1_poll, original, 'ready')
      || !deliveredAt(pin.new_completed, pin.new, 'completed')) return false;
  if (!sameRun(cells.cache_eviction_replay.original)
      || !(cells.cache_eviction_replay.eviction?.metrics?.eviction > 0)
      || cells.cache_eviction_replay.cold_replay?.side_effect_calls !== 2
      || !(cells.cache_eviction_replay.cold_replay?.metrics?.forced_cold_replay > 0)
      || !sameRun(cells.no_compatible_worker.original)
      || cells.no_compatible_worker.show?.run_id !== original.run_id
      || cells.no_compatible_worker.show?.compatibility !== pin.v1_build
      || cells.no_compatible_worker.show?.compatibility_status !== 'no_compatible_worker'
      || !deliveredAt(cells.cache_eviction_replay.cold_replay, original, 'finish')
      || !Array.isArray(cells.no_compatible_worker.incompatible_polls)
      || cells.no_compatible_worker.incompatible_polls.length === 0
      || !cells.no_compatible_worker.incompatible_polls.every((poll) => poll.processed === 0)) return false;
  const history = cold.history?.events;
  return sameRun(cold.original) && cold.killed?.signal === 9
    && deliveredAt(cold.resumed, original, 'settle')
    && cold.resumed.side_effect_calls === 0 && cold.resumed.metrics?.entries === 1
    && cold.killed.pid === cold.first_replacement?.pid
    && Number.isInteger(cold.killed.pid) && cold.killed.pid > 0
    && Number.isInteger(cold.replacement?.pid) && cold.replacement.pid > 0
    && cold.replacement.pid !== cold.killed.pid
    && cold.replacement.metrics?.entries === 0 && cold.replacement.metrics?.hit === 0
    && cold.completed?.side_effect_calls === 0 && cold.completed?.metrics?.entries === 0
    && deliveredAt(cold.completed, original, 'completed')
    && cold.show?.run_id === original.run_id && cold.show?.compatibility === pin.v1_build
    && cold.show?.status === 'completed'
    && cold.result?.producer === 'original-v1' && Array.isArray(history)
    && history.filter((event) => event.event_type === 'SideEffectRecorded').length === 1
    && history.filter((event) => event.event_type === 'WorkflowCompleted').length === 1;
}

function run(command, args, log, options = {}) {
  const result = spawnSync(command, args, { encoding:'utf8', maxBuffer:16 * 1024 * 1024,
    timeout:900000, ...options });
  fs.writeFileSync(log, `${result.stdout ?? ''}${result.stderr ?? ''}`);
  if (result.error || result.status !== 0) {
    throw new Error(`${command} failed (${result.status ?? result.signal}): ${result.error?.message ?? `see ${path.basename(log)}`}`);
  }
  return result.stdout;
}

async function main() {
  const startedAt = new Date().toISOString();
  const version = process.env.DW_RUST_SDK_VERSION ?? '';
  if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error('DW_RUST_SDK_VERSION must be an exact stable crate version');
  if (!process.env.DW_WV_SERVER_URL) throw new Error('DW_WV_SERVER_URL is required');
  process.env.DW_WV_NAMESPACE ??= 'worker-versioning-conformance';
  const resultDir = process.env.DW_WV_RESULT_DIR ?? process.cwd();
  const root = path.join(process.env.DW_WV_RUN_ROOT ?? resultDir, 'published-rust-worker-shard');
  const resultPath = path.join(resultDir, 'worker-versioning-rust-result.json');
  fs.mkdirSync(path.join(root, 'src'), { recursive:true });
  fs.mkdirSync(resultDir, { recursive:true });
  const serverRelease = path.join(resultDir, 'server-source-release.json');
  if (fs.existsSync(serverRelease)
      && JSON.parse(fs.readFileSync(serverRelease, 'utf8')).server?.version !== process.env.DW_SERVER_VERSION) {
    throw new Error('Published image release metadata does not match DW_SERVER_VERSION');
  }
  fs.writeFileSync(path.join(root, 'Cargo.toml'), `[package]\nname = "worker-versioning-rust-probe"\nversion = "0.0.0"\nedition = "2021"\nrust-version = "1.86"\n[dependencies]\ndurable-workflow = "=${version}"\nserde_json = "1"\nreqwest = { version = "0.12", default-features = false, features = ["json", "rustls-tls"] }\ntokio = { version = "1", features = ["macros", "rt-multi-thread", "signal", "time"] }\n`);
  fs.copyFileSync(new URL('./worker-versioning-rust-probe.rs', import.meta.url), path.join(root, 'src/main.rs'));
  fs.appendFileSync(path.join(root, 'Cargo.toml'), '\n[profile.dev]\ndebug = 0\nincremental = false\n');
  const manifest = path.join(root, 'Cargo.toml');
  const metadata = JSON.parse(run('cargo', ['metadata', '--format-version', '1', '--manifest-path', manifest], path.join(resultDir, 'worker-versioning-rust-metadata.log')));
  const sdk = metadata.packages.filter((pkg) => pkg.name === 'durable-workflow');
  if (sdk.length !== 1 || sdk[0].version !== version || sdk[0].source !== 'registry+https://github.com/rust-lang/crates.io-index') {
    throw new Error('Rust SDK must resolve from the exact registry package without a checkout or patch');
  }
  run('cargo', ['build', '--locked', '--manifest-path', manifest, '--message-format=json'], path.join(resultDir, 'worker-versioning-rust-build.log'));
  const messages = fs.readFileSync(path.join(resultDir, 'worker-versioning-rust-build.log'), 'utf8').split('\n').flatMap((line) => {
    try { return [JSON.parse(line)]; } catch { return []; }
  });
  const binary = messages.find((message) => message.reason === 'compiler-artifact'
    && message.target?.name === 'worker-versioning-rust-probe' && message.executable)?.executable;
  if (!binary) throw new Error('compiled Rust fixture executable is missing');
  const lock = fs.readFileSync(path.join(root, 'Cargo.lock'), 'utf8');
  const sdkLock = lock.split('[[package]]').find((block) => /^name = "durable-workflow"$/m.test(block));
  const checksum = sdkLock?.match(/^checksum = "([a-f0-9]{64})"$/m)?.[1];
  if (!checksum || !sdkLock.includes(`version = "${version}"`)) throw new Error('Registry crate checksum is missing');
  const namespace = await fetch(`${process.env.DW_WV_SERVER_URL.replace(/\/+$/, '')}/api/namespaces`, {
    method:'POST', signal:AbortSignal.timeout(10000),
    headers:{ 'Content-Type':'application/json', Accept:'application/json',
      Authorization:`Bearer ${process.env.DW_WV_AUTH_TOKEN ?? 'dev-token'}`,
      'X-Namespace':process.env.DW_WV_BOOTSTRAP_NAMESPACE ?? 'default',
      'X-Durable-Workflow-Control-Plane-Version':'2' },
    body:JSON.stringify({ name:process.env.DW_WV_NAMESPACE, retention_days:7 }),
  });
  if (![200, 201, 409].includes(namespace.status)) throw new Error(`Namespace prerequisite returned HTTP ${namespace.status}`);
  run(binary, [resultPath], path.join(resultDir, 'worker-versioning-rust-execution.log'));
  const report = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
  report.registry_package = { version, source:sdk[0].source, checksum,
    url:`https://crates.io/api/v1/crates/durable-workflow/${version}/download` };
  if (!rustVersioningPasses(report, version)) throw new Error('Rust observations do not prove the selected six cells');
  fs.copyFileSync(path.join(root, 'Cargo.lock'), path.join(resultDir, 'worker-versioning-rust-Cargo.lock'));
  report.runner_commit = process.env.GITHUB_SHA ?? process.env.DW_WV_RUNNER_COMMIT ?? null;
  report.fixture_sha256 = createHash('sha256').update(fs.readFileSync(path.join(root, 'src/main.rs'))).digest('hex');
  report.started_at = startedAt;
  report.finished_at = new Date().toISOString();
  report.artifact_versions = { server:process.env.DW_SERVER_VERSION ?? null, 'sdk-rust':version };
  report.artifact_sources = { server:process.env.DW_SERVER_IMAGE ?? process.env.DW_WV_SERVER_URL,
    'sdk-rust':report.registry_package.url };
  fs.writeFileSync(resultPath, `${JSON.stringify(report, null, 2)}\n`);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((error) => {
    const directory = process.env.DW_WV_RESULT_DIR ?? process.cwd();
    const resultPath = path.join(directory, 'worker-versioning-rust-result.json');
    fs.mkdirSync(directory, { recursive:true });
    let report;
    try { report = JSON.parse(fs.readFileSync(resultPath, 'utf8')); } catch { report = {}; }
    fs.writeFileSync(resultPath, `${JSON.stringify({ ...report, outcome:'fail', error:error.message }, null, 2)}\n`);
    console.error(error.message);
    process.exitCode = 1;
  });
}
