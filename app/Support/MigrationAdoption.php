<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;

/**
 * Adopts create-table migrations whose target tables already exist but are not
 * yet recorded in the `migrations` table.
 *
 * BLK-S002 surfaced a real operator scenario: a fresh MySQL deploy of the
 * server image wedged because an earlier partial bootstrap had already created
 * the `workflow_schedule_history_events` table but not recorded the migration.
 * Each retry then hit "table already exists".
 *
 * The workflow v2 migration slate is pinned as final-form create-table only
 * (Workflow\V2 `MigrationsTest::testV2MigrationSlateDoesNotUseSchemaDetectionGuards`),
 * so the recovery guard lives here in the server instead of in the package
 * migrations. This scans every registered migration, and for any pending
 * migration whose `Schema::create()` targets all already exist on the
 * connection, records it as applied in the current batch so `migrate` becomes
 * a no-op for those files.
 */
class MigrationAdoption
{
    public function __construct(
        private readonly Migrator $migrator,
    ) {
    }

    /**
     * @return list<string> names of migrations that were adopted
     */
    public function adopt(): array
    {
        $repository = $this->migrator->getRepository();

        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $paths = array_merge(
            [database_path('migrations')],
            $this->migrator->paths(),
        );

        $files = $this->migrator->getMigrationFiles($paths);

        if ($files === []) {
            return [];
        }

        $ran = $repository->getRan();
        $batch = $repository->getNextBatchNumber();
        $adopted = [];

        foreach ($files as $name => $path) {
            if (in_array($name, $ran, true)) {
                continue;
            }

            $tables = $this->createdTablesIn($path);

            if ($tables === []) {
                continue;
            }

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue 2;
                }
            }

            $repository->log($name, $batch);
            $adopted[] = $name;
        }

        return $adopted;
    }

    /**
     * Extract table names from `Schema::create(...)` calls in a migration file.
     * Handles both string literals and `self::CONST` references where the
     * constant is declared in the same file as a string. Returns an empty list
     * for migrations that do not create tables (ALTER-style, no-op tombstones,
     * dynamic names), which are left to the normal migrator.
     *
     * @return list<string>
     */
    private function createdTablesIn(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        $constants = [];

        if (preg_match_all(
            "/\\bconst\\s+(\\w+)\\s*=\\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $constMatches,
        )) {
            $constants = array_combine($constMatches[1], $constMatches[2]);
        }

        if (! preg_match_all(
            "/Schema::create\\(\\s*(?:['\"]([^'\"]+)['\"]|self::(\\w+))/",
            $contents,
            $createMatches,
        )) {
            return [];
        }

        $tables = [];

        foreach ($createMatches[1] as $i => $literal) {
            if ($literal !== '') {
                $tables[] = $literal;

                continue;
            }

            $constName = $createMatches[2][$i] ?? '';

            if ($constName !== '' && isset($constants[$constName])) {
                $tables[] = $constants[$constName];
            }
        }

        return array_values(array_unique($tables));
    }
}
