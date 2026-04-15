<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Activity;

/**
 * PHP activity fixture for polyglot interop testing.
 *
 * This activity accepts various JSON-serializable types and returns
 * structured data to validate correct payload round-trip between
 * Python and PHP workers.
 */
#[Type('tests.polyglot.php-activity')]
class PolyglotPhpActivity extends Activity
{
    public ?string $queue = 'polyglot-queue';

    public function handle(array $input): array
    {
        // Accept structured input and return enriched output
        // to prove JSON codec round-trip works correctly
        return [
            'runtime' => 'php',
            'received_input' => $input,
            'type_checks' => [
                'has_string' => isset($input['name']) && is_string($input['name']),
                'has_int' => isset($input['count']) && is_int($input['count']),
                'has_float' => isset($input['price']) && is_float($input['price']),
                'has_bool' => isset($input['active']) && is_bool($input['active']),
                'has_array' => isset($input['tags']) && is_array($input['tags']),
                'has_nested' => isset($input['metadata']) && is_array($input['metadata']),
            ],
            'computed' => [
                'name_length' => isset($input['name']) ? strlen($input['name']) : 0,
                'count_doubled' => isset($input['count']) ? $input['count'] * 2 : 0,
                'tags_count' => isset($input['tags']) ? count($input['tags']) : 0,
            ],
        ];
    }
}
