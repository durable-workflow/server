<?php

namespace Tests\Fixtures;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('tests.legacy-version-query-workflow')]
class LegacyVersionQueryWorkflow extends Workflow
{
    private bool $patched = false;

    private string $stage = 'starting';

    public function handle(): array
    {
        $this->patched = Workflow::patched('legacy-query-upgrade');
        $this->stage = 'waiting-for-timer';
        Workflow::timer(60);
        $this->stage = 'completed';

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return ['patched' => $this->patched, 'stage' => $this->stage];
    }
}
