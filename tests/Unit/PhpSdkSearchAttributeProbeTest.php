<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../scripts/conformance/php-sdk-search-attribute-probe.php';

final class PhpSdkSearchAttributeProbeTest extends TestCase
{
    public function test_schema_setup_creates_missing_definitions(): void
    {
        $required = php_sdk_search_attribute_probe_values('fresh')['definitions'];
        $listed = [];
        $created = [];

        $result = php_sdk_ensure_search_attribute_definitions(
            $required,
            static function () use (&$listed): array {
                return $listed;
            },
            static function (string $name, string $type) use (&$listed, &$created): array {
                $listed[$name] = $type;
                $created[$name] = $type;

                return ['outcome' => 'created', 'name' => $name, 'type' => $type];
            },
        );

        $this->assertSame($required, $created);
        $this->assertSame($required, $result);
    }

    public function test_schema_setup_reuses_matching_existing_definitions_without_creating_them(): void
    {
        $required = php_sdk_search_attribute_probe_values('existing')['definitions'];
        $createCalled = false;

        $result = php_sdk_ensure_search_attribute_definitions(
            $required,
            static fn (): array => $required,
            static function () use (&$createCalled): never {
                $createCalled = true;
                throw new RuntimeException('Existing definitions must not be created again.');
            },
        );

        $this->assertFalse($createCalled);
        $this->assertSame($required, $result);
    }

    public function test_schema_setup_rejects_an_existing_definition_with_the_wrong_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Search attribute customer_id has type [int]; expected [string].');

        php_sdk_ensure_search_attribute_definitions(
            ['customer_id' => 'string'],
            static fn (): array => ['customer_id' => 'int'],
            static fn (): never => throw new RuntimeException('The mismatched definition must not be created.'),
        );
    }

    public function test_schema_setup_accepts_a_matching_definition_created_by_a_concurrent_run(): void
    {
        $listed = [];

        $result = php_sdk_ensure_search_attribute_definitions(
            ['customer_id' => 'string'],
            static function () use (&$listed): array {
                return $listed;
            },
            static function (string $name, string $type) use (&$listed): array {
                $listed[$name] = $type;

                return ['outcome' => 'already_exists', 'name' => $name, 'type' => $type];
            },
        );

        $this->assertSame(['customer_id' => 'string'], $result);
    }

    public function test_visibility_query_targets_a_unique_upserted_value(): void
    {
        $values = php_sdk_search_attribute_probe_values('run-42');
        $query = $values['visibility_query'];

        $this->assertSame('upserted_search_attributes', $query['attribute_source']);
        $this->assertSame('priority_tier', $query['name']);
        $this->assertSame($values['upserted_search_attributes']['priority_tier'], $query['value']);
        $this->assertNotSame($values['start_search_attributes']['priority_tier'], $query['value']);
        $this->assertSame('priority_tier = "platinum-run-42"', $query['query']);
    }

    public function test_namespace_isolation_query_uses_a_unique_start_value_without_peer_execution(): void
    {
        $values = php_sdk_search_attribute_probe_values('run-42');
        $query = $values['namespace_isolation_query'];

        $this->assertSame('start_search_attributes', $query['attribute_source']);
        $this->assertSame('customer_id', $query['name']);
        $this->assertSame($values['start_search_attributes']['customer_id'], $query['value']);
        $this->assertSame('customer_id = "cust-php-run-42"', $query['query']);
    }

    public function test_namespace_isolation_evidence_accepts_a_pending_peer_workflow_from_start_visibility(): void
    {
        $values = php_sdk_search_attribute_probe_values('run-42');
        $evidence = php_sdk_search_attribute_namespace_isolation_evidence(
            'primary',
            'peer',
            'primary-workflow',
            'peer-workflow',
            $values['namespace_isolation_query'],
            ['primary-workflow'],
            ['peer-workflow'],
        );

        $this->assertFalse($evidence['peer_execution_required']);
        $this->assertSame('start_search_attributes', $evidence['attribute_source']);
        $this->assertSame(1, $evidence['primary_query_count']);
        $this->assertSame(1, $evidence['peer_query_count']);
        $this->assertTrue($evidence['primary_visibility_match']);
        $this->assertTrue($evidence['peer_visibility_match']);
        $this->assertFalse($evidence['cross_namespace_leak_detected']);
    }

    public function test_namespace_isolation_evidence_detects_a_cross_namespace_result(): void
    {
        $values = php_sdk_search_attribute_probe_values('run-42');
        $evidence = php_sdk_search_attribute_namespace_isolation_evidence(
            'primary',
            'peer',
            'primary-workflow',
            'peer-workflow',
            $values['namespace_isolation_query'],
            ['primary-workflow', 'peer-workflow'],
            ['peer-workflow'],
        );

        $this->assertTrue($evidence['cross_namespace_leak_detected']);
    }
}
