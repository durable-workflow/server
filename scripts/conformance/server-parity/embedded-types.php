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
