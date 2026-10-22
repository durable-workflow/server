<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

if (InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== '3.2.0'
    || InstalledVersions::getReference('durable-workflow/sdk') !== 'dfaae75a94b1d855379863799ce2c9a53f900f97') {
    throw new RuntimeException('The normal published PHP SDK identity changed.');
}

final class GeneralLeaseWorkflow
{
    #[Workflow('qualification.runtime-lease')]
    public function run(WorkflowContext $context, int $seconds, string $mode): array
    {
        return $context->activity('qualification.blocking', [$seconds, $mode], [
            'retry_policy' => ['max_attempts' => 2, 'backoff_seconds' => [0]],
        ]);
    }
}

final class GeneralLeaseActivity
{
    #[Activity('qualification.blocking')]
    public function run(ActivityContext $context, int $seconds, string $mode): array
    {
        file_put_contents('/state/invocations.jsonl', json_encode([
            'mode' => $mode,
            'attempt' => $context->attemptNumber,
            'task_id' => $context->taskId,
            'activity_attempt_id' => $context->activityAttemptId,
            'pid' => getmypid(),
            'started_at' => gmdate('c'),
            'monotonic_ns' => hrtime(true),
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        if ($mode === 'long' || $context->attemptNumber === 1) {
            sleep($seconds);
        }

        return [9007199254740993, null, 'λ'];
    }
}

file_put_contents('/state/worker.pid', (string) getmypid());
$client = new Client(getenv('SERVER_URL'), namespace: getenv('NAMESPACE') ?: 'default',
    token: 'synthetic', workerProtocolVersion: '1.20');
$worker = new Worker($client, 'lease-qualification', workerId: getenv('WORKER_ID') ?: 'lease-long',
    diagnosticListener: static function (string $event, array $fields): void {
        $record = ['event' => $event, 'at' => gmdate('c')];
        if (($fields['exception'] ?? null) instanceof Throwable) {
            $record['exception'] = get_class($fields['exception']);
            $record['message'] = substr($fields['exception']->getMessage(), 0, 512);
        }
        file_put_contents('/state/worker-events.jsonl', json_encode($record, JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND | LOCK_EX);
    });
$worker->register(GeneralLeaseWorkflow::class, GeneralLeaseActivity::class)->run(1);
