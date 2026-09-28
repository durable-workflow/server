<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$runId = getenv('QUALIFICATION_RUN_ID');

if (! is_string($runId) || $runId === '') {
    throw new RuntimeException('QUALIFICATION_RUN_ID is required.');
}

$run = WorkflowRun::query()->findOrFail($runId);
$summary = WorkflowRunSummary::query()->find($runId);
$events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId);
$signals = WorkflowSignal::query()->where('workflow_run_id', $runId);
$tasks = WorkflowTask::query()->where('workflow_run_id', $runId);
$commands = WorkflowCommand::query()->where('resolved_workflow_run_id', $runId);

$snapshot = [
    'run_id' => $runId,
    'status' => $run->status->value,
    'last_history_sequence' => $run->last_history_sequence,
    'message_cursor_position' => $run->message_cursor_position,
    'history_event_rows' => (clone $events)->count(),
    'history_event_types' => (clone $events)
        ->selectRaw('event_type, COUNT(*) AS n')
        ->groupBy('event_type')
        ->pluck('n', 'event_type')
        ->all(),
    'timeline_rows' => WorkflowTimelineEntry::query()->where('workflow_run_id', $runId)->count(),
    'signals_received' => (clone $signals)->where('status', 'received')->count(),
    'signals_applied' => (clone $signals)->where('status', 'applied')->count(),
    'signals_rejected' => (clone $signals)->where('status', 'rejected')->count(),
    'open_tasks' => (clone $tasks)->whereIn('status', ['ready', 'leased'])->count(),
    'commands_accepted' => (clone $commands)->where('status', 'accepted')->count(),
    'commands_rejected' => (clone $commands)->where('status', 'rejected')->count(),
    'summary_event_count' => $summary?->history_event_count,
    'summary_size_bytes' => $summary?->history_size_bytes,
    'summary_pressure' => $summary?->history_budget_pressure,
    'summary_continue_as_new' => $summary?->continue_as_new_recommended,
];

echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
