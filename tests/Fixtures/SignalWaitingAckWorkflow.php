<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('tests.signal-waiting-ack')]
#[Signal('advance')]
#[Signal('finish')]
final class SignalWaitingAckWorkflow extends Workflow
{
    public function handle(): mixed
    {
        return \Workflow\V2\all([
            static fn () => \Workflow\V2\signal('advance'),
            static fn () => \Workflow\V2\signal('finish'),
            static fn () => \Workflow\V2\timer(1),
        ]);
    }
}
