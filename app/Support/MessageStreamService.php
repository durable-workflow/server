<?php

namespace App\Support;

use App\Contracts\RuntimeSignalControlPlane;
use App\Models\WorkflowInboundStream;
use App\Models\WorkflowInboundStreamItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloads;

final class MessageStreamService
{
    public function __construct(
        private readonly RuntimeSignalControlPlane $controlPlane,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function append(
        string $namespace,
        string $workflowId,
        string $streamName,
        string $messageId,
        string $payloadCodec,
        string $payloadBlob,
        string $payloadHash,
        ?CommandContext $commandContext = null,
    ): array {
        return DB::transaction(function () use (
            $namespace,
            $workflowId,
            $streamName,
            $messageId,
            $payloadCodec,
            $payloadBlob,
            $payloadHash,
            $commandContext,
        ): array {
            $instance = WorkflowInstance::query()
                ->whereKey($workflowId)
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->first();

            if (! $instance instanceof WorkflowInstance) {
                return $this->rejection('instance_not_found', 404, $workflowId, $streamName, $messageId);
            }

            $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);
            if (! $run instanceof WorkflowRun || $run->status->isTerminal()) {
                return $this->rejection('run_not_active', 409, $workflowId, $streamName, $messageId);
            }

            $stream = $this->lockedStream($namespace, $workflowId, $streamName);
            $existing = WorkflowInboundStreamItem::query()
                ->where('stream_id', $stream->id)
                ->where('message_id', $messageId)
                ->first();

            if ($existing instanceof WorkflowInboundStreamItem) {
                $samePayload = hash_equals((string) $existing->payload_hash, $payloadHash);
                $stream->forceFill([
                    'duplicate_count' => $stream->duplicate_count + 1,
                    'last_input_outcome' => $samePayload ? 'duplicate' : 'message_identity_conflict',
                    'last_input_message_id' => $messageId,
                    'last_input_at' => now(),
                ])->save();

                return [
                    'accepted' => $samePayload,
                    'duplicate' => $samePayload,
                    'workflow_id' => $workflowId,
                    'stream_name' => $streamName,
                    'message_id' => $messageId,
                    'position' => $existing->position,
                    'cursor_position' => $stream->cursor_position,
                    'outcome' => $samePayload ? 'duplicate' : 'rejected',
                    'reason' => $samePayload ? null : 'message_identity_conflict',
                    'status' => $samePayload ? 200 : 409,
                ];
            }

            $position = $stream->last_position + 1;
            $item = WorkflowInboundStreamItem::query()->create([
                'stream_id' => $stream->id,
                'namespace' => $namespace,
                'workflow_instance_id' => $workflowId,
                'stream_name' => $streamName,
                'message_id' => $messageId,
                'position' => $position,
                'payload_codec' => $payloadCodec,
                'payload_blob' => $payloadBlob,
                'payload_hash' => $payloadHash,
            ]);

            $stream->forceFill([
                'last_position' => $position,
                'waiting_run_id' => null,
                'waiting_after_position' => null,
                'waiting_since' => null,
                'last_input_outcome' => 'accepted',
                'last_input_message_id' => $messageId,
                'last_input_at' => now(),
            ])->save();

            $delivery = $this->deliver($item, $run, $commandContext);
            if (($delivery['accepted'] ?? false) !== true) {
                $item->delete();
                $stream->forceFill([
                    'last_position' => $position - 1,
                    'last_input_outcome' => 'message_stream_not_supported',
                ])->save();

                return $this->rejection(
                    'message_stream_not_supported',
                    409,
                    $workflowId,
                    $streamName,
                    $messageId,
                    [
                        'detail' => $delivery['reason'] ?? $delivery['command_reason'] ?? 'internal_signal_rejected',
                        'remediation' => 'Use a PHP, Python, or Rust worker that advertises the message_streams capability.',
                    ],
                );
            }

            return [
                'accepted' => true,
                'duplicate' => false,
                'workflow_id' => $workflowId,
                'run_id' => $run->id,
                'stream_name' => $streamName,
                'message_id' => $messageId,
                'position' => $position,
                'cursor_position' => $stream->cursor_position,
                'outcome' => 'accepted',
                'reason' => null,
                'status' => 202,
            ];
        });
    }

    public function recordMalformed(
        string $namespace,
        string $workflowId,
        string $streamName,
        ?string $messageId,
    ): void {
        $diagnosticMessageId = $this->diagnosticMessageId($messageId);

        DB::transaction(function () use ($namespace, $workflowId, $streamName, $diagnosticMessageId): void {
            $instance = WorkflowInstance::query()
                ->whereKey($workflowId)
                ->where('namespace', $namespace)
                ->lockForUpdate()
                ->first();
            if (! $instance instanceof WorkflowInstance) {
                return;
            }

            $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);
            if (! $run instanceof WorkflowRun || $run->status->isTerminal()) {
                return;
            }

            $stream = $this->lockedStream($namespace, $workflowId, $streamName);
            $stream->forceFill([
                'malformed_count' => $stream->malformed_count + 1,
                'last_input_outcome' => 'malformed',
                'last_input_message_id' => $diagnosticMessageId,
                'last_input_at' => now(),
            ])->save();
        });
    }

    private function diagnosticMessageId(?string $messageId): string
    {
        if ($messageId === null) {
            return '[omitted]';
        }

        if (strlen($messageId) <= 191 && preg_match('/^[A-Za-z0-9._:-]+$/', $messageId) === 1) {
            return $messageId;
        }

        return 'sha256:'.hash('sha256', $messageId);
    }

    /**
     * Validate worker-owned acknowledgements before the task completion mutates history.
     *
     * @param  list<array<string, mixed>>  $cursors
     * @param  list<array<string, mixed>>  $waits
     */
    public function validateCompletion(string $namespace, string $taskId, array $cursors, array $waits): void
    {
        $task = WorkflowTask::query()
            ->whereKey($taskId)
            ->where('namespace', $namespace)
            ->first();
        $run = $task instanceof WorkflowTask ? WorkflowRun::query()->find($task->workflow_run_id) : null;

        if (! $task instanceof WorkflowTask || ! $run instanceof WorkflowRun) {
            return;
        }

        $cursorNames = [];
        $proposedCursors = [];
        foreach ($cursors as $index => $cursor) {
            $streamName = $this->metadataStreamName($cursor, "message_stream_cursors.{$index}.stream_name");
            if (isset($cursorNames[$streamName])) {
                throw ValidationException::withMessages([
                    "message_stream_cursors.{$index}.stream_name" => ['A stream may be acknowledged only once per workflow task completion.'],
                ]);
            }
            $cursorNames[$streamName] = true;

            $through = $cursor['through_position'] ?? null;
            if (! is_int($through) || $through < 0) {
                throw ValidationException::withMessages([
                    "message_stream_cursors.{$index}.through_position" => ['The through_position must be a non-negative integer.'],
                ]);
            }

            $stream = WorkflowInboundStream::query()
                ->where('namespace', $namespace)
                ->where('workflow_instance_id', $run->workflow_instance_id)
                ->where('stream_name', $streamName)
                ->first();

            if (! $stream instanceof WorkflowInboundStream || $through > $stream->last_position) {
                throw ValidationException::withMessages([
                    "message_stream_cursors.{$index}.through_position" => ['The cursor cannot advance beyond the last accepted stream position.'],
                ]);
            }

            if ($through > $stream->cursor_position) {
                $expected = $through - $stream->cursor_position;
                $delivered = WorkflowInboundStreamItem::query()
                    ->where('stream_id', $stream->id)
                    ->where('position', '>', $stream->cursor_position)
                    ->where('position', '<=', $through)
                    ->where('delivered_run_id', $run->id)
                    ->count();

                if ($delivered !== $expected) {
                    throw ValidationException::withMessages([
                        "message_stream_cursors.{$index}.through_position" => ['The cursor must advance contiguously through messages delivered to this workflow run.'],
                    ]);
                }
            }
            $proposedCursors[$streamName] = max($stream->cursor_position, $through);
        }

        $waitNames = [];
        foreach ($waits as $index => $wait) {
            $streamName = $this->metadataStreamName($wait, "message_stream_waits.{$index}.stream_name");
            if (isset($waitNames[$streamName])) {
                throw ValidationException::withMessages([
                    "message_stream_waits.{$index}.stream_name" => ['A stream may have only one pending wait per workflow task completion.'],
                ]);
            }
            $waitNames[$streamName] = true;

            $after = $wait['after_position'] ?? null;
            if (! is_int($after) || $after < 0) {
                throw ValidationException::withMessages([
                    "message_stream_waits.{$index}.after_position" => ['The after_position must be a non-negative integer.'],
                ]);
            }

            $stream = WorkflowInboundStream::query()
                ->where('namespace', $namespace)
                ->where('workflow_instance_id', $run->workflow_instance_id)
                ->where('stream_name', $streamName)
                ->first();
            $expected = $proposedCursors[$streamName]
                ?? ($stream instanceof WorkflowInboundStream ? $stream->cursor_position : 0);
            if ($after !== $expected) {
                throw ValidationException::withMessages([
                    "message_stream_waits.{$index}.after_position" => ['The pending wait must start after the durable stream cursor.'],
                ]);
            }
        }
    }

    /**
     * Persist cursor and wait metadata after a successful bridge completion.
     *
     * @param  list<array<string, mixed>>  $cursors
     * @param  list<array<string, mixed>>  $waits
     * @param  array<string, mixed>  $outcome
     */
    public function recordCompletion(
        string $namespace,
        string $taskId,
        array $cursors,
        array $waits,
        array $outcome,
    ): void {
        if (($outcome['completed'] ?? false) !== true) {
            return;
        }

        $task = WorkflowTask::query()->find($taskId);
        $sourceRun = $task instanceof WorkflowTask ? WorkflowRun::query()->find($task->workflow_run_id) : null;
        if (! $task instanceof WorkflowTask || ! $sourceRun instanceof WorkflowRun) {
            return;
        }

        foreach ($cursors as $cursor) {
            $streamName = (string) $cursor['stream_name'];
            $through = (int) $cursor['through_position'];
            $stream = $this->lockedStream($namespace, $sourceRun->workflow_instance_id, $streamName);
            $previous = $stream->cursor_position;

            if ($through <= $previous) {
                continue;
            }

            WorkflowInboundStreamItem::query()
                ->where('stream_id', $stream->id)
                ->where('position', '>', $previous)
                ->where('position', '<=', $through)
                ->update([
                    'consumed_run_id' => $sourceRun->id,
                    'consumed_task_id' => $taskId,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            $stream->forceFill([
                'cursor_position' => $through,
                'waiting_run_id' => null,
                'waiting_after_position' => null,
                'waiting_since' => null,
            ])->save();

            WorkflowHistoryEvent::record($sourceRun, HistoryEventType::MessageCursorAdvanced, [
                'stream_key' => $streamName,
                'previous_position' => $previous,
                'new_position' => $through,
            ], $task);
        }

        foreach ($waits as $wait) {
            $stream = $this->lockedStream(
                $namespace,
                $sourceRun->workflow_instance_id,
                (string) $wait['stream_name'],
            );
            $after = (int) $wait['after_position'];

            if ($stream->last_position > $after) {
                continue;
            }

            $stream->forceFill([
                'waiting_run_id' => $sourceRun->id,
                'waiting_after_position' => $after,
                'waiting_since' => now(),
            ])->save();
        }

        $currentRun = NamespaceWorkflowScope::currentRun($namespace, $sourceRun->workflow_instance_id);
        if ($currentRun instanceof WorkflowRun && $currentRun->id !== $sourceRun->id) {
            $this->carryForward($namespace, $sourceRun->workflow_instance_id, $currentRun);
        }
    }

    /** @return list<array<string, mixed>> */
    public function diagnostics(string $namespace, string $workflowId, ?string $streamName = null): array
    {
        return WorkflowInboundStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowId)
            ->when($streamName !== null, static fn ($query) => $query->where('stream_name', $streamName))
            ->orderBy('stream_name')
            ->get()
            ->map(static function (WorkflowInboundStream $stream): array {
                return [
                    'stream_name' => $stream->stream_name,
                    'last_position' => $stream->last_position,
                    'cursor_position' => $stream->cursor_position,
                    'pending_count' => max(0, $stream->last_position - $stream->cursor_position),
                    'pending_wait' => $stream->waiting_run_id !== null ? [
                        'run_id' => $stream->waiting_run_id,
                        'after_position' => $stream->waiting_after_position,
                        'since' => $stream->waiting_since?->toJSON(),
                    ] : null,
                    'duplicate_count' => $stream->duplicate_count,
                    'malformed_count' => $stream->malformed_count,
                    'last_input_outcome' => $stream->last_input_outcome,
                    'last_input_message_id' => $stream->last_input_message_id,
                    'last_input_at' => $stream->last_input_at?->toJSON(),
                ];
            })
            ->all();
    }

    private function lockedStream(string $namespace, string $workflowId, string $streamName): WorkflowInboundStream
    {
        $stream = WorkflowInboundStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowId)
            ->where('stream_name', $streamName)
            ->lockForUpdate()
            ->first();

        if ($stream instanceof WorkflowInboundStream) {
            return $stream;
        }

        return WorkflowInboundStream::query()->create([
            'namespace' => $namespace,
            'workflow_instance_id' => $workflowId,
            'stream_name' => $streamName,
            'last_position' => 0,
            'cursor_position' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function deliver(
        WorkflowInboundStreamItem $item,
        WorkflowRun $run,
        ?CommandContext $commandContext,
    ): array {
        $payloadCodec = PayloadCodecContract::canonicalize((string) $item->payload_codec);
        $arguments = [[
            'schema' => MessageStreamsContract::MESSAGE_SCHEMA,
            'stream_name' => $item->stream_name,
            'message_id' => $item->message_id,
            'position' => $item->position,
            'payload_envelope' => ExternalPayloads::wireEnvelope(
                (string) $item->payload_blob,
                $payloadCodec,
                (string) $item->namespace,
            ),
        ]];
        $signalCodec = PayloadCodecContract::CODEC;

        $result = $this->controlPlane->runtimeSignal(
            (string) $item->workflow_instance_id,
            MessageStreamsContract::INTERNAL_SIGNAL,
            array_filter([
                'namespace' => $item->namespace,
                'arguments' => $arguments,
                'payload_codec' => $signalCodec,
                'payload_blob' => Serializer::serializeWithCodec($signalCodec, $arguments),
                'command_context' => $commandContext,
                'strict_configured_type_validation' => true,
            ], static fn (mixed $value): bool => $value !== null),
        );

        if (($result['accepted'] ?? false) === true) {
            $item->forceFill([
                'delivered_run_id' => $run->id,
                'workflow_command_id' => $result['workflow_command_id'] ?? null,
                'delivered_at' => now(),
            ])->save();
        }

        return $result;
    }

    private function carryForward(string $namespace, string $workflowId, WorkflowRun $currentRun): void
    {
        WorkflowInboundStream::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowId)
            ->where('cursor_position', '>', 0)
            ->where(function ($query) use ($currentRun): void {
                $query->whereNull('cursor_checkpoint_run_id')
                    ->orWhere('cursor_checkpoint_run_id', '!=', $currentRun->id);
            })
            ->orderBy('stream_name')
            ->each(function (WorkflowInboundStream $stream) use ($currentRun): void {
                $this->deliverCursorCheckpoint($stream, $currentRun);
            });

        WorkflowInboundStreamItem::query()
            ->where('namespace', $namespace)
            ->where('workflow_instance_id', $workflowId)
            ->whereNull('consumed_at')
            ->where(function ($query) use ($currentRun): void {
                $query->whereNull('delivered_run_id')
                    ->orWhere('delivered_run_id', '!=', $currentRun->id);
            })
            ->orderBy('id')
            ->chunkById(MessageStreamsContract::MAX_BATCH_SIZE, function ($items) use ($currentRun): void {
                foreach ($items as $item) {
                    if ($item instanceof WorkflowInboundStreamItem) {
                        $result = $this->deliver($item, $currentRun, null);
                        if (($result['accepted'] ?? false) !== true) {
                            throw new \RuntimeException('Unable to carry an unconsumed message stream item into the continued workflow run.');
                        }
                    }
                }
            });
    }

    private function deliverCursorCheckpoint(WorkflowInboundStream $stream, WorkflowRun $run): void
    {
        $arguments = [[
            'schema' => MessageStreamsContract::CURSOR_SCHEMA,
            'stream_name' => $stream->stream_name,
            'through_position' => $stream->cursor_position,
        ]];
        $codec = PayloadCodecContract::CODEC;
        $result = $this->controlPlane->runtimeSignal(
            (string) $stream->workflow_instance_id,
            MessageStreamsContract::INTERNAL_SIGNAL,
            [
                'namespace' => $stream->namespace,
                'arguments' => $arguments,
                'payload_codec' => $codec,
                'payload_blob' => Serializer::serializeWithCodec($codec, $arguments),
                'strict_configured_type_validation' => true,
            ],
        );

        if (($result['accepted'] ?? false) !== true) {
            throw new \RuntimeException('Unable to carry the message stream cursor into the continued workflow run.');
        }

        $stream->forceFill(['cursor_checkpoint_run_id' => $run->id])->save();
    }

    private function metadataStreamName(array $metadata, string $field): string
    {
        $streamName = $metadata['stream_name'] ?? null;
        if (! is_string($streamName)
            || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $streamName)) {
            throw ValidationException::withMessages([
                $field => ['The stream_name must contain 1-128 letters, numbers, periods, underscores, colons, or hyphens.'],
            ]);
        }

        return $streamName;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function rejection(
        string $reason,
        int $status,
        string $workflowId,
        string $streamName,
        string $messageId,
        array $extra = [],
    ): array {
        return array_merge([
            'accepted' => false,
            'duplicate' => false,
            'workflow_id' => $workflowId,
            'stream_name' => $streamName,
            'message_id' => $messageId,
            'position' => null,
            'outcome' => 'rejected',
            'reason' => $reason,
            'status' => $status,
        ], $extra);
    }
}
