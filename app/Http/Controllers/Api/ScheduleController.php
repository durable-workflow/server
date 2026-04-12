<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ScheduleController
{
    public function index(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        // TODO: List schedules from workflow_schedules

        return response()->json([
            'schedules' => [],
            'next_page_token' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'schedule_id' => ['nullable', 'string', 'max:128'],
            'spec' => ['required', 'array'],
            'spec.cron_expressions' => ['nullable', 'array'],
            'spec.cron_expressions.*' => ['string'],
            'spec.intervals' => ['nullable', 'array'],
            'spec.timezone' => ['nullable', 'string', 'max:64'],
            'action' => ['required', 'array'],
            'action.workflow_type' => ['required', 'string'],
            'action.task_queue' => ['nullable', 'string'],
            'action.input' => ['nullable', 'array'],
            'action.workflow_execution_timeout' => ['nullable', 'integer'],
            'action.workflow_run_timeout' => ['nullable', 'integer'],
            'overlap_policy' => ['nullable', 'string', 'in:skip,buffer_one,buffer_all,cancel_other,terminate_other,allow_all'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'paused' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $scheduleId = $validated['schedule_id'] ?? Str::ulid()->toBase32();

        // TODO: Create workflow_schedule record with durable timer

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'created',
        ], 201);
    }

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        // TODO: Load schedule detail

        return response()->json([
            'schedule_id' => $scheduleId,
            'spec' => null,
            'action' => null,
            'state' => null,
            'info' => null,
        ]);
    }

    public function update(Request $request, string $scheduleId): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'spec' => ['nullable', 'array'],
            'action' => ['nullable', 'array'],
            'overlap_policy' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // TODO: Update schedule

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'updated',
        ]);
    }

    public function destroy(Request $request, string $scheduleId): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        // TODO: Delete schedule

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'deleted',
        ]);
    }

    public function pause(Request $request, string $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // TODO: Pause schedule

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'paused',
        ]);
    }

    public function resume(Request $request, string $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // TODO: Resume schedule

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'resumed',
        ]);
    }

    public function trigger(Request $request, string $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'overlap_policy' => ['nullable', 'string'],
        ]);

        // TODO: Trigger immediate schedule execution

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'triggered',
        ]);
    }

    public function backfill(Request $request, string $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date'],
            'overlap_policy' => ['nullable', 'string'],
        ]);

        // TODO: Backfill missed schedule executions

        return response()->json([
            'schedule_id' => $scheduleId,
            'outcome' => 'backfill_started',
        ]);
    }
}
