<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\HistoryBudget;

final class HistoryBudgetCanonicalJsonTest extends TestCase
{
    public function test_shared_native_history_bytes_match_the_published_counter(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/HistoryBudget/canonical-json.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach ($cases as $case) {
            $event = new WorkflowHistoryEvent();
            $event->setRawAttributes([
                'event_type' => $case['event_type'],
                'payload' => $case['payload_json'],
            ]);

            $this->assertSame($case['history_size_bytes'], HistoryBudget::sizeOfEvent($event), $case['name']);
        }
    }
}
