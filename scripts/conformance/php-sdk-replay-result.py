#!/usr/bin/env python3
"""Gate the complete PHP shard; a focused run makes no polyglot aggregate claim."""
import hashlib
import json
import os
import sys
from pathlib import Path

from php_sdk_replay_scenarios import expand_php_replay_scenarios


def evaluate(source, sdk_version, server_version, server_image):
    scenarios, contract = expand_php_replay_scenarios(source)
    passed = (
        source.get('outcome') == 'pass'
        and source.get('runner_blocked') is False
        and source.get('local_product_source_checkouts_used') is False
        and source.get('artifact_versions', {}).get('sdk-php') == sdk_version
        and source.get('artifact_versions', {}).get('server') == server_version
        and source.get('artifact_sources', {}).get('server') == 'docker://' + server_image
        and bool(source.get('package_provenance'))
        and bool(source.get('apache_avro_provenance'))
        and contract['all_declared_scenarios_executed']
        and all(row['status'] == 'pass' for row in scenarios.values())
    )
    return {
        'schema': 'durable-workflow.v2.replay-conformance.sdk-php-shard',
        'runtime': 'sdk-php', 'outcome': 'pass' if passed else 'fail',
        'runner_blocked': source.get('runner_blocked') is True,
        'artifact_versions': source.get('artifact_versions', {}),
        'server_image': server_image,
        'artifact_sources': source.get('artifact_sources', {}),
        'source_policy': {'artifact_source': 'published_artifacts',
                          'local_product_source_checkouts_used': source.get('local_product_source_checkouts_used')},
        'scenario_results': scenarios, 'expanded_scenario_contract': contract,
        'package_provenance': source.get('package_provenance', {}),
        'apache_avro_provenance': source.get('apache_avro_provenance', {}),
        'process_boundary': source.get('process_boundary', {}),
        'executed_distribution_identities': source.get('executed_distribution_identities', {}),
        'callback_counts': source.get('callback_counts', {}),
        'activity_callback_cardinality': source.get('activity_callback_cardinality', {}),
        'history_assertions': source.get('history_assertions', {}),
        'findings': source.get('findings', []),
    }


def main():
    root = Path(sys.argv[1])
    raw = (root / 'php-sdk-conformance-result.json').read_bytes()
    result = evaluate(json.loads(raw), os.environ['DW_PHP_SDK_VERSION'],
                      os.environ['DW_SERVER_VERSION'], os.environ['DW_SERVER_IMAGE'])
    result['source_document'] = {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
    (root / 'php-replay-shard.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({'outcome': result['outcome'], 'scenarios': len(result['scenario_results'])}))
    return 0 if result['outcome'] == 'pass' else 1


if __name__ == '__main__':
    sys.exit(main())
