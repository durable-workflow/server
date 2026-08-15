<?php

namespace Tests\Unit;

use DurableWorkflow\Server\Ci\OpenApiDocumentEvolution;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/ci/OpenApiDocumentEvolution.php';

class OpenApiDocumentEvolutionTest extends TestCase
{
    public function test_feature_ci_compares_the_candidate_to_its_base_revision(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/phpunit-feature.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            'php scripts/ci/check-worker-openapi-evolution.php "$CORPUS_BASE_REF"',
            $workflow,
        );
        $this->assertStringContainsString(
            'tests/Unit/OpenApiDocumentEvolutionTest.php',
            $workflow,
        );
    }

    public function test_it_rejects_semantic_changes_that_reuse_a_document_version(): void
    {
        $candidate = $this->document();
        $candidate['paths']['/worker/poll']['post']['responses']['409'] = [
            'description' => 'Conflict',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Semantic OpenAPI changes require a new info.version');

        OpenApiDocumentEvolution::assertVersionedChange($this->document(), $candidate);
    }

    public function test_it_allows_semantic_changes_with_a_new_document_version(): void
    {
        $candidate = $this->document('9');
        $candidate['paths']['/worker/poll']['post']['responses']['409'] = [
            'description' => 'Conflict',
        ];

        $result = OpenApiDocumentEvolution::assertVersionedChange($this->document(), $candidate);

        $this->assertSame('8', $result['previous_version']);
        $this->assertSame('9', $result['candidate_version']);
        $this->assertTrue($result['semantic_shape_changed']);
    }

    public function test_it_allows_description_only_edits_without_a_version_change(): void
    {
        $candidate = $this->document();
        $candidate['info']['description'] = 'Reworded document description.';
        $candidate['paths']['/worker/poll']['post']['description'] = 'Reworded operation description.';
        $candidate['paths']['/worker/poll']['post']['responses']['200']['description'] = 'Reworded response description.';
        $candidate['components']['schemas']['PollResult']['description'] = 'Reworded schema description.';

        $result = OpenApiDocumentEvolution::assertVersionedChange($this->document(), $candidate);

        $this->assertFalse($result['semantic_shape_changed']);
        $this->assertSame('8', $result['candidate_version']);
    }

    public function test_a_description_key_inside_a_schema_const_remains_semantic(): void
    {
        $previous = $this->document();
        $previous['components']['schemas']['PollResult']['const'] = ['description' => 'old'];
        $candidate = $previous;
        $candidate['components']['schemas']['PollResult']['const'] = ['description' => 'new'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Semantic OpenAPI changes require a new info.version');

        OpenApiDocumentEvolution::assertVersionedChange($previous, $candidate);
    }

    public function test_a_wire_property_named_description_remains_semantic(): void
    {
        $candidate = $this->document();
        $candidate['paths']['/worker/poll']['post']['responses']['200']['content'] = [
            'application/json' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Semantic OpenAPI changes require a new info.version');

        OpenApiDocumentEvolution::assertVersionedChange($this->document(), $candidate);
    }

    public function test_it_ignores_mapping_key_order_but_preserves_list_order(): void
    {
        $previous = $this->document();
        $candidate = $this->document();
        $candidate['info'] = array_reverse($candidate['info'], true);

        $this->assertFalse(
            OpenApiDocumentEvolution::assertVersionedChange($previous, $candidate)['semantic_shape_changed'],
        );

        $candidate['tags'] = array_reverse($candidate['tags']);

        $this->expectException(RuntimeException::class);
        OpenApiDocumentEvolution::assertVersionedChange($previous, $candidate);
    }

    public function test_it_rejects_a_document_version_rollback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not move backwards');

        OpenApiDocumentEvolution::assertVersionedChange(
            $this->document('9'),
            $this->document('8'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $version = '8'): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'durable-workflow.v2.worker-protocol-api',
                'version' => $version,
                'description' => 'Worker protocol.',
            ],
            'tags' => [
                ['name' => 'worker'],
                ['name' => 'workflow-task'],
            ],
            'paths' => [
                '/worker/poll' => [
                    'post' => [
                        'description' => 'Poll for work.',
                        'responses' => [
                            '200' => ['description' => 'Poll result.'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'PollResult' => [
                        'description' => 'Poll result schema.',
                        'type' => 'object',
                    ],
                ],
            ],
        ];
    }
}
