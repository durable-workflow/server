<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowObservationController
{
    public function show(Request $request, string $workflowId, ?string $runId = null): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'search_attribute_keys' => ['nullable', 'array', 'max:20'],
            'search_attribute_keys.*' => ['required', 'string', 'min:1', 'max:255', 'distinct'],
        ]);
        $namespace = (string) $request->attributes->get('namespace');
        $instanceModel = ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class);
        $instance = $instanceModel::query()->setEagerLoads([])
            ->whereKey($workflowId)->where('namespace', $namespace)->first(['id', 'current_run_id']);
        $selectedRunId = $runId ?? $instance?->current_run_id;
        $runModel = ConfiguredV2Models::resolve('run_model', WorkflowRun::class);
        $run = $instance === null || $selectedRunId === null ? null
            : $runModel::query()->setEagerLoads([])->whereKey($selectedRunId)
                ->where('workflow_instance_id', $workflowId)->where('namespace', $namespace)
                ->first([
                    'id', 'workflow_instance_id', 'namespace', 'workflow_class', 'workflow_type',
                    'run_number', 'status', 'closed_reason', 'business_key', 'visibility_labels',
                    'compatibility', 'connection', 'queue', 'started_at', 'closed_at', 'archived_at',
                    'details_pruned_at', 'created_at', 'updated_at', 'execution_deadline_at', 'run_deadline_at',
                ]);
        if (! $run instanceof WorkflowRun) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'workflow_id' => $workflowId, 'run_id' => $runId,
                'message' => 'Workflow run observation not found.',
                'reason' => $runId === null ? 'current_run_observation_unavailable' : 'run_not_found',
            ], 404);
        }

        $observer = app(OperatorObservabilityRepository::class);
        if (! method_exists($observer, 'runObservation')) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'workflow_id' => $workflowId, 'run_id' => $run->id,
                'message' => 'The configured observer does not support bounded run observations.',
                'reason' => 'bounded_run_observation_unsupported',
            ], 501);
        }

        $observation = $observer->runObservation($run);
        $observation['workflow_id'] = $workflowId;
        $observation['task_queue'] = $observation['queue'];
        $observation['read_mode'] = 'bounded';
        $observation['search_attributes'] = $this->searchAttributes($run, $validated['search_attribute_keys'] ?? []);

        return ControlPlaneProtocol::jsonForRequest($request, $observation);
    }

    private function searchAttributes(WorkflowRun $run, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $model = ConfiguredV2Models::resolve('search_attribute_model', WorkflowSearchAttribute::class);

        return (new $model)->setConnection($run->getConnectionName())->newQuery()->setEagerLoads([])
            ->where('workflow_run_id', $run->id)->where('workflow_instance_id', $run->workflow_instance_id)
            ->whereIn('key', $keys)->orderBy('key')->limit(20)->get()
            ->mapWithKeys(static fn (WorkflowSearchAttribute $attribute): array => [$attribute->key => $attribute->getValue()])
            ->all();
    }
}
