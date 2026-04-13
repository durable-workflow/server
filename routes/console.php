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

    $due = \App\Models\WorkflowSchedule::query()
        ->where('paused', false)
        ->whereNotNull('next_fire_at')
        ->where('next_fire_at', '<=', now())
        ->orderBy('next_fire_at')
        ->limit($limit)
        ->get();

    if ($due->isEmpty()) {
        $this->components->info('No schedules due.');

        return 0;
    }

    $this->components->info(sprintf('Evaluating %d due schedule(s)...', $due->count()));

    $startService = app(\App\Support\WorkflowStartService::class);

    $fired = 0;
    $failed = 0;

    foreach ($due as $schedule) {
        $action = $schedule->action;

        try {
            $result = $startService->start([
                'workflow_type' => $action['workflow_type'],
                'task_queue' => $action['task_queue'] ?? null,
                'input' => $action['input'] ?? [],
                'memo' => $schedule->memo,
                'search_attributes' => $schedule->search_attributes,
                'duplicate_policy' => $schedule->overlap_policy === 'skip' ? 'use-existing' : null,
            ]);

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

    $this->components->info(sprintf('Done: %d fired, %d failed.', $fired, $failed));

    return $failed > 0 ? 1 : 0;
})->purpose('Evaluate due schedules and start their workflows');
