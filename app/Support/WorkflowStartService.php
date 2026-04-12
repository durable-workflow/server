<?php

namespace App\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;

final class WorkflowStartService
{
    public function __construct(
        private readonly WorkflowControlPlane $controlPlane,
        private readonly ConfiguredWorkflowTypeValidator $workflowTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     workflow_id: string,
     *     run_id: string|null,
     *     workflow_type: string,
     *     outcome: string|null,
     *     reason: string|null,
     * }
     */
    public function start(array $validated): array
    {
        $workflowType = (string) $validated['workflow_type'];
        $workflowId = isset($validated['workflow_id']) && is_string($validated['workflow_id'])
            ? $validated['workflow_id']
            : null;
        $this->workflowTypes->assertLoadable($workflowType);

        return $this->startRemoteWorkflow($workflowType, $workflowId, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     workflow_id: string,
     *     run_id: string|null,
     *     workflow_type: string,
     *     outcome: string|null,
     *     reason: string|null,
     * }
     */
    private function startRemoteWorkflow(string $workflowType, ?string $workflowId, array $validated): array
    {
        $result = $this->controlPlane->start($workflowType, $workflowId, array_filter([
            'arguments' => Serializer::serialize(array_values($this->arrayValue($validated, 'input'))),
            'queue' => isset($validated['task_queue']) && is_string($validated['task_queue'])
                ? $validated['task_queue']
                : null,
            'business_key' => isset($validated['business_key']) && is_string($validated['business_key'])
                ? $validated['business_key']
                : null,
            'labels' => $this->arrayValue($validated, 'search_attributes'),
            'memo' => $this->arrayValue($validated, 'memo'),
            'duplicate_start_policy' => $this->controlPlaneDuplicatePolicy($validated['duplicate_policy'] ?? null),
        ], static fn (mixed $value): bool => $value !== null));

        return [
            'workflow_id' => $result['workflow_instance_id'],
            'run_id' => $result['workflow_run_id'],
            'workflow_type' => $result['workflow_type'],
            'outcome' => $result['outcome'],
            'reason' => $result['reason'],
        ];
    }

    private function controlPlaneDuplicatePolicy(?string $policy): string
    {
        return match ($policy) {
            'use-existing' => 'return_existing_active',
            default => 'reject_duplicate',
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int|string, mixed>
     */
    private function arrayValue(array $validated, string $key): array
    {
        $value = $validated[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
