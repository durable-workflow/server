<?php

namespace App\Support;

use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Support\PayloadEnvelopeResolver;

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
    public function start(
        array $validated,
        ?string $namespace = null,
        ?CommandContext $commandContext = null,
    ): array {
        $workflowType = (string) $validated['workflow_type'];
        $workflowId = isset($validated['workflow_id']) && is_string($validated['workflow_id'])
            ? $validated['workflow_id']
            : null;
        $this->workflowTypes->assertLoadable($workflowType);

        return $this->startRemoteWorkflow($workflowType, $workflowId, $validated, $namespace, $commandContext);
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
    private function startRemoteWorkflow(
        string $workflowType,
        ?string $workflowId,
        array $validated,
        ?string $namespace = null,
        ?CommandContext $commandContext = null,
    ): array {
        $envelope = PayloadEnvelopeResolver::resolve($validated['input'] ?? null);

        // Back-compat: when the client sends no input (or an empty array),
        // fall back to the configured default codec and emit a serialized
        // empty-arg list so the run's `arguments` column stays non-null
        // (matching pre-#164 behavior that legacy tests assert against).
        $arguments = $envelope['blob'] ?? Serializer::serialize([]);
        $payloadCodec = $envelope['codec'];

        $result = $this->controlPlane->start($workflowType, $workflowId, array_filter([
            'arguments' => $arguments,
            'payload_codec' => $payloadCodec,
            'queue' => isset($validated['task_queue']) && is_string($validated['task_queue'])
                ? $validated['task_queue']
                : null,
            'business_key' => isset($validated['business_key']) && is_string($validated['business_key'])
                ? $validated['business_key']
                : null,
            'search_attributes' => $this->arrayValue($validated, 'search_attributes'),
            'memo' => $this->arrayValue($validated, 'memo'),
            'duplicate_start_policy' => $this->controlPlaneDuplicatePolicy($validated['duplicate_policy'] ?? null),
            'execution_timeout_seconds' => $this->intValue($validated, 'execution_timeout_seconds'),
            'run_timeout_seconds' => $this->intValue($validated, 'run_timeout_seconds'),
            'namespace' => $namespace,
            'command_context' => $commandContext,
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function intValue(array $validated, string $key): ?int
    {
        $value = $validated[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
