<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\TaskQueueVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskQueueController
{
    public function __construct(
        private readonly TaskQueueVisibility $visibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $taskQueues = WorkerRegistration::where('namespace', $namespace)
            ->select('task_queue')
            ->distinct()
            ->pluck('task_queue');

        return ControlPlaneProtocol::json([
            'task_queues' => $taskQueues->map(fn (string $name) => [
                'name' => $name,
            ]),
        ]);
    }

    public function show(Request $request, string $taskQueue): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            $this->visibility->describe($namespace, $taskQueue),
        );
    }
}
