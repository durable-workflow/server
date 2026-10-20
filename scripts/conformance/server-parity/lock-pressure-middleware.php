<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__.'/lock-pressure-retry.php';

/**
 * The published PHP SQLite reference shares its file with its maintenance loop
 * and may refuse a request with the documented 503 backend_lock_pressure reply
 * (see lockPressureOperationRetryDelay and lockPressurePollRetryDelay for the
 * exact conditions). This Guzzle middleware retries only that refusal, below the SDK transport, so
 * the transport keeps every capability the SDK checks (bounded requests,
 * payload transfer). It stops within the request's own timeout. Push it after
 * any recording middleware (HandlerStack::push), which makes it innermost:
 * Guzzle wraps the stack in reverse, so unshift would put it outermost and let
 * recorders see each refused attempt. Recorders then see only the final reply
 * and observations stay comparable across targets. Retries are reported
 * once on stderr when the process exits.
 */
final class LockPressurePollRetries
{
    /** @var list<array{path: string, retry_after_seconds: float}> */
    public static array $retries = [];
    private static bool $reporting = false;

    public static function middleware(): callable
    {
        if (!self::$reporting) {
            self::$reporting = true;
            register_shutdown_function(static function (): void {
                if (self::$retries !== []) {
                    fwrite(STDERR, json_encode(['lock_pressure_poll_retries' => self::$retries], JSON_THROW_ON_ERROR).PHP_EOL);
                }
            });
        }

        return static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler) {
            $started = microtime(true);
            $budget = (float) ($options['timeout'] ?? 0);
            $waited = 0.0;
            $count = 0;
            while (true) {
                try {
                    $response = $handler($request, $options)->wait();
                } catch (\Throwable $error) {
                    return Create::rejectionFor($error);
                }
                // A request body that cannot be replayed exactly is never retried.
                if (!$response instanceof ResponseInterface || $response->getStatusCode() !== 503
                    || (!$request->getBody()->isSeekable() && $request->getBody()->getSize() !== 0)) {
                    return Create::promiseFor($response);
                }
                $text = (string) $response->getBody();
                $response = $response->withBody(Utils::streamFor($text));
                $decoded = json_decode($text, true);
                $delay = lockPressureOperationRetryDelay($request->getMethod(), (string) $request->getUri(), 503,
                    is_array($decoded) ? $decoded : null, $count, $waited);
                if ($delay === null || ($budget > 0 && microtime(true) - $started + $delay >= $budget)) {
                    return Create::promiseFor($response);
                }
                self::$retries[] = ['path' => $request->getUri()->getPath(), 'retry_after_seconds' => $delay];
                usleep((int) round($delay * 1_000_000));
                $waited += $delay;
                $count++;
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
            }
        };
    }

    /** The SDK's default transport, with this retry innermost. */
    public static function transport(): Psr18Transport
    {
        $stack = HandlerStack::create();
        $stack->push(self::middleware(), 'lock_pressure_poll_retry');

        return new Psr18Transport(client: new GuzzleClient(['http_errors' => false, 'handler' => $stack]));
    }
}

/** An SDK client for a parity probe. A caller-supplied transport is used unchanged. */
function parityClient(mixed ...$arguments): Client
{
    $arguments['transport'] ??= LockPressurePollRetries::transport();

    return new Client(...$arguments);
}
