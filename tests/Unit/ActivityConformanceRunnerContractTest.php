<?php

namespace Tests\Unit;

use App\Support\ActivityRuntimeContract;
use App\Support\ActivityRuntimeResultGate;
use PHPUnit\Framework\TestCase;

class ActivityConformanceRunnerContractTest extends TestCase
{
    public function test_runner_script_routes_every_required_activity_scenario(): void
    {
        $source = $this->read('scripts/conformance/activities-published-artifacts.sh');

        $this->assertStringContainsString(
            'Usage: activities-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]',
            $source,
        );
        $this->assertStringContainsString('activities-result.json', $source);
        $this->assertStringContainsString('activities-record.json', $source);
        $this->assertStringContainsString('activities-findings.json', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_SCENARIO_MANIFEST', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_EVIDENCE', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_EVIDENCE_PATH', $source);
        $this->assertStringContainsString('DW_ACTIVITIES_RUNNER_SOURCE', $source);
        $this->assertStringContainsString('RUNNER_REPO_ROOT', $source);

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PYTHON_SDK_VERSION',
            'DW_WORKFLOW_PHP_VERSION',
            'DW_WATERLINE_VERSION',
        ] as $envName) {
            $this->assertStringContainsString($envName, $source);
        }

        foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $this->assertStringContainsString(
                $scenarioId,
                $source,
                "the published-artifact runner must know how to route scenario {$scenarioId}",
            );
        }

        foreach ([
            'workflow-embedded',
            'standalone',
            'not_covered',
            'runner_blocked',
            'product-gap',
            'coverage-gap',
            'runner-gap',
            'stale-artifact',
            'pipeline-churn',
            'conformance_runner_coverage_gap',
            'artifact_install_evidence missing',
            'activity host evidence missing',
            'local_product_source_checkouts_used',
            'FORBIDDEN_INSTALL_SOURCE_TOKENS',
            'published_artifact_worker_execution',
            'published_artifact_worker_execution_derived',
            'published_server_image_activity_runtime_probe',
            'published_artifact_worker_execution must prove execution inside the pinned server container',
            'SOURCE_FREE_RUNNER_STATEMENT',
            'published_server_image_conformance_handoff',
            'local vendor trees were not used as pass evidence',
            'outcome === \'pass\' ? 0 : 1',
        ] as $token) {
            $this->assertStringContainsString($token, $source);
        }
    }

    public function test_runner_does_not_pass_without_activity_product_evidence(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $env = [
                'DW_SERVER_VERSION' => '9.9.9',
                'DW_CLI_VERSION' => '9.9.9',
                'DW_PYTHON_SDK_VERSION' => '9.9.9',
                'DW_WORKFLOW_PHP_VERSION' => '9.9.9',
                'DW_WATERLINE_VERSION' => '9.9.9',
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['server']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['cli']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['sdk-python']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['workflow']);
            $this->assertSame('9.9.9', $result['published_artifact_versions']['waterline']);

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $this->assertArrayHasKey($scenarioId, $byScenario);
                $this->assertSame('not_covered', $byScenario[$scenarioId]['status']);
                $this->assertSame('coverage-gap', $byScenario[$scenarioId]['classification']);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_accepts_digest_pinned_server_install_source(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $digest = 'sha256:'.str_repeat('a', 64);
            $serverImage = 'durableworkflow/server@'.$digest;

            $installEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
                'local_product_source_checkouts_used' => false,
                'artifacts' => [
                    [
                        'artifact' => 'server',
                        'version' => $version,
                        'source' => $serverImage,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'cli',
                        'version' => $version,
                        'source' => 'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/install.sh',
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'sdk-python',
                        'version' => $version,
                        'source' => 'https://pypi.org/project/durable-workflow/'.$version.'/',
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'workflow-php',
                        'version' => $version,
                        'source' => 'https://packagist.org/packages/durable-workflow/workflow#'.$version,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                    [
                        'artifact' => 'waterline',
                        'version' => $version,
                        'source' => 'https://packagist.org/packages/durable-workflow/waterline#'.$version,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => false,
                    ],
                ],
            ];

            $scenarioResults = [];
            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                $scenarioResults[] = [
                    'scenario_id' => $scenarioId,
                    'status' => 'pass',
                    'observed_outputs' => [
                        'evidence' => $scenarioId,
                    ],
                    'scenario_evidence' => [
                        'evidence' => $scenarioId,
                    ],
                ];
            }

            $activityEvidence = [
                'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
                'scenario_results' => $scenarioResults,
                'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence($version, $serverImage),
                'published_artifact_install' => [
                    'status' => 'pass',
                    'server_image' => $serverImage,
                ],
                'runtime_matrix' => [
                    'execution_modes' => ['workflow-embedded', 'standalone'],
                    'runtimes' => ['workflow-php', 'sdk-python'],
                ],
                'durable_result_recording' => ['status' => 'pass'],
                'retry_backoff' => ['status' => 'pass'],
                'timeout_behavior' => ['status' => 'pass'],
                'typed_failure_propagation' => ['status' => 'pass'],
                'heartbeat_cancellation' => ['status' => 'pass'],
                'idempotent_completion' => ['status' => 'pass'],
                'operator_visibility' => ['status' => 'pass'],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(0, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('pass', $result['outcome']);
            $this->assertSame($version, $result['published_artifact_versions']['server']);
            $this->assertSame($serverImage, $result['artifact_sources']['server']);
            $this->assertSame([], $result['published_artifact_install']['pin_failures'] ?? []);
            $this->assertSame([], $result['published_artifact_install']['install_failures'] ?? []);
            $this->assertSame(
                'pass',
                ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest())['status'],
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_local_repo_root_vendor_runtime_probe(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $installEvidence = $this->completeRunnerInstallEvidence($version);
            $activityEvidence = $this->completeRunnerActivityEvidence($version);
            unset($activityEvidence['published_artifact_worker_execution']);
            $activityEvidence['published_server_image_activity_runtime_probe'] = [
                'label' => 'published_server_image_activity_runtime_probe',
                'status' => 'pass',
                'execution_environment' => 'local_php',
                'working_directory' => $repoRoot,
                'command' => 'php '.$repoRoot.'/vendor/bin/phpunit',
                'autoload_path' => $repoRoot.'/vendor/autoload.php',
                'local_product_source_checkouts_used' => true,
                'artifacts' => [
                    [
                        'artifact' => 'server',
                        'version' => $version,
                        'source' => $repoRoot,
                        'status' => 'pass',
                        'local_product_source_checkouts_used' => true,
                    ],
                ],
            ];

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertNotEmpty($result['published_artifact_worker_execution_failures'] ?? []);
            $this->assertStringContainsString(
                'local product source probe',
                implode('; ', $result['published_artifact_worker_execution_failures'] ?? []),
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $scenario = $byScenario['workflow_embedded_activity_result'] ?? [];
            $this->assertSame('not_covered', $scenario['status'] ?? null);
            $this->assertSame('coverage-gap', $scenario['classification'] ?? null);
            $this->assertNotEmpty($scenario['linked_findings'] ?? []);
            $this->assertStringContainsString(
                'pinned published server artifact',
                $scenario['linked_findings'][0]['observed_behavior'] ?? '',
            );
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_does_not_derive_published_execution_from_workspace_checkout_even_with_runner_source(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $serverImage = 'durableworkflow/server:'.$version;
            $activityEvidence = $this->completeRunnerActivityEvidence($version, $serverImage);
            unset($activityEvidence['published_artifact_worker_execution']);

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($this->completeRunnerInstallEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($activityEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_IMAGE' => $serverImage,
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
                'DW_ACTIVITIES_RUNNER_SOURCE' => $serverImage,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertNull($result['published_artifact_worker_execution']);
            $this->assertFalse($result['published_artifact_worker_execution_derived']);
            $this->assertSame('missing', $result['published_artifact_worker_execution_source']);
            $this->assertStringContainsString(
                'published server image root',
                $result['published_artifact_worker_execution_derivation_reason'] ?? '',
            );
            $this->assertContains(
                'published_artifact_worker_execution missing',
                $result['published_artifact_worker_execution_failures'] ?? [],
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('pass', $byScenario['published_artifact_install_only']['status'] ?? null);
            foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
                if ($scenarioId === 'published_artifact_install_only') {
                    continue;
                }

                $this->assertSame('not_covered', $byScenario[$scenarioId]['status'] ?? null);
                $this->assertSame('coverage-gap', $byScenario[$scenarioId]['classification'] ?? null);
                $this->assertNotEmpty($byScenario[$scenarioId]['linked_findings'] ?? []);
            }
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_runner_rejects_unofficial_cli_install_source_when_behavior_evidence_passes(): void
    {
        if (trim((string) shell_exec('command -v bash 2>/dev/null')) === ''
            || trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
            $this->markTestSkipped('bash and node are required to exercise the activities runner.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = $repoRoot.'/storage/framework/activities-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($resultDir, 0777, true));

        try {
            $version = '9.9.9';
            $unofficialCliSource = 'https://github.com/not-durable-workflow/cli/releases/download/v'.$version.'/install.sh';
            $installEvidence = $this->completeRunnerInstallEvidence($version);
            $installEvidence['artifacts'][1]['source'] = $unofficialCliSource;

            file_put_contents(
                $resultDir.'/artifact-install-evidence.json',
                json_encode($installEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
            file_put_contents(
                $resultDir.'/activity-evidence.json',
                json_encode($this->completeRunnerActivityEvidence($version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $env = [
                'DW_SERVER_VERSION' => $version,
                'DW_CLI_VERSION' => $version,
                'DW_PYTHON_SDK_VERSION' => $version,
                'DW_WORKFLOW_PHP_VERSION' => $version,
                'DW_WATERLINE_VERSION' => $version,
            ];
            $envPrefix = implode(' ', array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                '%s bash %s --result-dir %s >/dev/null 2>&1',
                $envPrefix,
                escapeshellarg($repoRoot.'/scripts/conformance/activities-published-artifacts.sh'),
                escapeshellarg($resultDir),
            );

            exec($command, $output, $exitCode);
            $this->assertSame(1, $exitCode);

            $result = json_decode(
                file_get_contents($resultDir.'/activities-result.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $record = json_decode(
                file_get_contents($resultDir.'/activities-record.json') ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame('non_passing', $result['outcome']);
            $this->assertFalse($result['runner_blocked']);
            $this->assertSame('fail', $record['outcome']);
            $this->assertFalse($record['runnerBlocked']);
            $this->assertSame($unofficialCliSource, $result['artifact_sources']['cli']);
            $this->assertContains(
                'cli.source='.$unofficialCliSource,
                $result['published_artifact_install']['install_failures'] ?? [],
            );

            $byScenario = [];
            foreach ($result['scenario_results'] ?? [] as $scenario) {
                $byScenario[$scenario['scenario_id']] = $scenario;
            }

            $this->assertSame('not_covered', $byScenario['published_artifact_install_only']['status'] ?? null);
            $this->assertContains(
                'cli.source='.$unofficialCliSource,
                $byScenario['published_artifact_install_only']['observed_outputs']['artifact_install_failures'] ?? [],
            );
            $this->assertNotEmpty($byScenario['published_artifact_install_only']['linked_findings'] ?? []);

            $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());
            $this->assertSame('non_passing', $evaluation['status']);
            $this->assertContains(
                'unrecognized_published_artifact_install_source',
                array_column($evaluation['gate_failures'], 'code'),
            );
            $this->assertContains('cli', array_column($evaluation['gate_failures'], 'artifact'));
        } finally {
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($resultDir)) {
                rmdir($resultDir);
            }
        }
    }

    public function test_result_gate_accepts_full_activity_product_evidence(): void
    {
        $evaluation = ActivityRuntimeResultGate::evaluate(
            $this->completeActivityResult(),
            ActivityRuntimeContract::manifest(),
        );

        $this->assertSame('pass', $evaluation['status']);
        $this->assertSame([], $evaluation['gate_failures']);
    }

    public function test_result_gate_requires_source_free_statement_for_published_execution_evidence(): void
    {
        $result = $this->completeActivityResult();
        unset($result['published_artifact_worker_execution']['source_integrity_statement']);
        foreach ($result['published_artifact_worker_execution']['artifacts'] as &$artifact) {
            unset($artifact['source_integrity_statement']);
        }
        unset($artifact);
        foreach ($result['scenario_results'] as &$scenario) {
            unset($scenario['observed_outputs']['published_artifact_worker_execution']['source_integrity_statement']);
            unset($scenario['scenario_evidence']['published_artifact_worker_execution']['source_integrity_statement']);
        }
        unset($scenario);

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_worker_execution_source_integrity_statement',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_rejects_local_runtime_probe_as_pass_evidence(): void
    {
        $result = $this->completeActivityResult();
        $localProbe = [
            'label' => 'published_server_image_activity_runtime_probe',
            'status' => 'pass',
            'execution_environment' => 'local_php',
            'working_directory' => dirname(__DIR__, 2),
            'command' => 'php REPO_ROOT/vendor/bin/phpunit',
            'autoload_path' => 'REPO_ROOT/vendor/autoload.php',
            'local_product_source_checkouts_used' => true,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => '9.9.9',
                    'source' => dirname(__DIR__, 2),
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => true,
                ],
            ],
        ];

        foreach ($result['scenario_results'] as &$scenario) {
            if (($scenario['scenario_id'] ?? '') === 'published_artifact_install_only') {
                continue;
            }
            $scenario['observed_outputs']['published_artifact_worker_execution'] = $localProbe;
            $scenario['scenario_evidence']['published_artifact_worker_execution'] = $localProbe;
        }
        unset($scenario);
        $result['published_artifact_worker_execution'] = $localProbe;

        $evaluation = ActivityRuntimeResultGate::evaluate($result, ActivityRuntimeContract::manifest());

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'local_product_source_checkouts_used_must_be_false',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains(
            'forbidden_published_artifact_worker_execution_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_explicit_runner_blocked_false_for_product_evidence(): void
    {
        $result = $this->completeActivityResult();
        unset($result['runner_blocked']);

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $result = $this->completeActivityResult();
        $result['runner_blocked'] = 'false';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains('runner_blocked', $this->missingRunRecordFields($evaluation));
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );

        $result = $this->completeActivityResult();
        $result['runner_blocked'] = true;

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'runner_blocked_result_is_not_product_evidence',
            array_column($evaluation['gate_failures'], 'code'),
        );
    }

    public function test_result_gate_requires_every_activity_install_channel_source(): void
    {
        $result = $this->completeActivityResult();
        unset($result['artifact_sources']['workflow']);

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'missing_published_artifact_install_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('workflow-php', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_unrecognized_activity_install_sources(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['cli'] = 'https://github.com/not-durable-workflow/cli/releases/download/v9.9.9/install.sh';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'unrecognized_published_artifact_install_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('cli', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_local_activity_install_sources(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['server'] = '/workspace/repos/server';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertContains(
            'forbidden_artifact_source',
            array_column($evaluation['gate_failures'], 'code'),
        );
        $this->assertContains('server', array_column($evaluation['gate_failures'], 'artifact'));
    }

    public function test_result_gate_rejects_generic_activity_source_labels(): void
    {
        $result = $this->completeActivityResult();
        $result['artifact_sources']['cli'] = 'github_release';
        $result['artifact_sources']['sdk-python'] = 'pypi';
        $result['artifact_sources']['workflow'] = 'packagist';

        $evaluation = ActivityRuntimeResultGate::evaluate($result);

        $this->assertSame('non_passing', $evaluation['status']);
        $this->assertSame(
            ['cli', 'sdk-python', 'workflow-php'],
            array_values(array_intersect(
                ['cli', 'sdk-python', 'workflow-php'],
                array_column($evaluation['gate_failures'], 'artifact'),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerInstallEvidence(string $version): array
    {
        return [
            'schema' => 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
            'local_product_source_checkouts_used' => false,
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => $version,
                    'source' => 'durableworkflow/server:'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'cli',
                    'version' => $version,
                    'source' => 'https://github.com/durable-workflow/cli/releases/download/v'.$version.'/install.sh',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'sdk-python',
                    'version' => $version,
                    'source' => 'https://pypi.org/project/durable-workflow/'.$version.'/',
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'workflow-php',
                    'version' => $version,
                    'source' => 'https://packagist.org/packages/durable-workflow/workflow#'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
                [
                    'artifact' => 'waterline',
                    'version' => $version,
                    'source' => 'https://packagist.org/packages/durable-workflow/waterline#'.$version,
                    'status' => 'pass',
                    'local_product_source_checkouts_used' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRunnerActivityEvidence(string $version = '9.9.9', ?string $serverImage = null): array
    {
        $scenarioResults = [];
        foreach (ActivityRuntimeContract::manifest()['required_scenarios'] as $scenarioId) {
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => [
                    'evidence' => $scenarioId,
                ],
                'scenario_evidence' => [
                    'evidence' => $scenarioId,
                ],
            ];
        }

        return [
            'schema' => 'durable-workflow.v2.activity-runtime.host-evidence',
            'scenario_results' => $scenarioResults,
            'published_artifact_worker_execution' => $this->publishedServerExecutionEvidence(
                $version,
                $serverImage ?? 'durableworkflow/server:'.$version,
            ),
            'published_artifact_install' => [
                'status' => 'pass',
            ],
            'runtime_matrix' => [
                'execution_modes' => ['workflow-embedded', 'standalone'],
                'runtimes' => ['workflow-php', 'sdk-python'],
            ],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'typed_failure_propagation' => ['status' => 'pass'],
            'heartbeat_cancellation' => ['status' => 'pass'],
            'idempotent_completion' => ['status' => 'pass'],
            'operator_visibility' => ['status' => 'pass'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completeActivityResult(): array
    {
        $contract = ActivityRuntimeContract::manifest();
        $artifactVersions = [
            'server' => '9.9.9',
            'cli' => '9.9.9',
            'sdk-python' => '9.9.9',
            'workflow' => '9.9.9',
            'waterline' => '9.9.9',
        ];
        $publishedServerExecution = $this->publishedServerExecutionEvidence(
            $artifactVersions['server'],
            'docker.io/durableworkflow/server:'.$artifactVersions['server'],
        );
        $scenarioResults = [];
        foreach ($contract['required_scenarios'] as $scenarioId) {
            $scenarioResults[] = [
                'scenario_id' => $scenarioId,
                'status' => 'pass',
                'observed_outputs' => [
                    'sample' => $scenarioId,
                    'published_artifact_worker_execution' => $publishedServerExecution,
                ],
                'scenario_evidence' => [
                    'sample' => $scenarioId,
                    'published_artifact_worker_execution' => $publishedServerExecution,
                ],
            ];
        }

        return [
            'outcome' => 'pass',
            'runner_blocked' => false,
            'started_at' => '2026-06-21T00:00:00Z',
            'finished_at' => '2026-06-21T00:00:10Z',
            'generated_at' => '2026-06-21T00:00:10Z',
            'artifact_versions' => $artifactVersions,
            'published_artifact_versions' => $artifactVersions,
            'artifact_sources' => [
                'server' => 'docker.io/durableworkflow/server:9.9.9',
                'cli' => 'https://github.com/durable-workflow/cli/releases/download/v9.9.9/install.sh',
                'sdk-python' => 'https://pypi.org/project/durable-workflow/9.9.9/',
                'workflow' => 'https://packagist.org/packages/durable-workflow/workflow#9.9.9',
                'waterline' => 'https://packagist.org/packages/durable-workflow/waterline#9.9.9',
            ],
            'scenario_results' => $scenarioResults,
            'published_artifact_worker_execution' => $publishedServerExecution,
            'findings' => [],
            'finding_links' => [],
            'topology' => [
                'task_queue' => 'activities-shared',
            ],
            'runtime_matrix' => [
                'execution_modes' => ['workflow-embedded', 'standalone'],
                'runtimes' => ['workflow-php', 'sdk-python'],
            ],
            'published_artifact_install' => ['status' => 'pass'],
            'durable_result_recording' => ['status' => 'pass'],
            'retry_backoff' => ['status' => 'pass'],
            'timeout_behavior' => ['status' => 'pass'],
            'typed_failure_propagation' => ['status' => 'pass'],
            'heartbeat_cancellation' => ['status' => 'pass'],
            'idempotent_completion' => ['status' => 'pass'],
            'operator_visibility' => ['status' => 'pass'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedServerExecutionEvidence(string $version, string $serverImage): array
    {
        return [
            'schema' => 'durable-workflow.v2.activity-runtime.published-server-execution',
            'status' => 'pass',
            'execution_environment' => 'docker_container',
            'worker_execution_mode' => 'published_server_image_conformance_handoff',
            'executed_in_pinned_server_artifact' => true,
            'local_product_source_checkouts_used' => false,
            'source_integrity_statement' => 'Activities conformance ran from the pinned published server container; local product checkouts, branch source, and local vendor trees were not used as pass evidence.',
            'image_identity' => [
                'pinned_server_image' => $serverImage,
                'runner_source' => $serverImage,
                'matches_pinned_server_image' => true,
            ],
            'artifacts' => [
                [
                    'artifact' => 'server',
                    'version' => $version,
                    'source' => $serverImage,
                    'status' => 'pass',
                    'execution_context' => 'published_server_image_conformance_handoff',
                    'local_product_source_checkouts_used' => false,
                    'source_integrity_statement' => 'Activities conformance ran from the pinned published server container; local product checkouts, branch source, and local vendor trees were not used as pass evidence.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $evaluation
     *
     * @return list<string>
     */
    private function missingRunRecordFields(array $evaluation): array
    {
        return array_values(array_map(
            static fn (array $failure): string => (string) ($failure['field'] ?? ''),
            array_filter(
                $evaluation['gate_failures'],
                static fn (array $failure): bool => ($failure['code'] ?? null) === 'missing_run_record_field',
            ),
        ));
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
