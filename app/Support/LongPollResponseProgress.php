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

    private ?JsonResponse $projectedResponse = null;

    private ?Closure $responseProjector = null;

    private ?int $waitSeconds = null;

    private float $lastProgressAt = 0;

    private readonly float $startedAt;

    private bool $abandonmentRecorded = false;

    public function __construct(private readonly Request $request)
    {
        $this->startedAt = hrtime(true) / 1_000_000_000;
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

    public function projectResponsesUsing(Closure $projector): void
    {
        $this->responseProjector = $projector;
    }

    public function checkpoint(): void
    {
        if ($this->fiber === null || Fiber::getCurrent() !== $this->fiber
            || hrtime(true) / 1_000_000_000 - $this->lastProgressAt < 1) {
            return;
        }

        $this->waitSeconds ??= max(0, (int) $this->request->input('timeout_seconds', config('server.polling.timeout', 30)));
        $this->lastProgressAt = hrtime(true) / 1_000_000_000;
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
                    $observedSeconds = hrtime(true) / 1_000_000_000 - $this->startedAt;
                    try {
                        $this->fiber->throw(new PollClientDisconnected);
                    } finally {
                        // Logging and optional cache I/O follow exact wait
                        // cleanup, so a slow observer cannot retain capacity.
                        $this->recordAbandonment($observedSeconds);
                    }

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
        if ($this->projectedResponse !== null) {
            return $this->projectedResponse;
        }

        $response = $this->fiber->getReturn();

        if ($response instanceof JsonResponse) {
            // Immediate responses are inspected for status before send(), while
            // suspended waits finish during send(). Project either exactly once.
            return $this->projectedResponse = $this->responseProjector !== null ? ($this->responseProjector)($response) : $response;
        }

        logger()->error('Worker poll returned an unsupported progress-transport response.');

        return $this->projectedResponse = new JsonResponse([
            'task' => null,
            'reason' => 'worker_poll_transport_unavailable',
            'retryable' => true,
        ], 500);
    }

    private function recordAbandonment(?float $observedSeconds = null): void
    {
        if ($this->abandonmentRecorded || $this->waitSeconds === null) {
            return;
        }

        $this->abandonmentRecorded = true;
        $observedSeconds ??= hrtime(true) / 1_000_000_000 - $this->startedAt;
        logger()->warning('Worker long-poll client disconnected before its response was delivered.', [
            'namespace' => $this->request->attributes->get('namespace'),
            'task_queue' => $this->request->input('task_queue'),
            'observed_seconds' => round($observedSeconds, 3),
            'configured_wait_seconds' => $this->waitSeconds,
        ]);
        app(WorkerPollDiagnostics::class)->recordDisconnect(
            (string) $this->request->attributes->get('namespace'),
            (string) $this->request->input('task_queue'),
            basename(dirname($this->request->path())),
            $observedSeconds,
            $this->waitSeconds,
        );
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
