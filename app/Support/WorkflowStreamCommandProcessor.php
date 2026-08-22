<?php

namespace App\Support;

use InvalidArgumentException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * Applies replay-safe Workflow Stream directives before task completion.
 *
 * The directive rides a record_side_effect command so the append/close and
 * SideEffectRecorded event commit in one outer database transaction. The
 * directive is stripped before the package command normalizer sees it.
 */
final class WorkflowStreamCommandProcessor
{
    public function __construct(
        private readonly WorkflowStreamService $streams,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    public function process(string $taskId, string $namespace, array $commands): array
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->whereKey($taskId)
            ->where('namespace', $namespace)
            ->lockForUpdate()
            ->first();

        if (! $task instanceof WorkflowTask) {
            return $commands;
        }

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->whereKey($task->workflow_run_id)->lockForUpdate()->first();

        if (! $run instanceof WorkflowRun) {
            return $commands;
        }

        $taskPayload = is_array($task->payload) ? $task->payload : [];
        $commandIdentity = $this->nonEmptyString($taskPayload['workflow_command_id'] ?? null)
            ?? (string) $task->id;

        foreach ($commands as $commandIndex => $command) {
            $directive = $command['workflow_stream'] ?? null;

            if ($directive === null) {
                continue;
            }

            if (($command['type'] ?? null) !== 'record_side_effect' || ! is_array($directive)) {
                throw new InvalidArgumentException(
                    'workflow_stream directives require a record_side_effect workflow command',
                );
            }

            $suppliedIdentity = $this->nonEmptyString($directive['command_identity'] ?? null);
            $commandOrdinal = $directive['command_ordinal'] ?? null;

            if ($suppliedIdentity !== $commandIdentity) {
                throw new InvalidArgumentException(
                    'workflow_stream command_identity must match the durable workflow command identity',
                );
            }

            if (! is_int($commandOrdinal) || $commandOrdinal < 0) {
                throw new InvalidArgumentException(
                    'workflow_stream command_ordinal must be a non-negative integer',
                );
            }

            $streamName = $this->nonEmptyString($directive['stream_name'] ?? null);

            if ($streamName === null) {
                throw new InvalidArgumentException('workflow_stream stream_name is required');
            }

            $operation = $directive['operation'] ?? null;

            if ($operation === 'append') {
                $items = $directive['items'] ?? null;

                if (! is_array($items) || $items === []) {
                    throw new InvalidArgumentException('workflow_stream append requires items');
                }

                $normalizedItems = [];
                foreach (array_values($items) as $itemIndex => $item) {
                    if (! is_array($item)) {
                        throw new InvalidArgumentException('workflow_stream items must be objects');
                    }

                    $expectedKey = sprintf(
                        'dw-stream:%s:%d:%d',
                        $commandIdentity,
                        $commandOrdinal,
                        $itemIndex,
                    );

                    if (($item['idempotency_key'] ?? null) !== $expectedKey) {
                        throw new InvalidArgumentException(
                            'workflow_stream item idempotency_key must derive from durable command identity',
                        );
                    }

                    $item['origin'] = 'workflow_command';
                    $item['origin_reference'] = $commandIdentity;
                    $normalizedItems[] = $item;
                }

                $this->streams->append(
                    $run,
                    $namespace,
                    $streamName,
                    $normalizedItems,
                    isset($directive['max_pending_items'])
                        ? (int) $directive['max_pending_items']
                        : WorkflowStreamService::DEFAULT_MAX_PENDING_ITEMS,
                );
            } elseif ($operation === 'close' || $operation === 'error') {
                $errorReason = $operation === 'error'
                    ? $this->nonEmptyString($directive['error_reason'] ?? null)
                    : null;

                if ($operation === 'error' && $errorReason === null) {
                    throw new InvalidArgumentException(
                        'workflow_stream error requires a non-empty error_reason',
                    );
                }

                $this->streams->close(
                    $run,
                    $namespace,
                    $streamName,
                    $errorReason,
                    isset($directive['retention_seconds'])
                        ? (int) $directive['retention_seconds']
                        : null,
                );
            } else {
                throw new InvalidArgumentException(sprintf(
                    'unsupported workflow_stream operation at command %d',
                    $commandIndex,
                ));
            }

            unset($commands[$commandIndex]['workflow_stream']);
        }

        return array_values($commands);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
