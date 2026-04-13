<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ControlPlaneResultMapper
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function signal(string $workflowId, string $signalName, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'signal',
            operationName: $signalName,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 202,
            fallbackFields: [
                'signal_name' => $signalName,
            ],
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function query(string $workflowId, string $queryName, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'query',
            operationName: $queryName,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: [
                'query_name' => $queryName,
            ],
            projectCommandReason: false,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function update(
        string $workflowId,
        string $updateName,
        ?string $waitFor,
        array $result,
    ): JsonResponse {
        $fallbackFields = [
            'update_name' => $updateName,
        ];

        if (is_string($waitFor) && $waitFor !== '') {
            $fallbackFields['wait_for'] = $waitFor;
        }

        return $this->commandResponse(
            operation: 'update',
            operationName: $updateName,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: $fallbackFields,
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function cancel(string $workflowId, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'cancel',
            operationName: null,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: [],
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function terminate(string $workflowId, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'terminate',
            operationName: null,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: [],
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function repair(string $workflowId, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'repair',
            operationName: null,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: [],
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function archive(string $workflowId, array $result): JsonResponse
    {
        return $this->commandResponse(
            operation: 'archive',
            operationName: null,
            workflowId: $workflowId,
            result: $result,
            defaultStatus: 200,
            fallbackFields: [],
            projectCommandReason: true,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $fallbackFields
     */
    private function commandResponse(
        string $operation,
        ?string $operationName,
        string $workflowId,
        array $result,
        int $defaultStatus,
        array $fallbackFields,
        bool $projectCommandReason,
    ): JsonResponse {
        if ($this->instanceNotFound($result)) {
            return ControlPlaneProtocol::json(
                ControlPlaneResponseContract::attach(
                    operation: $operation,
                    operationName: $operationName,
                    payload: [
                        'message' => 'Workflow not found.',
                        'workflow_id' => $workflowId,
                        'reason' => 'instance_not_found',
                    ],
                ),
                404,
            );
        }

        $payload = $this->canonicalPayload(
            workflowId: $workflowId,
            result: $result,
            fallbackFields: $fallbackFields,
            projectCommandReason: $projectCommandReason,
        );

        return ControlPlaneProtocol::json(
            ControlPlaneResponseContract::attach(
                operation: $operation,
                operationName: $operationName,
                payload: $payload,
            ),
            (int) ($result['status'] ?? $defaultStatus),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $fallbackFields
     * @return array<string, mixed>
     */
    private function canonicalPayload(
        string $workflowId,
        array $result,
        array $fallbackFields,
        bool $projectCommandReason,
    ): array {
        $payload = $result;

        if (
            $projectCommandReason
            && ($payload['reason'] ?? null) === null
            && isset($payload['command_reason'])
        ) {
            $payload['reason'] = $payload['command_reason'];
        }

        unset(
            $payload['status'],
            $payload['accepted'],
            $payload['success'],
            $payload['workflow_instance_id'],
            $payload['workflow_command_id'],
            $payload['command_reason'],
        );

        $payload['workflow_id'] = $this->stringValue($payload['workflow_id'] ?? null) ?? $workflowId;

        foreach ($fallbackFields as $field => $value) {
            if ($this->stringValue($payload[$field] ?? null) === null) {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function instanceNotFound(array $result): bool
    {
        return ($result['status'] ?? null) === 404
            && ($result['reason'] ?? null) === 'instance_not_found';
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? $value
            : null;
    }
}
