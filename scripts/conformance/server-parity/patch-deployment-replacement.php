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
        $first = self::patched(\PatchDeploymentState::$changeId);
        $second = self::patched(\PatchDeploymentState::$changeId);
        \PatchDeploymentState::$decisions[] = [$first, $second];
        if ($first || $second) {
            throw new \LogicException('An unmarked old run selected the new patch branch.');
        }

        return \Workflow\V2\activity(\ServerParity\EchoActivity::class, $value);
    }
}
