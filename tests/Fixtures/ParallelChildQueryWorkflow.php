<?php

namespace Tests\Fixtures;

use Throwable;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('tests.parallel-child-query-workflow')]
class ParallelChildQueryWorkflow extends Workflow
{
    private string $stage = 'waiting-for-children';

    private mixed $value = null;

    private ?string $failure = null;

    public function handle(): array
    {
        try {
            $this->value = Workflow::all([
                static fn () => Workflow::child(InternalChildWorkflow::class, 'first'),
                static fn () => Workflow::child(InternalChildWorkflow::class, 'second'),
            ]);
        } catch (Throwable $failure) {
            $this->failure = $failure->getMessage();
        }
        $this->stage = 'waiting-for-finish';
        Workflow::awaitSignal('finish');

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return ['stage' => $this->stage, 'value' => $this->value, 'failure' => $this->failure];
    }
}
