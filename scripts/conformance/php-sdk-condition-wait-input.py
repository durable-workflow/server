#!/usr/bin/env python3
"""Bind the installed C1-C5 runs and raw exports to the reviewed read-only SQL."""
import hashlib
import json
from pathlib import Path
import re
import sys

CASES = ('C1', 'C2', 'C3-terminate', 'C3-cancel', 'C3-continue', 'C4', 'C5')
SQL_SHA256 = '1f828778b952b931fd99acccc05af415aab1e279f2713cc164f737a99337b21c'
RUN_ID = re.compile(r'[0-9A-HJKMNP-TV-Z]{26}')


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def report_runs(report):
    require(isinstance(report, dict), 'condition_report_shape')
    require(isinstance(report.get('namespace'), str)
            and re.fullmatch(r'php-cwn-[a-f0-9]{12}', report['namespace']) is not None,
            'condition_namespace')
    cases = report.get('cases')
    require(isinstance(cases, dict) and set(cases) == set(CASES), 'condition_cases_missing')
    rows = []
    for case in CASES:
        entry = cases[case]
        require(isinstance(entry, dict) and entry.get('evidence'), 'condition_evidence_missing')
        require(isinstance(entry.get('command_contract'), dict)
                and entry['command_contract'].get('source') == 'durable_history',
                'condition_contract_not_durable')
        runs = entry.get('runs')
        require(isinstance(runs, list) and len(runs) == (2 if case in ('C3-continue', 'C5') else 1),
                'condition_run_count')
        for run in runs:
            require(isinstance(run, dict), 'condition_run_shape')
            require(isinstance(run.get('run_id'), str) and RUN_ID.fullmatch(run['run_id']) is not None,
                    'condition_run_id')
            require(isinstance(run.get('workflow_id'), str)
                    and re.fullmatch(r'c[1-5]-[a-z0-9-]+', run['workflow_id']) is not None,
                    'condition_workflow_id')
            require(isinstance(run.get('events'), list) and run['events']
                    and all(isinstance(event, str) and event for event in run['events']),
                    'condition_events_missing')
            rows.append((case, run))
    ids = [run['run_id'] for _, run in rows]
    require(len(ids) == 9 and len(set(ids)) == 9 and report.get('run_ids') == ids,
            'condition_run_inventory')
    require(report.get('c1_run_ids') == [rows[0][1]['run_id']] and report.get('c1_timeout') == 20,
            'condition_first_case_identity')
    return rows


def extract_report(raw):
    """Keep the published CLI's progress separate from its final JSON object."""
    lines = raw.splitlines(keepends=True)
    progress = 0
    for line in lines:
        if not line.startswith(b'ok: '):
            break
        progress += 1
    require(progress > 0, 'condition_cli_progress_missing')
    report = json.loads(b''.join(lines[progress:]))
    report_runs(report)
    return report


def validate_exports(report, exports):
    rows = report_runs(report)
    for case, run in rows:
        export = exports.get(run['run_id'])
        require(isinstance(export, dict) and export.get('schema') == 'durable-workflow.v2.history-export'
                and export.get('schema_version') == 2 and export.get('history_complete') is True,
                'condition_export_incomplete')
        workflow = export.get('workflow', {})
        require(isinstance(workflow, dict)
                and workflow.get('run_id') == run['run_id'] and workflow.get('instance_id') == run['workflow_id']
                and workflow.get('source_runtime') == 'standalone_server'
                and workflow.get('source_process_class') == 'server_http_node'
                and 'migration_projection' not in export, 'condition_export_identity')
        events = export.get('history_events')
        require(isinstance(events, list) and len(events) >= 2, 'condition_export_events')
        identities = set()
        for sequence, event in enumerate(events, 1):
            require(isinstance(event, dict) and type(event.get('sequence')) is int
                    and event['sequence'] == sequence and isinstance(event.get('id'), str)
                    and event['id'] and event['id'] not in identities, 'condition_export_prefix')
            identities.add(event['id'])
        require([event.get('type') for event in events] == run['events'], 'condition_export_history_mismatch')
        pair = report['cases'][case]['runs']
        if len(pair) == 2 and run['run_id'] == pair[1]['run_id']:
            parent = pair[0]
            started = events[0].get('payload', {})
            parent_events = exports[parent['run_id']]['history_events']
            links = [event.get('payload', {}) for event in parent_events
                     if event.get('type') == 'WorkflowContinuedAsNew']
            require(events[0]['type'] == 'WorkflowStarted' and isinstance(started, dict)
                    and events[0].get('workflow_task_id') is None
                    and events[0].get('workflow_command_id') is None
                    and parent['workflow_id'] == run['workflow_id']
                    and started.get('continued_from_run_id') == parent['run_id']
                    and started.get('workflow_run_id') == run['run_id']
                    and started.get('workflow_instance_id') == run['workflow_id']
                    and isinstance(started.get('workflow_link_id'), str) and started['workflow_link_id']
                    and len(links) == 1 and isinstance(links[0], dict)
                    and links[0].get('continued_to_run_id') == run['run_id']
                    and links[0].get('workflow_link_id') == started['workflow_link_id'],
                    'condition_export_continuation_authority')
        else:
            require(events[0]['type'] == 'StartAccepted' and events[1]['type'] == 'WorkflowStarted'
                    and events[0].get('workflow_task_id') is None and events[1].get('workflow_task_id') is None
                    and isinstance(events[0].get('workflow_command_id'), str)
                    and events[0]['workflow_command_id']
                    and events[0]['workflow_command_id'] == events[1].get('workflow_command_id'),
                    'condition_export_start_authority')
        if case == 'C2':
            kinds = [event['type'] for event in events]
            required = ('ActivityScheduled', 'ActivityCompleted', 'ConditionWaitOpened', 'WorkflowCompleted')
            require(all(kind in kinds for kind in required), 'condition_c2_activity_missing')
            scheduled, completed, opened = (kinds.index(kind) for kind in required[:3])
            noise = [index for index, event in enumerate(events) if event['type'] == 'SignalReceived'
                     and isinstance(event.get('payload'), dict) and event['payload'].get('signal_name') == 'noise']
            require(any(scheduled < index < completed < opened for index in noise),
                    'condition_c2_signal_not_during_activity')
    return rows


def sql_input(report, exports, sql):
    rows = validate_exports(report, exports)
    require(hashlib.sha256(sql).hexdigest() == SQL_SHA256, 'condition_sql_identity')
    ids = ','.join(run['run_id'] for _, run in rows)
    return ("SET SESSION time_zone = '+00:00';\nSET @run_ids = '" + ids + "';\n"
            "SET @expected_namespace = '" + report['namespace'] + "';\n"
            "SET @max_dispatch_seconds = 2;\nSET @expected_budgets = NULL;\n"
            "START TRANSACTION READ ONLY;\n"
            "SELECT 'R0_run_inventory', COUNT(*) FROM workflow_runs "
            "WHERE FIND_IN_SET(id COLLATE utf8mb4_bin, CONVERT(@run_ids USING utf8mb4) COLLATE utf8mb4_bin) "
            "AND namespace COLLATE utf8mb4_bin = CONVERT(@expected_namespace USING utf8mb4) COLLATE utf8mb4_bin "
            "HAVING COUNT(*) <> 9;\n" + sql.decode('utf-8') + "\nROLLBACK;\n")


def main():
    if len(sys.argv) == 3 and sys.argv[1] == '--extract-report':
        root = Path(sys.argv[2])
        report = extract_report((root / 'php-condition-waits.stdout.log').read_bytes())
        sys.stdout.write(json.dumps(report, indent=2) + '\n')
        return
    root = Path(sys.argv[1])
    report = json.loads((root / 'php-condition-waits.json').read_bytes())
    rows = report_runs(report)
    exports = {run['run_id']: json.loads((root / ('php-condition-wait-export-' + run['run_id'] + '.json')).read_bytes())
               for _, run in rows}
    sql = Path(__file__).with_name('condition-wait-anchored-check.sql').read_bytes()
    sys.stdout.write(sql_input(report, exports, sql))


if __name__ == '__main__':
    main()
