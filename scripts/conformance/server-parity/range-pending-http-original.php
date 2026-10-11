<?php

declare(strict_types=1);

return static function (\DurableWorkflow\Worker\WorkflowContext $context, array $value): array {
    RangeRepairState::$versions[] = $context->getVersion(RangeRepairState::$changeId, 1, 1);
    $selected = $context->select([
        'activity' => $context->deferActivity('parity.v1.echo_activity', [$value], ['task_queue' => 'server-parity-range-activity']),
        'timer' => $context->deferTimer(3),
    ]);
    RangeRepairState::$selections[] = ['key' => $selected->key, 'kind' => $selected->kind, 'identity' => $selected->identity];

    return $selected->handles['activity']->await();
};
