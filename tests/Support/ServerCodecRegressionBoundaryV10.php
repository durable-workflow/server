<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\DirectConformanceWorkerProtocol;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Workflow\Serializers\Avro;

/** Direct-conformance extension for whitespace-surrounded JSON scalars. */
final class ServerCodecRegressionBoundaryV10
{
    public static function exerciseDirectCompletion(): void
    {
        $task = [
            'task_id' => 'codec-direct-conformance-scalar-task',
            'lease_owner' => 'codec-direct-conformance-scalar-worker',
            'workflow_task_attempt' => 1,
        ];
        $envelope = Avro::envelope('official-avro-string-after-json-scalar');

        if (self::proofInputCodec() === 'avro') {
            self::assertAccepted($task, $envelope);

            return;
        }

        $failure = null;
        try {
            DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
                'type' => 'complete_workflow',
                'result' => " \t-7.25e+3 \r\n",
            ]]);
        } catch (InvalidArgumentException $exception) {
            $failure = $exception;
        }

        Assert::assertInstanceOf(InvalidArgumentException::class, $failure);
        Assert::assertStringContainsString(
            'json_bytes_labeled_avro',
            $failure->getMessage(),
        );
        self::assertAccepted($task, $envelope);
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array{codec: string, blob: string}  $envelope
     */
    private static function assertAccepted(array $task, array $envelope): void
    {
        $completion = DirectConformanceWorkerProtocol::workflowTaskCompletion($task, [[
            'type' => 'complete_workflow',
            'result' => $envelope,
        ]]);

        Assert::assertSame($envelope, $completion['commands'][0]['result']);
    }

    private static function proofInputCodec(): string
    {
        $configuredCodec = getenv('SERVER_CODEC_PROOF_INPUT_CODEC');
        $codec = is_string($configuredCodec) ? $configuredCodec : 'json';
        Assert::assertContains($codec, ['avro', 'json']);

        return $codec;
    }
}
