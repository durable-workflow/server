<?php

declare(strict_types=1);

namespace ServerParity;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Attributes\Signal;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Workflow;
use Workflow\WorkflowOptions;
use Workflow\V2\Support\ActivityOptions;

use function Workflow\V2\activity;
use function Workflow\V2\timer;
use function Workflow\V2\signal;
use function Workflow\V2\child;

#[Type('parity.v1.cooperative_cleanup')]
final class CooperativeCleanupWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        try {
            timer(60);
        } catch (\Workflow\V2\Exceptions\WorkflowCancellationRequestedException $cancel) {
            $snapshot = $cancel->cancellation?->toArray() ?? throw new \RuntimeException('Canonical embedded root context is missing.');

            return self::cancellationShield(static function () use ($value, $snapshot): array {
                timer($value['cleanup_delay_seconds']);

                return activity(CooperativeCleanupActivity::class, $value, $snapshot);
            });
        }

        throw new \RuntimeException('The original timer unexpectedly completed without cancellation.');
    }
}

#[Type('parity.v1.cooperative_cleanup_activity')]
final class CooperativeCleanupActivity extends Activity
{
    public function handle(array $value, array $snapshot): array
    {
        $this->heartbeat(['details' => ['request_id' => $snapshot['request_id']]]);

        return ['value' => $value, 'cancellation' => $snapshot];
    }
}

#[Type('parity.v1.cancel_before_claim')]
final class CancelBeforeClaimWorkflow extends Workflow
{
    public function handle(array $value): array { return $value; }
}

#[Type('parity.v1.cancel_pending_timer')]
final class CancelPendingTimerWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        timer(60);

        return $value;
    }
}

#[Type('parity.v1.cancel_leased_activity')]
final class CancelLeasedActivityWorkflow extends Workflow
{
    public function handle(array $value): array { return activity(CancelActivity::class, $value); }
}

#[Type('parity.v1.cancel_activity')]
final class CancelActivity extends Activity
{
    public function handle(array $value): array
    {
        \CancellationProbeState::$claims[] = $this->taskId;
        \cancelEmbeddedRun($this->runId());

        return $value;
    }
}

#[Type('parity.v1.cancelled_child_parent')]
final class CancelledChildParentWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        try {
            child(CancelledChildWorkflow::class, $value, new WorkflowOptions(connection: 'database', queue: 'server-parity-v1'));
        } catch (\Workflow\V2\Exceptions\WorkflowCancelledException $error) {
            \ChildCancellationProbeState::$caught = ['class' => $error::class, 'message' => $error->getMessage()];

            return ['value' => $value, 'child_cancelled' => true];
        }

        throw new \LogicException('The original child unexpectedly completed.');
    }
}

#[Type('parity.v1.cancelled_child')]
final class CancelledChildWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        timer(60);

        return $value;
    }
}

#[Type('parity.v1.two_children')]
final class TwoChildrenWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        $results = [];
        foreach ($value['payloads'] as $payload) {
            $results[] = child(ChildActivityWorkflow::class, $payload, new WorkflowOptions(connection: 'database', queue: 'server-parity-v1'));
        }

        return ['child_results' => $results];
    }
}

#[Type('parity.v1.child_activity')]
final class ChildActivityWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return ['echo' => activity(EchoActivity::class, $value), 'source' => 'child-workflow'];
    }
}

#[Type('parity.v1.echo')]
final class EchoWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return $value;
    }
}

#[Type('parity.v1.updates')]
#[Signal('payload', [['name' => 'value', 'type' => 'array']])]
final class UpdatesWorkflow extends Workflow
{
    private array $values = [];

    public function handle(array $value, int $target): array
    {
        signal('payload');

        return ['values' => $this->values];
    }

    #[UpdateMethod('set_payload')]
    public function setPayload(array $value): array
    {
        $this->values[] = $value;

        return ['value' => $value, 'applied' => count($this->values)];
    }
}

#[Type('parity.v1.one_activity')]
final class OneActivityWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return activity(EchoActivity::class, $value);
    }
}

#[Type('parity.v1.activity_retry')]
final class ActivityRetryWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return activity(RetryActivity::class, new ActivityOptions(maxAttempts: 2, backoff: [1]), $value);
    }
}

#[Type('parity.v1.retry_activity')]
final class RetryActivity extends Activity
{
    public function handle(array $value): array
    {
        if ($this->attemptCount() === 1) {
            throw new \RuntimeException('parity retry λ');
        }

        return ['echo' => $value, 'attempt' => $this->attemptCount()];
    }
}

#[Type('parity.v1.activity_retry_unmatched_filter')]
final class UnmatchedFilterWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return activity(RetryActivity::class, new ActivityOptions(maxAttempts: 2, backoff: [1], nonRetryableErrorTypes: ['InvalidArgumentException']), $value);
    }
}

abstract class CatchingFailureWorkflow extends Workflow
{
    abstract protected function options(): ActivityOptions;

    public function handle(array $value): array
    {
        try {
            activity(TerminalActivity::class, $this->options(), $value);
        } catch (\RuntimeException $failure) {
            // Embedded replay must restore the original application exception.
            if ($failure::class !== \RuntimeException::class) {
                throw $failure;
            }

            return ['echo' => $value, 'caught' => ['type' => $failure::class, 'message' => $failure->getMessage()]];
        }

        throw new \LogicException('The terminal fixture activity unexpectedly succeeded.');
    }
}

#[Type('parity.v1.activity_failure_exhausted')]
final class ExhaustedFailureWorkflow extends CatchingFailureWorkflow
{
    protected function options(): ActivityOptions
    {
        return new ActivityOptions(maxAttempts: 2, backoff: [1]);
    }
}

#[Type('parity.v1.activity_failure_filtered')]
final class FilteredFailureWorkflow extends CatchingFailureWorkflow
{
    protected function options(): ActivityOptions
    {
        return new ActivityOptions(maxAttempts: 3, backoff: [1], nonRetryableErrorTypes: ['RuntimeException']);
    }
}

#[Type('parity.v1.terminal_activity')]
final class TerminalActivity extends Activity
{
    public function handle(array $value): array
    {
        throw new \RuntimeException('parity terminal λ');
    }
}

#[Type('parity.v1.echo_activity')]
final class EchoActivity extends Activity
{
    public function handle(array $value): array
    {
        return $value;
    }
}

#[Type('parity.v1.one_timer')]
final class TimerWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        foreach ($value['delays'] as $delay) {
            timer($delay);
        }

        return $value;
    }
}

#[Type('parity.v1.signals')]
#[Signal('payload', [['name' => 'value', 'type' => 'array']])]
final class SignalsWorkflow extends Workflow
{
    public function handle(array $value, int $target): array
    {
        $received = null;
        for ($index = 0; $index < $target; $index++) {
            $received = signal('payload');
        }

        return $received;
    }
}

#[Type('parity.v1.queries')]
#[Signal('payload', [['name' => 'value', 'type' => 'array']])]
final class QueriesWorkflow extends Workflow
{
    private int $delivered = 0;
    private ?array $last = null;

    public function handle(array $value, int $target): array
    {
        for ($index = 0; $index < $target; $index++) {
            $this->last = signal('payload');
            $this->delivered++;
        }

        return $this->last;
    }

    #[QueryMethod('state')]
    public function state(array $request): array
    {
        return ['request' => $request, 'delivered' => $this->delivered, 'last' => $this->last];
    }
}
