<?php

namespace App\Support;

use Closure;
use Fiber;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LongPollResponseProgress
{
    public const MEDIA_TYPE = 'application/vnd.durable-workflow.poll.v1+json';

    public const SCHEMA = 'durable-workflow.poll-transport.v1';

    public const REQUEST_ATTRIBUTE = '_server_poll_response_progress';

    private ?Fiber $fiber = null;

    private ?int $waitSeconds = null;

    private float $lastProgressAt = 0;

    private readonly float $startedAt;

    private bool $abandonmentRecorded = false;

    public function __construct(private readonly Request $request)
    {
        $this->startedAt = microtime(true);
    }

    public static function current(): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }

        $progress = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return $progress instanceof self ? $progress : null;
    }

    public function beginWait(int $waitSeconds): void
    {
        $this->waitSeconds = $waitSeconds;
        $this->checkpoint();
    }

    public function checkpoint(): void
    {
        if ($this->fiber === null || Fiber::getCurrent() !== $this->fiber
            || microtime(true) - $this->lastProgressAt < 1) {
            return;
        }

        $this->waitSeconds ??= max(0, (int) $this->request->input('timeout_seconds', config('server.polling.timeout', 30)));
        $this->lastProgressAt = microtime(true);
        Fiber::suspend();
    }

    public function response(Closure $handler): Response
    {
        $this->fiber = new Fiber($handler);
        $this->fiber->start();
        $status = $this->fiber->isTerminated() ? $this->terminalResponse()->getStatusCode() : 200;

        return new StreamedResponse($this->send(...), $status, [
            'Content-Type' => self::MEDIA_TYPE,
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
            'Vary' => 'Accept',
        ]);
    }

    private function send(): void
    {
        $previous = ignore_user_abort(true);

        try {
            while (! $this->fiber->isTerminated()) {
                echo "\n";
                $this->flushOutput();

                if ($this->clientDisconnected()) {
                    $this->recordAbandonment();
                    $this->fiber->throw(new PollClientDisconnected);

                    return;
                }

                $this->fiber->resume();
            }

            $response = $this->terminalResponse();
            $headers = array_filter([
                'retry-after' => $response->headers->get('Retry-After'),
                strtolower(WorkerProtocol::HEADER) => $response->headers->get(WorkerProtocol::HEADER),
            ], static fn (?string $value): bool => $value !== null);

            // Keep the already encoded worker body intact, including numeric
            // representation and payload envelopes. Progress is JSON whitespace.
            echo '{"schema":"'.self::SCHEMA.'","status":'.$response->getStatusCode().',"headers":';
            echo json_encode((object) $headers, JSON_THROW_ON_ERROR);
            echo ',"body":', $response->getContent(), '}';
            $this->flushOutput();

            if ($this->clientDisconnected()) {
                $this->recordAbandonment();
            }
        } catch (PollClientDisconnected) {
            // The exact wait owner has unwound through its normal finally.
        } finally {
            try {
                if ($this->fiber->isSuspended()) {
                    try {
                        $this->fiber->throw(new PollClientDisconnected);
                    } catch (PollClientDisconnected) {
                        // Do not leave an admitted wait suspended on output failure.
                    }
                }
            } finally {
                ignore_user_abort($previous);
            }
        }
    }

    private function terminalResponse(): JsonResponse
    {
        $response = $this->fiber->getReturn();

        if ($response instanceof JsonResponse) {
            return $response;
        }

        logger()->error('Worker poll returned an unsupported progress-transport response.');

        return new JsonResponse([
            'task' => null,
            'reason' => 'worker_poll_transport_unavailable',
            'retryable' => true,
        ], 500);
    }

    private function recordAbandonment(): void
    {
        if ($this->abandonmentRecorded || $this->waitSeconds === null) {
            return;
        }

        $this->abandonmentRecorded = true;
        logger()->warning('Worker long-poll client disconnected before its response was delivered.', [
            'namespace' => $this->request->attributes->get('namespace'),
            'task_queue' => $this->request->input('task_queue'),
            'observed_seconds' => round(microtime(true) - $this->startedAt, 3),
            'configured_wait_seconds' => $this->waitSeconds,
        ]);
    }

    protected function clientDisconnected(): bool
    {
        return connection_aborted() !== 0;
    }

    protected function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
