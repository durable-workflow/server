<?php

namespace App\Support;

use App\Contracts\AuthProvider;
use App\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer as Authorizer;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ServiceCallPrincipal;

/** The request's authenticated principal authorizes intent from a leased run. */
final class WorkflowServiceOperationAuthorizer implements Authorizer
{
    public function __construct(
        private readonly Request $request,
        private readonly AuthProvider $auth,
        private readonly RouteAuthorizationResource $resources,
    ) {}

    public function authorize(WorkflowRun $run, array $command): ServiceCallPrincipal
    {
        $principal = Authenticate::principal($this->request);
        if ($principal === null || ! is_string($run->namespace) || $run->namespace === '') {
            throw new ServiceOperationAuthorizationException;
        }
        $target = $command['namespace'] ?? $run->namespace;
        $resource = array_replace($this->resources->make($this->request, ['worker']), [
            'operation_family' => 'service',
            'operation_name' => 'execute_operation',
            'target_namespace' => $target,
            'namespace_name' => $target,
            'caller_namespace' => $run->namespace,
            'caller_workflow_instance_id' => $run->workflow_instance_id,
            'caller_workflow_run_id' => $run->id,
            'service_endpoint_name' => strtolower(trim($command['endpoint_name'])),
            'service_name' => strtolower(trim($command['service_name'])),
            'service_operation_name' => strtolower(trim($command['operation_name'])),
        ]);
        if (! $this->auth->authorize($principal, 'execute_operation', $resource)) {
            throw new ServiceOperationAuthorizationException;
        }

        return new ServiceCallPrincipal(
            subject: $principal->subject(),
            method: $principal->method(),
            roles: $principal->roles(),
            tenant: $principal->tenant(),
            claims: $principal->claims(),
        );
    }
}
