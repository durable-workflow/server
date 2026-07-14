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
        } finally {
            foreach (glob($tempRoot.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempRoot);
        }
    }
}
