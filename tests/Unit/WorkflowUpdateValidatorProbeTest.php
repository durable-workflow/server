<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;

class WorkflowUpdateValidatorProbeTest extends TestCase
{
    public function test_validator_probe_runs_after_principal_probe_and_drains_persisted_multiplex_cursor(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents(
            $root.'/scripts/conformance/workflow-updates-published-artifacts.sh',
        );
        $start = strpos($source, "<?php\ndeclare(strict_types=1);");
        $end = strpos(
            $source,
            "\ntry {\n    write_json_file('workflow-updates-focused-evidence.json', run_focused_probe());",
            $start === false ? 0 : $start,
        );

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);

        $temporaryRoot = is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
        $testDir = $temporaryRoot.'/dw-validator-probe-'.bin2hex(random_bytes(6));
        $probe = $testDir.'/validator-probe.php';
        $database = $testDir.'/validator-probe.sqlite';
        $result = $testDir.'/validator-results.json';
        mkdir($testDir, 0777, true);

        file_put_contents(
            $probe,
            substr($source, $start, $end - $start).<<<'PHP'

bootstrap_application();
write_json_file('validator-results.json', [
    'principal_attribution' => run_principal_attribution_probe('multiplex-regression'),
    'scenario_results' => run_update_validator_probe('multiplex-regression'),
]);
PHP,
        );

        try {
            $command = sprintf(
                '%s %s %s %s %s 2>&1',
                'APP_ENV='.escapeshellarg('testing'),
                'DB_DATABASE='.escapeshellarg($database),
                'RESULT_DIR='.escapeshellarg($testDir),
                'RUNNER_REPO_ROOT='.escapeshellarg($root),
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe),
            );
            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
            $this->assertFileExists($result, implode("\n", $output));
            $json = file_get_contents($result);
            $this->assertIsString($json, implode("\n", $output));
            $this->assertNotSame('', trim($json), implode("\n", $output));
            $evidence = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('pass', $evidence['principal_attribution']['status']);
            $scenarios = $evidence['scenario_results'];

            foreach ([
                'update_validator_approval_boundary',
                'update_validator_rejection_boundary',
                'update_validator_worker_replacement',
                'duplicate_validation_completion',
                'unsupported_validation_capability',
            ] as $scenarioId) {
                $this->assertSame('pass', $scenarios[$scenarioId]['status'], $scenarioId);
            }

            $approval = $scenarios['update_validator_approval_boundary']['observed_outputs'];
            $this->assertSame('approved', $approval['validation_task_terminal_state']['status']);
            $this->assertSame(1, $approval['validation_task_terminal_state']['attempt_count']);
            $this->assertSame(
                $approval['validation_task']['lease_owner'],
                $approval['validation_task_terminal_state']['lease_owner'],
            );

            $rejection = $scenarios['update_validator_rejection_boundary']['observed_outputs'];
            $this->assertSame('rejected', $rejection['validation_task_terminal_state']['status']);
            $this->assertSame(1, $rejection['validation_task_terminal_state']['attempt_count']);
            $this->assertSame(
                $rejection['validation_task']['lease_owner'],
                $rejection['validation_task_terminal_state']['lease_owner'],
            );

            $replacement = $scenarios['update_validator_worker_replacement']['observed_outputs'];
            $this->assertSame(
                $replacement['first_delivery']['update_validation_task_id'],
                $replacement['replacement_delivery']['update_validation_task_id'],
            );
            $this->assertSame(1, $replacement['first_delivery']['update_validation_attempt']);
            $this->assertSame(2, $replacement['replacement_delivery']['update_validation_attempt']);
            $this->assertSame('workflow', $replacement['fairness_state_before_replacement_poll']['next_task_kind']);
            $this->assertTrue($replacement['fairness_state_before_replacement_poll']['workflow_ready']);
            $this->assertTrue($replacement['fairness_state_before_replacement_poll']['validation_reclaimable']);
            $this->assertCount(1, $replacement['multiplexed_workflow_tasks_drained']);

            $drained = $replacement['multiplexed_workflow_tasks_drained'][0];
            $this->assertSame('workflow', $drained['task']['task_kind']);
            $this->assertSame(
                $replacement['replacement_delivery']['workflow_id'],
                $drained['task']['workflow_id'],
            );
            $this->assertSame(
                $replacement['replacement_delivery']['run_id'],
                $drained['task']['run_id'],
            );
            $this->assertSame(1, $drained['task']['workflow_task_attempt']);
            $this->assertSame(
                $replacement['replacement_delivery']['lease_owner'],
                $drained['task']['lease_owner'],
            );
            $this->assertSame('completed', $drained['completion']['outcome']);
        } finally {
            if (is_dir($testDir)) {
                foreach (new FilesystemIterator($testDir) as $file) {
                    if ($file->isFile()) {
                        unlink($file->getPathname());
                    }
                }
                rmdir($testDir);
            }
        }
    }

    public function test_operator_contract_explicitly_declares_no_update_validators(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/workflow-updates-published-artifacts.sh',
        );
        $start = strpos($source, 'function operator_workflow_command_contract(): array');
        $end = strpos($source, 'function operator_register_worker(', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $contractFunction = substr($source, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString("'update_validators' => []", $contractFunction);
    }
}
