import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const runner = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../scripts/conformance/heartbeats-wave-result.mjs',
);

function isolation() {
  return {
    php: {
      namespace: 'hb-wave-example-php',
      task_queue_prefix: 'hb-php-',
      workflow_id_prefix: 'hb-php-',
      worker_id_prefix: 'heartbeat-php-',
    },
    python: {
      namespace: 'hb-wave-example-python',
      task_queue_prefix: 'hb-python-',
      workflow_id_prefix: 'hb-python-',
      worker_id_prefix: 'heartbeat-python-',
    },
    rust: {
      namespace: 'hb-wave-example-rust',
      task_queue_prefix: 'hb-rust-',
      workflow_id_prefix: 'hb-rust-',
      worker_id_prefix: 'heartbeat-rust-',
    },
    waterline: {
      namespace: 'hb-wave-example-waterline',
      task_queue_prefix: 'waterline-status-',
      workflow_id_prefix: 'waterline-worker-status-',
      worker_id_prefix: 'waterline-',
    },
  };
}

function sdkEvidence(cell, outcome = 'pass') {
  return {
    outcome,
    runner_blocked: false,
    classification: outcome === 'pass' ? `published-${cell}-sdk-heartbeat-loop-proven` : 'product-gap',
    artifact_versions: { server: '2.0.0-beta.12', [`sdk-${cell}`]: '2.0.0-beta.10' },
    executed_distribution_identities: { server: {}, [`sdk-${cell}`]: {} },
    topology: {
      namespace: `hb-wave-example-${cell}`,
      task_queue: `hb-${cell}-cell`,
      stale_worker_id: `heartbeat-${cell}-stale`,
      fresh_worker_id: `heartbeat-${cell}-fresh`,
    },
    workflow_execution: {
      initial: { workflow_id: `hb-${cell}-initial` },
      after_stale: { workflow_id: `hb-${cell}-after-stale` },
    },
    cleanup: { status: 'pass' },
    findings: outcome === 'pass' ? [] : [{ finding_type: 'product-gap' }],
  };
}

function writeFixture(root, pythonOutcome = 'pass') {
  const state = {
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap',
    version: 1,
    wave_run_id: 'heartbeat-wave-example',
    server: {
      requested_reference: 'durableworkflow/server:2.0.0-beta.12',
      resolved_public_digest: `durableworkflow/server@sha256:${'a'.repeat(64)}`,
      exact_published_image_verified: true,
    },
    clean_bootstrap: {
      status: 'pass',
      fresh_compose_project: true,
      migrations_completed: true,
      exit_code: 0,
    },
    cell_isolation: isolation(),
    lifecycle: {
      owner: 'heartbeat-wave-runner',
      cleanup_required: true,
      cleanup_status: 'pass',
      cleanup_failures: [],
    },
  };
  fs.writeFileSync(path.join(root, 'shared-server-state.json'), JSON.stringify(state));
  fs.writeFileSync(path.join(root, 'heartbeat-shared-wave-isolation.json'), JSON.stringify({
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-isolation',
    version: 1,
    wave_run_id: state.wave_run_id,
    outcome: 'pass',
    observations: {},
    failures: [],
  }));
  for (const cell of ['php', 'python', 'rust', 'waterline']) {
    fs.mkdirSync(path.join(root, cell), { recursive: true });
    fs.writeFileSync(
      path.join(root, cell, 'exit-code'),
      `${cell === 'python' && pythonOutcome !== 'pass' ? 1 : 0}\n`,
    );
  }
  fs.writeFileSync(
    path.join(root, 'php/php-sdk-heartbeat-loop-evidence.json'),
    JSON.stringify(sdkEvidence('php')),
  );
  fs.writeFileSync(
    path.join(root, 'python/python-sdk-heartbeat-loop-evidence.json'),
    JSON.stringify(sdkEvidence('python', pythonOutcome)),
  );
  fs.writeFileSync(
    path.join(root, 'rust/rust-sdk-heartbeat-loop-evidence.json'),
    JSON.stringify(sdkEvidence('rust')),
  );
  fs.writeFileSync(
    path.join(root, 'waterline/waterline-worker-status-result.json'),
    JSON.stringify({
      outcome: 'pass',
      runner_blocked: false,
      classification: 'published-waterline-worker-status-proven',
      artifact_versions: { server: '2.0.0-beta.12', waterline: '2.0.0-beta.12' },
      topology: {
        namespace: 'hb-wave-example-waterline',
        task_queue: 'waterline-status-cell',
      },
      cleanup: { status: 'pass' },
      product_evidence: {
        findings: [],
        topology: {
          namespace: 'hb-wave-example-waterline',
          task_queue: 'waterline-status-cell',
          stale_worker_id: 'waterline-stale-cell',
          fresh_worker_id: 'waterline-fresh-cell',
          initial_workflow_id: 'waterline-worker-status-initial-cell',
          after_stale_workflow_id: 'waterline-worker-status-after-stale-cell',
        },
      },
    }),
  );
}

function execute(root) {
  return spawnSync(process.execPath, [runner], {
    env: {
      ...process.env,
      RESULT_DIR: root,
      STATE_FILE: path.join(root, 'shared-server-state.json'),
      STARTED_AT: new Date(Date.now() - 1_000).toISOString(),
      MAXIMUM_SECONDS: '360',
      DW_SERVER_VERSION: '2.0.0-beta.12',
      DW_CLI_VERSION: '2.0.0-beta.10',
      DW_PHP_SDK_VERSION: '2.0.0-beta.10',
      DW_PYTHON_SDK_VERSION: '2.0.0-beta.10',
      DW_RUST_SDK_VERSION: '2.0.0-beta.10',
      DW_WORKFLOW_PHP_VERSION: '2.0.0-beta.11',
      DW_WATERLINE_VERSION: '2.0.0-beta.12',
    },
    encoding: 'utf8',
  });
}

test('one-bootstrap wave passes only with four isolated cells and final cleanup', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-pass-'));
  try {
    writeFixture(root);
    const execution = execute(root);
    assert.equal(execution.status, 0, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'pass');
    assert.equal(result.published_server_bootstrap.bootstrap_count, 1);
    assert.equal(result.cleanup.cleanup_status, 'pass');
    assert.deepEqual(Object.keys(result.cells), ['php', 'python', 'rust', 'waterline']);
    assert.equal(result.completed_peer_evidence.length, 4);
    assert.equal(result.isolation.namespaces_unique, true);
    assert.equal(result.isolation.every_cell_matches_receipt, true);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a failed cell retains completed peer evidence and independent attribution', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-failure-'));
  try {
    writeFixture(root, 'fail');
    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, false);
    assert.equal(result.cells.python.outcome, 'fail');
    assert.equal(result.cells.python.runner_blocked, false);
    assert.equal(result.cells.php.outcome, 'pass');
    assert.equal(result.cells.rust.outcome, 'pass');
    assert.equal(result.cells.waterline.outcome, 'pass');
    assert.equal(result.completed_peer_evidence.length, 4);
    assert.equal(result.cleanup.cleanup_status, 'pass');
    assert.equal(
      result.findings.some((finding) =>
        finding.finding_type === 'heartbeat_wave_cell_failed'
        && finding.owning_cell === 'python'),
      true,
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a timed-out cell retains completed peer evidence and final shared cleanup', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-timeout-'));
  try {
    writeFixture(root);
    fs.rmSync(path.join(root, 'python/python-sdk-heartbeat-loop-evidence.json'));
    fs.writeFileSync(path.join(root, 'python/exit-code'), '124\n');
    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(result.cells.python.outcome, 'runner_blocked');
    assert.equal(result.cells.python.timed_out, true);
    assert.equal(result.cells.php.outcome, 'pass');
    assert.equal(result.cells.rust.outcome, 'pass');
    assert.equal(result.cells.waterline.outcome, 'pass');
    assert.equal(result.completed_peer_evidence.length, 3);
    assert.equal(result.cleanup.cleanup_status, 'pass');
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a cross-namespace observer leak remains product evidence', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-leak-'));
  try {
    writeFixture(root);
    fs.writeFileSync(path.join(root, 'heartbeat-shared-wave-isolation.json'), JSON.stringify({
      schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-isolation',
      version: 1,
      wave_run_id: 'heartbeat-wave-example',
      outcome: 'fail',
      observations: {},
      failures: [{
        cell: 'php',
        namespace: 'hb-wave-example-php',
        leaked_worker_ids: ['heartbeat-python-fresh'],
        leaked_task_queues: [],
        leaked_workflow_ids: [],
      }],
    }));

    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, false);
    assert.equal(result.isolation.observer_projection_no_leaks, false);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
