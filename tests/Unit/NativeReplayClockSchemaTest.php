<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/replay-clock-schema.php';

class NativeReplayClockSchemaTest extends TestCase
{
    public function test_mysql_and_mariadb_projection_preserves_the_entire_prior_catalog(): void
    {
        foreach (['mysql', 'mariadb'] as $backend) {
            $path = __DIR__.'/../../rust/migrations/'.$backend.'/';
            $old = json_decode(file_get_contents($path.'0009_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode(file_get_contents($path.'0010_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($old, projectMysqlReplayClockIndex($new));
            foreach (['`event_type`,`workflow_run_id`,`sequence`', '`workflow_run_id`,`sequence`'] as $columns) {
                $changed = $new;
                foreach ($changed as &$row) {
                    if ($row[0] === 'workflow_history_events') {
                        $row[1] = str_replace('`workflow_run_id`,`event_type`,`sequence`', $columns, $row[1]);
                    }
                }
                unset($row);
                try {
                    projectMysqlReplayClockIndex($changed);
                    $this->fail('A changed history index was projected away.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_postgres_projection_requires_the_complete_valid_nonunique_index(): void
    {
        $path = __DIR__.'/../../rust/migrations/postgres/';
        $old = json_decode(file_get_contents($path.'0009_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents($path.'0010_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($old, projectPostgresReplayClockIndex($new));
        foreach (['valid' => false, 'ready' => false, 'unique' => true, 'extra' => true] as $field => $value) {
            $changed = $new;
            foreach ($changed as &$row) {
                if ($row[0] === 'index' && $row[1] === 'workflow_history_events_run_type_sequence_index') {
                    $row[2][$field] = $value;
                }
            }
            unset($row);
            try {
                projectPostgresReplayClockIndex($changed);
                $this->fail('An invalid history index was projected away.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(RuntimeException::class);
        projectPostgresReplayClockIndex($old);
    }

    public function test_sqlite_projection_uses_the_actual_additive_ddl(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $path = __DIR__.'/../../rust/migrations/sqlite/';
        foreach (['0003_full_schema', '0004_worker_incarnations', '0005_worker_session_holders',
            '0006_stream_reclaims', '0007_service_calls', '0008_external_payload_deletions', '0009_task_ready_origin'] as $step) {
            $pdo->exec(file_get_contents($path.$step.'.sql'));
        }
        $catalog = static fn (): array => $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE sql IS NOT NULL AND name NOT GLOB 'sqlite_*' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        $old = $catalog();
        $pdo->exec(file_get_contents($path.'0010_replay_clock_history_index.sql'));
        $this->assertSame($old, projectSqliteReplayClockIndex($catalog()));
        $pdo->exec('DROP INDEX workflow_history_events_run_type_sequence_index');
        $pdo->exec('CREATE INDEX workflow_history_events_run_type_sequence_index ON workflow_history_events (event_type, workflow_run_id, sequence)');
        $this->expectException(RuntimeException::class);
        projectSqliteReplayClockIndex($catalog());
    }
}
