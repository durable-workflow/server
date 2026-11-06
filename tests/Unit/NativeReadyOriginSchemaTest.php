<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/ready-origin-schema.php';

class NativeReadyOriginSchemaTest extends TestCase
{
    public function test_only_the_exact_mysql_or_mariadb_additive_column_is_projected(): void
    {
        foreach (['mysql', 'mariadb'] as $backend) {
            $path = __DIR__.'/../../rust/migrations/'.$backend.'/';
            $old = json_decode(file_get_contents($path.'0008_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode(file_get_contents($path.'0009_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($old, projectMysqlReadyOrigin($new));
            foreach (['datetime(3)', 'timestamp(6)', 'datetime(6) NOT NULL'] as $type) {
                $changed = $new;
                foreach ($changed as &$row) {
                    if ($row[0] === 'workflow_tasks') {
                        $row[1] = str_replace('`ready_origin_at` datetime(6)', '`ready_origin_at` '.$type, $row[1]);
                    }
                }
                unset($row);
                try {
                    projectMysqlReadyOrigin($changed);
                    $this->fail('Changed physical ready-origin type was hidden.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Native ready origin definition mismatch.', $error->getMessage());
                }
            }
        }
    }

    public function test_postgres_projection_preserves_every_other_catalog_row(): void
    {
        $path = __DIR__.'/../../rust/migrations/postgres/';
        $old = json_decode(file_get_contents($path.'0008_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents($path.'0009_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($old, projectPostgresReadyOrigin($new));
        foreach (['type' => 'timestamp without time zone', 'default' => 'now()', 'not_null' => true] as $key => $value) {
            $changed = $new;
            foreach ($changed as &$row) {
                if ($row[0] === 'column' && $row[1] === 'workflow_tasks.ready_origin_at') {
                    $row[2][$key] = $value;
                }
            }
            unset($row);
            try {
                projectPostgresReadyOrigin($changed);
                $this->fail('Changed physical ready-origin definition was hidden.');
            } catch (RuntimeException $error) {
                $this->assertSame('Native ready origin definition mismatch.', $error->getMessage());
            }
        }
    }

    public function test_sqlite_projection_matches_the_real_additive_ddl_catalog(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $path = __DIR__.'/../../rust/migrations/sqlite/';
        foreach (['0003_full_schema', '0004_worker_incarnations', '0005_worker_session_holders',
            '0006_stream_reclaims', '0007_service_calls', '0008_external_payload_deletions'] as $step) {
            $pdo->exec(file_get_contents($path.$step.'.sql'));
        }
        $catalog = static fn (): array => $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE sql IS NOT NULL AND name NOT GLOB 'sqlite_*' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        $old = $catalog();
        $pdo->exec(file_get_contents($path.'0009_task_ready_origin.sql'));
        $this->assertSame($old, projectSqliteReadyOrigin($catalog()));
    }
}
