<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SchedulesCadencePollingContractTest extends TestCase
{
    public function test_node_cadence_polling_regressions(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is required to exercise cadence polling.');
        }

        $process = proc_open(
            [$node, '--test', __DIR__.'/SchedulesCadencePollingTest.mjs'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $output);
    }

    public function test_persistent_history_transport_loss_is_runner_blocked_and_tears_down_compose(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is required to exercise cadence polling.');
        }

        $repoRoot = dirname(__DIR__, 2);
        $resultDir = sys_get_temp_dir().'/dw-schedules-cadence-'.bin2hex(random_bytes(4));
        $fakeBin = $resultDir.'/bin';
        $dockerLog = $resultDir.'/docker-calls.log';
        $historyState = $resultDir.'/history-state.json';
        $serverLog = $resultDir.'/http-server.log';
        $router = $resultDir.'/router.php';
        mkdir($fakeBin, 0777, true);
        file_put_contents($historyState, '{}');
        file_put_contents(
            $fakeBin.'/docker',
            <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DW_FAKE_DOCKER_LOG"
case "$*" in
  "--version"|"compose version"|"image pull "*)
    exit 0
    ;;
  *" port server 8080")
    printf '0.0.0.0:%s\n' "$SERVER_PORT"
    exit 0
    ;;
  *)
    exit 0
    ;;
esac
SH,
        );
        chmod($fakeBin.'/docker', 0755);
        file_put_contents(
            $router,
            <<<'PHP'
<?php

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');
if ($method === 'GET' && $path === '/api/ready') {
    echo '{}';
    return;
}
if ($method === 'POST' && $path === '/api/namespaces') {
    http_response_code(201);
    echo '{"name":"schedules-conformance","status":"active"}';
    return;
}
if ($method === 'POST' && $path === '/api/schedules') {
    http_response_code(201);
    echo '{"status":"created"}';
    return;
}
if ($method === 'DELETE' && preg_match('#^/api/schedules/[^/]+$#', $path) === 1) {
    http_response_code(204);
    return;
}
if ($method === 'GET' && preg_match('#^/api/schedules/([^/]+)/history$#', $path, $matches) === 1) {
    $scheduleId = rawurldecode($matches[1]);
    $statePath = (string) getenv('DW_FAKE_HISTORY_STATE');
    $state = json_decode((string) file_get_contents($statePath), true);
    $state[$scheduleId] = ($state[$scheduleId] ?? 0) + 1;
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);

    if ($state[$scheduleId] > 1) {
        http_response_code(503);
        echo '{"reason":"injected_persistent_transport_loss"}';
        return;
    }

    echo json_encode([
        'events' => [[
            'event_type' => 'ScheduleTriggered',
            'recorded_at' => '2026-07-27T08:00:00Z',
            'payload' => ['occurrence_time' => '2026-07-27T08:00:00Z'],
        ]],
    ], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(404);
echo '{"reason":"not_found"}';
PHP,
        );

        $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketErrorMessage);
        $this->assertIsResource($socket, $socketErrorMessage);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $serverPort = (int) substr((string) strrchr($address, ':'), 1);
        $httpServer = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [
                1 => ['file', $serverLog, 'a'],
                2 => ['file', $serverLog, 'a'],
            ],
            $serverPipes,
            $resultDir,
            ['DW_FAKE_HISTORY_STATE' => $historyState],
        );
        $this->assertIsResource($httpServer);

        try {
            $serverReady = false;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.05);
                if (is_resource($connection)) {
                    fclose($connection);
                    $serverReady = true;
                    break;
                }
                usleep(20_000);
            }
            $this->assertTrue($serverReady, (string) @file_get_contents($serverLog));

            $process = proc_open(
                [$node, $repoRoot.'/scripts/conformance/schedules-published-artifacts.mjs'],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $repoRoot,
                [
                    'PATH' => $fakeBin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'DW_FAKE_DOCKER_LOG' => $dockerLog,
                    'DW_SCHEDULES_RESULT_DIR' => $resultDir,
                    'DW_SCHEDULES_REPO_ROOT' => $repoRoot,
                    'DW_SCHEDULES_RUN_CADENCE_SHARD' => '1',
                    'DW_SCHEDULES_SERVER_PORT' => (string) $serverPort,
                    'DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS' => '3',
                    'DW_SCHEDULES_CADENCE_TIMEOUT_SECONDS' => '10',
                    'DW_SCHEDULES_CADENCE_POLL_SECONDS' => '1',
                    'DW_SCHEDULES_CADENCE_TRANSPORT_FAILURE_BUDGET' => '1',
                    'DW_SERVER_IMAGE' => 'durableworkflow/server:2.0.0-beta.18',
                    'DW_SERVER_VERSION' => '2.0.0-beta.18',
                    'DW_CLI_VERSION' => '2.0.0-beta.18',
                    'DW_PYTHON_SDK_VERSION' => '2.0.0-beta.18',
                    'DW_PHP_SDK_VERSION' => '2.0.0-beta.18',
                    'DW_WATERLINE_VERSION' => '2.0.0-beta.18',
                    'DW_SCHEDULES_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED' => 'false',
                ],
            );
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr."\n".$stdout);

            $result = json_decode(
                (string) file_get_contents($resultDir.'/schedules-runtime-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            foreach (['cron_cadence', 'fixed_rate_cadence'] as $scenarioId) {
                $scenario = $result['scenario_results'][$scenarioId];
                $this->assertSame('runner_blocked', $scenario['status']);
                $this->assertSame(1, $scenario['observed_outputs']['observed_fire_count']);
                $this->assertSame(
                    'conformance_runner_blocked',
                    $scenario['linked_findings'][0]['finding_type'],
                );
                $this->assertSame(
                    'conformance_harness',
                    $scenario['linked_findings'][0]['owning_surface'],
                );
            }
            $this->assertNotContains(
                'schedule_cadence_contract_gap',
                array_column($result['findings'], 'finding_type'),
            );

            $dockerCalls = (string) file_get_contents($dockerLog);
            $this->assertStringContainsString('up -d --wait --wait-timeout', $dockerCalls);
            $this->assertSame(1, substr_count($dockerCalls, 'down -v --remove-orphans'));
        } finally {
            proc_terminate($httpServer);
            proc_close($httpServer);
            $this->removeDirectory($resultDir);
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
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
