<?php

namespace Tests\Fixtures;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\awaitSignal;

#[Type('tests.interactive-command-workflow')]
#[Signal('advance', [
    [
        'name' => 'name',
        'type' => 'string',
    ],
])]
#[Signal('finish')]
class InteractiveCommandWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $name = null;

    private bool $approved = false;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(): array
    {
        $this->stage = 'waiting-for-advance';
        $this->events[] = 'started';

        $name = awaitSignal('advance');

        $this->name = $name;
        $this->stage = 'waiting-for-finish';
        $this->events[] = sprintf('signal:%s', $name);

        awaitSignal('finish');

        $this->stage = 'completed';
        $this->events[] = 'finish';

        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'approved' => $this->approved,
            'events' => $this->events,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'approved' => $this->approved,
            'events' => $this->events,
        ];
    }

    #[QueryMethod('events-starting-with')]
    public function countEventsByPrefix(string $prefix): int
    {
        return count(array_filter(
            $this->events,
            static fn (string $event): bool => str_starts_with($event, $prefix),
        ));
    }

    #[UpdateMethod]
    public function approve(bool $approved, string $source = 'manual'): array
    {
        $this->approved = $approved;
        $this->events[] = sprintf('approved:%s:%s', $approved ? 'yes' : 'no', $source);

        return $this->currentState();
    }
}
