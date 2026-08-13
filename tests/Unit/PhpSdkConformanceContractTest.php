<?php

namespace Tests\Unit;

use App\Support\PhpSdkConformanceContract;
use PHPUnit\Framework\TestCase;

final class PhpSdkConformanceContractTest extends TestCase
{
    public function test_manifest_publishes_source_free_process_boundary_contract(): void
    {
        $manifest = PhpSdkConformanceContract::manifest();

        $this->assertSame(PhpSdkConformanceContract::SCHEMA, $manifest['schema']);
        $this->assertSame(PhpSdkConformanceContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow/sdk', $manifest['product_boundary']['remote_package']);
        $this->assertSame('durable-workflow/workflow', $manifest['product_boundary']['embedded_server_engine']);
        $this->assertTrue($manifest['product_boundary']['server_keeps_embedded_engine_dependency']);
        $this->assertTrue($manifest['artifact_policy']['local_product_source_checkouts_used_must_be_false']);
        $this->assertTrue($manifest['topology']['client_and_worker_process_ids_must_differ']);
        $this->assertContains('durable_replay', $manifest['required_scenarios']);
        $this->assertContains('search_attributes', $manifest['required_scenarios']);
        $this->assertContains('apache_avro_provenance', $manifest['required_evidence']);
        $this->assertContains('server_image', $manifest['required_evidence']);
        $this->assertContains('worker_readiness', $manifest['required_evidence']);
        $this->assertContains('server_visible_workflow_command_contracts', $manifest['required_evidence']);
        $this->assertContains('workflow_started_command_contract', $manifest['required_evidence']);
        $this->assertContains(
            'addressable_started_contract_snapshotted_before_client_commands',
            $manifest['history_requirements'],
        );
        $this->assertSame('sdk-php', $manifest['failure_routing']['sdk']);
        $this->assertSame('server', $manifest['failure_routing']['server']);
        $this->assertSame('sdk-php-release', $manifest['failure_routing']['package-publication']);
        $this->assertSame('conformance_harness', $manifest['failure_routing']['runner']);
        $this->assertTrue($manifest['runtime_failure_evidence']['durable_observed_evidence_required']);
        $this->assertTrue($manifest['runtime_failure_evidence']['diagnostic_file_reference_alone_forbidden']);
        $this->assertSame(4096, $manifest['runtime_failure_evidence']['retained_diagnostic_excerpt_max_bytes']);
        $this->assertTrue(
            $manifest['runtime_failure_evidence']['client_timeout_and_unavailable_worker_retain_companion_process_and_server_state'],
        );
        $this->assertSame(
            'durable-workflow.v2.php-sdk-companion-failure',
            $manifest['runtime_failure_evidence']['companion_diagnostic_schema'],
        );
        $this->assertSame(6144, $manifest['runtime_failure_evidence']['companion_diagnostic_max_bytes']);
        $this->assertTrue($manifest['runtime_failure_evidence']['early_exit_scenario_result_required']);
        $this->assertTrue($manifest['runtime_failure_evidence']['early_exit_scenario_result_must_be_keyed']);
        $this->assertSame(
            [
                'lifecycle' => 'php_sdk_lifecycle_surface',
                'namespace' => 'php_worker_task_queue_namespace_isolation',
                'search-attributes' => 'php_worker_start_and_upsert_visibility',
            ],
            $manifest['runtime_failure_evidence']['early_exit_scenario_ids_by_scope'],
        );
        $this->assertSame(24576, $manifest['runtime_failure_evidence']['failure_scenario_max_bytes']);
        $this->assertSame(3072, $manifest['runtime_failure_evidence']['failure_evidence_component_max_bytes']);
        $this->assertTrue(
            $manifest['runtime_failure_evidence']['readiness_failure_retains_expected_and_observed_contracts'],
        );
        $this->assertTrue(
            $manifest['runtime_failure_evidence']['assertion_failure_retains_expected_and_observed_per_operation'],
        );
        $this->assertTrue(
            $manifest['runtime_failure_evidence']['assertion_failure_retains_worker_and_sdk_response_layers_when_observed'],
        );
        $this->assertContains(
            'public_error_envelope',
            $manifest['runtime_failure_evidence']['required_http_failure_fields'],
        );
        $this->assertContains(
            'owning_surface',
            $manifest['runtime_failure_evidence']['required_http_failure_fields'],
        );
        $this->assertSame(
            'scripts/conformance/php-sdk-published-artifacts.sh',
            $manifest['host_runner_contract']['scenario_runner_path'],
        );
        $this->assertTrue($manifest['host_runner_contract']['host_runner_implemented']);
        $searchAttributes = $manifest['host_runner_contract']['focused_scopes']['search-attributes'];
        $this->assertSame('sdk-php-search-attributes-shard.json', $searchAttributes['result_file']);
        $this->assertSame('durable-workflow/sdk', $searchAttributes['standalone_connectivity_package']);
        $this->assertFalse($searchAttributes['workflow_standalone_client_or_worker_loaded']);
        $this->assertContains('typed_values', $searchAttributes['required_evidence']);
        $this->assertContains('python_to_php_codec_reader', $searchAttributes['required_evidence']);
        $this->assertContains('php_to_python_codec_writer_handoff', $searchAttributes['required_evidence']);
    }

    public function test_static_mirror_preserves_required_scenarios_and_evidence(): void
    {
        $mirror = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/static/platform-conformance/php-sdk-conformance.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $manifest = PhpSdkConformanceContract::manifest();

        $this->assertSame($manifest['schema'], $mirror['schema']);
        $this->assertSame($manifest['version'], $mirror['version']);
        $this->assertSame($manifest['purpose'], $mirror['purpose']);
        $this->assertSame($manifest['required_scenarios'], $mirror['required_scenarios']);
        $this->assertSame($manifest['required_evidence'], $mirror['required_evidence']);
        $this->assertSame($manifest['history_requirements'], $mirror['history_requirements']);
        $this->assertSame($manifest['runtime_failure_evidence'], $mirror['runtime_failure_evidence']);
        $this->assertSame(
            $manifest['host_runner_contract']['scenario_runner_path'],
            $mirror['host_runner_contract']['scenario_runner_path'],
        );
        $this->assertSame(
            $manifest['host_runner_contract']['focused_scopes'],
            $mirror['host_runner_contract']['focused_scopes'],
        );
    }

    public function test_runner_installs_the_sdk_and_records_process_provenance(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/php-sdk-published-artifacts.sh',
        );

        $this->assertStringContainsString('"durable-workflow/sdk": "$sdk_version"', $runner);
        $this->assertStringContainsString("package_from_lock(\$lock, 'apache/avro')", $runner);
        $this->assertStringContainsString("'local_product_source_checkouts_used' => false", $runner);
        $this->assertStringContainsString("'client_worker_distinct_processes'", $runner);
        $this->assertStringContainsString("'callback_counts'", $runner);
        $this->assertStringContainsString("'history_assertions'", $runner);
        $this->assertStringContainsString('php-sdk-worker-readiness.php', $runner);
        $this->assertStringContainsString('server_visible_workflow_command_contracts', $runner);
        $this->assertStringContainsString('worker_command_contract_readiness', $runner);
        $this->assertStringContainsString('workflow_started_command_contract', $runner);
        $this->assertStringContainsString('$worker->declareSignal(', $runner);
        $this->assertStringContainsString("'invalid_signal_arguments'", $runner);
        $this->assertStringContainsString("'unknown_signal'", $runner);
        $this->assertStringContainsString('php-sdk-waiting-signal-replay.json', $runner);
        $this->assertStringContainsString('worker_process_exit', $runner);
        $this->assertStringContainsString('worker_readiness_timeout', $runner);
        $this->assertStringContainsString('last_server_observation', $runner);
        $this->assertStringContainsString('DW_PHP_SDK_CONFORMANCE_WORKER_RUN_DELAY_MS', $runner);
        $this->assertStringContainsString("'php.sdk.search-attributes'", $runner);
        $this->assertStringContainsString('sdk-php-search-attributes-shard.json', $runner);
        $this->assertStringContainsString('DW_PHP_SDK_SEARCH_ATTRIBUTES_PYTHON_FIXTURE_JSON', $runner);
        $this->assertStringContainsString('php-sdk-search-attribute-probe.php', $runner);
        $this->assertStringContainsString('php_sdk_ensure_search_attribute_definitions(', $runner);
        $this->assertStringContainsString('standalone_workflow_package_absent', $runner);
        $this->assertStringNotContainsString('$client->registerWorker(', $runner);
        $this->assertStringNotContainsString('durable-workflow/workflow:', $runner);
        $this->assertStringNotContainsString('"type": "path"', $runner);
        $delayPosition = strpos($runner, "getenv('DW_PHP_SDK_CONFORMANCE_WORKER_RUN_DELAY_MS')");
        $managedRunPosition = strpos($runner, '$worker->run(1);');
        $this->assertIsInt($delayPosition);
        $this->assertIsInt($managedRunPosition);
        $this->assertLessThan($managedRunPosition, $delayPosition);

        $addressableStartPosition = strpos($runner, "startWorkflow('php.sdk.waiting', \$addressableWorkflowId");
        $startedHistoryPosition = strpos($runner, '$addressableStartedHistory = $client->workflowHistory(');
        $startedContractPosition = strpos($runner, '$addressableStartedContract = php_sdk_waiting_started_contract_evidence(');
        $firstSignalPosition = strpos($runner, "\$addressable->signal('increment', [3]);");
        $this->assertIsInt($addressableStartPosition);
        $this->assertIsInt($startedHistoryPosition);
        $this->assertIsInt($startedContractPosition);
        $this->assertIsInt($firstSignalPosition);
        $this->assertLessThan($startedHistoryPosition, $addressableStartPosition);
        $this->assertLessThan($startedContractPosition, $startedHistoryPosition);
        $this->assertLessThan($firstSignalPosition, $startedContractPosition);
    }

    public function test_generated_handlers_execute_with_the_current_fiber_sdk(): void
    {
        $composerBinary = trim((string) shell_exec('command -v composer 2>/dev/null'));
        $this->assertNotSame('', $composerBinary, 'Composer is required for exact published SDK validation.');

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-php-sdk-handler-validation-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $process = proc_open(
            [
                $repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh',
                '--result-dir',
                $resultDir,
                '--validate-definitions',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
            array_merge($_ENV, [
                'DW_PHP_SDK_VERSION' => '2.0.0-rc.30',
            ]),
        );

        try {
            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(
                0,
                proc_close($process),
                "Generated SDK handler validation failed.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
            );

            $report = json_decode(
                (string) file_get_contents($resultDir.'/php-sdk-handler-definition-validation.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame('2.0.0-rc.30', $report['sdk_version']);
            $this->assertTrue($report['replay_matrix_enabled']);
            $this->assertSame(
                [
                    'lifecycle_activity' => 'schedule_activity',
                    'replay_timer' => 'start_timer',
                    'search_attributes' => 'upsert_search_attributes',
                    'signal_query_wait' => 'start_timer',
                ],
                array_map(
                    static function (array $scenario): string {
                        self::assertTrue($scenario['executed']);
                        self::assertSame($scenario['expected_first_command'], $scenario['observed_first_command']);

                        return $scenario['observed_first_command'];
                    },
                    $report['fiber_runtime_scenarios'],
                ),
            );
            $this->assertContains('php.sdk.failure', $report['registered_contracts']['workflows']);
            $this->assertContains('php.sdk.replay-matrix', $report['registered_contracts']['workflows']);
            $this->assertContains('php.sdk.replay-matrix-fail', $report['registered_contracts']['activities']);
            $this->assertContains('current', $report['registered_contracts']['workflow_commands']['php.sdk.waiting']['queries']);
            $this->assertContains('set', $report['registered_contracts']['workflow_commands']['php.sdk.waiting']['updates']);
            $this->assertContains('state', $report['registered_contracts']['workflow_commands']['php.sdk.replay-matrix']['queries']);
            $this->assertContains('set', $report['registered_contracts']['workflow_commands']['php.sdk.replay-matrix']['updates']);

            $workflowRejection = $report['zero_argument_rejections']['workflow php.sdk.failure'];
            $this->assertSame('workflow php.sdk.failure', $workflowRejection['contract']);
            $this->assertSame(
                'DurableWorkflow\\Exception\\InvalidWorkerDefinition',
                $workflowRejection['exception_type'],
            );
            $this->assertSame(
                'Invalid worker contract workflow php.sdk.failure. Make the first handler parameter DurableWorkflow\\Worker\\WorkflowContext.',
                $workflowRejection['message'],
            );

            $activityRejection = $report['zero_argument_rejections']['activity php.sdk.replay-matrix-fail'];
            $this->assertSame('activity php.sdk.replay-matrix-fail', $activityRejection['contract']);
            $this->assertSame(
                'Invalid worker contract activity php.sdk.replay-matrix-fail. Make the first handler parameter DurableWorkflow\\Worker\\ActivityContext.',
                $activityRejection['message'],
            );
        } finally {
            $this->removeDirectory($resultDir);
        }
    }

    public function test_search_attribute_scope_emits_a_focused_bounded_preflight_result(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise structured PHP SDK evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-php-sdk-search-attributes-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);

        try {
            $command = implode(' ', [
                'DW_PHP_SDK_VERSION=0.1.13',
                'DW_SERVER_VERSION=0.2.693',
                'DW_SERVER_IMAGE=durableworkflow/server:0.2.693',
                escapeshellarg($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh'),
                '--scope',
                'search-attributes',
                '--result-dir',
                escapeshellarg($resultDir),
            ]);
            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));
            $shard = json_decode(
                (string) file_get_contents($resultDir.'/sdk-php-search-attributes-shard.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $scenario = $shard['scenario_results']['php_worker_start_and_upsert_visibility'];

            $this->assertSame('durable-workflow.v2.search-attribute-runtime.sdk-php-shard', $shard['schema']);
            $this->assertTrue($shard['runner_blocked']);
            $this->assertSame('runner_blocked', $scenario['status']);
            $this->assertSame('conformance_harness', $scenario['linked_findings'][0]['owning_surface']);
            $this->assertSame('durable-workflow/sdk', $shard['package_ownership']['standalone_connectivity']);
            $this->assertFalse($shard['package_ownership']['workflow_standalone_client_or_worker_loaded']);
        } finally {
            foreach (scandir($resultDir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($resultDir.'/'.$entry);
                }
            }
            rmdir($resultDir);
        }
    }

    public function test_every_scope_retains_a_keyed_bounded_scenario_on_preflight_exit(): void
    {
        if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('node is required to exercise structured PHP SDK evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $manifest = PhpSdkConformanceContract::manifest();
        $scenarioIds = $manifest['runtime_failure_evidence']['early_exit_scenario_ids_by_scope'];
        $scenarioMaxBytes = $manifest['runtime_failure_evidence']['failure_scenario_max_bytes'];

        foreach ($scenarioIds as $scope => $scenarioId) {
            $resultDir = sys_get_temp_dir().'/dw-php-sdk-early-exit-'.$scope.'-'.bin2hex(random_bytes(6));
            mkdir($resultDir, 0777, true);

            try {
                $command = implode(' ', [
                    'env -u DW_PHP_SDK_CONFORMANCE_SERVER_URL',
                    'DW_PHP_SDK_VERSION=0.1.15',
                    'DW_SERVER_VERSION=0.2.694',
                    'DW_SERVER_IMAGE=durableworkflow/server:0.2.694',
                    escapeshellarg($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh'),
                    '--scope',
                    escapeshellarg($scope),
                    '--result-dir',
                    escapeshellarg($resultDir),
                ]);
                $output = [];
                $exitCode = 0;
                exec($command.' 2>&1', $output, $exitCode);

                $this->assertSame(0, $exitCode, implode("\n", $output));
                $result = json_decode(
                    (string) file_get_contents($resultDir.'/php-sdk-conformance-result.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );

                $this->assertSame([$scenarioId], array_keys($result['scenario_results']));
                $scenario = $result['scenario_results'][$scenarioId];
                $observed = $scenario['observed_outputs'];
                $this->assertSame($scenarioId, $scenario['scenario_id']);
                $this->assertSame('runner_blocked', $scenario['status']);
                $this->assertSame('preflight', $observed['failure_stage']);
                $this->assertSame('runner', $observed['failure_classification']);
                $this->assertSame('conformance_harness', $observed['failure_owner']);
                $this->assertSame('not_started', $observed['worker_evidence']['process_state']['state']);
                $this->assertSame('durableworkflow/server:0.2.694', $observed['server_evidence']['image']);
                $this->assertLessThanOrEqual(
                    $scenarioMaxBytes,
                    strlen(json_encode($scenario, JSON_THROW_ON_ERROR)),
                );
            } finally {
                foreach (glob($resultDir.'/*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($resultDir);
            }
        }
    }

    public function test_worker_readiness_waits_for_the_authoritative_handler_contract(): void
    {
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('allow_url_fopen is required to exercise worker readiness.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-worker-readiness.php';
        $resultDir = sys_get_temp_dir().'/dw-php-sdk-worker-readiness-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $autoload = $resultDir.'/autoload.php';
        $router = $resultDir.'/router.php';
        $registrationStateFile = $resultDir.'/registration-state';
        $serverLog = $resultDir.'/server.log';
        $metadataFile = $resultDir.'/worker.json';
        require_once $repoRoot.'/scripts/conformance/php-sdk-started-contract.php';
        $completeContract = php_sdk_waiting_command_contract();
        unset($completeContract['workflow_type']);
        $completeContract['query_contracts'][0] = array_reverse(
            $completeContract['query_contracts'][0],
            true,
        );
        $completeContract['update_contracts'][0]['parameters'][0] = array_reverse(
            $completeContract['update_contracts'][0]['parameters'][0],
            true,
        );
        $completeContract['update_contracts'][0] = array_reverse(
            $completeContract['update_contracts'][0],
            true,
        );
        $completeContract = array_reverse($completeContract, true);
        file_put_contents($autoload, <<<'PHP'
<?php
namespace Composer {
    final class InstalledVersions
    {
        public static function getPrettyVersion(string $package): ?string
        {
            return '0.1.6';
        }

        public static function getVersion(string $package): ?string
        {
            return '0.1.6.0';
        }
    }
}
namespace DurableWorkflow {
    final class Version
    {
        public const CONTROL_PLANE_PROTOCOL = '2';
    }
}
namespace DurableWorkflow\Transport {
    final class Psr18Transport
    {
        public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
        {
            $formattedHeaders = [];
            foreach ($headers as $name => $value) {
                $formattedHeaders[] = $name.': '.$value;
            }
            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $formattedHeaders),
                    'ignore_errors' => true,
                ],
            ]);
            $response = file_get_contents($uri, false, $context);

            return json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
        }
    }
}
PHP);
        file_put_contents($router, '<?php'."\n"
            .'$headers = getallheaders();'."\n"
            .'if (($headers[\'Authorization\'] ?? null) !== \'Bearer control-token\''
            .' || ($headers[\'X-Namespace\'] ?? null) !== \'workflow-lifecycle-conformance\''
            .' || ($headers[\'X-Durable-Workflow-Control-Plane-Version\'] ?? null) !== \'2\') {'."\n"
            .'    http_response_code(401);'."\n"
            .'    echo json_encode([\'reason\' => \'unauthorized\']);'."\n"
            .'    return;'."\n"
            .'}'."\n"
            .'$stateFile = '.var_export($registrationStateFile, true).';'."\n"
            .'$observation = (int) file_get_contents($stateFile) + 1;'."\n"
            .'file_put_contents($stateFile, (string) $observation, LOCK_EX);'."\n"
            .'if ($observation >= 4) {'."\n"
            .'    usleep(300000);'."\n"
            .'    $contracts = [\'php.sdk.waiting\' => '.var_export($completeContract, true).'];'."\n"
            .'} elseif ($observation >= 2) {'."\n"
            .'    $contracts = [\'php.sdk.waiting\' => [\'queries\' => [\'current\'], \'query_contracts\' => [], \'signals\' => [\'increment\'], \'signal_contracts\' => [], \'updates\' => [\'set\'], \'update_contracts\' => []]];'."\n"
            .'} else {'."\n"
            .'    $contracts = [];'."\n"
            .'}'."\n"
            .'header(\'Content-Type: application/json\');'."\n"
            .'echo json_encode(['."\n"
            .'    \'worker_id\' => \'php-sdk-worker-1\','."\n"
            .'    \'status\' => \'active\','."\n"
            .'    \'last_heartbeat_at\' => \'2026-07-15T20:00:00Z\','."\n"
            .'    \'workflow_command_contracts\' => $contracts,'."\n"
            .']);'."\n");

        $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorMessage);
        $this->assertIsResource($socket, $socketErrorMessage);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [
                1 => ['file', $serverLog, 'a'],
                2 => ['file', $serverLog, 'a'],
            ],
            $pipes,
            $resultDir,
        );
        $this->assertIsResource($process);

        try {
            $serverReady = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.05);
                if (is_resource($connection)) {
                    fclose($connection);
                    $serverReady = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($serverReady, (string) @file_get_contents($serverLog));

            $startedEpoch = microtime(true);
            file_put_contents($registrationStateFile, '0');
            $invoke = function (int $attempt) use (
                $helper,
                $autoload,
                $address,
                $resultDir,
                $startedEpoch,
                $metadataFile,
            ): int {
                $command = [
                    PHP_BINARY,
                    $helper,
                    $autoload,
                    'http://'.$address,
                    'workflow-lifecycle-conformance',
                    'control-token',
                    'php-sdk-worker-1',
                    '4321',
                    $resultDir,
                    'lifecycle',
                    '2026-07-15T20:00:00Z',
                    (string) $startedEpoch,
                    (string) $attempt,
                    $metadataFile,
                ];
                $probe = proc_open(
                    $command,
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $probePipes,
                    $resultDir,
                );
                self::assertIsResource($probe);
                stream_get_contents($probePipes[1]);
                $stderr = stream_get_contents($probePipes[2]);
                fclose($probePipes[1]);
                fclose($probePipes[2]);
                $exitCode = proc_close($probe);
                self::assertContains($exitCode, [0, 1], (string) $stderr);

                return $exitCode;
            };

            $this->assertSame(1, $invoke(1));
            $this->assertFileDoesNotExist($metadataFile);
            $exitCode = 1;
            $attempt = 1;
            while ($exitCode === 1 && $attempt < 40) {
                usleep(25000);
                $exitCode = $invoke(++$attempt);
            }

            $this->assertSame(0, $exitCode);
            $metadata = json_decode(
                (string) file_get_contents($metadataFile),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame(4, $metadata['readiness']['attempts']);
            $this->assertGreaterThanOrEqual(250, $metadata['readiness']['wait_ms']);
            $this->assertTrue($metadata['readiness']['contract_free_registration_observed']);
            $this->assertTrue($metadata['readiness']['name_only_registration_observed']);
            $this->assertTrue($metadata['readiness']['client_release_after_authoritative_registration']);
            $this->assertSame($completeContract, $metadata['server_visible_registration']['workflow_command_contracts']['php.sdk.waiting']);
            $readinessObservation = json_decode(
                (string) file_get_contents(
                    $resultDir.'/php-sdk-worker-php-sdk-worker-1.readiness-observation.json',
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame(200, $readinessObservation['last_server_observation']['http_status']);
            $this->assertSame(
                $completeContract,
                $readinessObservation['last_server_observation']['payload']['workflow_command_contracts']['php.sdk.waiting'],
            );
        } finally {
            proc_terminate($process);
            proc_close($process);
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($resultDir);
        }
    }

    public function test_command_contract_matcher_ignores_only_json_object_key_order(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-started-contract.php';

        $required = php_sdk_waiting_command_contract();
        $serverContract = $required;
        unset($serverContract['workflow_type']);
        $serverContract['query_contracts'][0] = array_reverse(
            $serverContract['query_contracts'][0],
            true,
        );
        $serverContract['update_contracts'][0]['parameters'][0] = array_reverse(
            $serverContract['update_contracts'][0]['parameters'][0],
            true,
        );
        $serverContract['update_contracts'][0] = array_reverse(
            $serverContract['update_contracts'][0],
            true,
        );
        $serverContract = array_reverse($serverContract, true);

        $this->assertTrue(php_sdk_command_contract_matches($serverContract, $required));
        $this->assertTrue(php_sdk_started_payload_matches([
            'declared_update_contracts' => $serverContract['update_contracts'],
            'declared_updates' => $serverContract['updates'],
            'declared_signal_contracts' => $serverContract['signal_contracts'],
            'declared_signals' => $serverContract['signals'],
            'declared_query_contracts' => $serverContract['query_contracts'],
            'declared_queries' => $serverContract['queries'],
        ], $required));

        $invalidContracts = [];
        $invalidContracts['query name'] = $serverContract;
        $invalidContracts['query name']['queries'][0] = 'changed';
        $invalidContracts['query contract name'] = $serverContract;
        $invalidContracts['query contract name']['query_contracts'][0]['name'] = 'changed';
        $invalidContracts['signal name'] = $serverContract;
        $invalidContracts['signal name']['signals'][0] = 'changed';
        $invalidContracts['signal contract name'] = $serverContract;
        $invalidContracts['signal contract name']['signal_contracts'][0]['name'] = 'changed';
        $invalidContracts['update name'] = $serverContract;
        $invalidContracts['update name']['updates'][0] = 'changed';
        $invalidContracts['update contract name'] = $serverContract;
        $invalidContracts['update contract name']['update_contracts'][0]['name'] = 'changed';
        foreach ([
            'parameter name' => ['name', 'changed'],
            'parameter position' => ['position', 1],
            'parameter required' => ['required', false],
            'parameter variadic' => ['variadic', true],
            'parameter default availability' => ['default_available', true],
            'parameter default' => ['default', 0],
            'parameter type' => ['type', 'string'],
            'parameter nullability' => ['allows_null', true],
        ] as $label => [$field, $value]) {
            $invalidContracts[$label] = $serverContract;
            $invalidContracts[$label]['update_contracts'][0]['parameters'][0][$field] = $value;

            $signalLabel = 'signal '.$label;
            $invalidContracts[$signalLabel] = $serverContract;
            $invalidContracts[$signalLabel]['signal_contracts'][0]['parameters'][0][$field] = $value;
        }

        foreach ($invalidContracts as $label => $invalidContract) {
            $this->assertFalse(
                php_sdk_command_contract_matches($invalidContract, $required),
                $label.' must remain contract-significant.',
            );
        }

        $orderedParametersRequired = $required;
        $secondParameter = $required['update_contracts'][0]['parameters'][0];
        $secondParameter['name'] = 'second';
        $secondParameter['position'] = 1;
        $orderedParametersRequired['update_contracts'][0]['parameters'][] = $secondParameter;
        $reorderedParameters = $orderedParametersRequired;
        unset($reorderedParameters['workflow_type']);
        $reorderedParameters['update_contracts'][0]['parameters'] = array_reverse(
            $reorderedParameters['update_contracts'][0]['parameters'],
        );
        $this->assertFalse(php_sdk_command_contract_matches(
            $reorderedParameters,
            $orderedParametersRequired,
        ));

        $orderedNamesRequired = $required;
        $orderedNamesRequired['queries'][] = 'second';
        $orderedNamesRequired['query_contracts'][] = ['name' => 'second', 'parameters' => []];
        $reorderedNames = $orderedNamesRequired;
        unset($reorderedNames['workflow_type']);
        $reorderedNames['queries'] = array_reverse($reorderedNames['queries']);
        $this->assertFalse(php_sdk_command_contract_matches($reorderedNames, $orderedNamesRequired));
    }

    public function test_started_contract_gate_rejects_one_immutable_name_only_event(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-started-contract.php';

        $history = [
            'events' => [[
                'sequence' => 1,
                'event_type' => 'WorkflowStarted',
                'payload' => [
                    'declared_queries' => ['current'],
                    'declared_query_contracts' => [],
                    'declared_signals' => ['increment'],
                    'declared_signal_contracts' => [],
                    'declared_updates' => ['set'],
                    'declared_update_contracts' => [],
                ],
            ]],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared_query_contracts');

        php_sdk_waiting_started_contract_evidence(
            $history,
            'php-sdk-addressable-fixture',
            'run-fixture',
            '2026-07-15T23:00:00Z',
            microtime(true),
        );
    }

    public function test_started_contract_gate_accepts_one_complete_immutable_event_before_commands(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-started-contract.php';

        $required = php_sdk_waiting_command_contract();
        $serverQueryContracts = $required['query_contracts'];
        $serverQueryContracts[0] = array_reverse($serverQueryContracts[0], true);
        $serverUpdateContracts = $required['update_contracts'];
        $serverUpdateContracts[0]['parameters'][0] = array_reverse(
            $serverUpdateContracts[0]['parameters'][0],
            true,
        );
        $serverUpdateContracts[0] = array_reverse($serverUpdateContracts[0], true);
        $serverSignalContracts = $required['signal_contracts'];
        $serverSignalContracts[0]['parameters'][0] = array_reverse(
            $serverSignalContracts[0]['parameters'][0],
            true,
        );
        $serverSignalContracts[0] = array_reverse($serverSignalContracts[0], true);
        $started = [
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'timestamp' => '2026-07-15T23:00:00Z',
            'payload' => [
                'declared_queries' => $required['queries'],
                'declared_query_contracts' => $serverQueryContracts,
                'declared_signals' => $required['signals'],
                'declared_signal_contracts' => $serverSignalContracts,
                'declared_updates' => $required['updates'],
                'declared_update_contracts' => $serverUpdateContracts,
            ],
        ];

        $evidence = php_sdk_waiting_started_contract_evidence(
            ['events' => [$started]],
            'php-sdk-addressable-fixture',
            'run-fixture',
            '2026-07-15T23:00:00Z',
            microtime(true) - 0.05,
        );

        $this->assertSame('durable_history', $evidence['command_contract_source']);
        $this->assertSame(1, $evidence['history_reads']);
        $this->assertTrue($evidence['validated_before_client_commands']);
        $this->assertSame($required, $evidence['required_workflow_command_contract']);
        $this->assertSame($started, $evidence['workflow_started_event']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['workflow_started_event_fingerprint']);
        $this->assertGreaterThanOrEqual(40, $evidence['snapshot_wait_after_start_ms']);
    }

    public function test_runner_has_a_focused_namespace_scope_with_incremental_evidence(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/php-sdk-published-artifacts.sh',
        );

        $this->assertStringContainsString('[--scope lifecycle|namespace|search-attributes]', $runner);
        $this->assertStringContainsString('if [[ "$scope" == namespace ]]; then', $runner);
        $this->assertStringContainsString('initial_client_phase=namespace', $runner);
        $this->assertStringContainsString('run_namespace_probe', $runner);
        $this->assertStringContainsString('php-sdk-namespace-evidence.json', $runner);
        $this->assertStringContainsString('worker_namespace_registration', $runner);
        $this->assertStringContainsString('namespace_worker_execution', $runner);
        $this->assertStringContainsString('write_namespace_result', $runner);
    }

    public function test_runtime_failure_uses_full_stdout_and_retains_early_php_display_errors(): void
    {
        if (! is_file('/bin/bash')) {
            $this->markTestSkipped('bash is required to exercise runtime failure evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.sh';
        $runner = (string) file_get_contents(
            $repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh',
        );
        $this->assertStringContainsString(
            'classification="$(classify_runtime_failure "$stdout_file" "$stderr_file")"',
            $runner,
        );
        $this->assertStringContainsString(
            'capture_runtime_diagnostic "$stdout_file" "$stderr_file" "$diagnostic_file" "$classification"',
            $runner,
        );
        $this->assertStringContainsString(
            "const excerpt = fs.readFileSync(diagnosticFile, 'utf8');",
            $runner,
        );
        $this->assertStringContainsString('finding.observed_evidence = runtimeFailure;', $runner);
        $this->assertStringContainsString('observed.runtime_failure_evidence = runtimeFailure;', $runner);
        $this->assertStringContainsString('assertCompleteHttpFailureEvidence(runtimeFailure, classification);', $runner);
        $this->assertStringContainsString(
            'capture_expected_terminal_exception(',
            $runner,
        );
        $this->assertStringContainsString(
            "set_runtime_failure_context('workflow.update:set'",
            $runner,
        );
        $tempRoot = sys_get_temp_dir().'/dw-php-sdk-diagnostic-'.bin2hex(random_bytes(6));
        mkdir($tempRoot, 0777, true);
        $stdoutFile = $tempRoot.'/client.stdout';
        $stderrFile = $tempRoot.'/client.stderr';
        $diagnosticFile = $tempRoot.'/client.diagnostic.log';
        file_put_contents(
            $stdoutFile,
            "PHP Fatal error: Uncaught ServerException: HTTP/1.1 500 Internal Server Error\n"
                .str_repeat("# trailing stack frame\n", 1000),
        );
        file_put_contents($stderrFile, '');

        try {
            $process = proc_open(
                [
                    '/bin/bash',
                    '-c',
                    'source "$1"; classification="$(classify_runtime_failure "$2" "$3")"; capture_runtime_diagnostic "$2" "$3" "$4" "$classification"; printf "%s\\n" "$classification"',
                    'php-sdk-runtime-failure-test',
                    $helper,
                    $stdoutFile,
                    $stderrFile,
                    $diagnosticFile,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
            );

            $this->assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(0, $exitCode, (string) $stderr);
            $this->assertSame('server', trim((string) $stdout));
            $this->assertGreaterThan(4096, filesize($stdoutFile));
            $this->assertStringContainsString('HTTP/1.1 500 Internal Server Error', (string) file_get_contents($diagnosticFile));
            $this->assertLessThanOrEqual(8192, filesize($diagnosticFile));

            $structuredPayload = [
                'classification' => 'server',
                'owning_surface' => 'server',
                'operation' => 'workflow.result:cancelled',
                'status_code' => 503,
                'public_error_envelope' => [
                    'message' => str_repeat('quote-heavy "response" ', 180),
                ],
                'workflow_id' => 'workflow-123',
                'run_id' => 'run-456',
            ];
            file_put_contents($stdoutFile, '');
            file_put_contents(
                $stderrFile,
                'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode($structuredPayload, JSON_THROW_ON_ERROR)."\n",
            );
            $process = proc_open(
                [
                    '/bin/bash',
                    '-c',
                    'source "$1"; capture_runtime_diagnostic "$2" "$3" "$4" server',
                    'php-sdk-runtime-failure-test',
                    $helper,
                    $stdoutFile,
                    $stderrFile,
                    $diagnosticFile,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
            );
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);

            $diagnostic = (string) file_get_contents($diagnosticFile);
            $this->assertMatchesRegularExpression('/DW_PHP_SDK_RUNTIME_FAILURE=(\{[^\r\n]+\})/', $diagnostic);
            preg_match('/DW_PHP_SDK_RUNTIME_FAILURE=(\{[^\r\n]+\})/', $diagnostic, $matches);
            $preserved = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('workflow.result:cancelled', $preserved['operation']);
            $this->assertSame('run-456', $preserved['run_id']);
            $this->assertLessThanOrEqual(8192, filesize($diagnosticFile));
        } finally {
            foreach (glob($tempRoot.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempRoot);
        }
    }

    public function test_structured_http_failure_is_bounded_redacted_and_durable(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise structured failure evidence.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs';
        $payload = [
            'classification' => 'server',
            'owning_surface' => 'server',
            'process' => 'client',
            'operation' => 'workflow.update:set',
            'http_method' => 'POST',
            'endpoint' => '/api/workflows/{workflow_id}/update/set',
            'status_code' => 404,
            'public_error_envelope' => [
                'error' => 'workflow_update_not_found',
                'message' => 'Update set is not declared for this workflow.',
                'reason' => 'unknown_update',
                'workflow_id' => 'php-sdk-addressable-123',
                'run_id' => 'run-456',
                'token' => 'private-test-token',
                'details' => str_repeat('bounded detail ', 400),
            ],
            'workflow_id' => 'php-sdk-addressable-123',
            'run_id' => 'run-456',
            'exception_type' => 'DurableWorkflow\\Exception\\UpdateFailed',
            'message' => 'Update set failed with token private-test-token.',
        ];
        $diagnostic = "[stderr: php-sdk-client-baseline.json.log]\n"
            .'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode($payload, JSON_THROW_ON_ERROR)."\n";
        $node = <<<'JS'
const fs = require('node:fs');
const helper = require(process.argv[1]);
const source = fs.readFileSync(0, 'utf8');
const evidence = helper.extractRuntimeFailureEvidence(source, {secrets: ['private-test-token']});
const summary = helper.failureSummary(evidence, 'baseline_client', 'fallback');
process.stdout.write(JSON.stringify({
    evidence,
    summary,
    evidence_bytes: helper.serializedBytes(evidence),
    envelope_bytes: helper.serializedBytes(evidence.public_error_envelope),
}));
JS;
        $process = proc_open(
            [$nodeBinary, '-e', $node, $helper],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
        );

        $this->assertIsResource($process);
        fwrite($pipes[0], $diagnostic);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);
        $result = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        $evidence = $result['evidence'];
        $this->assertSame(404, $evidence['status_code']);
        $this->assertSame('workflow.update:set', $evidence['operation']);
        $this->assertSame('php-sdk-addressable-123', $evidence['workflow_id']);
        $this->assertSame('run-456', $evidence['run_id']);
        $this->assertSame('server', $evidence['owning_surface']);
        $this->assertSame('unknown_update', $evidence['public_error_envelope']['reason']);
        $this->assertTrue($evidence['public_error_envelope']['_truncated']);
        $this->assertLessThanOrEqual(4096, $result['evidence_bytes']);
        $this->assertLessThanOrEqual(2048, $result['envelope_bytes']);
        $this->assertStringNotContainsString('private-test-token', (string) $stdout);
        $this->assertStringContainsString('HTTP 404', $result['summary']);
        $this->assertStringContainsString('workflow.update:set', $result['summary']);
        $this->assertStringNotContainsString('.diagnostic.log', $result['summary']);
    }

    public function test_http_envelope_compaction_enforces_serialized_utf8_bytes(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-runtime-failure.php';

        foreach ([
            'quote-heavy' => str_repeat('"\\', 3000),
            'multibyte' => str_repeat('😀漢字', 3000),
        ] as $name => $adversarial) {
            $envelope = \bounded_runtime_failure_envelope([
                'error' => $adversarial,
                'message' => $adversarial,
                'reason' => $adversarial,
                'workflow_id' => $adversarial,
                'run_id' => $adversarial,
            ], []);
            $serialized = json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );

            $this->assertLessThanOrEqual(
                2048,
                strlen($serialized),
                $name.' envelope exceeded the serialized byte contract.',
            );
            $this->assertTrue($envelope['_truncated'] ?? false);
        }
    }

    public function test_terminal_result_capture_rethrows_unexpected_exceptions(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-runtime-failure.php';

        $expected = new SyntheticTerminalException('cancelled');
        $captured = \capture_expected_terminal_exception(
            static fn (): never => throw $expected,
            SyntheticTerminalException::class,
        );
        $this->assertSame(SyntheticTerminalException::class, $captured['type']);

        $serverFailure = new SyntheticServerException('HTTP 503');
        try {
            \capture_expected_terminal_exception(
                static fn (): never => throw $serverFailure,
                SyntheticTerminalException::class,
            );
            $this->fail('Unexpected server exceptions must reach the structured exception handler.');
        } catch (SyntheticServerException $exception) {
            $this->assertSame($serverFailure, $exception);
        }
    }

    public function test_server_classification_rejects_missing_or_malformed_markers(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise structured failure validation.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs';
        $node = <<<'JS'
const helper = require(process.argv[1]);
const sources = [
  'ServerException: HTTP 500',
  'DW_PHP_SDK_RUNTIME_FAILURE={malformed',
  `DW_PHP_SDK_RUNTIME_FAILURE=${JSON.stringify({
    classification: 'server',
    owning_surface: 'server',
    status_code: 500,
    operation: 'unknown',
    public_error_envelope: null,
  })}`,
  `DW_PHP_SDK_RUNTIME_FAILURE=${JSON.stringify({
    classification: 'server',
    owning_surface: 'server',
    status_code: 500,
    operation: 'workflow.start',
    public_error_envelope: {},
  })}`,
];
const rejected = sources.map((source) => {
  const evidence = helper.extractRuntimeFailureEvidence(source);
  try {
    helper.assertCompleteHttpFailureEvidence(evidence, 'server');
    return false;
  } catch {
    return true;
  }
});
process.stdout.write(JSON.stringify(rejected));
JS;
        $process = proc_open(
            [$nodeBinary, '-e', $node, $helper],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);
        $this->assertSame([true, true, true, true], json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_failure_writer_fails_closed_without_complete_http_evidence(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the failure writer.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh');
        $matched = preg_match(
            "~write_failure\\(\\) \\{.*?node <<'NODE'\n(.*?)\nNODE\n\\}~s",
            $runner,
            $matches,
        );
        $this->assertSame(1, $matched);
        $writer = $matches[1];
        $resultDir = sys_get_temp_dir().'/dw-php-sdk-failure-writer-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $diagnosticFile = $resultDir.'/baseline.diagnostic.log';
        $companionFile = $resultDir.'/baseline-companion.json';
        file_put_contents($companionFile, json_encode([
            'schema' => 'durable-workflow.v2.php-sdk-companion-failure',
            'version' => 1,
            'failure_kind' => 'client_timeout',
            'operation' => 'worker.run',
            'classification' => 'server',
            'owning_surface' => 'server',
            'classification_basis' => 'worker_protocol_server_failure',
            'worker' => [
                'worker_id' => 'php-sdk-worker-1',
                'process_state' => ['state' => 'exited', 'alive' => false, 'exit_code' => 1],
                'last_protocol_failure' => ['status_code' => 500, 'operation' => 'worker.run'],
            ],
            'server' => [
                'health' => ['http_status' => 200, 'payload' => ['status' => 'serving']],
                'run_state' => ['http_status' => 200, 'payload' => ['status' => 'running']],
            ],
            'retained_after_cleanup' => true,
            'max_bytes' => 6144,
        ], JSON_THROW_ON_ERROR));
        $environment = array_merge($_ENV, [
            'RESULT_DIR' => $resultDir,
            'SDK_VERSION' => '0.1.5',
            'SERVER_VERSION' => '0.2.657',
            'SERVER_IMAGE' => 'durableworkflow/server:0.2.657',
            'SERVER_URL' => 'http://server.test',
            'NAMESPACE' => 'conformance',
            'STARTED_AT' => '2026-07-14T00:00:00Z',
            'FAILURE_CLASSIFICATION' => 'server',
            'FAILURE_OWNER' => 'server',
            'FAILURE_STAGE' => 'baseline_client',
            'FAILURE_SUMMARY' => 'generic fallback',
            'FAILURE_DIAGNOSTIC_FILE' => $diagnosticFile,
            'FAILURE_COMPANION_FILE' => $companionFile,
            'FAILURE_EVIDENCE_HELPER' => $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs',
            'CONTROL_TOKEN' => 'control-secret',
            'WORKER_TOKEN' => 'worker-secret',
        ]);

        try {
            file_put_contents($diagnosticFile, 'ServerException: HTTP 500 without a marker');
            $process = proc_open(
                [$nodeBinary, '-e', $writer],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                $environment,
            );
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertNotSame(0, proc_close($process));
            $this->assertStringContainsString('missing a valid status', (string) $stderr);
            $this->assertFileDoesNotExist($resultDir.'/php-sdk-conformance-result.json');

            $payload = [
                'classification' => 'server',
                'owning_surface' => 'server',
                'process' => 'client',
                'operation' => 'workflow.result:cancelled',
                'http_method' => 'GET',
                'endpoint' => '/api/workflows/{workflow_id}/runs/{run_id}/result',
                'status_code' => 503,
                'public_error_envelope' => ['error' => 'temporarily_unavailable'],
                'workflow_id' => 'workflow-123',
                'run_id' => 'run-456',
                'exception_type' => 'DurableWorkflow\\Exception\\ServerException',
                'message' => 'temporarily unavailable',
            ];
            file_put_contents(
                $diagnosticFile,
                'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode($payload, JSON_THROW_ON_ERROR)."\n",
            );
            $process = proc_open(
                [$nodeBinary, '-e', $writer],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                $environment,
            );
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $stderr);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/php-sdk-conformance-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $evidence = $result['findings'][0]['observed_evidence'];
            $this->assertSame(503, $evidence['status_code']);
            $this->assertSame('workflow.result:cancelled', $evidence['operation']);
            $this->assertSame('workflow-123', $evidence['workflow_id']);
            $this->assertSame('run-456', $evidence['run_id']);
            $this->assertSame('server', $result['findings'][0]['owning_surface']);
            $this->assertSame(['php_sdk_lifecycle_surface'], array_keys($result['scenario_results']));
            $scenario = $result['scenario_results']['php_sdk_lifecycle_surface'];
            $observed = $scenario['observed_outputs'];
            $this->assertSame('fail', $scenario['status']);
            $this->assertSame('baseline_client', $observed['failure_stage']);
            $this->assertSame('server', $observed['failure_classification']);
            $this->assertSame('server', $observed['failure_owner']);
            $this->assertSame(503, $observed['server_evidence']['runtime_failure']['status_code']);
            $this->assertSame('exited', $observed['worker_evidence']['process_state']['state']);
            $this->assertSame(
                'worker_protocol_server_failure',
                $result['findings'][0]['observed_evidence']['companion_failure_evidence']['classification_basis'],
            );
            $this->assertSame(200, $observed['server_evidence']['companion']['health']['http_status']);
            $this->assertLessThanOrEqual(
                24576,
                strlen(json_encode($scenario, JSON_THROW_ON_ERROR)),
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($resultDir);
        }
    }

    public function test_worker_startup_failure_evidence_distinguishes_timeout_from_process_exit(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise the failure writer.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($repoRoot.'/scripts/conformance/php-sdk-published-artifacts.sh');
        $matched = preg_match(
            "~write_failure\\(\\) \\{.*?node <<'NODE'\n(.*?)\nNODE\n\\}~s",
            $runner,
            $matches,
        );
        $this->assertSame(1, $matched);
        $writer = $matches[1];
        $resultDir = sys_get_temp_dir().'/dw-php-sdk-startup-evidence-'.bin2hex(random_bytes(6));
        mkdir($resultDir, 0777, true);
        $observationFile = $resultDir.'/readiness-observation.json';
        $diagnosticFile = $resultDir.'/worker-process-exit.log';
        $lastServerRegistration = [
            'worker_id' => 'php-sdk-worker-1',
            'status' => 'active',
            'last_heartbeat_at' => '2026-07-16T00:00:00Z',
            'workflow_command_contracts' => [
                'php.sdk.waiting' => ['queries' => ['current'], 'updates' => ['set']],
            ],
        ];
        file_put_contents($observationFile, json_encode([
            'first_server_registration_observed_at' => '2026-07-16T00:00:00Z',
            'last_server_registration_observed_at' => '2026-07-16T00:00:10Z',
            'required_workflow_command_contract' => [
                'workflow_type' => 'php.sdk.waiting',
                'queries' => ['current'],
                'updates' => ['set'],
            ],
            'readiness_mismatch' => [
                'reason' => 'authoritative_workflow_command_contract_mismatch',
                'workflow_type' => 'php.sdk.waiting',
                'expected_contract' => [
                    'queries' => ['current'],
                    'updates' => ['set'],
                    'update_contracts' => [['name' => 'set']],
                ],
                'observed_contract' => [
                    'queries' => ['current'],
                    'updates' => ['set'],
                    'update_contracts' => [],
                ],
            ],
            'last_server_registration' => $lastServerRegistration,
            'last_server_observation' => [
                'observed_at' => '2026-07-16T00:00:10Z',
                'http_status' => 200,
                'payload' => $lastServerRegistration,
            ],
        ], JSON_THROW_ON_ERROR));
        $environment = array_merge($_ENV, [
            'RESULT_DIR' => $resultDir,
            'SDK_VERSION' => '0.1.6',
            'SERVER_VERSION' => '0.2.662',
            'SERVER_IMAGE' => 'durableworkflow/server:0.2.662',
            'SERVER_URL' => 'http://server.test',
            'NAMESPACE' => 'workflow-lifecycle-conformance',
            'STARTED_AT' => '2026-07-16T00:00:00Z',
            'FAILURE_CLASSIFICATION' => 'sdk',
            'FAILURE_OWNER' => 'sdk-php',
            'FAILURE_STAGE' => 'worker_readiness_timeout',
            'FAILURE_SUMMARY' => 'worker readiness timed out',
            'FAILURE_DIAGNOSTIC_FILE' => '',
            'FAILURE_EVIDENCE_HELPER' => $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs',
            'WORKER_START_OUTCOME' => 'readiness_timeout',
            'WORKER_START_WORKER_ID' => 'php-sdk-worker-1',
            'WORKER_START_ATTEMPTS' => '100',
            'WORKER_START_PROCESS_ID' => '4321',
            'WORKER_START_PROCESS_ALIVE' => 'true',
            'WORKER_START_PROCESS_EXIT_CODE' => '',
            'WORKER_START_OBSERVATION_FILE' => $observationFile,
            'CONTROL_TOKEN' => 'control-secret',
            'WORKER_TOKEN' => 'worker-secret',
        ]);

        try {
            $runWriter = static function (array $writerEnvironment) use (
                $nodeBinary,
                $writer,
                $repoRoot,
            ): void {
                $process = proc_open(
                    [$nodeBinary, '-e', $writer],
                    [
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    $repoRoot,
                    $writerEnvironment,
                );
                self::assertIsResource($process);
                stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), (string) $stderr);
            };

            $runWriter($environment);
            $result = json_decode(
                (string) file_get_contents($resultDir.'/php-sdk-conformance-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $timeout = $result['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs']['worker_evidence']['startup'];
            $this->assertSame('readiness_timeout', $timeout['outcome']);
            $this->assertTrue($timeout['process_alive_at_failure']);
            $this->assertNull($timeout['process_exit_code']);
            $this->assertSame(100, $timeout['attempts']);
            $this->assertSame($lastServerRegistration, $timeout['last_server_observation']['payload']);
            $this->assertSame(
                'authoritative_workflow_command_contract_mismatch',
                $timeout['readiness_observation']['readiness_mismatch']['reason'],
            );

            $exitEnvironment = array_merge($environment, [
                'FAILURE_STAGE' => 'worker_process_exit',
                'FAILURE_SUMMARY' => 'worker process exited',
                'FAILURE_DIAGNOSTIC_FILE' => $diagnosticFile,
                'WORKER_START_OUTCOME' => 'process_exit',
                'WORKER_START_ATTEMPTS' => '3',
                'WORKER_START_PROCESS_ALIVE' => 'false',
                'WORKER_START_PROCESS_EXIT_CODE' => '17',
            ]);
            file_put_contents(
                $diagnosticFile,
                'DW_PHP_SDK_RUNTIME_FAILURE='.json_encode([
                    'classification' => 'sdk',
                    'owning_surface' => 'sdk-php',
                    'process' => 'worker',
                    'operation' => 'worker.lifecycle',
                    'http_method' => 'MULTIPLE',
                    'endpoint' => '/api/worker-protocol/*',
                    'status_code' => null,
                    'public_error_envelope' => null,
                    'exception_type' => 'DurableWorkflow\\Exception\\InvalidWorkerDefinition',
                    'contract' => 'workflow php.sdk.failure',
                    'message' => 'Invalid worker contract workflow php.sdk.failure. Make the first handler parameter DurableWorkflow\\Worker\\WorkflowContext.',
                ], JSON_THROW_ON_ERROR)."\n",
            );
            $runWriter($exitEnvironment);
            $result = json_decode(
                (string) file_get_contents($resultDir.'/php-sdk-conformance-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $processExit = $result['scenario_results']['php_sdk_lifecycle_surface']['observed_outputs']['worker_evidence']['startup'];
            $this->assertSame('process_exit', $processExit['outcome']);
            $this->assertFalse($processExit['process_alive_at_failure']);
            $this->assertSame(17, $processExit['process_exit_code']);
            $this->assertSame(3, $processExit['attempts']);
            $this->assertSame($lastServerRegistration, $processExit['last_server_observation']['payload']);
            $expectedSummary = 'The released PHP SDK raised DurableWorkflow\\Exception\\InvalidWorkerDefinition during worker_process_exit: '
                .'Invalid worker contract workflow php.sdk.failure. Make the first handler parameter DurableWorkflow\\Worker\\WorkflowContext.';
            $this->assertSame($expectedSummary, $result['findings'][0]['summary']);
            $this->assertSame(
                'DurableWorkflow\\Exception\\InvalidWorkerDefinition',
                $result['findings'][0]['observed_evidence']['exception_type'],
            );
            $this->assertSame(
                'workflow php.sdk.failure',
                $result['findings'][0]['observed_evidence']['contract'],
            );
            $this->assertStringContainsString(
                'workflow php.sdk.failure',
                $result['findings'][0]['observed_evidence']['message'],
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($resultDir);
        }
    }

    public function test_javascript_compaction_handles_escaped_and_multibyte_envelopes(): void
    {
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($nodeBinary === '') {
            $this->markTestSkipped('node is required to exercise structured failure validation.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $helper = $repoRoot.'/scripts/conformance/php-sdk-runtime-failure-evidence.cjs';
        $payloads = array_map(
            static fn (string $adversarial): array => [
                'classification' => 'server',
                'owning_surface' => 'server',
                'process' => 'client',
                'operation' => 'workflow.result:cancelled',
                'http_method' => 'GET',
                'endpoint' => '/api/workflows/{workflow_id}/runs/{run_id}/result',
                'status_code' => 503,
                'public_error_envelope' => [
                    'error' => $adversarial,
                    'message' => $adversarial,
                    'reason' => $adversarial,
                    'workflow_id' => $adversarial,
                    'run_id' => $adversarial,
                ],
                'workflow_id' => 'workflow-123',
                'run_id' => 'run-456',
            ],
            [str_repeat('"\\', 3000), str_repeat('😀漢字', 3000)],
        );
        $node = <<<'JS'
const fs = require('node:fs');
const helper = require(process.argv[1]);
const payloads = JSON.parse(fs.readFileSync(0, 'utf8'));
const results = payloads.map((payload) => {
  const source = `DW_PHP_SDK_RUNTIME_FAILURE=${JSON.stringify(payload)}`;
  const evidence = helper.extractRuntimeFailureEvidence(source);
  return {
    bytes: helper.serializedBytes(evidence.public_error_envelope),
    complete: helper.isCompleteHttpFailureEvidence(evidence),
  };
});
process.stdout.write(JSON.stringify(results));
JS;
        $process = proc_open(
            [$nodeBinary, '-e', $node, $helper],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $repoRoot,
        );

        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($payloads, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, (string) $stderr);
        $results = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
        foreach ($results as $result) {
            $this->assertLessThanOrEqual(2048, $result['bytes']);
            $this->assertTrue($result['complete']);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($directory);
    }
}

final class SyntheticTerminalException extends \RuntimeException {}

final class SyntheticServerException extends \RuntimeException {}
