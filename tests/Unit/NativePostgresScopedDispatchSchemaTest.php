<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/ready-origin-schema.php';

class NativePostgresScopedDispatchSchemaTest extends TestCase
{
    public function test_only_the_exact_postgres_native_index_is_projected(): void
    {
        $path = __DIR__.'/../../rust/migrations/postgres/';
        $old = json_decode(file_get_contents($path.'0010_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents($path.'0011_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($old, projectPostgresScopedDispatch($new));
        foreach (['definition', 'valid', 'missing', 'duplicate'] as $mutation) {
            $changed = [];
            foreach ($new as $row) {
                if ($row[0] === 'index' && $row[1] === 'dw_workflow_tasks_scoped_dispatch') {
                    if ($mutation === 'missing') {
                        continue;
                    }
                    if ($mutation === 'definition') {
                        $row[2]['definition'] = str_replace('namespace, queue, task_type, status', 'queue, status', $row[2]['definition']);
                    } elseif ($mutation === 'valid') {
                        $row[2]['valid'] = false;
                    } elseif ($mutation === 'duplicate') {
                        $changed[] = $row;
                    }
                }
                $changed[] = $row;
            }
            try {
                projectPostgresScopedDispatch($changed);
                $this->fail('Changed physical index was hidden.');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('Native scoped dispatch', $error->getMessage());
            }
        }
    }
}
