<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('server:bootstrap {--force : Run bootstrap commands without a production prompt}', function (): int {
    $this->components->info('Running Durable Workflow server bootstrap...');

    $migrate = $this->call('migrate', [
        '--force' => (bool) $this->option('force'),
    ]);

    if ($migrate !== 0) {
        return $migrate;
    }

    $seed = $this->call('db:seed', [
        '--class' => 'Database\\Seeders\\DatabaseSeeder',
        '--force' => (bool) $this->option('force'),
    ]);

    if ($seed === 0) {
        $this->components->info('Durable Workflow server bootstrap completed.');
    }

    return $seed;
})->purpose('Run server migrations and seed the default namespace');

Artisan::command('schedule:evaluate {--limit=100 : Maximum schedules to fire per evaluation}', function (): int {
    $limit = (int) $this->option('limit');

    $startService = app(\App\Support\WorkflowStartService::class);
    $overlapEnforcer = app(\App\Support\ScheduleOverlapEnforcer::class);

    $fired = 0;
    $failed = 0;
    $buffered = 0;
    $drained = 0;

    // ── Phase 1: Drain buffered actions for schedules whose previous workflow finished ──

    $withBuffer = \App\Models\WorkflowSchedule::query()
        ->where('paused', false)
        ->whereNotNull('buffered_actions')
        ->get()
        ->filter(fn (\App\Models\WorkflowSchedule $s): bool => $s->hasBufferedActions());

    foreach ($withBuffer as $schedule) {
        if ($overlapEnforcer->lastFiredWorkflowIsRunning($schedule)) {
            continue;
        }

        $action = $schedule->action;
        $drainedAction = $schedule->drainBuffer();

        if ($drainedAction === null) {
            continue;
        }

        try {
            $result = $startService->start(array_filter([
                'workflow_type' => $action['workflow_type'],
                'task_queue' => $action['task_queue'] ?? null,
                'input' => $action['input'] ?? [],
                'execution_timeout_seconds' => isset($action['workflow_execution_timeout']) ? (int) $action['workflow_execution_timeout'] : null,
                'run_timeout_seconds' => isset($action['workflow_run_timeout']) ? (int) $action['workflow_run_timeout'] : null,
                'memo' => $schedule->memo,
                'search_attributes' => $schedule->search_attributes,
            ], static fn (mixed $value): bool => $value !== null), $schedule->namespace);

            $schedule->recordFire($result['workflow_id'], $result['run_id'], $result['outcome'] ?? 'drained');
            $schedule->save();

            $this->components->twoColumnDetail(
                $schedule->schedule_id,
                sprintf('<fg=cyan>drained</> → %s', $result['workflow_id']),
            );

            $drained++;
        } catch (\Throwable $e) {
            $schedule->recordFailure($e->getMessage());
            $schedule->save();

            $this->components->twoColumnDetail(
                $schedule->schedule_id,
                sprintf('<fg=red>drain failed</>: %s', $e->getMessage()),
            );

            $failed++;
        }
    }

    // ── Phase 2: Evaluate due schedules ────────────────────────────────

    $due = \App\Models\WorkflowSchedule::query()
        ->where('paused', false)
        ->whereNotNull('next_fire_at')
        ->where('next_fire_at', '<=', now())
        ->orderBy('next_fire_at')
        ->limit($limit)
        ->get();

    if ($due->isEmpty() && $drained === 0 && $failed === 0) {
        $this->components->info('No schedules due.');

        return 0;
    }

    if ($due->isNotEmpty()) {
        $this->components->info(sprintf('Evaluating %d due schedule(s)...', $due->count()));
    }

    foreach ($due as $schedule) {
        $action = $schedule->action;
        $overlapPolicy = $schedule->overlap_policy ?? 'skip';

        // Buffer policies: check if the previous workflow is still running
        if ($overlapEnforcer->isBufferPolicy($overlapPolicy)) {
            if ($overlapEnforcer->lastFiredWorkflowIsRunning($schedule)) {
                if ($schedule->isAtBufferCapacity($overlapPolicy)) {
                    $this->components->twoColumnDetail(
                        $schedule->schedule_id,
                        sprintf('<fg=yellow>skipped</>: buffer at capacity (%s)', $overlapPolicy),
                    );

                    // Advance next_fire_at so we don't re-evaluate this fire
                    $schedule->next_fire_at = $schedule->computeNextFireAt();
                    $schedule->save();
                } else {
                    $schedule->bufferAction();
                    $schedule->next_fire_at = $schedule->computeNextFireAt();
                    $schedule->save();

                    $this->components->twoColumnDetail(
                        $schedule->schedule_id,
                        sprintf('<fg=cyan>buffered</> (%s, %d in buffer)', $overlapPolicy, count($schedule->buffered_actions ?? [])),
                    );

                    $buffered++;
                }

                continue;
            }

            // Previous workflow is not running — fire normally (fall through)
        }

        try {
            $overlapEnforcer->enforce($schedule, $overlapPolicy);

            $result = $startService->start(array_filter([
                'workflow_type' => $action['workflow_type'],
                'task_queue' => $action['task_queue'] ?? null,
                'input' => $action['input'] ?? [],
                'execution_timeout_seconds' => isset($action['workflow_execution_timeout']) ? (int) $action['workflow_execution_timeout'] : null,
                'run_timeout_seconds' => isset($action['workflow_run_timeout']) ? (int) $action['workflow_run_timeout'] : null,
                'memo' => $schedule->memo,
                'search_attributes' => $schedule->search_attributes,
                'duplicate_policy' => $overlapEnforcer->duplicateStartPolicy($overlapPolicy),
            ], static fn (mixed $value): bool => $value !== null), $schedule->namespace);

            $schedule->recordFire($result['workflow_id'], $result['run_id'], $result['outcome'] ?? 'scheduled');
            $schedule->save();

            $this->components->twoColumnDetail(
                $schedule->schedule_id,
                sprintf('<fg=green>fired</> → %s', $result['workflow_id']),
            );

            $fired++;
        } catch (\Throwable $e) {
            $schedule->recordFailure($e->getMessage());
            $schedule->save();

            $this->components->twoColumnDetail(
                $schedule->schedule_id,
                sprintf('<fg=red>failed</>: %s', $e->getMessage()),
            );

            $failed++;
        }
    }

    $parts = [sprintf('%d fired', $fired)];

    if ($drained > 0) {
        $parts[] = sprintf('%d drained', $drained);
    }
    if ($buffered > 0) {
        $parts[] = sprintf('%d buffered', $buffered);
    }

    $parts[] = sprintf('%d failed', $failed);

    $this->components->info(sprintf('Done: %s.', implode(', ', $parts)));

    return $failed > 0 ? 1 : 0;
})->purpose('Evaluate due schedules and start their workflows');

Artisan::command('activity:timeout-enforce {--limit=100 : Maximum expired executions to process per pass}', function (): int {
    $limit = max(1, (int) $this->option('limit'));

    $expiredIds = \Workflow\V2\Support\ActivityTimeoutEnforcer::expiredExecutionIds($limit);

    if ($expiredIds === []) {
        $this->components->info('No expired activity executions.');

        return 0;
    }

    $this->components->info(sprintf('Enforcing %d expired activity execution(s)...', count($expiredIds)));

    $enforced = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($expiredIds as $executionId) {
        $result = \Workflow\V2\Support\ActivityTimeoutEnforcer::enforce($executionId);

        if ($result['enforced']) {
            $label = $result['next_task'] !== null ? 'retry scheduled' : 'terminal';

            $this->components->twoColumnDetail(
                $executionId,
                sprintf('<fg=green>enforced</> (%s)', $label),
            );

            $enforced++;
        } elseif ($result['reason'] !== null && str_contains($result['reason'], 'Exception')) {
            $this->components->twoColumnDetail(
                $executionId,
                sprintf('<fg=red>error</>: %s', $result['reason']),
            );

            $failed++;
        } else {
            $this->components->twoColumnDetail(
                $executionId,
                sprintf('<fg=yellow>skipped</>: %s', $result['reason'] ?? 'unknown'),
            );

            $skipped++;
        }
    }

    $this->components->info(sprintf(
        'Done: %d enforced, %d skipped, %d failed.',
        $enforced,
        $skipped,
        $failed,
    ));

    return $failed > 0 ? 1 : 0;
})->purpose('Enforce activity timeout deadlines on expired executions');
