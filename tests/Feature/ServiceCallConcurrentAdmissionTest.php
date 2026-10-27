<?php

namespace Tests\Feature;

use App\Support\ServerPollingCache;
use App\Support\ServiceCallBoundary;
use App\Support\SharedServiceBoundaryPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceBoundaryAuditRecorder;

// Each case must release every inherited PDO/WAL handle before the next
// migrate:fresh. Reusing the PHPUnit process can leave stale SQLite handles.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ServiceCallConcurrentAdmissionTest extends TestCase
{
    use DatabaseMigrations;
    use ServerTestHelpers;

    #[DataProvider('interleavings')]
    public function test_concurrent_http_winner_keeps_one_canonical_identity_and_one_budget_charge(string $kind, bool $foreign, string $pauseAt, bool $policyDenied): void
    {
        $driver = DB::connection()->getDriverName();
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Independent processes are required.');
        }
        if ($driver === 'sqlite' && (config('database.connections.sqlite.database') === ':memory:' || $pauseAt === 'insert')) {
            $this->markTestSkipped('SQLite needs a shared file and acquires its writer lock before insertion.');
        }
        Carbon::setTestNow('2026-10-27T04:00:05Z');
        $this->createNamespace('default');
        $this->createNamespace('other');
        config([
            'cache.default' => 'file',
            'server.service_boundary.shared_admission.max_requests_per_minute_per_namespace' => null,
            'server.service_boundary.shared_admission.max_in_flight_per_namespace' => null,
        ]);
        // Both actors inherit the same dedicated file-cache path. Array cache
        // would give each process its own quota and miss a double charge.
        app(ServerPollingCache::class)->store()->flush();
        $policy = new SharedServiceBoundaryPolicy(app(ServerPollingCache::class), [
            'rate_limit' => ['requests_per_minute' => 1],
        ]);
        $this->app->instance(ServiceBoundaryPolicy::class, $policy);
        $this->app->instance(ServiceCallBoundary::class, new ServiceCallBoundary($policy, new ServiceBoundaryAuditRecorder));
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'default', 'endpoint_name' => 'billing']);
        $service = WorkflowService::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'invoices',
        ]);
        $operation = WorkflowServiceOperation::query()->create([
            'namespace' => 'default', 'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id, 'operation_name' => 'create',
            'operation_mode' => 'async', 'handler_binding_kind' => $kind, 'handler_target_reference' => 'fixture-target',
        ]);
        $path = '/api/service-endpoints/billing/services/invoices/operations/create/execute';
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 15);
            $ok = false;
            try {
                DB::reconnect();
                if ($pauseAt === 'insert') {
                    DB::statement($driver === 'pgsql'
                        ? 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED'
                        : 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $paused = false;
                $pause = static function () use (&$paused, $sockets): void {
                    if ($paused) {
                        return;
                    }
                    $paused = true;
                    fwrite($sockets[1], "absent\n");
                    if (fgets($sockets[1]) !== "committed\n") {
                        throw new RuntimeException('The competing admission did not commit.');
                    }
                };
                if ($pauseAt === 'insert') {
                    WorkflowServiceCall::creating($pause);
                } else {
                    DB::connection()->beforeStartingTransaction($pause);
                }
                $response = $this->withHeaders($this->apiHeaders())->postJson($path, [
                    'idempotency_key' => 'same-key', 'caller_namespace' => $foreign ? 'other' : 'default',
                ]);
                fwrite($sockets[1], json_encode([
                    'status' => $response->status(),
                    'service_call_id' => $response->json('service_call_id'),
                    'reason' => $response->json('reason'),
                    'replayed' => $response->json('idempotent_replay'),
                    'retry_after_seconds' => $response->json('retry_after_seconds'),
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
        stream_set_timeout($sockets[0], 20);
        try {
            $this->assertSame("absent\n", fgets($sockets[0]));
            DB::reconnect();
            $this->withHeaders($this->apiHeaders())->postJson($path, ['idempotency_key' => 'same-key'])->assertOk();
            $winner = WorkflowServiceCall::query()->sole();
            $before = $winner->getRawOriginal();
            $issuedField = $kind === 'activity_execution' ? 'activity_execution_id' : 'carrier_request_id';
            $this->assertNotEmpty($winner->metadata[$issuedField] ?? null);
            if ($policyDenied) {
                $endpoint->update(['boundary_policy' => ['rate_limit' => ['requests_per_minute' => 3]]]);
                $service->update(['boundary_policy' => ['concurrency_limit' => 2]]);
                $operation->update(['boundary_policy' => ['circuit_break' => ['open' => true, 'retry_after_seconds' => 17]]]);
            }
            fwrite($sockets[0], "committed\n");
            $result = json_decode((string) fgets($sockets[0]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('error_class', $result);
            $this->assertSame($foreign ? 403 : ($policyDenied ? 503 : 200), $result['status']);
            if ($foreign) {
                $this->assertSame('service_operation_forbidden', $result['reason']);
                $this->assertNull($result['service_call_id']);
            } elseif ($policyDenied) {
                $this->assertSame('circuit_open', $result['reason']);
                $this->assertSame(17, $result['retry_after_seconds']);
                $this->assertNotSame($winner->id, $result['service_call_id']);
                $rejection = WorkflowServiceCall::query()->whereKeyNot($winner->id)->sole();
                $this->assertSame('failed', $rejection->status);
                $this->assertNull($rejection->idempotency_scope_hash);
                $recordedPolicy = $rejection->boundary_policy;
                ksort($recordedPolicy);
                $this->assertSame([
                    'circuit_break' => ['open' => true, 'retry_after_seconds' => 17],
                    'concurrency_limit' => 2,
                    'rate_limit' => ['requests_per_minute' => 3],
                ], $recordedPolicy);
            } else {
                $this->assertSame($winner->id, $result['service_call_id']);
                $this->assertTrue($result['replayed']);
            }
            pcntl_waitpid($pid, $status);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertDatabaseCount('workflow_service_calls', $policyDenied ? 2 : 1);
            $this->assertSame(1, WorkflowServiceCall::query()->whereNotNull('idempotency_scope_hash')->count());
            $this->assertSame($before, $winner->fresh()->getRawOriginal());
            if ($policyDenied) {
                $endpoint->update(['boundary_policy' => null]);
                $service->update(['boundary_policy' => null]);
                $operation->update(['boundary_policy' => null]);
            }
            $this->withHeaders($this->apiHeaders())->postJson($path, ['idempotency_key' => 'different-key'])->assertStatus(429);
        } finally {
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            fclose($sockets[0]);
            DB::purge();
            DB::reconnect();
            Cache::store('file')->flush();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function interleavings(): iterable
    {
        foreach (['activity_execution', 'invocable_carrier_request'] as $kind) {
            foreach ([false, true] as $foreign) {
                foreach (['transaction', 'insert'] as $pauseAt) {
                    yield $kind.'-'.(int) $foreign.'-'.$pauseAt => [$kind, $foreign, $pauseAt, false];
                }
            }
            foreach (['transaction', 'insert'] as $pauseAt) {
                yield $kind.'-policy-denial-'.$pauseAt => [$kind, false, $pauseAt, true];
            }
        }
    }
}
