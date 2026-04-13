<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;

/**
 * Decorates the default Bus Dispatcher to suppress queue dispatch of workflow
 * and activity task jobs in service mode. The package's TaskDispatcher creates
 * the database row (WorkflowTask in Ready status) before calling dispatch(),
 * so the row is already available for external workers to poll. Timer tasks
 * pass through because they don't require user workflow/activity classes.
 */
final class ServiceModeBusDispatcher implements Dispatcher
{
    public function __construct(
        private readonly Dispatcher $inner,
    ) {}

    public function dispatch($command): mixed
    {
        if ($command instanceof RunWorkflowTask || $command instanceof RunActivityTask) {
            return null;
        }

        return $this->inner->dispatch($command);
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        if ($command instanceof RunWorkflowTask || $command instanceof RunActivityTask) {
            return null;
        }

        return $this->inner->dispatchSync($command, $handler);
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        if ($command instanceof RunWorkflowTask || $command instanceof RunActivityTask) {
            return null;
        }

        return $this->inner->dispatchNow($command, $handler);
    }

    public function dispatchAfterResponse($command, $handler = null): void
    {
        if ($command instanceof RunWorkflowTask || $command instanceof RunActivityTask) {
            return;
        }

        $this->inner->dispatchAfterResponse($command, $handler);
    }

    public function chain($jobs = null): mixed
    {
        return $this->inner->chain($jobs);
    }

    public function hasCommandHandler($command): bool
    {
        return $this->inner->hasCommandHandler($command);
    }

    public function getCommandHandler($command): mixed
    {
        return $this->inner->getCommandHandler($command);
    }

    public function pipeThrough(array $pipes): static
    {
        $this->inner->pipeThrough($pipes);

        return $this;
    }

    public function map(array $map): static
    {
        $this->inner->map($map);

        return $this;
    }
}
