<?php

declare(strict_types=1);

namespace ServerParityPatch;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('parity.v1.one_activity')]
final class DeploymentWorkflow extends Workflow
{
    public function handle(array $value): array
    {
        return \Workflow\V2\activity(\ServerParity\EchoActivity::class, $value);
    }
}
