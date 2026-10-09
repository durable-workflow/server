<?php

declare(strict_types=1);

namespace ServerParity;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\timer;
use function Workflow\V2\signal;

#[Type('parity.v1.echo')]
final class EchoWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return $value;
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
