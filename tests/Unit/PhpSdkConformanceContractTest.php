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
        $this->assertSame('sdk-php', $manifest['failure_routing']['sdk']);
        $this->assertSame('server', $manifest['failure_routing']['server']);
        $this->assertSame('sdk-php-release', $manifest['failure_routing']['package-publication']);
        $this->assertSame('conformance_harness', $manifest['failure_routing']['runner']);
        $this->assertTrue($manifest['runtime_failure_evidence']['durable_observed_evidence_required']);
        $this->assertTrue($manifest['runtime_failure_evidence']['diagnostic_file_reference_alone_forbidden']);
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
        $this->assertSame($manifest['runtime_failure_evidence'], $mirror['runtime_failure_evidence']);
        $this->assertSame(
            $manifest['host_runner_contract']['scenario_runner_path'],
            $mirror['host_runner_contract']['scenario_runner_path'],
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
        $this->assertStringNotContainsString('durable-workflow/workflow:', $runner);
        $this->assertStringNotContainsString('"type": "path"', $runner);
    }

    public function test_runner_has_a_focused_namespace_scope_with_incremental_evidence(): void
    {
        $runner = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/php-sdk-published-artifacts.sh',
        );

        $this->assertStringContainsString('[--scope lifecycle|namespace]', $runner);
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
        $this->assertStringContainsString('assertCompleteHttpFailureEvidence(runtimeFailure, requestedClassification);', $runner);
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
}

final class SyntheticTerminalException extends \RuntimeException
{
}

final class SyntheticServerException extends \RuntimeException
{
}
