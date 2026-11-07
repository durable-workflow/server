<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/server-parity/ready-origin-schema.php';

class NativePostgresScopedDispatchSchemaTest extends TestCase
{
    public function test_only_the_exact_postgres_native_index_replacement_is_projected(): void
    {
        $path = __DIR__.'/../../rust/migrations/postgres/';
        $old = json_decode(file_get_contents($path.'0010_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode(file_get_contents($path.'0011_catalog.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($old, projectPostgresScopedDispatch($new));
        foreach (['definition', 'valid', 'ready', 'unique', 'primary', 'legacy', 'missing', 'duplicate'] as $mutation) {
            $changed = [];
            foreach ($new as $row) {
                if ($row[0] === 'index' && $row[1] === 'workflow_tasks_dispatch_order_index') {
                    if ($mutation === 'missing') {
                        continue;
                    }
                    if ($mutation === 'definition') {
                        $row[2]['definition'] = str_replace('namespace, queue, task_type, status', 'queue, status', $row[2]['definition']);
                    } elseif (in_array($mutation, ['valid', 'ready'], true)) {
                        $row[2][$mutation] = false;
                    } elseif (in_array($mutation, ['unique', 'primary'], true)) {
                        $row[2][$mutation] = true;
                    } elseif ($mutation === 'legacy') {
                        $row[2]['definition'] = 'CREATE INDEX workflow_tasks_dispatch_order_index ON public.workflow_tasks USING btree (queue, status, priority, available_at)';
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
