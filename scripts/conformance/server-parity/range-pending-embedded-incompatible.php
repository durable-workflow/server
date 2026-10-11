<?php

declare(strict_types=1);

#[\Workflow\V2\Attributes\Type('parity.v1.range_pending_repair')]
final class RangeRepairWorkflow extends \Workflow\V2\Workflow
{
    public function handle(array $value): array
    {
        RangeRepairState::$versions[] = self::getVersion(RangeRepairState::$changeId, 2, 2);
        $selected = self::select([
            'activity' => static fn () => \Workflow\V2\activity(RangeRepairActivity::class,
                new \Workflow\V2\Support\ActivityOptions(queue: 'server-parity-range-activity'), $value),
            'timer' => static fn () => \Workflow\V2\timer(3),
        ]);
        RangeRepairState::$selections[] = ['key' => $selected->key, 'kind' => $selected->kind, 'identity' => $selected->identity];

        return $selected->handles['activity']->await();
    }
}
