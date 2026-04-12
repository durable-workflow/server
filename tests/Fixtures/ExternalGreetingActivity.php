<?php

namespace Tests\Fixtures;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Activity;

#[Type('tests.external-greeting-activity')]
class ExternalGreetingActivity extends Activity
{
    public ?string $queue = 'external-activities';

    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}
