<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NamespaceLifecycleCleanup
{
    /**
     * @return array<string, int>
     */
    public function cleanup(string $namespace): array
    {
        $namespace = strtolower($namespace);

        return DB::transaction(function () use ($namespace): array {
            $runIds = $this->values('workflow_runs', 'id', 'namespace', $namespace);
            $instanceIds = array_values(array_unique(array_merge(
                $this->values('workflow_instances', 'id', 'namespace', $namespace),
                $this->values('workflow_runs', 'workflow_instance_id', 'namespace', $namespace),
            )));
            $taskIds = array_values(array_unique(array_merge(
                $this->values('workflow_tasks', 'id', 'namespace', $namespace),
                $this->valuesWhereIn('workflow_tasks', 'id', 'workflow_run_id', $runIds),
            )));

            $externalPayloads = app(ExternalPayloadRetentionCleanup::class)
                ->deleteForNamespaceCleanup($namespace, $runIds, $instanceIds);

            if ($externalPayloads['blocked']) {
                throw new ExternalPayloadStorageUnavailable(
                    'External payload storage driver is unavailable for namespace cleanup.',
                );
            }

            $deleted = [];
            $deleted['external_payloads_deleted'] = $externalPayloads['deleted'];

            $deleted['activity_attempts'] = $this->deleteByAnyIn('activity_attempts', [
                'workflow_run_id' => $runIds,
                'workflow_task_id' => $taskIds,
            ]);
            $deleted['workflow_updates'] = $this->deleteByAnyIn('workflow_updates', [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]);
            $deleted['workflow_signal_records'] = $this->deleteByAnyIn('workflow_signal_records', [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]);
            $deleted['workflow_messages'] = $this->deleteByAnyIn('workflow_messages', [
                'workflow_instance_id' => $instanceIds,
                'workflow_run_id' => $runIds,
            ]);
            $deleted['workflow_child_calls'] = $this->deleteByAnyIn('workflow_child_calls', [
                'parent_workflow_instance_id' => $instanceIds,
                'parent_workflow_run_id' => $runIds,
            ]);
            $deleted['workflow_links'] = $this->deleteByAnyIn('workflow_links', [
                'parent_workflow_instance_id' => $instanceIds,
                'parent_workflow_run_id' => $runIds,
            ]);
            $deleted['workflow_service_calls'] = $this->deleteByAnyIn('workflow_service_calls', [
                'namespace' => [$namespace],
                'target_namespace' => [$namespace],
            ]);

            foreach ([
                'activity_executions',
                'workflow_commands',
                'workflow_failures',
                'workflow_history_events',
                'workflow_memos',
                'workflow_run_lineage_entries',
                'workflow_run_timer_entries',
                'workflow_run_timeline_entries',
                'workflow_run_waits',
                'workflow_run_timers',
                'workflow_search_attributes',
                'workflow_tasks',
            ] as $table) {
                $deleted[$table] = $this->deleteByRunOrInstance($table, $runIds, $instanceIds);
            }

            $deleted['workflow_schedule_history_events'] = $this->deleteByNamespace('workflow_schedule_history_events', $namespace);
            $deleted['workflow_schedules'] = $this->deleteByNamespace('workflow_schedules', $namespace);
            $deleted['workflow_service_operations'] = $this->deleteByNamespace('workflow_service_operations', $namespace);
            $deleted['workflow_services'] = $this->deleteByNamespace('workflow_services', $namespace);
            $deleted['workflow_service_endpoints'] = $this->deleteByNamespace('workflow_service_endpoints', $namespace);
            $deleted['workflow_worker_compatibility_heartbeats'] = $this->deleteByNamespace('workflow_worker_compatibility_heartbeats', $namespace);
            $deleted['workflow_worker_sessions'] = $this->deleteByNamespace('workflow_worker_sessions', $namespace);
            $deleted['workflow_worker_build_id_rollouts'] = $this->deleteByNamespace('workflow_worker_build_id_rollouts', $namespace);
            $deleted['workflow_worker_registrations'] = $this->deleteByNamespace('workflow_worker_registrations', $namespace);
            $deleted['search_attribute_definitions'] = $this->deleteByNamespace('search_attribute_definitions', $namespace);
            $deleted['workflow_durable_stream_items'] = $this->deleteByNamespace('workflow_durable_stream_items', $namespace);
            $deleted['workflow_durable_streams'] = $this->deleteByNamespace('workflow_durable_streams', $namespace);
            $deleted['workflow_run_summaries'] = $this->deleteByRunOrInstance('workflow_run_summaries', $runIds, $instanceIds);
            $deleted['workflow_runs'] = $this->deleteByNamespace('workflow_runs', $namespace);
            $deleted['workflow_instances'] = $this->deleteByNamespace('workflow_instances', $namespace);

            return array_filter($deleted, static fn (int $count): bool => $count > 0);
        });
    }

    /**
     * @return list<string>
     */
    private function values(string $table, string $select, string $whereColumn, string $whereValue): array
    {
        if (! $this->hasColumns($table, [$select, $whereColumn])) {
            return [];
        }

        return DB::table($table)
            ->where($whereColumn, $whereValue)
            ->pluck($select)
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    /**
     * @param list<string> $whereValues
     * @return list<string>
     */
    private function valuesWhereIn(string $table, string $select, string $whereColumn, array $whereValues): array
    {
        if ($whereValues === [] || ! $this->hasColumns($table, [$select, $whereColumn])) {
            return [];
        }

        return DB::table($table)
            ->whereIn($whereColumn, $whereValues)
            ->pluck($select)
            ->map(static fn ($value): string => (string) $value)
            ->all();
    }

    private function deleteByNamespace(string $table, string $namespace): int
    {
        if (! $this->hasColumns($table, ['namespace'])) {
            return 0;
        }

        return DB::table($table)
            ->where('namespace', $namespace)
            ->delete();
    }

    /**
     * @param list<string> $runIds
     * @param list<string> $instanceIds
     */
    private function deleteByRunOrInstance(string $table, array $runIds, array $instanceIds): int
    {
        return $this->deleteByAnyIn($table, [
            'workflow_run_id' => $runIds,
            'workflow_instance_id' => $instanceIds,
        ]);
    }

    /**
     * @param array<string, list<string>> $columns
     */
    private function deleteByAnyIn(string $table, array $columns): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $columns = array_filter(
            $columns,
            fn (array $values, string $column): bool => $values !== [] && Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($columns === []) {
            return 0;
        }

        return DB::table($table)
            ->where(function ($query) use ($columns): void {
                foreach ($columns as $column => $values) {
                    $query->orWhereIn($column, $values);
                }
            })
            ->delete();
    }

    /**
     * @param list<string> $columns
     */
    private function hasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
