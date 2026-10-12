<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\FailureFactory;

final class ServerParityUpdateFailureNormalizationTest extends TestCase
{
    public function test_published_php_failure_rules_match_the_native_portable_corpus(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/ServerParityNormalization/update-failure-commands.json'), true, flags: JSON_THROW_ON_ERROR);
        $normalize = new ReflectionMethod(DefaultWorkflowTaskBridge::class, 'normalizeFailUpdateCommand');
        foreach ($fixture['cases'] as $case) {
            $command = $normalize->invoke(null, $case['command']);
            $class = $command['exception_class'] ?? RuntimeException::class;
            self::assertSame($case['expected'], [
                'update_id' => $command['update_id'],
                'message' => $command['message'],
                'exception_class' => $class,
                'exception_type' => $command['exception_type'] ?? null,
                'non_retryable' => $command['non_retryable'] ?? FailureFactory::isNonRetryableFromStrings($class),
                'category' => FailureFactory::classifyFromStrings('update', 'workflow_command', $class, $command['message'])->value,
            ]);
        }
        foreach ($fixture['rejected'] as $command) {
            self::assertNull($normalize->invoke(null, $command));
        }
    }
}
