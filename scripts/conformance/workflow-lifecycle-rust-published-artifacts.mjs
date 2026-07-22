import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { isExactSemverRelease } from './version-identities.mjs';

const RESULT_DIR = required('RESULT_DIR');
const REPO_ROOT = required('REPO_ROOT');
const SDK_VERSION = required('DW_RUST_SDK_VERSION');
const SERVER_VERSION = required('DW_SERVER_VERSION');
const SERVER_IMAGE = process.env.DW_SERVER_IMAGE || `durableworkflow/server:${SERVER_VERSION}`;
const RUST_IMAGE = process.env.DW_WORKFLOW_LIFECYCLE_RUST_IMAGE || 'rust:1.86.0-slim-bookworm';
const PROJECT_DIR = path.join(RESULT_DIR, 'rust-sdk-lifecycle-probe');
const SIDECAR = path.join(RESULT_DIR, 'rust-sdk-lifecycle-evidence.json');
const MINIMUM_LIFECYCLE_SDK = [0, 1, 15];
const FAILURE_MESSAGE_LIMIT = 512;

class CommandFailure extends Error {
  constructor(command, status) {
    super(`${path.basename(command)} failed before validated Rust probe evidence was available`);
    this.name = 'CommandFailure';
    this.stableReason = 'rust_sdk_runner_command_failed';
    this.exitStatus = Number.isInteger(status) && status > 0 ? status : 1;
  }
}

class ProbeContractFailure extends Error {
  constructor(stableReason, message, status = 1) {
    super(message);
    this.name = 'ProbeContractFailure';
    this.stableReason = stableReason;
    this.exitStatus = Number.isInteger(status) && status > 0 ? status : 1;
  }
}

function required(name) {
  const value = (process.env[name] || '').trim();
  if (!value) throw new Error(`${name} is required`);
  return value;
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    timeout: options.timeout || 900_000,
    env: options.env || process.env,
    cwd: options.cwd,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new CommandFailure(command, result.status);
  }
  return String(result.stdout || '').trim();
}

function runProbe(command, args, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    maxBuffer: 4 * 1024 * 1024,
    timeout: options.timeout || 900_000,
    env: options.env || process.env,
    cwd: options.cwd,
  });
  if (result.error) {
    throw new ProbeContractFailure(
      'rust_sdk_probe_launch_failed',
      'The Rust lifecycle probe process could not be launched.',
    );
  }
  return {
    exitStatus: Number.isInteger(result.status) && result.status >= 0 ? result.status : 1,
    stdout: String(result.stdout || ''),
  };
}

function boundedFailureMessage(value) {
  let message = String(value || '')
    .replace(/[\u0000-\u001f\u007f]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  for (const name of ['DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN', 'DURABLE_WORKFLOW_TOKEN', 'DW_TOKEN', 'APP_KEY']) {
    const secret = String(process.env[name] || '').trim();
    if (secret) message = message.split(secret).join('[REDACTED]');
  }
  message = message
    .replace(/(authorization\s*[:=]\s*(?:bearer\s+)?)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/((?:credential|password|passwd|secret|token|api[_-]?key)\s*[:=]\s*)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/(https?:\/\/)[^\s/@:]+:[^\s/@]+@/ig, '$1[REDACTED]@');
  return message.slice(0, FAILURE_MESSAGE_LIMIT);
}

function commandExists(command) {
  return spawnSync('sh', ['-c', `command -v "$1" >/dev/null 2>&1`, 'sh', command]).status === 0;
}

function versionAtLeast(version, minimum) {
  const numeric = version.split(/[+-]/, 1)[0].split('.').map((part) => Number.parseInt(part, 10));
  return numeric.some((part, index) => part > minimum[index]
    && numeric.slice(0, index).every((earlier, earlierIndex) => earlier === minimum[earlierIndex]))
    || numeric.every((part, index) => part === minimum[index]);
}

function writeSidecar(status, classification, outputs, summary = '', shardExitStatus = status === 'pass' ? 0 : 1) {
  const executed = Boolean(outputs.published_artifact_cell_executed);
  const safeSummary = boundedFailureMessage(summary);
  const failingCell = String(outputs.failing_lifecycle_cell || 'rust_sdk_lifecycle_surface');
  const finding = status === 'pass' ? [] : [{
    finding_id: `workflow-lifecycle-rust-sdk-lifecycle-surface-${classification}`,
    finding_type: classification === 'runner-gap' ? 'conformance_runner_blocked' : 'product_behavior_failure',
    classification,
    scenario_id: 'rust_sdk_lifecycle_surface',
    owning_surface: classification === 'runner-gap' ? 'conformance_harness' : 'sdk-rust-or-server',
    summary: safeSummary,
    observed_evidence: outputs.scenario_outcomes?.[failingCell] || {},
    next_acceptance_criterion: executed
      ? `Make ${failingCell} satisfy the Rust lifecycle contract against the exact crate and server artifact tuple, then rerun workflow-lifecycle conformance.`
      : 'Run every Rust lifecycle cell using the exact crates.io package against the matching published server image.',
  }];
  fs.writeFileSync(SIDECAR, `${JSON.stringify({
    schema: 'durable-workflow.v2.workflow-lifecycle.rust-sdk-sidecar',
    version: 1,
    generated_at: new Date().toISOString(),
    runner: 'published-rust-sdk-lifecycle-surface-probe',
    runner_blocked: status === 'runner_blocked',
    shard_exit_status: shardExitStatus,
    scenario_results: {
      rust_sdk_lifecycle_surface: {
        scenario_id: 'rust_sdk_lifecycle_surface',
        status,
        classification,
        published_artifact_cell_executed: executed,
        observed_outputs: outputs,
        linked_findings: finding,
      },
    },
  }, null, 2)}\n`);
}

function packageBlock(lock, name, version = '') {
  return lock.split('[[package]]').find((block) =>
    block.includes(`name = "${name}"`) && (!version || block.includes(`version = "${version}"`)));
}

function provenance(lock, name, version = '') {
  const block = packageBlock(lock, name, version);
  if (!block) throw new Error(`Cargo.lock did not resolve ${name}${version ? ` ${version}` : ''}`);
  const resolvedVersion = block.match(/version = "([^"]+)"/)?.[1] || '';
  const source = block.match(/source = "([^"]+)"/)?.[1] || '';
  const checksum = block.match(/checksum = "([0-9a-f]{64})"/)?.[1] || '';
  if (!source.includes('crates.io') || !checksum) {
    throw new Error(`${name} did not resolve from crates.io with a registry checksum`);
  }
  return { package: name, resolved_version: resolvedVersion, registry_source: source, registry_checksum_sha256: checksum };
}

function parseProbeOutput(probe) {
  const line = probe.stdout.split(/\r?\n/).map((entry) => entry.trim()).filter(Boolean).at(-1);
  let outputs;
  try {
    outputs = JSON.parse(line || '');
  } catch {
    throw new ProbeContractFailure(
      'rust_sdk_probe_output_contract_invalid',
      'The Rust lifecycle probe did not emit a valid JSON result envelope.',
      probe.exitStatus,
    );
  }
  if (!outputs || typeof outputs !== 'object' || Array.isArray(outputs)
      || outputs.rust_shard_contract_version !== 3
      || outputs.sdk !== 'sdk-rust'
      || outputs.artifact_version !== SDK_VERSION
      || outputs.server_version !== SERVER_VERSION
      || outputs.published_artifact_cell_executed !== true
      || !outputs.scenario_outcomes
      || typeof outputs.scenario_outcomes !== 'object'
      || Array.isArray(outputs.scenario_outcomes)) {
    const mismatch = outputs?.artifact_version !== undefined
      && (outputs.artifact_version !== SDK_VERSION || outputs.server_version !== SERVER_VERSION);
    throw new ProbeContractFailure(
      mismatch ? 'rust_sdk_probe_artifact_mismatch' : 'rust_sdk_probe_output_contract_invalid',
      mismatch
        ? 'The Rust lifecycle probe result did not match the requested crate and server tuple.'
        : 'The Rust lifecycle probe result did not satisfy lifecycle shard contract version 3.',
      probe.exitStatus,
    );
  }

  if (outputs.probe_outcome === 'pass' && probe.exitStatus === 0) {
    return { status: 'pass', outputs };
  }

  const stableReason = String(outputs.stable_reason || '');
  const failureMessage = boundedFailureMessage(outputs.failure_message);
  const failingCell = String(outputs.failing_lifecycle_cell || '');
  const failingOutcome = outputs.scenario_outcomes[failingCell];
  const validFailure = outputs.probe_outcome === 'fail'
    && probe.exitStatus > 0
    && /^[a-z0-9][a-z0-9_]{0,95}$/.test(stableReason)
    && /^[a-z0-9][a-z0-9_]{0,95}$/.test(failingCell)
    && failureMessage !== ''
    && failingOutcome
    && typeof failingOutcome === 'object'
    && !Array.isArray(failingOutcome)
    && failingOutcome.status === 'fail'
    && failingOutcome.stable_reason === stableReason
    && boundedFailureMessage(failingOutcome.observed_behavior) !== '';
  if (!validFailure) {
    throw new ProbeContractFailure(
      'rust_sdk_probe_output_contract_invalid',
      'The unsuccessful Rust lifecycle probe did not emit validated executed failure evidence.',
      probe.exitStatus,
    );
  }

  outputs.failure_message = failureMessage;
  outputs.scenario_outcomes[failingCell].observed_behavior = boundedFailureMessage(
    failingOutcome.observed_behavior,
  );
  return { status: 'fail', outputs };
}

function dockerArgs(extra) {
  return [
    'run', '--rm',
    '--network', process.env.DW_WORKFLOW_LIFECYCLE_RUST_DOCKER_NETWORK || 'host',
    '-e', `DURABLE_WORKFLOW_SERVER_URL=${process.env.DW_WORKFLOW_LIFECYCLE_SERVER_URL || 'http://127.0.0.1:8080'}`,
    '-e', `DURABLE_WORKFLOW_TOKEN=${process.env.DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN || 'dev-token'}`,
    '-e', `DURABLE_WORKFLOW_NAMESPACE=${process.env.DW_WORKFLOW_LIFECYCLE_NAMESPACE || 'workflow-lifecycle-conformance'}`,
    '-e', `DW_SERVER_VERSION=${SERVER_VERSION}`,
    '-e', `DW_RUST_SDK_VERSION=${SDK_VERSION}`,
    '-e', `DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS=${process.env.DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS || ''}`,
    '-e', `DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS=${process.env.DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS || ''}`,
    '-e', `DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR=${process.env.DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR || ''}`,
    '-v', `${PROJECT_DIR}:/app`, '-w', '/app', RUST_IMAGE,
    ...extra,
  ];
}

let installProvenance = null;
try {
  if (!isExactSemverRelease(SDK_VERSION)) throw new Error('DW_RUST_SDK_VERSION must be exact semver');
  if (!versionAtLeast(SDK_VERSION, MINIMUM_LIFECYCLE_SDK)) {
    throw new Error('DW_RUST_SDK_VERSION does not expose deterministic continue-as-new replay');
  }
  if (!isExactSemverRelease(SERVER_VERSION)) throw new Error('DW_SERVER_VERSION must be an exact SemVer tag');
  const exactServerTag = SERVER_IMAGE === `durableworkflow/server:${SERVER_VERSION}`
    || SERVER_IMAGE === `docker.io/durableworkflow/server:${SERVER_VERSION}`;
  const exactServerDigest = /^(?:docker\.io\/)?durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(SERVER_IMAGE);
  if (!exactServerTag && !exactServerDigest) {
    throw new Error('DW_SERVER_IMAGE must be the exact requested server tag or digest');
  }
  if (process.env.DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS !== 'exact_published_image'
      || process.env.DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS !== 'exact_published_image'
      || process.env.DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR !== 'host_rust_container') {
    throw new Error('Rust lifecycle shard requires the host exact-image HTTP, scheduler, and Rust executor topology');
  }

  fs.mkdirSync(path.join(PROJECT_DIR, 'src'), { recursive: true });
  fs.copyFileSync(path.join(REPO_ROOT, 'scripts/conformance/workflow-lifecycle-rust-probe.rs'), path.join(PROJECT_DIR, 'src/main.rs'));
  fs.writeFileSync(path.join(PROJECT_DIR, 'Cargo.toml'), `[package]
name = "workflow-lifecycle-published-rust-probe"
version = "0.0.0"
edition = "2021"
publish = false

[dependencies]
durable-workflow = "=${SDK_VERSION}"
apache-avro = { version = "0.21", default-features = false }
axum = "0.8"
reqwest = { version = "0.12", default-features = false, features = ["json", "rustls-tls"] }
serde_json = "1"
tokio = { version = "1", features = ["macros", "net", "rt-multi-thread", "sync", "time"] }
`);

  const cargoOverride = (process.env.DW_WORKFLOW_LIFECYCLE_CARGO_BIN || process.env.CARGO_BIN || '').trim();
  const forceDocker = process.env.DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR === 'host_rust_container';
  const useLocal = !forceDocker && (cargoOverride || commandExists('cargo'));
  if (useLocal) {
    const cargo = cargoOverride || 'cargo';
    run(cargo, ['generate-lockfile'], { cwd: PROJECT_DIR });
    run(cargo, ['build', '--locked', '--release'], { cwd: PROJECT_DIR });
  } else {
    if (!commandExists('docker')) {
      writeSidecar('runner_blocked', 'runner-gap', {
        sdk: 'sdk-rust', artifact_version: SDK_VERSION, server_version: SERVER_VERSION,
        stable_reason: 'rust_executor_unavailable', published_artifact_cell_executed: false,
        local_product_source_checkouts_used: false,
      }, 'Neither Cargo nor Docker is available for the mandatory exact-crate Rust shard.');
      process.exit(0);
    }
    run('docker', ['pull', RUST_IMAGE], { timeout: 300_000 });
    run('docker', dockerArgs(['cargo', 'generate-lockfile']));
    run('docker', dockerArgs(['cargo', 'build', '--locked', '--release']));
  }

  const lock = fs.readFileSync(path.join(PROJECT_DIR, 'Cargo.lock'), 'utf8');
  const sdk = provenance(lock, 'durable-workflow', SDK_VERSION);
  if (sdk.resolved_version !== SDK_VERSION) throw new Error('resolved durable-workflow version does not match the requested tuple');
  const avro = provenance(lock, 'apache-avro');
  installProvenance = {
    package: 'durable-workflow',
    requested_version: SDK_VERSION,
    installed_version: sdk.resolved_version,
    registry_source: sdk.registry_source,
    registry_checksum_sha256: sdk.registry_checksum_sha256,
    cargo_requirement: `=${SDK_VERSION}`,
    cargo_lock_sha256: crypto.createHash('sha256').update(lock).digest('hex'),
    installer_runtime: useLocal ? 'configured-cargo' : RUST_IMAGE,
    install_mode: 'exact crates.io dependency with Cargo.lock',
  };

  let probe;
  if (useLocal) {
    probe = runProbe(path.join(PROJECT_DIR, 'target/release/workflow-lifecycle-published-rust-probe'), [], {
      env: {
        ...process.env,
        DURABLE_WORKFLOW_SERVER_URL: process.env.DW_WORKFLOW_LIFECYCLE_SERVER_URL || 'http://127.0.0.1:8080',
        DURABLE_WORKFLOW_TOKEN: process.env.DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN || 'dev-token',
        DURABLE_WORKFLOW_NAMESPACE: process.env.DW_WORKFLOW_LIFECYCLE_NAMESPACE || 'workflow-lifecycle-conformance',
        DW_SERVER_VERSION: SERVER_VERSION,
        DW_RUST_SDK_VERSION: SDK_VERSION,
        DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS: process.env.DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS,
        DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS: process.env.DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS,
        DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR: process.env.DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR,
      },
    });
  } else {
    probe = runProbe('docker', dockerArgs(['/app/target/release/workflow-lifecycle-published-rust-probe']));
  }
  const probeEvidence = parseProbeOutput(probe);
  const outputs = probeEvidence.outputs;
  outputs.install_provenance = installProvenance;
  outputs.payload_contract = {
    ...outputs.payload_contract,
    apache_avro_version: avro.resolved_version,
    apache_avro_registry_source: avro.registry_source,
    apache_avro_registry_checksum_sha256: avro.registry_checksum_sha256,
  };
  outputs.server_image = SERVER_IMAGE;
  outputs.artifact_source = `crates.io://durable-workflow@${SDK_VERSION}`;
  outputs.server_artifact_source = `docker://${SERVER_IMAGE}`;
  outputs.shard_runner = 'published-rust-sdk-lifecycle-surface-probe';
  outputs.shard_exit_status = probe.exitStatus;
  if (probeEvidence.status === 'fail') {
    writeSidecar('fail', 'product-gap', outputs, outputs.failure_message, probe.exitStatus);
    process.exitCode = probe.exitStatus;
  } else {
    writeSidecar('pass', 'product-gap', outputs);
  }
} catch (error) {
  const failureMessage = boundedFailureMessage(error instanceof Error ? error.message : String(error));
  const stableReason = error?.stableReason || 'rust_sdk_runner_setup_failed';
  const shardExitStatus = Number.isInteger(error?.exitStatus) ? error.exitStatus : 1;
  writeSidecar('runner_blocked', 'runner-gap', {
    sdk: 'sdk-rust',
    covered_cells: [],
    unsupported_cells: [],
    typed_errors: [],
    artifact_version: SDK_VERSION,
    server_version: SERVER_VERSION,
    server_image: SERVER_IMAGE,
    server_artifact_source: `docker://${SERVER_IMAGE}`,
    artifact_source: `crates.io://durable-workflow@${SDK_VERSION}`,
    install_provenance: installProvenance,
    stable_reason: stableReason,
    stable_reasons: [stableReason],
    failure_message: failureMessage,
    scenario_outcomes: {},
    rust_shard_contract_version: 3,
    shard_runner: 'published-rust-sdk-lifecycle-surface-probe',
    shard_exit_status: shardExitStatus,
    executor_topology: {
      server_http_process: process.env.DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS || '',
      scheduler_process: process.env.DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS || '',
      rust_executor: process.env.DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR || '',
      rust_executor_outside_server_image: true,
    },
    published_artifact_cell_executed: false,
    local_product_source_checkouts_used: false,
  }, failureMessage, shardExitStatus);
  process.exitCode = 1;
}
