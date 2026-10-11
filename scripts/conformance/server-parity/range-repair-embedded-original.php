<?php

declare(strict_types=1);

#[\Workflow\V2\Attributes\Type('parity.v1.range_repair')]
final class RangeRepairWorkflow extends \Workflow\V2\Workflow
{
    public function handle(array $value): array
    {
        RangeRepairState::$versions[] = self::getVersion(RangeRepairState::$changeId, 1, 1);

        return \Workflow\V2\activity(RangeRepairActivity::class, $value);
    }
}
