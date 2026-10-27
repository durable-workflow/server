<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\BridgeCommandIdentityWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Models\WorkflowCommand;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class BridgeAdapterConcurrentAdmissionTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    #[DataProvider('commands')]
    public function test_overlapping_bridge_requests_reuse_the_committed_receipt(string $action, string $field, string $name, string $type, string $effects): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Independent processes are required.');
        }
        if (DB::connection()->getDriverName() === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
            $this->markTestSkipped('Independent SQLite processes require a shared database file.');
        }
        Queue::fake();
        $this->createNamespace('default');
        $this->configureWorkflowTypes(['tests.bridge-command-identity' => BridgeCommandIdentityWorkflow::class]);
        $start = $this->withHeaders($this->apiHeaders())->postJson('/api/workflows', [
            'workflow_id' => 'bridge-race', 'workflow_type' => 'tests.bridge-command-identity',
        ])->assertCreated();
        $this->runReadyWorkflowTask($start->json('run_id'));
        $path = '/api/bridge-adapters/webhook/stripe';
        $event = ['action' => $action, 'idempotency_key' => 'same-provider-event',
            'target' => ['workflow_id' => 'bridge-race', $field => $name], 'input' => ['first']];
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 20);
            $ok = false;
            try {
                DB::reconnect();
                $paused = false;
                // The old controller has already missed its duplicate lookup.
                // Pause before the writer opens a transaction or holds a lock.
                DB::connection()->beforeStartingTransaction(static function () use (&$paused, $sockets): void {
                    if ($paused) {
                        return;
                    }
                    $paused = true;
                    fwrite($sockets[1], "before-writer\n");
                    if (fgets($sockets[1]) !== "committed\n") {
                        throw new RuntimeException('The competing bridge admission did not commit.');
                    }
                });
                $response = $this->withHeaders($this->apiHeaders())->postJson($path, array_replace($event, ['input' => ['ignored']]));
                fwrite($sockets[1], json_encode([
                    'status' => $response->status(), 'outcome' => $response->json('outcome'),
                    'run_id' => $response->json('run_id'), 'idempotency_key' => $response->json('idempotency_key'),
                ], JSON_THROW_ON_ERROR)."\n");
                $ok = true;
            } catch (Throwable $exception) {
                fwrite($sockets[1], json_encode(['error_class' => $exception::class], JSON_THROW_ON_ERROR)."\n");
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 25);
        try {
            $this->assertSame("before-writer\n", fgets($sockets[0]));
            DB::reconnect();
            $accepted = $this->withHeaders($this->apiHeaders())->postJson($path, $event)->assertStatus(202);
            $winner = WorkflowCommand::query()->where('command_type', $type)->sole();
            $before = $winner->getRawOriginal();
            $this->assertSame(['first'], $winner->payloadArguments());
            fwrite($sockets[0], "committed\n");
            $result = json_decode((string) fgets($sockets[0]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error_class', $result);
            $this->assertSame(1, WorkflowCommand::query()->where('command_type', $type)->count());
            $this->assertDatabaseCount($effects, 1);
            $this->assertSame(200, $result['status']);
            $this->assertSame('duplicate', $result['outcome']);
            $this->assertSame($accepted->json('run_id'), $result['run_id']);
            $this->assertSame('same-provider-event', $result['idempotency_key']);
            pcntl_waitpid($pid, $status);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame($before, $winner->fresh()->getRawOriginal());
        } finally {
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
        }
    }

    public static function commands(): iterable
    {
        yield 'signal' => ['signal_workflow', 'signal_name', 'advance', 'signal', 'workflow_signal_records'];
        yield 'update' => ['update_workflow', 'update_name', 'approve', 'update', 'workflow_updates'];
    }
}
