<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/ready-origin-schema.php';

class NativeScopedDispatchSchemaTest extends TestCase
{
    public function test_only_the_exact_native_index_is_removed_from_both_physical_catalogs(): void
    {
        foreach (['mysql', 'mariadb'] as $backend) {
            $path = __DIR__.'/../../rust/migrations/'.$backend.'/';
            $old = json_decode(file_get_contents($path.'0010_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $new = json_decode(file_get_contents($path.'0011_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($old, projectMysqlScopedDispatch($new));
            foreach (['prefix', 'order', 'missing', 'duplicate'] as $mutation) {
                $changed = $new;
                foreach ($changed as &$row) {
                    if ($row[0] !== 'workflow_tasks') {
                        continue;
                    }
                    $index = ",\n  KEY `dw_workflow_tasks_scoped_dispatch` (`namespace`,`queue`,`task_type`(16),`status`(16),`priority`,`available_at`,`id`)";
                    $replacement = match ($mutation) {
                        'prefix' => str_replace('(`namespace`,`queue`', '(`namespace`(64),`queue`(64)', $index),
                        'order' => str_replace('`priority`,`available_at`', '`available_at`,`priority`', $index),
                        'missing' => '',
                        'duplicate' => $index.$index,
                    };
                    $row[1] = str_replace($index, $replacement, $row[1]);
                }
                unset($row);
                try {
                    projectMysqlScopedDispatch($changed);
                    $this->fail('Changed native index definition was hidden.');
                } catch (RuntimeException $error) {
                    $this->assertSame('Native scoped dispatch definition mismatch.', $error->getMessage());
                }
            }
        }
    }
}
