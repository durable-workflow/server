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
        $decisions = [];
        foreach (\PatchDeploymentState::$expectedDecisions as $_) {
            $decisions[] = self::patched(\PatchDeploymentState::$changeId);
        }
        \PatchDeploymentState::$decisions[] = $decisions;
        if ($decisions !== \PatchDeploymentState::$expectedDecisions) {
            throw new \LogicException('Patch decisions differ from the declared deployment contract.');
        }

        return \Workflow\V2\activity(\ServerParity\EchoActivity::class, $value);
    }
}
