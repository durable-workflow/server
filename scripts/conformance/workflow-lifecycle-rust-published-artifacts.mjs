import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const RESULT_DIR = required('RESULT_DIR');
const REPO_ROOT = required('REPO_ROOT');
const SDK_VERSION = required('DW_RUST_SDK_VERSION');
const SERVER_VERSION = required('DW_SERVER_VERSION');
const SERVER_IMAGE = process.env.DW_SERVER_IMAGE || `durableworkflow/server:${SERVER_VERSION}`;
const RUST_IMAGE = process.env.DW_WORKFLOW_LIFECYCLE_RUST_IMAGE || 'rust:1.86.0-slim-bookworm';
const PROJECT_DIR = path.join(RESULT_DIR, 'rust-sdk-lifecycle-probe');
const SIDECAR = path.join(RESULT_DIR, 'rust-sdk-lifecycle-evidence.json');
const SEMVER = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
const MINIMUM_LIFECYCLE_SDK = [0, 1, 10];

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
    const detail = String(result.stderr || result.stdout || '').trim().slice(-4000);
    throw new Error(`${command} ${args.join(' ')} exited ${result.status}: ${detail}`);
  }
  return String(result.stdout || '').trim();
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

function writeSidecar(status, classification, outputs, summary = '') {
  const finding = status === 'pass' ? [] : [{
    finding_id: `workflow-lifecycle-rust-sdk-lifecycle-surface-${classification}`,
    finding_type: classification === 'runner-gap' ? 'conformance_runner_blocked' : 'product_behavior_failure',
    classification,
    scenario_id: 'rust_sdk_lifecycle_surface',
    owning_surface: classification === 'runner-gap' ? 'conformance_harness' : 'sdk-rust-or-server',
    summary,
    next_acceptance_criterion: 'Run every Rust lifecycle cell using the exact crates.io package against the matching published server image.',
  }];
  fs.writeFileSync(SIDECAR, `${JSON.stringify({
    schema: 'durable-workflow.v2.workflow-lifecycle.rust-sdk-sidecar',
    version: 1,
    generated_at: new Date().toISOString(),
    runner: 'published-rust-sdk-lifecycle-surface-probe',
    runner_blocked: status === 'runner_blocked',
    shard_exit_status: status === 'pass' ? 0 : 1,
    scenario_results: {
      rust_sdk_lifecycle_surface: {
        scenario_id: 'rust_sdk_lifecycle_surface',
        status,
        classification,
        published_artifact_cell_executed: status !== 'runner_blocked',
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

try {
  if (!SEMVER.test(SDK_VERSION)) throw new Error('DW_RUST_SDK_VERSION must be exact semver');
  if (!versionAtLeast(SDK_VERSION, MINIMUM_LIFECYCLE_SDK)) {
    throw new Error('DW_RUST_SDK_VERSION does not expose server-enforced workflow start deadlines');
  }
  if (!/^\d+\.\d+\.\d+$/.test(SERVER_VERSION)) throw new Error('DW_SERVER_VERSION must be an exact patch tag');
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
serde_json = "1"
tokio = { version = "1", features = ["macros", "rt-multi-thread", "time"] }
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
  const install = {
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

  let stdout;
  if (useLocal) {
    stdout = run(path.join(PROJECT_DIR, 'target/release/workflow-lifecycle-published-rust-probe'), [], {
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
    stdout = run('docker', dockerArgs(['/app/target/release/workflow-lifecycle-published-rust-probe']));
  }
  const line = stdout.split(/\r?\n/).filter(Boolean).at(-1);
  const outputs = JSON.parse(line || '{}');
  if (outputs.rust_shard_contract_version !== 2) throw new Error('Rust probe did not emit lifecycle shard contract version 2');
  outputs.install_provenance = install;
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
  outputs.shard_exit_status = 0;
  writeSidecar('pass', 'product-gap', outputs);
} catch (error) {
  writeSidecar('fail', 'product-gap', {
    sdk: 'sdk-rust', artifact_version: SDK_VERSION, server_version: SERVER_VERSION,
    server_image: SERVER_IMAGE, stable_reason: 'rust_sdk_shard_unsuccessful',
    failure_message: error instanceof Error ? error.message : String(error),
    published_artifact_cell_executed: true, local_product_source_checkouts_used: false,
  }, error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
}
