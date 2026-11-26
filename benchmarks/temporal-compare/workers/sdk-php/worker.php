<?php

declare(strict_types=1);

// Durable Workflow side of the Temporal comparison race (Server #1081). The
// workflow and activity code matches workers/roadrunner/worker.php: the same
// type names, five sequential steps and the same sleep in each step.

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Attribute\Activity;
use DurableWorkflow\Attribute\Workflow;
use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;

function raceEnvironment(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || trim($value) === '') {
        fwrite(STDERR, "Set {$name}.\n");
        exit(2);
    }

    return trim($value);
}

function raceStepMicroseconds(): int
{
    $milliseconds = raceEnvironment('RACE_STEP_MILLISECONDS');
    if (preg_match('/^[0-9]{1,4}$/', $milliseconds) !== 1 || (int) $milliseconds > 1000) {
        fwrite(STDERR, "RACE_STEP_MILLISECONDS must be 0 to 1000.\n");
        exit(2);
    }

    return (int) $milliseconds * 1000;
}

const RACE_STEPS = 5;

final class FiveStepsWorkflow
{
    #[Workflow('race.five_steps')]
    public function run(WorkflowContext $context): int
    {
        $total = 0;
        for ($step = 0; $step < RACE_STEPS; $step++) {
            $total += (int) $context->activity('race.step', [$step]);
        }

        return $total;
    }
}

final class FiveLocalStepsWorkflow
{
    #[Workflow('race.five_local_steps')]
    public function run(WorkflowContext $context): int
    {
        $total = 0;
        for ($step = 0; $step < RACE_STEPS; $step++) {
            $total += (int) $context->localActivity('race.local_step', [$step]);
        }

        return $total;
    }
}

final class RaceActivities
{
    public function __construct(private readonly int $sleepMicroseconds)
    {
    }

    #[Activity('race.step')]
    public function step(ActivityContext $context, int $step): int
    {
        usleep($this->sleepMicroseconds);

        return $step;
    }

    #[Activity('race.local_step')]
    public function localStep(ActivityContext $context, int $step): int
    {
        usleep($this->sleepMicroseconds);

        return $step;
    }
}

$client = new Client(
    raceEnvironment('DURABLE_WORKFLOW_RUNTIME_URL'),
    namespace: 'default',
    token: raceEnvironment('DURABLE_WORKFLOW_TOKEN'),
);

Worker::create($client, raceEnvironment('RACE_TASK_QUEUE'))
    ->register(FiveStepsWorkflow::class, FiveLocalStepsWorkflow::class, new RaceActivities(raceStepMicroseconds()))
    ->run();
