<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

require getenv('DW_PARITY_SDK_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
[$script, $url, $mode, $workflowId, $runId, $case, $profile, $policy, $inputJson] = $argv;
$value = json_decode($inputJson, true, flags: JSON_THROW_ON_ERROR);
$deadline = microtime(true) + 30;
$worker = null;
$stack = HandlerStack::create();
$stack->push(static function (callable $handler) use (&$worker, $mode): callable {
    return static function (RequestInterface $request, array $options) use ($handler, &$worker, $mode) {
        return $handler($request, $options)->then(static function (ResponseInterface $response) use ($request, &$worker, $mode): ResponseInterface {
            $kind = $mode === 'activity' ? 'activity' : 'workflow';
            if ($request->getMethod() === 'POST' && $response->getStatusCode() === 200
                && preg_match('#^/api/worker/'.$kind.'-tasks/[^/]+/(complete|fail)$#', $request->getUri()->getPath())) {
                $worker?->requestShutdown();
            }

            return $response;
        });
    };
});
$client = new Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null,
    transport: new Psr18Transport(client: new HttpClient(['http_errors' => false, 'handler' => $stack])));
$queue = $workflowId.($mode === 'activity' ? '-activity' : '-workflow');
$worker = new Worker($client, $queue, workerId: $workflowId.':'.$mode.':'.$profile,
    clock: static function () use ($deadline): float {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original 30-second worker observation expired');
        }

        return microtime(true);
    });
$decisions = [];
if ($mode === 'activity') {
    $worker->registerActivity('parity.v1.echo_activity', static fn (ActivityContext $ctx, mixed $input): mixed => $input);
} elseif ($mode === 'workflow') {
    $worker->registerWorkflow('parity.v1.corrected_markers', static function (WorkflowContext $ctx, mixed $input) use ($case, $profile, $policy, $workflowId, &$decisions): array {
        $projection = match ($case) {
            'numeric', 'activity_repair', 'timer_repair', 'full_retry' => (static function () use ($ctx, $profile): array {
                [$first, $second] = match ($profile) {
                    'original' => [[-3, 7], [-3, 7]],
                    'wider' => [[-10, 10], [5, 20]],
                    'excluding' => [[0, 6], [-3, 7]],
                    default => throw new InvalidArgumentException('Unknown numeric profile'),
                };

                return [$ctx->getVersion('corrected-numeric', ...$first), $ctx->getVersion('corrected-numeric', ...$second)];
            })(),
            'zero' => (static function () use ($ctx): array {
                $first = $ctx->getVersion('corrected-zero', -7, 0);
                $patch = $ctx->patched('corrected-zero');
                $ctx->deprecatePatch('corrected-zero');
                $wider = $ctx->getVersion('corrected-zero', 0, 10);

                return [$first, $patch, null, $wider, $ctx->patched('corrected-zero')];
            })(),
            'clock_zero' => [$ctx->getVersion('corrected-zero', -7, 0), $ctx->getVersion('corrected-zero', -7, 0)],
            'deprecated', 'genuine_one' => (static function () use ($ctx): array {
                $ctx->deprecatePatch('corrected-one');
                $patch = $ctx->patched('corrected-one');
                $version = $ctx->getVersion('corrected-one', 0, 10);
                $ctx->deprecatePatch('corrected-one');

                return [null, $patch, $version, null];
            })(),
            default => throw new InvalidArgumentException('Unknown marker case'),
        };
        $decisions[] = $projection;
        $backoff = match ($policy) {
            'empty' => [],
            'php_full' => [1, 2, 5, 10, 15, 30, 60, 120],
            'different' => [1, 2, 5, 10, 15, 30, 60, 121],
            default => throw new InvalidArgumentException('Unknown explicit retry policy'),
        };
        $options = ['queue' => $workflowId.'-activity',
            'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => $backoff, 'non_retryable_error_types' => []],
            'start_to_close_timeout' => 120, 'schedule_to_close_timeout' => 600];
        if ($case === 'timer_repair') {
            $selected = $ctx->select([
                'pending' => $ctx->deferActivity('parity.v1.echo_activity', [$input], $options),
                'timer' => $ctx->deferTimer(2),
            ]);
            if ($selected->kind !== 'timer') {
                throw new RuntimeException('Original timer did not win');
            }

            return ['projection' => $projection, 'winner' => 'timer', 'typed' => $selected->handles['pending']->await()];
        }

        return ['projection' => $projection, 'typed' => $ctx->activity('parity.v1.echo_activity', [$input], $options)];
    });
} else {
    throw new InvalidArgumentException('Unknown worker mode');
}
$worker->run(1);
echo json_encode(['pid' => getmypid(), 'sdk_version' => ltrim(InstalledVersions::getPrettyVersion('durable-workflow/sdk'), 'v'),
    'workflow_id' => $workflowId, 'run_id' => $runId, 'mode' => $mode, 'case' => $case,
    'range_profile' => $profile, 'retry_policy' => $policy, 'decisions' => $decisions, 'worker_finished' => true],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
