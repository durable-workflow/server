<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AgentOperabilityConformanceRunnerContractTest extends TestCase
{
    public function test_runner_names_the_executable_agent_loop_contract(): void
    {
        $source = $this->read('scripts/conformance/agent-operability-published-artifacts.sh');

        $this->assertStringContainsString('agent-operability-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]', $source);
        $this->assertStringContainsString('DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH', $source);
        $this->assertStringContainsString('DW_AGENT_OPERABILITY_MCP_URL', $source);
        $this->assertStringContainsString('diagnostic_failure', $source);
        $this->assertStringContainsString('sample_app_artifact_tuple', $source);
        $this->assertStringContainsString('artifactVersions exactly match the pinned published artifact tuple', $source);
        $this->assertStringContainsString('durable-workflow.v2.agent-operability.executable-loop.result', $source);
        $this->assertStringContainsString('durable-workflow.v2.agent-root-cause', $source);
        $this->assertStringContainsString('durable-workflow.v2.agent-remediation', $source);
        $this->assertStringContainsString('durable-workflow.v2.safe-mutation', $source);
        $this->assertStringContainsString('"discover"', $source);
        $this->assertStringContainsString('"change"', $source);
        $this->assertStringContainsString('"run"', $source);
        $this->assertStringContainsString('"diagnose"', $source);
        $this->assertStringContainsString('"repair"', $source);
        $this->assertStringContainsString('"local_product_source_checkouts_used": False', $source);
    }

    public function test_runner_validates_sample_app_executable_loop_metadata(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/dw-agent-operability-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $metadataPath = $dir.'/sample-app-metadata.json';
        file_put_contents($metadataPath, json_encode($this->sampleAppMetadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $command = sprintf(
            'DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s DW_SERVER_VERSION=0.2.442 DW_CLI_VERSION=0.1.81 DW_PYTHON_SDK_VERSION=0.4.89 DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.207 DW_WATERLINE_VERSION=2.0.0-alpha.111 DW_SAMPLE_APP_REF=v0.1.0 bash %s --result-dir %s 2>&1',
            escapeshellarg($metadataPath),
            escapeshellarg($root.'/scripts/conformance/agent-operability-published-artifacts.sh'),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        $result = json_decode((string) file_get_contents($dir.'/agent-operability-result.json'), true);
        $record = json_decode((string) file_get_contents($dir.'/agent-operability-record.json'), true);

        $this->assertSame('pass', $result['outcome']);
        $this->assertFalse($result['runner_blocked']);
        $this->assertSame('sample-app-conformance-metadata', $result['evidence']['source']);
        $this->assertSame('pass', $result['scenario_results']['discover']['status']);
        $this->assertSame('pass', $result['scenario_results']['change']['status']);
        $this->assertSame('pass', $result['scenario_results']['run']['status']);
        $this->assertSame('pass', $result['scenario_results']['diagnose']['status']);
        $this->assertSame('pass', $result['scenario_results']['repair']['status']);
        $this->assertSame('pass', $result['scenario_results']['artifact_versions']['status']);
        $this->assertSame('pass', $result['scenario_results']['sample_app_source_free']['status']);
        $this->assertSame('pass', $result['scenario_results']['sample_app_artifact_tuple']['status']);
        $this->assertSame(['metadata.artifactVersions'], $result['scenario_results']['sample_app_artifact_tuple']['evidence']['validated_sources']);
        $this->assertSame('pass', $record['outcome']);
        $this->assertSame('agent-operability', $record['experiment']);
        $this->assertSame('v0.1.0', $record['artifactVersions']['sample-app']);
        $this->assertStringContainsString('machine_readable_fields=discovery,change,run,diagnose,repair', $record['notes']);
    }

    public function test_runner_keeps_unresolved_artifact_pins_nonpassing(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/dw-agent-operability-unresolved-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $metadataPath = $dir.'/sample-app-metadata.json';
        file_put_contents($metadataPath, json_encode($this->sampleAppMetadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $command = sprintf(
            'DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s bash %s --result-dir %s 2>&1',
            escapeshellarg($metadataPath),
            escapeshellarg($root.'/scripts/conformance/agent-operability-published-artifacts.sh'),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode, implode("\n", $output));

        $result = json_decode((string) file_get_contents($dir.'/agent-operability-result.json'), true);

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('fail', $result['scenario_results']['artifact_versions']['status']);
        $this->assertContains('server=unresolved', $result['scenario_results']['artifact_versions']['evidence']['observed']['failures']);
        $this->assertSame('pass', $result['scenario_results']['sample_app_source_free']['status']);
    }

    public function test_runner_keeps_stale_sample_app_metadata_tuple_nonpassing(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/dw-agent-operability-stale-metadata-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $metadata = $this->sampleAppMetadata();
        $metadata['artifactVersions']['server'] = '0.2.441';
        $metadataPath = $dir.'/sample-app-metadata.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $command = sprintf(
            'DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s DW_SERVER_VERSION=0.2.442 DW_CLI_VERSION=0.1.81 DW_PYTHON_SDK_VERSION=0.4.89 DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.207 DW_WATERLINE_VERSION=2.0.0-alpha.111 DW_SAMPLE_APP_REF=v0.1.0 bash %s --result-dir %s 2>&1',
            escapeshellarg($metadataPath),
            escapeshellarg($root.'/scripts/conformance/agent-operability-published-artifacts.sh'),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode, implode("\n", $output));

        $result = json_decode((string) file_get_contents($dir.'/agent-operability-result.json'), true);

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['artifact_versions']['status']);
        $this->assertSame('pass', $result['scenario_results']['sample_app_source_free']['status']);
        $this->assertSame('fail', $result['scenario_results']['sample_app_artifact_tuple']['status']);
        $this->assertContains(
            'metadata.artifactVersions.server expected 0.2.442 but observed 0.2.441',
            $result['scenario_results']['sample_app_artifact_tuple']['evidence']['observed']['failures'],
        );
    }

    public function test_runner_keeps_stale_sample_app_evidence_tuple_nonpassing(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/dw-agent-operability-stale-evidence-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $metadata = $this->sampleAppMetadata();
        $metadata['surfaces']['mcp_workflow_api']['agent_loop_evidence']['artifactVersions'] = $metadata['artifactVersions'];
        $metadata['surfaces']['mcp_workflow_api']['agent_loop_evidence']['artifactVersions']['cli'] = '0.1.80';
        $metadataPath = $dir.'/sample-app-metadata.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $command = sprintf(
            'DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s DW_SERVER_VERSION=0.2.442 DW_CLI_VERSION=0.1.81 DW_PYTHON_SDK_VERSION=0.4.89 DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.207 DW_WATERLINE_VERSION=2.0.0-alpha.111 DW_SAMPLE_APP_REF=v0.1.0 bash %s --result-dir %s 2>&1',
            escapeshellarg($metadataPath),
            escapeshellarg($root.'/scripts/conformance/agent-operability-published-artifacts.sh'),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode, implode("\n", $output));

        $result = json_decode((string) file_get_contents($dir.'/agent-operability-result.json'), true);

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['artifact_versions']['status']);
        $this->assertSame('pass', $result['scenario_results']['sample_app_source_free']['status']);
        $this->assertSame('fail', $result['scenario_results']['sample_app_artifact_tuple']['status']);
        $this->assertContains(
            'evidence.artifactVersions.cli expected 0.1.81 but observed 0.1.80',
            $result['scenario_results']['sample_app_artifact_tuple']['evidence']['observed']['failures'],
        );
    }

    public function test_runner_keeps_missing_source_free_sample_app_evidence_nonpassing(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/dw-agent-operability-source-free-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $metadata = $this->sampleAppMetadata();
        unset($metadata['local_product_source_checkouts_used']);
        $metadataPath = $dir.'/sample-app-metadata.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $command = sprintf(
            'DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH=%s DW_SERVER_VERSION=0.2.442 DW_CLI_VERSION=0.1.81 DW_PYTHON_SDK_VERSION=0.4.89 DW_WORKFLOW_PHP_VERSION=2.0.0-alpha.207 DW_WATERLINE_VERSION=2.0.0-alpha.111 DW_SAMPLE_APP_REF=v0.1.0 bash %s --result-dir %s 2>&1',
            escapeshellarg($metadataPath),
            escapeshellarg($root.'/scripts/conformance/agent-operability-published-artifacts.sh'),
            escapeshellarg($dir),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(1, $exitCode, implode("\n", $output));

        $result = json_decode((string) file_get_contents($dir.'/agent-operability-result.json'), true);

        $this->assertSame('fail', $result['outcome']);
        $this->assertSame('pass', $result['scenario_results']['artifact_versions']['status']);
        $this->assertSame('fail', $result['scenario_results']['sample_app_source_free']['status']);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        $this->assertIsString($contents);

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleAppMetadata(): array
    {
        return [
            'schema' => 'durable-workflow.sample-app.conformance.run',
            'app_url' => 'http://app:8000',
            'artifactVersions' => [
                'server' => '0.2.442',
                'cli' => '0.1.81',
                'sdk-python' => '0.4.89',
                'workflow' => '2.0.0-alpha.207',
                'waterline' => '2.0.0-alpha.111',
                'sample-app' => 'v0.1.0',
            ],
            'local_product_source_checkouts_used' => false,
            'summary' => [
                'status' => 'passed',
            ],
            'surfaces' => [
                'mcp_workflow_api' => [
                    'status' => 'passed',
                    'agent_loop_evidence' => [
                        'schema' => 'durable-workflow.v2.agent-operability.executable-loop',
                        'version' => 1,
                        'discovery' => [
                            'tool_names' => [
                                'list_workflows',
                                'start_workflow',
                                'get_workflow_result',
                                'get_workflow_history',
                                'diagnose_workflow',
                                'repair_workflow',
                            ],
                            'available_workflow_keys' => [
                                'simple',
                                'diagnostic_failure',
                            ],
                            'failure_workflow_no_credentials' => true,
                        ],
                        'change' => [
                            'kind' => 'guarded_operating_choice',
                            'failure_workflow' => 'diagnostic_failure',
                            'failure_arguments' => ['agent-operability-induced-failure'],
                        ],
                        'run' => [
                            'failure' => [
                                'workflow_id' => 'agent-operability-failure',
                                'run_id' => 'run-1',
                                'workflow_status' => 'failed',
                                'failed' => true,
                            ],
                        ],
                        'diagnose' => [
                            'failure' => [
                                'root_cause_schema' => 'durable-workflow.v2.agent-root-cause',
                                'root_cause_category' => 'activity_failure',
                                'remediation_schema' => 'durable-workflow.v2.agent-remediation',
                                'remediation_classification' => 'change_workflow_or_activity_source',
                            ],
                        ],
                        'repair' => [
                            'failure' => [
                                'decision' => 'request_safe_repair_after_diagnosis',
                                'safe_mutation_schema' => 'durable-workflow.v2.safe-mutation',
                                'safe_mutation_applied' => false,
                                'remediation_classification' => 'repair_refused',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
