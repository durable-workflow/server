<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowUpdatesPhpShardPortabilityTest extends TestCase
{
    public function test_focused_probe_records_exact_php_sdk_artifact_identity_and_install_evidence(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/conformance/workflow-updates-published-artifacts.sh',
        );
        $start = strpos($source, 'function env_text(string $name): string');
        $end = strpos($source, 'function published_artifact_install_observed_outputs(): array');

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);

        $probe = sys_get_temp_dir().'/dw-workflow-updates-artifacts-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents(
            $probe,
            "<?php\n".substr($source, $start, $end - $start).<<<'PHP'

echo json_encode([
    'versions' => artifact_versions(),
    'sources' => artifact_sources(),
    'install' => published_artifact_install_evidence(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP,
        );

        try {
            $command = sprintf(
                '%s %s 2>&1',
                'DW_PHP_SDK_VERSION='.escapeshellarg('0.1.13'),
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe),
            );
            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
            $identity = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
            $sourceIdentity = 'packagist://durable-workflow/sdk@0.1.13';

            $this->assertSame('0.1.13', $identity['versions']['sdk-php']);
            $this->assertSame($sourceIdentity, $identity['sources']['sdk-php']);
            $this->assertSame([
                'installed_from' => $sourceIdentity,
                'version' => '0.1.13',
            ], $identity['install']['sdk-php']);
        } finally {
            @unlink($probe);
        }
    }

    public function test_php_sdk_shard_uses_connect_host_separately_from_published_bind_host(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is required to execute the workflow updates handoff');
        }

        $root = dirname(__DIR__, 2);
        $testDir = sys_get_temp_dir().'/dw-workflow-updates-php-topology-'.bin2hex(random_bytes(6));
        $binDir = $testDir.'/bin';
        $resultDir = $testDir.'/result';
        $dockerLog = $testDir.'/docker.log';
        $curlLog = $testDir.'/curl.log';
        mkdir($binDir, 0777, true);
        mkdir($resultDir, 0777, true);

        file_put_contents($binDir.'/docker', <<<'SH'
#!/bin/sh
printf 'SERVER_PORT=%s docker' "${SERVER_PORT:-}" >> "$DW_WORKFLOW_UPDATES_TEST_DOCKER_LOG"
printf ' %s' "$@" >> "$DW_WORKFLOW_UPDATES_TEST_DOCKER_LOG"
printf '\n' >> "$DW_WORKFLOW_UPDATES_TEST_DOCKER_LOG"
if [ "${1:-}" = "compose" ] && printf ' %s ' "$*" | grep -q ' ps -q server '; then
  printf '%s\n' 'workflow-updates-server-container'
fi
if [ "${1:-}" = "inspect" ]; then
  printf '%s\n' '{"Status":"running","Health":{"Status":"healthy"}}'
fi
exit 0
SH,
        );
        file_put_contents($binDir.'/curl', <<<'SH'
#!/bin/sh
output=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output)
      output="$2"
      shift 2
      ;;
    --write-out)
      shift 2
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done
if [ -n "$output" ]; then
  : > "$output"
fi
printf '%s\n' "$url" >> "$DW_WORKFLOW_UPDATES_TEST_CURL_LOG"
printf '200'
SH,
        );
        chmod($binDir.'/docker', 0755);
        chmod($binDir.'/curl', 0755);

        try {
            $environment = [
                'PATH' => $binDir.':'.(string) getenv('PATH'),
                'DW_WORKFLOW_UPDATES_TEST_DOCKER_LOG' => $dockerLog,
                'DW_WORKFLOW_UPDATES_TEST_CURL_LOG' => $curlLog,
                'DW_WORKFLOW_UPDATES_SKIP_FOCUSED_HOST_PROBE' => '1',
                'DW_WORKFLOW_UPDATES_SKIP_PYTHON_SDK_SHARD' => '1',
                'DW_WORKFLOW_UPDATES_SKIP_OPERATOR_DIAGNOSTICS_SHARD' => '1',
                'DW_WORKFLOW_UPDATES_RUN_PHP_PACKAGE_SHARD' => '1',
                'DW_WORKFLOW_UPDATES_PHP_SERVER_BIND_HOST' => '0.0.0.0',
                'DW_WORKFLOW_UPDATES_PHP_SERVER_CONNECT_HOST' => '172.19.0.1',
                'DW_WORKFLOW_UPDATES_PHP_SERVER_PORT' => '43182',
                'DW_SERVER_IMAGE' => 'durableworkflow/server:0.2.693',
                'DW_SERVER_VERSION' => '0.2.693',
                'DW_CLI_VERSION' => '0.1.93',
                'DW_PHP_SDK_VERSION' => '0.1.13',
                'DW_PYTHON_SDK_VERSION' => '0.4.103',
                'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.291',
                'DW_WATERLINE_VERSION' => '2.0.0-alpha.137',
            ];
            $assignments = array_map(
                static fn (string $name, string $value): string => $name.'='.escapeshellarg($value),
                array_keys($environment),
                array_values($environment),
            );
            $command = implode(' ', $assignments).' '.
                escapeshellarg($root.'/scripts/conformance/workflow-updates-published-artifacts.sh').
                ' --result-dir '.escapeshellarg($resultDir).' 2>&1';
            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
            $dockerCalls = (string) file_get_contents($dockerLog);
            $curlCalls = (string) file_get_contents($curlLog);
            $connectUrl = 'http://172.19.0.1:43182';

            $this->assertStringContainsString('SERVER_PORT=0.0.0.0:43182 docker compose', $dockerCalls);
            $this->assertStringContainsString($connectUrl.'/api/health', $curlCalls);
            $this->assertStringContainsString(
                'DW_PHP_SDK_CONFORMANCE_SERVER_URL='.$connectUrl,
                $dockerCalls,
            );
        } finally {
            exec('rm -rf '.escapeshellarg($testDir));
        }
    }
}
