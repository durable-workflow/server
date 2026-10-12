<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

use function Workflow\V2\timer;

#[Type('tests.post-commit-child')]
class PostCommitChildWorkflow extends Workflow
{
    public function handle(): string
    {
        timer(60);

        return 'timer completed';
    }
}
