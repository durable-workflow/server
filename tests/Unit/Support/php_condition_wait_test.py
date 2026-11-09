import importlib.util
import json
from pathlib import Path
import unittest

RUNNERS = Path(__file__).resolve().parents[3] / 'scripts/conformance'
spec = importlib.util.spec_from_file_location('condition_input', RUNNERS / 'php-sdk-condition-wait-input.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class InstalledConditionWaitInputTest(unittest.TestCase):
    def fixture(self):
        report = {'namespace': 'php-cwn-0123456789ab', 'cases': {}, 'run_ids': [], 'c1_timeout': 20}
        exports = {}
        number = 0
        for case in gate.CASES:
            rows = []
            for position in range(2 if case in ('C3-continue', 'C5') else 1):
                number += 1
                run_id = str(number).zfill(26)
                workflow_id = case.lower() + '-0123456789ab'
                kinds = ['StartAccepted', 'WorkflowStarted', 'ConditionWaitOpened', 'WorkflowCompleted']
                if case == 'C2':
                    kinds = ['StartAccepted', 'WorkflowStarted', 'ActivityScheduled', 'ActivityStarted',
                             'SignalReceived', 'ActivityCompleted', 'ConditionWaitOpened', 'WorkflowCompleted']
                if position == 1:
                    kinds = ['WorkflowStarted', 'WorkflowCompleted']
                events = [{'id': run_id + '-event-' + str(index), 'sequence': index, 'type': kind,
                           'workflow_command_id': 'start-authority' if index <= 2 else None,
                           'payload': {'signal_name': 'noise'} if kind == 'SignalReceived' else {}}
                          for index, kind in enumerate(kinds, 1)]
                if position == 1:
                    parent = rows[0]
                    link = 'continue-link-' + run_id
                    events[0]['workflow_command_id'] = None
                    events[0]['payload'] = {'continued_from_run_id': parent['run_id'], 'workflow_run_id': run_id,
                                            'workflow_instance_id': workflow_id, 'workflow_link_id': link}
                    parent['events'][-1] = 'WorkflowContinuedAsNew'
                    terminal = exports[parent['run_id']]['history_events'][-1]
                    terminal['type'] = 'WorkflowContinuedAsNew'
                    terminal['payload'] = {'continued_to_run_id': run_id, 'workflow_link_id': link}
                exports[run_id] = {
                    'schema': 'durable-workflow.v2.history-export', 'schema_version': 2, 'history_complete': True,
                    'workflow': {'run_id': run_id, 'instance_id': workflow_id,
                                 'source_runtime': 'standalone_server', 'source_process_class': 'server_http_node'},
                    'history_events': events,
                }
                rows.append({'run_id': run_id, 'workflow_id': workflow_id, 'events': kinds})
                report['run_ids'].append(run_id)
            report['cases'][case] = {'command_contract': {'source': 'durable_history'},
                                     'runs': rows, 'evidence': {'checked_by_installed_qualifier': True}}
        report['c1_run_ids'] = report['run_ids'][:1]
        return report, exports

    def test_complete_inventory_binds_all_nine_runs_in_a_read_only_transaction(self):
        report, exports = self.fixture()
        sql = (RUNNERS / 'condition-wait-anchored-check.sql').read_bytes()
        result = gate.sql_input(report, exports, sql)
        self.assertIn("SET @run_ids = '" + ','.join(report['run_ids']) + "';", result)
        self.assertIn('START TRANSACTION READ ONLY;\n', result)
        self.assertIn("SET @expected_namespace = 'php-cwn-0123456789ab';", result)
        self.assertIn("SELECT 'R0_run_inventory', COUNT(*)", result)
        self.assertIn('HAVING COUNT(*) <> 9;', result)
        self.assertTrue(result.endswith('\nROLLBACK;\n'))

    def test_published_cli_progress_is_retained_separately_from_the_final_report(self):
        report, _ = self.fixture()
        raw = b'ok: runtime advertises condition_wait_idempotent_reopen\nok: history fits one complete page\n'
        raw += json.dumps(report, indent=2).encode() + b'\n'
        with self.assertRaises(json.JSONDecodeError):
            json.loads(raw)
        self.assertEqual(gate.extract_report(raw), report)

    def test_cli_noise_crashes_missing_progress_and_partial_reports_refuse(self):
        report, _ = self.fixture()
        body = json.dumps(report).encode()
        for raw in (body, b'ok: started\n', b'unexpected log\n' + body,
                    b'ok: started\nunexpected log\n' + body,
                    b'ok: started\n' + body + b'\ntrailing output',
                    b'ok: started\n{}'):
            with self.subTest(raw=raw[:60]):
                with self.assertRaises(ValueError):
                    gate.extract_report(raw)

    def test_missing_duplicate_and_untrusted_run_inventories_refuse(self):
        for mutation in ('missing_case', 'extra_case', 'missing_evidence', 'contract', 'duplicate', 'sql_id', 'workflow_id'):
            with self.subTest(mutation=mutation):
                report, _ = self.fixture()
                row = report['cases']['C2']['runs'][0]
                if mutation == 'missing_case': del report['cases']['C2']
                if mutation == 'extra_case': report['cases']['unexpected'] = report['cases']['C1']
                if mutation == 'missing_evidence': report['cases']['C2']['evidence'] = {}
                if mutation == 'contract': report['cases']['C2']['command_contract']['source'] = 'inferred'
                if mutation == 'duplicate': report['run_ids'][1] = report['run_ids'][0]
                if mutation == 'sql_id': row['run_id'] = "x'; DELETE FROM workflow_runs; --"
                if mutation == 'workflow_id': row['workflow_id'] = '../other-run'
                with self.assertRaises(ValueError): gate.report_runs(report)

    def test_incomplete_foreign_imported_and_unbound_exports_refuse(self):
        for mutation in ('missing', 'truncated', 'foreign_run', 'foreign_workflow', 'imported', 'gap',
                         'duplicate_event', 'different_history', 'wrong_start'):
            with self.subTest(mutation=mutation):
                report, exports = self.fixture()
                run_id = report['run_ids'][0]
                export = exports[run_id]
                if mutation == 'missing': del exports[run_id]
                if mutation == 'truncated': export['history_complete'] = False
                if mutation == 'foreign_run': export['workflow']['run_id'] = report['run_ids'][1]
                if mutation == 'foreign_workflow': export['workflow']['instance_id'] = 'other-instance'
                if mutation == 'imported': export['migration_projection'] = {}
                if mutation == 'gap': export['history_events'][1]['sequence'] = 3
                if mutation == 'duplicate_event': export['history_events'][1]['id'] = export['history_events'][0]['id']
                if mutation == 'different_history': export['history_events'][-1]['type'] = 'WorkflowFailed'
                if mutation == 'wrong_start': export['history_events'][1]['workflow_command_id'] = 'other-command'
                with self.assertRaises(ValueError): gate.validate_exports(report, exports)

    def test_c2_must_actually_overlap_the_activity_before_opening_the_wait(self):
        for mutation in ('no_noise', 'before_activity', 'after_activity'):
            with self.subTest(mutation=mutation):
                report, exports = self.fixture()
                run_id = report['cases']['C2']['runs'][0]['run_id']
                events = exports[run_id]['history_events']
                if mutation == 'no_noise': events[4]['payload']['signal_name'] = 'other'
                if mutation == 'before_activity': events[2]['type'], events[4]['type'] = events[4]['type'], events[2]['type']; events[2]['payload'] = {'signal_name': 'noise'}
                if mutation == 'after_activity': events[4]['type'], events[5]['type'] = events[5]['type'], events[4]['type']; events[5]['payload'] = {'signal_name': 'noise'}
                report['cases']['C2']['runs'][0]['events'] = [event['type'] for event in events]
                with self.assertRaisesRegex(ValueError, 'signal_not_during_activity'):
                    gate.validate_exports(report, exports)

    def test_sql_drift_refuses_before_emitting_a_statement(self):
        report, exports = self.fixture()
        with self.assertRaisesRegex(ValueError, 'sql_identity'):
            gate.sql_input(report, exports, (RUNNERS / 'condition-wait-anchored-check.sql').read_bytes() + b'\nSELECT 1;')

    def test_continue_as_new_requires_the_exact_parent_and_both_halves_of_its_link(self):
        for mutation in ('parent', 'child', 'link', 'missing_predecessor'):
            with self.subTest(mutation=mutation):
                report, exports = self.fixture()
                parent, child = report['cases']['C5']['runs']
                started = exports[child['run_id']]['history_events'][0]['payload']
                if mutation == 'parent': started['continued_from_run_id'] = report['run_ids'][0]
                if mutation == 'child': started['workflow_run_id'] = report['run_ids'][0]
                if mutation == 'link': started['workflow_link_id'] = 'other-link'
                if mutation == 'missing_predecessor':
                    export = exports[parent['run_id']]
                    export['history_events'][-1]['type'] = 'WorkflowCompleted'
                    parent['events'][-1] = 'WorkflowCompleted'
                with self.assertRaisesRegex(ValueError, 'continuation_authority'):
                    gate.validate_exports(report, exports)


if __name__ == '__main__':
    unittest.main()
