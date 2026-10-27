<?php

namespace Tests\Fixtures;

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

use function Workflow\V2\signal;

#[Type('tests.bridge-command-identity')]
#[Signal('advance', ['value'])]
class BridgeCommandIdentityWorkflow extends Workflow
{
    public function handle(): void
    {
        signal('advance');
    }

    #[UpdateMethod]
    public function approve(string $note): string
    {
        return $note;
    }

    #[UpdateMethod]
    public function reject(string $note): string
    {
        return $note;
    }
}
