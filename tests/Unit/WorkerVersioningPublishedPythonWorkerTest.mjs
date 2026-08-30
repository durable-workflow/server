import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

import { pythonWorkerScript } from '../../scripts/conformance/worker-versioning-published-workers.mjs';

const fakePackage = `
from .client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST

class _Serializer:
    AVRO_CODEC = "avro"

    @staticmethod
    def encode(value, codec=None):
        return value

serializer = _Serializer()

class Client:
    def __init__(self, *args, **kwargs):
        pass

    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, traceback):
        return False

    async def register_worker(self, *, capability_manifest, **kwargs):
        assert capability_manifest == PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST
        return {"registered": True, "worker_id": kwargs["worker_id"]}
`;

const fakeManifest = `
PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST = {
    "local_activities": {
        "supported": False,
        "minimum_protocol_version": "1.18",
        "reason": "python_worker_does_not_execute_record_local_activity",
    },
    "worker_sessions": {
        "supported": False,
        "minimum_protocol_version": "1.18",
        "reason": "python_worker_has_no_typed_session_lifecycle",
    },
    "sticky_execution": {
        "supported": False,
        "minimum_protocol_version": "1.18",
        "reason": "python_worker_uses_complete_durable_history_replay",
    },
}
`;

const fakeErrors = `
class ServerError(Exception):
    def __init__(self, message="server error"):
        super().__init__(message)
        self.body = {}
        self.status = 500

    def reason(self):
        return "server_error"
`;

test('generated published Python worker registers with the exact portable capability manifest', (t) => {
  if (spawnSync('python3', ['--version']).status !== 0) {
    t.skip('python3 is required to exercise the generated published worker');
    return;
  }

  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'dw-published-python-worker-'));
  try {
    const packageRoot = path.join(root, 'durable_workflow');
    fs.mkdirSync(packageRoot);
    fs.writeFileSync(path.join(packageRoot, '__init__.py'), fakePackage);
    fs.writeFileSync(path.join(packageRoot, 'client.py'), fakeManifest);
    fs.writeFileSync(path.join(packageRoot, 'errors.py'), fakeErrors);

    const payloadPath = path.join(root, 'payload.json');
    fs.writeFileSync(payloadPath, JSON.stringify({
      action: 'register',
      server_url: 'http://published-server.invalid',
      token: 'test-token',
      namespace: 'worker-versioning',
      worker_id: 'python-current',
      task_queue: 'worker-versioning-current',
      workflow_type: 'Sequence',
      fingerprint: 'sequence-current',
      supported_activity_types: [],
      python_version: '2.0.0rc42',
      build_id: 'python-current-build',
    }));

    const script = pythonWorkerScript();
    assert.match(
      script,
      /from durable_workflow\.client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST/,
    );
    assert.match(
      script,
      /capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST/,
    );

    const scriptPath = path.join(root, 'published_worker.py');
    const outputPath = path.join(root, 'registration.json');
    fs.writeFileSync(scriptPath, script);
    const registered = spawnSync('python3', [scriptPath, payloadPath, outputPath], {
      encoding: 'utf8',
      env: { ...process.env, PYTHONPATH: root },
    });
    assert.equal(registered.status, 0, registered.stderr);
    assert.equal(JSON.parse(fs.readFileSync(outputPath, 'utf8')).response.registered, true);

    const counterfactualPath = path.join(root, 'published_worker_without_manifest.py');
    const counterfactualOutput = path.join(root, 'counterfactual.json');
    fs.writeFileSync(
      counterfactualPath,
      script.replace(
        '                    capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,\n',
        '',
      ),
    );
    const omitted = spawnSync('python3', [counterfactualPath, payloadPath, counterfactualOutput], {
      encoding: 'utf8',
      env: { ...process.env, PYTHONPATH: root },
    });
    assert.notEqual(omitted.status, 0);
    assert.match(omitted.stderr, /missing 1 required keyword-only argument: 'capability_manifest'/);
    assert.equal(fs.existsSync(counterfactualOutput), false);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
