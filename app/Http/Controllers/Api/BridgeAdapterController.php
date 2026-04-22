<?php

namespace App\Http\Controllers\Api;

use App\Support\BridgeAdapterOutcomeContract;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkflowCommandContextFactory;
use App\Support\WorkflowStartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Support\PayloadEnvelopeResolver;

class BridgeAdapterController
{
    public function __construct(
        private readonly WorkflowStartService $workflowStartService,
        private readonly WorkflowControlPlane $workflowControlPlane,
        private readonly WorkflowCommandContextFactory $commandContexts,
    ) {}

    public function webhook(Request $request, string $adapter): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $adapter = trim($adapter);

        if ($adapter === '' || ! preg_match('/^[a-zA-Z0-9._:-]+$/', $adapter)) {
            return $this->rejected($request, $adapter, null, null, 'unsupported_routing', [
                'message' => 'Bridge adapter route is unsupported.',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'action' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'target' => ['required', 'array'],
            'input' => ['nullable', 'array'],
            'correlation' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->rejected($request, $adapter, null, null, 'malformed_payload', [
                'message' => 'Bridge event payload is malformed.',
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $validated = $validator->validated();
        $action = (string) $validated['action'];
        $idempotencyKey = (string) $validated['idempotency_key'];
        $target = $this->arrayValue($validated, 'target');
        $correlation = $this->arrayValue($validated, 'correlation') ?: null;

        if (! in_array($action, ['start_workflow', 'signal_workflow', 'update_workflow'], true)) {
            return $this->rejected($request, $adapter, $action, $idempotencyKey, 'unsupported_action', [
                'message' => 'Bridge action is unsupported.',
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        return match ($action) {
            'start_workflow' => $this->startWorkflow($request, $adapter, $idempotencyKey, $target, $validated, $correlation),
            'signal_workflow' => $this->signalWorkflow($request, $adapter, $idempotencyKey, $target, $validated, $correlation),
            'update_workflow' => $this->updateWorkflow($request, $adapter, $idempotencyKey, $target, $validated, $correlation),
        };
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $correlation
     */
    private function startWorkflow(
        Request $request,
        string $adapter,
        string $idempotencyKey,
        array $target,
        array $validated,
        ?array $correlation,
    ): JsonResponse {
        $targetValidator = Validator::make($target, [
            'workflow_type' => ['required', 'string', 'max:255'],
            'workflow_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'task_queue' => ['nullable', 'string', 'max:255'],
            'business_key' => ['nullable', 'string', 'max:255'],
            'duplicate_policy' => ['nullable', 'string', 'in:reject_duplicate,use_existing'],
        ]);

        if ($targetValidator->fails()) {
            return $this->rejected($request, $adapter, 'start_workflow', $idempotencyKey, 'malformed_payload', [
                'message' => 'Bridge start target is malformed.',
                'errors' => $targetValidator->errors()->toArray(),
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $startTarget = $targetValidator->validated();
        $namespace = $request->attributes->get('namespace');
        $workflowId = is_string($startTarget['workflow_id'] ?? null)
            ? $startTarget['workflow_id']
            : $this->workflowIdFor($adapter, $idempotencyKey);

        try {
            $start = $this->workflowStartService->start(
                array_filter([
                    'workflow_id' => $workflowId,
                    'workflow_type' => $startTarget['workflow_type'],
                    'task_queue' => $startTarget['task_queue'] ?? null,
                    'business_key' => $startTarget['business_key'] ?? null,
                    'input' => $validated['input'] ?? null,
                    'duplicate_policy' => $this->startDuplicatePolicy($startTarget['duplicate_policy'] ?? null),
                ], static fn (mixed $value): bool => $value !== null),
                $namespace,
                $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'bridge.start_workflow',
                    metadata: [
                        'adapter' => $adapter,
                        'action' => 'start_workflow',
                        'idempotency_key' => $idempotencyKey,
                    ],
                ),
            );
        } catch (LogicException $exception) {
            return $this->rejected($request, $adapter, 'start_workflow', $idempotencyKey, 'unknown_target', [
                'message' => $exception->getMessage(),
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        } catch (ValidationException $exception) {
            return $this->rejected($request, $adapter, 'start_workflow', $idempotencyKey, 'malformed_payload', [
                'message' => $exception->getMessage(),
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        NamespaceWorkflowScope::bind($namespace, $start['workflow_id'], $start['workflow_type']);

        $duplicate = $start['outcome'] === 'returned_existing_active';

        return $this->outcome($request, [
            'adapter' => $adapter,
            'action' => 'start_workflow',
            'accepted' => ! $duplicate,
            'outcome' => $duplicate ? 'duplicate' : 'accepted',
            'reason' => $duplicate ? 'duplicate_start' : null,
            'idempotency_key' => $idempotencyKey,
            'target' => $this->redactedTarget($target + ['workflow_id' => $start['workflow_id']]),
            'correlation' => $correlation,
            'workflow_id' => $start['workflow_id'],
            'run_id' => $start['run_id'],
            'workflow_type' => $start['workflow_type'],
            'control_plane_outcome' => $start['outcome'],
        ], $duplicate ? 200 : 202);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $correlation
     */
    private function signalWorkflow(
        Request $request,
        string $adapter,
        string $idempotencyKey,
        array $target,
        array $validated,
        ?array $correlation,
    ): JsonResponse {
        $targetValidator = Validator::make($target, [
            'workflow_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'signal_name' => ['required', 'string', 'max:255'],
        ]);

        if ($targetValidator->fails()) {
            return $this->rejected($request, $adapter, 'signal_workflow', $idempotencyKey, 'malformed_payload', [
                'message' => 'Bridge signal target is malformed.',
                'errors' => $targetValidator->errors()->toArray(),
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $signalTarget = $targetValidator->validated();
        $namespace = $request->attributes->get('namespace');
        $workflowId = (string) $signalTarget['workflow_id'];
        $signalName = (string) $signalTarget['signal_name'];

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return $this->rejected($request, $adapter, 'signal_workflow', $idempotencyKey, 'unknown_target', [
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $envelope = PayloadEnvelopeResolver::resolve($validated['input'] ?? null, 'input');

        $duplicate = $this->duplicateBridgeCommand(
            workflowId: $workflowId,
            commandType: 'signal',
            adapter: $adapter,
            action: 'signal_workflow',
            idempotencyKey: $idempotencyKey,
            targetField: 'signal_name',
            targetName: $signalName,
        );

        if ($duplicate instanceof WorkflowCommand) {
            return $this->duplicateCommandOutcome(
                $request,
                $adapter,
                'signal_workflow',
                $idempotencyKey,
                $target,
                $correlation,
                $duplicate,
            );
        }

        $result = $this->workflowControlPlane->signal($workflowId, $signalName, [
            'arguments' => PayloadEnvelopeResolver::resolveToArray($validated['input'] ?? null, 'input'),
            'payload_codec' => $envelope['codec'],
            'payload_blob' => $envelope['blob'],
            'command_context' => $this->commandContexts->make(
                $request,
                workflowId: $workflowId,
                commandName: 'bridge.signal_workflow',
                metadata: [
                    'adapter' => $adapter,
                    'action' => 'signal_workflow',
                    'idempotency_key' => $idempotencyKey,
                    'request_id' => $idempotencyKey,
                    'signal_name' => $signalName,
                ],
            ),
            'strict_configured_type_validation' => true,
        ]);

        return $this->commandOutcome($request, $adapter, 'signal_workflow', $idempotencyKey, $target, $correlation, $result);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>|null  $correlation
     */
    private function updateWorkflow(
        Request $request,
        string $adapter,
        string $idempotencyKey,
        array $target,
        array $validated,
        ?array $correlation,
    ): JsonResponse {
        $targetValidator = Validator::make($target, [
            'workflow_id' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'update_name' => ['required', 'string', 'max:255'],
            'wait_for' => ['nullable', 'string', 'in:accepted,completed'],
            'wait_timeout_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($targetValidator->fails()) {
            return $this->rejected($request, $adapter, 'update_workflow', $idempotencyKey, 'malformed_payload', [
                'message' => 'Bridge update target is malformed.',
                'errors' => $targetValidator->errors()->toArray(),
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $updateTarget = $targetValidator->validated();
        $namespace = $request->attributes->get('namespace');
        $workflowId = (string) $updateTarget['workflow_id'];
        $updateName = (string) $updateTarget['update_name'];

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return $this->rejected($request, $adapter, 'update_workflow', $idempotencyKey, 'unknown_target', [
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $duplicate = $this->duplicateBridgeCommand(
            workflowId: $workflowId,
            commandType: 'update',
            adapter: $adapter,
            action: 'update_workflow',
            idempotencyKey: $idempotencyKey,
            targetField: 'update_name',
            targetName: $updateName,
        );

        if ($duplicate instanceof WorkflowCommand) {
            return $this->duplicateCommandOutcome(
                $request,
                $adapter,
                'update_workflow',
                $idempotencyKey,
                $target,
                $correlation,
                $duplicate,
            );
        }

        $result = $this->workflowControlPlane->update($workflowId, $updateName, [
            'arguments' => PayloadEnvelopeResolver::resolveToArray($validated['input'] ?? null, 'input'),
            'command_context' => $this->commandContexts->make(
                $request,
                workflowId: $workflowId,
                commandName: 'bridge.update_workflow',
                metadata: [
                    'adapter' => $adapter,
                    'action' => 'update_workflow',
                    'idempotency_key' => $idempotencyKey,
                    'request_id' => $idempotencyKey,
                    'update_name' => $updateName,
                    'wait_for' => $updateTarget['wait_for'] ?? 'accepted',
                ],
            ),
            'wait_for' => $updateTarget['wait_for'] ?? 'accepted',
            'wait_timeout_seconds' => $updateTarget['wait_timeout_seconds'] ?? null,
            'strict_configured_type_validation' => true,
        ]);

        return $this->commandOutcome($request, $adapter, 'update_workflow', $idempotencyKey, $target, $correlation, $result);
    }

    private function duplicateBridgeCommand(
        string $workflowId,
        string $commandType,
        string $adapter,
        string $action,
        string $idempotencyKey,
        string $targetField,
        string $targetName,
    ): ?WorkflowCommand {
        /** @var WorkflowCommand|null $command */
        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', $workflowId)
            ->where('command_type', $commandType)
            ->where('status', 'accepted')
            ->where('context->server->metadata->adapter', $adapter)
            ->where('context->server->metadata->action', $action)
            ->where('context->server->metadata->idempotency_key', $idempotencyKey)
            ->where("context->server->metadata->{$targetField}", $targetName)
            ->latest('created_at')
            ->first();

        return $command;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>|null  $correlation
     */
    private function duplicateCommandOutcome(
        Request $request,
        string $adapter,
        string $action,
        string $idempotencyKey,
        array $target,
        ?array $correlation,
        WorkflowCommand $command,
    ): JsonResponse {
        return $this->outcome($request, [
            'adapter' => $adapter,
            'action' => $action,
            'accepted' => false,
            'outcome' => 'duplicate',
            'idempotency_key' => $idempotencyKey,
            'target' => $this->redactedTarget($target),
            'correlation' => $correlation,
            'workflow_id' => $target['workflow_id'] ?? $command->workflow_instance_id,
            'run_id' => $command->resolved_workflow_run_id ?? $command->workflow_run_id,
            'control_plane_outcome' => 'deduped_existing_command',
        ], 200);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>|null  $correlation
     * @param  array<string, mixed>  $result
     */
    private function commandOutcome(
        Request $request,
        string $adapter,
        string $action,
        string $idempotencyKey,
        array $target,
        ?array $correlation,
        array $result,
    ): JsonResponse {
        if (($result['reason'] ?? null) === 'instance_not_found') {
            return $this->rejected($request, $adapter, $action, $idempotencyKey, 'unknown_target', [
                'target' => $this->redactedTarget($target),
                'correlation' => $correlation,
            ]);
        }

        $status = (int) ($result['status'] ?? 202);

        return $this->outcome($request, [
            'adapter' => $adapter,
            'action' => $action,
            'accepted' => $status < 400,
            'outcome' => $status < 400 ? 'accepted' : 'rejected',
            'reason' => $status < 400 ? null : ($result['reason'] ?? 'unsupported_routing'),
            'idempotency_key' => $idempotencyKey,
            'target' => $this->redactedTarget($target),
            'correlation' => $correlation,
            'workflow_id' => $result['workflow_id'] ?? $target['workflow_id'] ?? null,
            'run_id' => $result['run_id'] ?? null,
            'control_plane_outcome' => $result['outcome'] ?? null,
        ], $status < 400 ? 202 : 422);
    }

    /**
     * @param  array<string, mixed>|null  $extra
     */
    private function rejected(
        Request $request,
        string $adapter,
        ?string $action,
        ?string $idempotencyKey,
        string $reason,
        ?array $extra = null,
    ): JsonResponse {
        return $this->outcome($request, array_filter([
            'adapter' => $adapter,
            'action' => $action,
            'accepted' => false,
            'outcome' => 'rejected',
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            ...($extra ?? []),
        ], static fn (mixed $value): bool => $value !== null), 422);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outcome(Request $request, array $payload, int $status): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, array_filter([
            'schema' => BridgeAdapterOutcomeContract::SCHEMA,
            'version' => BridgeAdapterOutcomeContract::VERSION,
            ...$payload,
        ], static fn (mixed $value): bool => $value !== null), $status);
    }

    private function workflowIdFor(string $adapter, string $idempotencyKey): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9._:-]+/', '-', $adapter) ?: 'adapter';

        return 'bridge-'.$slug.'-'.substr(hash('sha256', $idempotencyKey), 0, 32);
    }

    private function startDuplicatePolicy(?string $policy): string
    {
        return match ($policy) {
            'reject_duplicate' => 'fail',
            default => 'use-existing',
        };
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function redactedTarget(array $target): array
    {
        return array_filter([
            'workflow_id' => $target['workflow_id'] ?? null,
            'workflow_type' => $target['workflow_type'] ?? null,
            'signal_name' => $target['signal_name'] ?? null,
            'update_name' => $target['update_name'] ?? null,
            'task_queue' => $target['task_queue'] ?? null,
            'business_key' => $target['business_key'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
