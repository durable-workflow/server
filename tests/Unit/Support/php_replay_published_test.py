import importlib.util
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

RUNNERS = Path(__file__).resolve().parents[3] / 'scripts/conformance'
sys.path.insert(0, str(RUNNERS))
spec = importlib.util.spec_from_file_location('php_replay_result', RUNNERS / 'php-sdk-replay-result.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
from php_sdk_replay_scenarios import REQUIRED_PHP_REPLAY_SCENARIOS

IMAGE = 'durableworkflow/server@sha256:' + 'a' * 64


class PublishedPhpReplayTest(unittest.TestCase):
    def source(self):
        return {
            'outcome': 'pass', 'runner_blocked': False,
            'local_product_source_checkouts_used': False,
            'artifact_versions': {'sdk-php': '3.11.0', 'server': '3.3.0'},
            'artifact_sources': {'server': 'docker://' + IMAGE},
            'package_provenance': {'package': 'durable-workflow/sdk', 'source': 'packagist'},
            'apache_avro_provenance': {'package': 'apache/avro'},
            'replay_scenario_results': {
                name: {'scenario_id': name, 'status': 'pass', 'executed_runtime_cell': True,
                       'runtime_cell': {'executed': True, 'cell_id': 'real-worker-cell'},
                       'observed_outputs': {'runtime_cell_executed': True}}
                for name in REQUIRED_PHP_REPLAY_SCENARIOS
            },
        }

    def evaluate(self, source):
        return gate.evaluate(source, '3.11.0', '3.3.0', IMAGE)

    def test_complete_matrix_preserves_every_runtime_record(self):
        source = self.source()
        result = self.evaluate(source)
        self.assertEqual('pass', result['outcome'])
        self.assertEqual(source['replay_scenario_results'], result['scenario_results'])
        self.assertEqual(13, len(result['scenario_results']))

    def test_each_missing_failed_or_unexecuted_cell_refuses(self):
        for name in REQUIRED_PHP_REPLAY_SCENARIOS:
            for mutation in ('missing', 'failed', 'unexecuted', 'unobserved', 'empty_cell_id'):
                with self.subTest(name=name, mutation=mutation):
                    source = self.source()
                    row = source['replay_scenario_results'][name]
                    if mutation == 'missing': del source['replay_scenario_results'][name]
                    if mutation == 'failed': row['status'] = 'fail'
                    if mutation == 'unexecuted': row['runtime_cell']['executed'] = False
                    if mutation == 'unobserved': row['observed_outputs']['runtime_cell_executed'] = False
                    if mutation == 'empty_cell_id': row['runtime_cell']['cell_id'] = ''
                    self.assertEqual('fail', self.evaluate(source)['outcome'])

    def test_a_top_level_pass_cannot_replace_replay_evidence(self):
        source = self.source()
        del source['replay_scenario_results']
        result = self.evaluate(source)
        self.assertEqual('fail', result['outcome'])
        self.assertEqual(13, len(result['expanded_scenario_contract']['missing_executed_scenarios']))

    def test_source_blocking_provenance_and_tuple_mismatches_refuse(self):
        mutations = [
            ('outcome', 'fail'), ('runner_blocked', True),
            ('local_product_source_checkouts_used', True),
            ('package_provenance', {}), ('apache_avro_provenance', {}),
            ('artifact_versions', {'sdk-php': '3.10.0', 'server': '3.3.0'}),
            ('artifact_versions', {'sdk-php': '3.11.0', 'server': '3.2.0'}),
            ('artifact_sources', {'server': 'docker://durableworkflow/server:latest'}),
        ]
        for key, value in mutations:
            with self.subTest(key=key, value=value):
                source = self.source(); source[key] = value
                self.assertEqual('fail', self.evaluate(source)['outcome'])

    def test_invalid_artifact_pins_refuse_before_any_docker_command(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            docker = root / 'docker'
            docker.write_text('#!/bin/sh\nprintf called >> "$CALLS"\nexit 99\n')
            docker.chmod(0o700)
            base = dict(os.environ, PATH=str(root) + ':' + os.defpath,
                        CALLS=str(root / 'calls'), DW_SERVER_VERSION='3.3.0',
                        DW_PHP_SDK_VERSION='3.11.0', DW_SERVER_IMAGE=IMAGE,
                        DW_WORKFLOW_LIFECYCLE_MYSQL_IMAGE='mysql@sha256:' + 'c' * 64,
                        DW_REPLAY_PHP_RUNNER_IMAGE='ghcr.io/durable-workflow/sample-app-devcontainer@sha256:' + 'b' * 64)
            for key, value in [('DW_SERVER_VERSION', 'latest'),
                               ('DW_PHP_SDK_VERSION', '^3.11'),
                               ('DW_SERVER_IMAGE', 'durableworkflow/server:3.3.0'),
                               ('DW_REPLAY_PHP_RUNNER_IMAGE', 'ghcr.io/foreign/tool@sha256:' + 'b' * 64),
                               ('DW_WORKFLOW_LIFECYCLE_MYSQL_IMAGE', 'mysql:latest'),
                               ('DW_JOB_RESOURCE_PREFIX', 'bad/name')]:
                with self.subTest(key=key):
                    env = dict(base); env[key] = value
                    run = subprocess.run(['bash', str(RUNNERS / 'php-sdk-replay-host-published-artifacts.sh'),
                                          '--result-dir', str(root / 'results')],
                                         env=env, capture_output=True, timeout=5)
                    self.assertEqual(2, run.returncode, run.stderr)
                    self.assertFalse((root / 'calls').exists())


if __name__ == '__main__':
    unittest.main()
