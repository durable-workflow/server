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

    $results = \Workflow\V2\Support\ScheduleManager::tick($limit);

    if ($results === []) {
        $this->components->info('No schedules due.');

        return 0;
    }

    $fired = 0;
    $skipped = 0;

    foreach ($results as $row) {
        if ($row['instance_id'] !== null) {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                sprintf('<fg=green>fired</> → %s', $row['instance_id']),
            );

            $fired++;
        } else {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                '<fg=yellow>skipped</>',
            );

            $skipped++;
        }
    }

    $this->components->info(sprintf('Done: %d fired, %d skipped.', $fired, $skipped));

    return 0;
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

Artisan::command('history:prune {--limit=100 : Maximum expired runs to prune per pass} {--namespace= : Prune only this namespace}', function (): int {
    $limit = max(1, (int) $this->option('limit'));
    $namespaceFilter = $this->option('namespace');

    $namespaces = $namespaceFilter
        ? \App\Models\WorkflowNamespace::query()->where('name', $namespaceFilter)->get()
        : \App\Models\WorkflowNamespace::all();

    if ($namespaces->isEmpty()) {
        $this->components->info('No namespaces found.');

        return 0;
    }

    $totalPruned = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    foreach ($namespaces as $ns) {
        $retentionDays = $ns->retention_days ?? (int) config('server.history.retention_days', 30);
        $cutoff = now()->subDays($retentionDays);

        $expiredRunIds = \App\Support\NamespaceWorkflowScope::runSummaryQuery($ns->name)
            ->whereIn('workflow_run_summaries.status_bucket', ['completed', 'failed'])
            ->whereNotNull('workflow_run_summaries.closed_at')
            ->where('workflow_run_summaries.closed_at', '<', $cutoff)
            ->orderBy('workflow_run_summaries.closed_at')
            ->limit($limit)
            ->pluck('workflow_run_summaries.id')
            ->all();

        if ($expiredRunIds === []) {
            continue;
        }

        $this->components->info(sprintf(
            'Namespace [%s]: %d expired run(s) past %d-day retention...',
            $ns->name,
            count($expiredRunIds),
            $retentionDays,
        ));

        foreach ($expiredRunIds as $runId) {
            try {
                $summary = \Workflow\V2\Models\WorkflowRunSummary::query()
                    ->where('id', $runId)
                    ->where('namespace', $ns->name)
                    ->first();

                if (! $summary) {
                    $totalSkipped++;

                    continue;
                }

                $status = is_string($summary->status)
                    ? \Workflow\V2\Enums\RunStatus::tryFrom($summary->status)
                    : null;

                if ($status === null || ! $status->isTerminal()) {
                    $totalSkipped++;

                    continue;
                }

                $historyDeleted = \Workflow\V2\Models\WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $runId)
                    ->delete();

                $tasksDeleted = \Workflow\V2\Models\WorkflowTask::query()
                    ->where('workflow_run_id', $runId)
                    ->delete();

                \Workflow\V2\Models\WorkflowRunSummary::query()
                    ->where('id', $runId)
                    ->delete();

                $this->components->twoColumnDetail(
                    $runId,
                    sprintf('<fg=green>pruned</> (%d events, %d tasks)', $historyDeleted, $tasksDeleted),
                );

                $totalPruned++;
            } catch (\Throwable $e) {
                $this->components->twoColumnDetail(
                    $runId,
                    sprintf('<fg=red>error</>: %s', $e->getMessage()),
                );

                $totalFailed++;
            }
        }
    }

    if ($totalPruned === 0 && $totalSkipped === 0 && $totalFailed === 0) {
        $this->components->info('No expired runs to prune.');

        return 0;
    }

    $this->components->info(sprintf(
        'Done: %d pruned, %d skipped, %d failed.',
        $totalPruned,
        $totalSkipped,
        $totalFailed,
    ));

    return $totalFailed > 0 ? 1 : 0;
})->purpose('Prune history and task data for closed runs past the retention window');
