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
  const staleWorkerShutdown = {
    stop_requested_at: '2026-07-01T10:00:00.000Z',
    stopped_at: '2026-07-01T10:00:15.000Z',
    final_accepted_heartbeat_at: '2026-07-01T10:00:14.000Z',
    advertised_stale_after_seconds: 7,
  };
  const staleTransition = {
    ...staleWorkerShutdown,
    observed_stale_at: '2026-07-01T10:00:24.000Z',
    transition_elapsed_seconds: 10,
    bounded_max_seconds: 12,
    within_bounded_window: true,
  };
  return {
    outcome,
    runner_blocked: false,
    classification: outcome === 'pass' ? `published-${cell}-sdk-heartbeat-loop-proven` : 'product-gap',
    artifact_versions: { server: '2.0.0-beta.18', [`sdk-${cell}`]: '2.0.0-beta.18' },
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
    stale_worker_shutdown: staleWorkerShutdown,
    stale_transition: staleTransition,
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
      requested_reference: 'durableworkflow/server:2.0.0-beta.18',
      resolved_public_digest: `durableworkflow/server@sha256:${'a'.repeat(64)}`,
      exact_published_image_verified: true,
    },
    clean_bootstrap: {
      status: 'pass',
      fresh_compose_project: true,
      migrations_completed: true,
      exit_code: 0,
    },
    compose: {
      project: 'dw-hb-wave-example',
    },
    cell_isolation: isolation(),
    lifecycle: {
      owner: 'heartbeat-wave-runner',
      cleanup_required: true,
      cleanup_status: 'pass',
      cleanup_failures: [],
      cleanup_resources_remaining: {
        containers: [],
        volumes: [],
        networks: [],
        attached_containers: [],
        sandbox_artifacts: [],
      },
      cleanup_verification: {
        elapsed_ms: 500,
        timeout_ms: 45_000,
        deadline_exhausted: false,
        required_stable_empty_observations: 3,
        stable_empty_observations: 3,
      },
    },
  };
  fs.writeFileSync(path.join(root, 'shared-server-state.json'), JSON.stringify(state));
  fs.writeFileSync(path.join(root, 'shared-server-cleanup-diagnostics.json'), JSON.stringify({
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-server-cleanup-diagnostics',
    version: 1,
    project: state.compose.project,
    elapsed_ms: 500,
    timeout_ms: 45_000,
    deadline_exhausted: false,
    stable_empty_observations: 3,
    final_resources_remaining: state.lifecycle.cleanup_resources_remaining,
    failures: [],
  }));
  fs.writeFileSync(path.join(root, 'heartbeat-shared-wave-children.json'), JSON.stringify({
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-children',
    version: 1,
    outcome: 'pass',
    required_cells: ['php', 'python', 'rust', 'waterline'],
    required_cells_present: true,
    all_process_groups_settled: true,
    cells: Object.fromEntries(
      ['php', 'python', 'rust', 'waterline'].map((cell, index) => [cell, {
        pid: 1000 + index,
        process_group_id: 1000 + index,
        exit_code: 0,
        settled: true,
        forced_signal: null,
      }]),
    ),
  }));
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
      artifact_versions: { server: '2.0.0-beta.18', waterline: '2.0.0-beta.18' },
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
      CELL_TIMEOUT_SECONDS: '300',
      RUST_PREPARATION_TIMEOUT_SECONDS: '240',
      WAVE_ORCHESTRATION_RESERVE_SECONDS: '60',
      RUST_EXECUTION_RESERVE_SECONDS: '60',
      DW_SERVER_VERSION: '2.0.0-beta.18',
      DW_CLI_VERSION: '2.0.0-beta.18',
      DW_PHP_SDK_VERSION: '2.0.0-beta.18',
      DW_PYTHON_SDK_VERSION: '2.0.0-beta.18',
      DW_RUST_SDK_VERSION: '2.0.0-beta.18',
      DW_WORKFLOW_PHP_VERSION: '2.0.0-beta.18',
      DW_WATERLINE_VERSION: '2.0.0-beta.18',
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
    assert.deepEqual(result.budget_allocation_seconds, {
      wave: 360,
      concurrent_cell: 300,
      rust_preparation: 240,
      rust_heartbeat_execution_reserve: 60,
      wave_orchestration_and_cleanup_reserve: 60,
    });
    assert.equal(result.published_server_bootstrap.bootstrap_count, 1);
    assert.equal(result.cleanup.cleanup_status, 'pass');
    assert.equal(result.child_processes.all_process_groups_settled, true);
    assert.deepEqual(Object.keys(result.cells), ['php', 'python', 'rust', 'waterline']);
    assert.equal(result.completed_peer_evidence.length, 4);
    assert.equal(result.isolation.namespaces_unique, true);
    assert.equal(result.isolation.every_cell_matches_receipt, true);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a prior cleanup flag cannot pass without settled child-process evidence', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-child-residue-'));
  try {
    writeFixture(root);
    fs.rmSync(path.join(root, 'heartbeat-shared-wave-children.json'));
    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(
      result.findings.some((finding) =>
        finding.finding_type === 'heartbeat_wave_child_process_cleanup_failed'),
      true,
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('an unsettled owned process group remains runner evidence', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-unsettled-child-'));
  try {
    writeFixture(root);
    const childrenPath = path.join(root, 'heartbeat-shared-wave-children.json');
    const children = JSON.parse(fs.readFileSync(childrenPath, 'utf8'));
    children.cells.python.settled = false;
    fs.writeFileSync(childrenPath, JSON.stringify(children));
    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(
      result.findings.some((finding) =>
        finding.finding_type === 'heartbeat_wave_child_process_cleanup_failed'),
      true,
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a cleanup pass flag cannot hide owned resource residue', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-resource-residue-'));
  try {
    writeFixture(root);
    const statePath = path.join(root, 'shared-server-state.json');
    const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
    state.lifecycle.cleanup_resources_remaining.containers = ['owned-server-container'];
    fs.writeFileSync(statePath, JSON.stringify(state));
    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(
      result.findings.some((finding) =>
        finding.finding_type === 'heartbeat_wave_cleanup_failed'),
      true,
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a clean inventory cannot pass after the cleanup wall-clock deadline', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-cleanup-deadline-'));
  try {
    writeFixture(root);
    const statePath = path.join(root, 'shared-server-state.json');
    const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
    state.lifecycle.cleanup_verification.elapsed_ms = 45_001;
    state.lifecycle.cleanup_verification.deadline_exhausted = true;
    fs.writeFileSync(statePath, JSON.stringify(state));

    const diagnosticsPath = path.join(root, 'shared-server-cleanup-diagnostics.json');
    const diagnostics = JSON.parse(fs.readFileSync(diagnosticsPath, 'utf8'));
    diagnostics.elapsed_ms = 45_001;
    diagnostics.deadline_exhausted = true;
    fs.writeFileSync(diagnosticsPath, JSON.stringify(diagnostics));

    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(
      result.findings.some((finding) =>
        finding.finding_type === 'heartbeat_wave_cleanup_failed'
        && finding.deadline.lifecycle.elapsed_ms === 45_001),
      true,
    );
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
    assert.equal(
      result.cells.python.stale_worker_shutdown.final_accepted_heartbeat_at,
      '2026-07-01T10:00:14.000Z',
    );
    assert.equal(result.cells.python.stale_transition.observed_stale_at, '2026-07-01T10:00:24.000Z');
    const pythonFinding = result.findings.find((finding) =>
      finding.finding_type === 'heartbeat_wave_cell_failed'
      && finding.owning_cell === 'python');
    assert.equal(
      pythonFinding.stale_worker_shutdown.final_accepted_heartbeat_at,
      '2026-07-01T10:00:14.000Z',
    );
    assert.equal(pythonFinding.stale_transition.advertised_stale_after_seconds, 7);
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

test('a bounded Rust preparation timeout retains exact pins and completed peers', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-rust-preparation-timeout-'));
  try {
    writeFixture(root);
    const rustEvidencePath = path.join(root, 'rust/rust-sdk-heartbeat-loop-evidence.json');
    const rustEvidence = sdkEvidence('rust', 'runner_blocked');
    rustEvidence.runner_blocked = true;
    rustEvidence.classification = 'rust-crates-io-preparation-timeout';
    rustEvidence.artifact_sources = {
      server: 'docker://durableworkflow/server:2.0.0-beta.18',
      'sdk-rust': 'crates.io://durable-workflow@2.0.0-beta.18',
    };
    rustEvidence.rust_crates_io_preparation = {
      source: 'crates.io://durable-workflow@2.0.0-beta.18',
      status: 'runner_blocked',
      timeout_ms: 240_000,
      failed_phase: 'crate_download',
      completed_phases: ['lockfile_resolution'],
    };
    rustEvidence.findings = [{ finding_type: 'conformance_runner_blocked' }];
    fs.writeFileSync(rustEvidencePath, JSON.stringify(rustEvidence));
    fs.writeFileSync(path.join(root, 'rust/exit-code'), '1\n');
    const childrenPath = path.join(root, 'heartbeat-shared-wave-children.json');
    const children = JSON.parse(fs.readFileSync(childrenPath, 'utf8'));
    children.cells.rust.exit_code = 1;
    fs.writeFileSync(childrenPath, JSON.stringify(children));

    const execution = execute(root);
    assert.equal(execution.status, 1, execution.stderr);
    const result = JSON.parse(fs.readFileSync(
      path.join(root, 'heartbeat-shared-wave-result.json'),
      'utf8',
    ));
    assert.equal(result.outcome, 'fail');
    assert.equal(result.runner_blocked, true);
    assert.equal(result.cells.rust.outcome, 'runner_blocked');
    assert.equal(result.cells.rust.classification, 'rust-crates-io-preparation-timeout');
    assert.equal(
      result.cells.rust.artifact_versions['sdk-rust'],
      '2.0.0-beta.18',
    );
    assert.equal(
      result.cells.rust.artifact_sources['sdk-rust'],
      'crates.io://durable-workflow@2.0.0-beta.18',
    );
    assert.equal(result.cells.php.outcome, 'pass');
    assert.equal(result.cells.python.outcome, 'pass');
    assert.equal(result.cells.waterline.outcome, 'pass');
    assert.equal(result.completed_peer_evidence.length, 4);
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
