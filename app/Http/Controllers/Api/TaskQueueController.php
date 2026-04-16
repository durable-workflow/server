<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\TaskQueuePollers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\OperatorQueueVisibility;

class TaskQueueController
{
    public function __construct(
        private readonly TaskQueuePollers $pollers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            OperatorQueueVisibility::forNamespace(
                $namespace,
                $this->pollers->forNamespace($namespace),
                now(),
                $this->pollers->staleAfterSeconds(),
            )->toArray(),
        );
    }

    public function show(Request $request, string $taskQueue): JsonResponse
    {
        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            OperatorQueueVisibility::forQueue(
                $namespace,
                $taskQueue,
                $this->pollers->forQueue($namespace, $taskQueue),
                now(),
                $this->pollers->staleAfterSeconds(),
            )->toArray(),
        );
    }
}
