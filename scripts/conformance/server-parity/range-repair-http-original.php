<?php

declare(strict_types=1);

return static function (\DurableWorkflow\Worker\WorkflowContext $context, array $value): array {
    RangeRepairState::$versions[] = $context->getVersion(RangeRepairState::$changeId, 1, 1);

    return $context->activity('parity.v1.echo_activity', [$value]);
};
