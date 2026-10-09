<?php

declare(strict_types=1);

namespace ServerParity;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Attributes\Signal;
use Workflow\Serializers\Serializer;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;
use function Workflow\V2\timer;
use function Workflow\V2\await;

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
        for ($index = 0; $index < $target; $index++) {
            await(fn (): bool => count($this->received()) > $index, conditionKey: 'payload:'.$index);
        }

        return $this->received()[$target - 1][0];
    }

    /** Match the SDK's history-derived signals() in this embedded adapter. */
    private function received(): array
    {
        return $this->run->historyEvents->filter(static fn ($event): bool =>
            $event->event_type->value === 'SignalReceived' && ($event->payload['signal_name'] ?? null) === 'payload'
        )->map(static function ($event): array {
            $arguments = $event->payload['arguments'];

            return Serializer::unserializeWithCodec('avro', is_array($arguments) ? $arguments['blob'] : $arguments);
        })->values()->all();
    }
}
