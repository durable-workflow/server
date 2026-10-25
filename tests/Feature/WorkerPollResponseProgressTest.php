<?php

namespace Tests\Feature;

use App\Http\Middleware\WorkerPollResponseProgress;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\LongPoller;
use App\Support\LongPollResponseProgress;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\QueryTaskPollRequestStore;
use App\Support\WorkflowTaskPollRequestStore;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkerPollResponseProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'server.polling.max_concurrent_waits' => 1]);
    }

    public function test_legacy_and_declined_transports_preserve_the_original_response(): void
    {
        foreach (['application/json', LongPollResponseProgress::MEDIA_TYPE.';q=0',
            LongPollResponseProgress::MEDIA_TYPE.';q=.5, application/json;q=1'] as $accept) {
            $original = new JsonResponse(['reason' => 'fixture'], 422);
            $response = $this->throughTransport(static fn () => $original, $accept);
            $this->assertSame($original, $response);
        }
    }

    public function test_negotiation_preserves_early_status_and_raw_json_body(): void
    {
        $raw = '{"task":null,"decimal":1.0,"large":9223372036854775808}';
        $original = JsonResponse::fromJsonString($raw, 429, ['Retry-After' => '2']);
        $response = $this->throughTransport(static fn () => $original, strtoupper(LongPollResponseProgress::MEDIA_TYPE).', application/json;q=0.9');
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(429, $response->getStatusCode());
        $wire = TestResponse::fromBaseResponse($response)->streamedContent();
        $this->assertStringContainsString('"body":'.$raw, $wire);
        $frame = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(LongPollResponseProgress::SCHEMA, $frame['schema']);
        $this->assertSame(429, $frame['status']);
        $this->assertSame('2', $frame['headers']['retry-after']);
    }

    public function test_a_real_wait_suspends_and_releases_its_slot_before_successful_delivery(): void
    {
        $poller = $this->poller(static fn () => usleep(2000));
        $probes = 0;
        $response = $this->throughTransport(function () use ($poller, &$probes): JsonResponse {
            $task = $poller->until(
                static function () use (&$probes): ?string {
                    return ++$probes > 1 ? 'fixture-task' : null;
                },
                static fn (?string $value): bool => $value !== null,
                timeoutSeconds: 1,
                intervalMilliseconds: 1,
                reserveWorkerWaitSlot: true,
                waitSlotNamespace: 'fixture',
            );

            return new JsonResponse(['task' => $task]);
        });
        $slots = app(LongPollWaitSlotStore::class);
        $this->assertSame(1, $probes);
        $this->assertNull($slots->tryAcquire(1, 'other'));
        $frame = json_decode(TestResponse::fromBaseResponse($response)->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(200, $frame['status']);
        $this->assertSame('fixture-task', $frame['body']['task']);
        $this->assertSame(2, $probes);
        $slot = $slots->tryAcquire(1, 'other');
        $this->assertNotNull($slot);
        $slot->release();
    }

    #[DataProvider('lateErrorStatuses')]
    public function test_late_framework_errors_preserve_status_retry_header_and_resource_cleanup(int $status): void
    {
        $poller = $this->poller(static function () use ($status): void {
            throw new HttpException($status, 'fixture late failure', headers: ['Retry-After' => '3']);
        });
        $response = $this->throughTransport(static function () use ($poller): JsonResponse {
            $poller->until(static fn () => null, static fn () => false, timeoutSeconds: 1, reserveWorkerWaitSlot: true);

            return new JsonResponse(['unreachable' => true]);
        });
        $this->assertSame(200, $response->getStatusCode());
        $frame = json_decode(TestResponse::fromBaseResponse($response)->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($status, $frame['status']);
        $this->assertSame('3', $frame['headers']['retry-after']);
        $this->assertIsArray($frame['body']);
        $this->assertNotSame('worker_poll_transport_unavailable', $frame['body']['reason'] ?? null);
        $slot = app(LongPollWaitSlotStore::class)->tryAcquire(1, 'other');
        $this->assertNotNull($slot);
        $slot->release();
    }

    public static function lateErrorStatuses(): array
    {
        return ['conflict' => [409], 'unavailable' => [503], 'internal' => [500]];
    }

    public function test_disconnect_unwinds_the_exact_wait_and_records_observed_and_configured_time(): void
    {
        $this->simulateDisconnect();
        Log::shouldReceive('warning')->once()->andReturnUsing(static function (): void {
            $slot = app(LongPollWaitSlotStore::class)->tryAcquire(1, 'diagnostic-observer');
            self::assertNotNull($slot, 'Diagnostic I/O must run after the abandoned wait releases its slot.');
            $slot->release();
        });
        $poller = $this->poller(static function (): void {
            self::fail('A disconnected wait must unwind before the next probe.');
        });
        $response = $this->throughTransport(static function () use ($poller): JsonResponse {
            $poller->until(static fn () => null, static fn () => false, timeoutSeconds: 30, reserveWorkerWaitSlot: true);

            return new JsonResponse(['unreachable' => true]);
        });
        $wire = TestResponse::fromBaseResponse($response)->streamedContent();
        $this->assertSame('', trim($wire));
        $slot = app(LongPollWaitSlotStore::class)->tryAcquire(1, 'other');
        $this->assertNotNull($slot);
        $slot->release();
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'disconnected') && $context['configured_wait_seconds'] === 30
            && $context['observed_seconds'] < 1 && $context['namespace'] === 'fixture');
    }

    #[DataProvider('pendingPollStores')]
    public function test_disconnected_duplicate_wait_does_not_release_the_live_leaders_pending_key(string $storeClass): void
    {
        $this->simulateDisconnect();
        $store = app($storeClass);
        $arguments = ['fixture', 'fixture-queue', null, 'fixture-worker', 'same-poll'];
        $this->assertTrue($store->tryStart(...$arguments));
        $response = $this->throughTransport(static function () use ($store, $arguments): JsonResponse {
            $store->waitForResult(...$arguments);

            return new JsonResponse(['unreachable' => true]);
        });
        $this->assertSame('', trim(TestResponse::fromBaseResponse($response)->streamedContent()));
        $this->assertFalse($store->tryStart(...$arguments));
        $store->forgetPending(...$arguments);
        $this->assertTrue($store->tryStart(...$arguments));
        $store->forgetPending(...$arguments);
    }

    public static function pendingPollStores(): array
    {
        return [
            'workflow' => [WorkflowTaskPollRequestStore::class],
            'activity' => [ActivityTaskPollRequestStore::class],
            'query' => [QueryTaskPollRequestStore::class],
        ];
    }

    private function simulateDisconnect(): void
    {
        $this->app->bind(LongPollResponseProgress::class, static function ($app, array $parameters) {
            return new class($parameters['request']) extends LongPollResponseProgress
            {
                protected function clientDisconnected(): bool
                {
                    return true;
                }
            };
        });
    }

    private function throughTransport(Closure $handler, string $accept = LongPollResponseProgress::MEDIA_TYPE): Response
    {
        $request = Request::create('/api/worker/workflow-tasks/poll', 'POST', ['task_queue' => 'fixture-queue', 'timeout_seconds' => 30]);
        $request->headers->set('Accept', $accept);
        $request->attributes->set('namespace', 'fixture');
        $this->app->instance('request', $request);

        return (new Pipeline($this->app))->send($request)
            ->through([WorkerPollResponseProgress::class])->then($handler);
    }

    private function poller(Closure $pause): LongPoller
    {
        return new class(app(LongPollSignalStore::class), app(LongPollWaitSlotStore::class), $pause) extends LongPoller
        {
            public function __construct($signals, $slots, private readonly Closure $pause)
            {
                parent::__construct($signals, $slots);
            }

            protected function pause(int $milliseconds): void
            {
                ($this->pause)();
            }
        };
    }
}
