<?php

use App\Models\WorkflowNamespace;
use App\Support\ActivityTimeoutGuard;
use App\Support\ActivityTimeoutScanner;
use App\Support\EnvAuditor;
use App\Support\HistoryRetentionEnforcer;
use App\Support\MigrationAdoption;
use App\Support\PayloadCodecDeploymentPreflight;
use App\Support\RuntimeExternalPayloadCleanup;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\ScheduleManager;

Artisan::command('server:bootstrap {--force : Run bootstrap commands without a production prompt}', function (Migrator $migrator, PayloadCodecDeploymentPreflight $payloadCodecPreflight): int {
    $this->components->info('Running Durable Workflow server bootstrap...');

    $adopted = (new MigrationAdoption($migrator))->adopt();

    foreach ($adopted as $name) {
        $this->components->twoColumnDetail(
            $name,
            '<fg=yellow>adopted</> (table already existed)',
        );
    }

    $migrate = $this->call('migrate', [
        '--force' => (bool) $this->option('force'),
    ]);

    if ($migrate !== 0) {
        return $migrate;
    }

    try {
        $report = $payloadCodecPreflight->assertReady();
        $this->components->info(sprintf(
            'Avro-only payload preflight passed (%d inline/history frame%s inspected).',
            $report['inspected_frames'],
            $report['inspected_frames'] === 1 ? '' : 's',
        ));
    } catch (\RuntimeException $exception) {
        $this->components->error($exception->getMessage());

        return 1;
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

Artisan::command('schedule:evaluate
    {--limit=100 : Maximum schedules to fire per evaluation}
    {--json : Emit a machine-readable evaluation report}', function (): int {
    $limit = (int) $this->option('limit');

    $results = ScheduleManager::tick($limit);

    $processedCount = count($results);
    $processedScheduleIds = array_values(array_filter(
        array_map(
            static fn (array $row): ?string => isset($row['schedule_id']) && is_string($row['schedule_id'])
                ? $row['schedule_id']
                : null,
            $results,
        ),
        static fn (?string $scheduleId): bool => $scheduleId !== null && $scheduleId !== '',
    ));

    $summary = [
        'processed' => $processedCount,
        'processed_count' => $processedCount,
        'processed_schedule_count' => $processedCount,
        'eligible_count' => $processedCount,
        'eligible_schedule_count' => $processedCount,
        'fired' => 0,
        'fired_count' => 0,
        'drained' => 0,
        'drained_count' => 0,
        'buffered' => 0,
        'buffered_count' => 0,
        'skipped' => 0,
        'skipped_count' => 0,
        'failed' => 0,
        'failed_count' => 0,
    ];

    foreach ($results as $row) {
        $outcome = $row['outcome'] ?? null;

        if (isset($row['error'])) {
            $summary['failed']++;
            $summary['failed_count']++;
        } elseif (($row['instance_id'] ?? null) !== null) {
            if ($outcome === 'drained') {
                $summary['drained']++;
                $summary['drained_count']++;
            } else {
                $summary['fired']++;
                $summary['fired_count']++;
            }
        } elseif ($outcome === 'buffered' || $outcome === 'buffer_full') {
            $summary['buffered']++;
            $summary['buffered_count']++;
            $summary['skipped']++;
            $summary['skipped_count']++;
        } else {
            $summary['skipped']++;
            $summary['skipped_count']++;
        }
    }

    if ((bool) $this->option('json')) {
        try {
            $this->line(json_encode([
                'schema' => 'durable-workflow.server.schedule-evaluation.report',
                'schema_version' => 1,
                'evaluated_at' => now()->toIso8601String(),
                'limit' => $limit,
                'processed' => $summary['processed'],
                'processed_count' => $summary['processed_count'],
                'processed_schedule_count' => $summary['processed_schedule_count'],
                'eligible_count' => $summary['eligible_count'],
                'eligible_schedule_count' => $summary['eligible_schedule_count'],
                'processed_schedule_ids' => $processedScheduleIds,
                'eligible_schedule_ids' => $processedScheduleIds,
                'fired' => $summary['fired'],
                'fired_count' => $summary['fired_count'],
                'drained' => $summary['drained'],
                'drained_count' => $summary['drained_count'],
                'buffered' => $summary['buffered'],
                'buffered_count' => $summary['buffered_count'],
                'skipped' => $summary['skipped'],
                'skipped_count' => $summary['skipped_count'],
                'failed' => $summary['failed'],
                'failed_count' => $summary['failed_count'],
                'summary' => $summary,
                'results' => $results,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            $this->components->error($exception->getMessage());

            return 1;
        }

        return $summary['failed'] > 0 ? 1 : 0;
    }

    if ($results === []) {
        $this->components->info('No schedules due.');

        return 0;
    }

    $fired = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($results as $row) {
        $outcome = $row['outcome'] ?? null;

        if (isset($row['error'])) {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                sprintf('<fg=red>failed</> — %s', $row['error']),
            );

            $failed++;
        } elseif ($row['instance_id'] !== null) {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                sprintf('<fg=green>fired</> → %s', $row['instance_id']),
            );

            $fired++;
        } elseif ($outcome === 'buffered' || $outcome === 'buffer_full') {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                sprintf('<fg=cyan>%s</>', $outcome),
            );

            $skipped++;
        } else {
            $this->components->twoColumnDetail(
                $row['schedule_id'],
                '<fg=yellow>skipped</>',
            );

            $skipped++;
        }
    }

    $this->components->info(sprintf('Done: %d processed, %d fired, %d skipped, %d failed.', $processedCount, $fired, $skipped, $failed));

    return $failed > 0 ? 1 : 0;
})->purpose('Evaluate due schedules and start their workflows');

Artisan::command('activity:timeout-enforce {--limit=100 : Maximum expired executions to process per pass}', function (): int {
    $limit = max(1, (int) $this->option('limit'));

    $expiredIds = ActivityTimeoutScanner::expiredExecutionIds($limit);

    if ($expiredIds === []) {
        $this->components->info('No expired activity executions.');

        return 0;
    }

    $this->components->info(sprintf('Enforcing %d expired activity execution(s)...', count($expiredIds)));

    $enforced = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($expiredIds as $executionId) {
        $result = ActivityTimeoutGuard::enforce($executionId);

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

Artisan::command('external-payloads:cleanup
    {--limit=100 : Maximum expired external payload references to process per pass}
    {--namespace= : Clean only this namespace}
    {--json : Emit a machine-readable cleanup report}', function (RuntimeExternalPayloadCleanup $cleanup): int {
    $namespace = $this->option('namespace');
    $report = $cleanup->runPass(
        is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null,
        (int) $this->option('limit'),
    );

    if ((bool) $this->option('json')) {
        $this->line(json_encode([
            'schema' => 'durable-workflow.v2.runtime-external-payload-cleanup-report.v1',
            'completed_at' => now()->toJSON(),
        ] + $report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        $this->components->info(sprintf(
            'Done: %d processed, %d references deleted, %d backing objects deleted, %d blocked, %d storage failures, %d remaining.',
            $report['processed'],
            $report['deleted_references'],
            $report['deleted_backing_objects'],
            $report['blocked'],
            $report['storage_driver_failures'],
            $report['backlog'],
        ));
    }

    return $report['blocked'] > 0 ? 1 : 0;
})->purpose('Reclaim expired and incomplete runtime external payload uploads');

Artisan::command('history:prune {--limit=100 : Maximum expired runs to prune per pass} {--namespace= : Prune only this namespace}', function (): int {
    $limit = max(1, (int) $this->option('limit'));
    $namespaceFilter = $this->option('namespace');

    $namespaces = $namespaceFilter
        ? WorkflowNamespace::query()->where('name', $namespaceFilter)->get()
        : WorkflowNamespace::all();

    if ($namespaces->isEmpty()) {
        $this->components->info('No namespaces found.');

        return 0;
    }

    $totalPruned = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    foreach ($namespaces as $ns) {
        if ($ns->retainsHistoryForever()) {
            $this->components->info(sprintf(
                'Namespace [%s]: automatic history pruning disabled by forever retention.',
                $ns->name,
            ));

            continue;
        }

        $retentionDays = HistoryRetentionEnforcer::retentionDays($ns->name);
        $expiredRunIds = HistoryRetentionEnforcer::expiredRunIds($ns->name, $limit);

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
                $summary = WorkflowRunSummary::query()
                    ->where('id', $runId)
                    ->where('namespace', $ns->name)
                    ->first();

                if (! $summary) {
                    $totalSkipped++;

                    continue;
                }

                $status = is_string($summary->status)
                    ? RunStatus::tryFrom($summary->status)
                    : null;

                if ($status === null || ! $status->isTerminal()) {
                    $totalSkipped++;

                    continue;
                }

                if ($summary->archived_at !== null) {
                    $totalSkipped++;

                    continue;
                }

                $result = HistoryRetentionEnforcer::pruneRun($ns->name, $runId);

                if (! $result['pruned']) {
                    $totalSkipped++;

                    continue;
                }

                $report = $result['deleted'];

                $this->components->twoColumnDetail(
                    $runId,
                    sprintf(
                        '<fg=green>pruned</> (%d events, %d tasks, %d detail rows)',
                        $report['history_events_deleted'] ?? 0,
                        $report['tasks_deleted'] ?? 0,
                        array_sum($report),
                    ),
                );

                $totalPruned++;
            } catch (Throwable $e) {
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

Artisan::command('env:audit {--strict : Exit non-zero when unknown or legacy DW_* vars are set}', function (): int {
    $contract = config('dw-contract');

    if (! is_array($contract)) {
        $this->components->error('config/dw-contract.php is missing or invalid.');

        return 1;
    }

    $report = EnvAuditor::audit(EnvAuditor::currentEnvironment(), $contract);

    $prefix = $report['prefix'];
    $issues = 0;

    if ($report['unknown'] !== []) {
        $this->components->warn(sprintf(
            'Unknown %s variables set in environment (typo or silent-drop rename?): %s',
            $prefix,
            implode(', ', $report['unknown']),
        ));
        $issues += count($report['unknown']);
    }

    foreach ($report['legacy'] as $hit) {
        $this->components->warn(sprintf(
            'Legacy env var %s is still honored but deprecated — rename to %s.',
            $hit['legacy'],
            $hit['replacement'],
        ));
        $issues++;
    }

    if ($issues === 0) {
        $this->components->info(sprintf(
            '%s environment contract OK (%d %s vars recognized).',
            rtrim($prefix, '_'),
            count($report['known']),
            $prefix,
        ));
    }

    if ((bool) $this->option('strict') && $issues > 0) {
        return 1;
    }

    return 0;
})->purpose('Audit the process environment against the DW_* contract in config/dw-contract.php');
