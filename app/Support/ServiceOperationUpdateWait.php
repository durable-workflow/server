<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * The update wait ceiling on service operations bound to a workflow update
 * (Server #775).
 *
 * A service operation's own `wait_timeout_seconds` contract (up to 86400 on
 * the execute route) is unchanged. When the operation's binding is a workflow
 * update, though, the service control plane hands that wait to the update
 * admission, so it is refused above the update ceiling before the operation
 * runs, as the update route refuses it.
 */
final class ServiceOperationUpdateWait
{
    public static function bindsWorkflowUpdate(?WorkflowServiceOperation $operation): bool
    {
        return $operation !== null
            && in_array(strtolower(trim((string) $operation->handler_binding_kind)), ['workflow_update', 'update_workflow'], true);
    }

    /**
     * Refuses a wait above the ceiling for an update-bound operation, with the
     * validation message the update route gives. `$path` is the dotted
     * attribute the caller sent the field as.
     */
    public static function assertWithinCeiling(?WorkflowServiceOperation $operation, mixed $seconds, string $path = 'wait_timeout_seconds'): void
    {
        if ($seconds === null || ! self::bindsWorkflowUpdate($operation)) {
            return;
        }

        $data = [];
        data_set($data, $path, $seconds);

        // A nested command field is named as workflow-task completion
        // validation names its wildcard fields, underscores kept.
        Validator::make($data, [
            $path => ['integer', 'max:'.ControlPlaneRequestContract::UPDATE_WAIT_TIMEOUT_SECONDS_MAX],
        ], [], str_contains($path, '.') ? [$path => $path] : [])->validate();
    }

    /**
     * The catalog operation a workflow-task `start_service_operation`
     * command names, in its target namespace.
     */
    public static function operation(string $namespace, string $endpointName, string $serviceName, string $operationName): ?WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()
            ->where('namespace', $namespace)
            ->where('endpoint_name', strtolower(trim($endpointName)))
            ->first();

        if ($endpoint === null) {
            return null;
        }

        $service = WorkflowService::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_endpoint_id', $endpoint->id)
            ->where('service_name', strtolower(trim($serviceName)))
            ->first();

        if ($service === null) {
            return null;
        }

        return WorkflowServiceOperation::query()
            ->where('namespace', $namespace)
            ->where('workflow_service_id', $service->id)
            ->where('operation_name', strtolower(trim($operationName)))
            ->first();
    }
}
