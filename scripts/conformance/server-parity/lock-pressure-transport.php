<?php

declare(strict_types=1);

use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Transport\Transport;

require_once __DIR__.'/lock-pressure-retry.php';

/**
 * Applies the documented worker-poll lock-pressure retry to an SDK transport and
 * records each retry so evidence shows when a reference backend was contended.
 */
final class LockPressurePollRetryTransport implements Transport
{
    /** @var list<array{path: string, status: int, retry_after_seconds: float}> */
    public array $retries = [];

    private readonly Transport $inner;

    public function __construct(?Transport $inner = null)
    {
        $this->inner = $inner ?? new Psr18Transport();
    }

    public function send(string $method, string $uri, array $headers, ?array $body = null): ?array
    {
        $waited = 0.0;
        while (true) {
            try {
                return $this->inner->send($method, $uri, $headers, $body);
            } catch (TransportException $exception) {
                $delay = lockPressurePollRetryDelay($method, $uri, $exception->status, $exception->response,
                    count($this->retries), $waited);
                if ($delay === null) {
                    throw $exception;
                }
                $this->retries[] = ['path' => (string) parse_url($uri, PHP_URL_PATH), 'status' => (int) $exception->status,
                    'retry_after_seconds' => $delay];
                usleep((int) round($delay * 1_000_000));
                $waited += $delay;
            }
        }
    }
}

/**
 * Creates an SDK client for a parity probe with the documented poll retry
 * applied to its transport, keeping any transport the caller supplies. Any
 * retries are reported once on stderr when the process exits, so recorded
 * observations stay comparable across targets.
 */
function parityClient(mixed ...$arguments): \DurableWorkflow\Client
{
    static $transports = [];
    $transport = new LockPressurePollRetryTransport($arguments['transport'] ?? null);
    if ($transports === []) {
        register_shutdown_function(static function () use (&$transports): void {
            $retries = array_merge(...array_map(static fn (LockPressurePollRetryTransport $used): array => $used->retries, $transports));
            if ($retries !== []) {
                fwrite(STDERR, json_encode(['lock_pressure_poll_retries' => $retries], JSON_THROW_ON_ERROR).PHP_EOL);
            }
        });
    }
    $transports[] = $transport;
    $arguments['transport'] = $transport;

    return new \DurableWorkflow\Client(...$arguments);
}
