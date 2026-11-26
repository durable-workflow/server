<?php

declare(strict_types=1);

// Temporal side of the comparison race (Server #1081), run by RoadRunner. The
// workflow and activity code matches workers/sdk-php/worker.php: the same
// type names, five sequential steps and the same sleep in each step.

require __DIR__.'/vendor/autoload.php';

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Activity\LocalActivityInterface;
use Temporal\Activity\LocalActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Temporal\WorkerFactory;

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

#[ActivityInterface(prefix: 'race.')]
final class RaceActivities
{
    public function __construct(private readonly int $sleepMicroseconds)
    {
    }

    #[ActivityMethod(name: 'step')]
    public function step(int $step): int
    {
        usleep($this->sleepMicroseconds);

        return $step;
    }
}

// The Temporal PHP SDK marks local activities on the class, so the local step
// is its own type with the same body.
#[LocalActivityInterface(prefix: 'race.')]
final class RaceLocalActivities
{
    public function __construct(private readonly int $sleepMicroseconds)
    {
    }

    #[ActivityMethod(name: 'local_step')]
    public function localStep(int $step): int
    {
        usleep($this->sleepMicroseconds);

        return $step;
    }
}

#[WorkflowInterface]
final class FiveStepsWorkflow
{
    #[WorkflowMethod(name: 'race.five_steps')]
    public function run(): \Generator
    {
        $activities = Workflow::newActivityStub(
            RaceActivities::class,
            ActivityOptions::new()->withStartToCloseTimeout(30),
        );
        $total = 0;
        for ($step = 0; $step < RACE_STEPS; $step++) {
            $total += (int) yield $activities->step($step);
        }

        return $total;
    }
}

#[WorkflowInterface]
final class FiveLocalStepsWorkflow
{
    #[WorkflowMethod(name: 'race.five_local_steps')]
    public function run(): \Generator
    {
        $activities = Workflow::newActivityStub(
            RaceLocalActivities::class,
            LocalActivityOptions::new()->withStartToCloseTimeout(30),
        );
        $total = 0;
        for ($step = 0; $step < RACE_STEPS; $step++) {
            $total += (int) yield $activities->localStep($step);
        }

        return $total;
    }
}

$sleepMicroseconds = raceStepMicroseconds();
$factory = WorkerFactory::create();
$worker = $factory->newWorker(raceEnvironment('RACE_TASK_QUEUE'));
$worker->registerWorkflowTypes(FiveStepsWorkflow::class, FiveLocalStepsWorkflow::class);
$worker->registerActivity(RaceActivities::class, static fn () => new RaceActivities($sleepMicroseconds));
$worker->registerActivity(RaceLocalActivities::class, static fn () => new RaceLocalActivities($sleepMicroseconds));
$factory->run();
