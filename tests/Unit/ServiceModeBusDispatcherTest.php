<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ServiceModeBusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Jobs\RunTimerTask;

class ServiceModeBusDispatcherTest extends TestCase
{
    public function test_it_suppresses_run_workflow_task_dispatch(): void
    {
        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->never())->method('dispatch');

        $dispatcher = new ServiceModeBusDispatcher($inner);
        $result = $dispatcher->dispatch(new RunWorkflowTask('task-1'));

        $this->assertNull($result);
    }

    public function test_it_suppresses_run_activity_task_dispatch(): void
    {
        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->never())->method('dispatch');

        $dispatcher = new ServiceModeBusDispatcher($inner);
        $result = $dispatcher->dispatch(new RunActivityTask('task-2'));

        $this->assertNull($result);
    }

    public function test_it_passes_through_timer_task_dispatch(): void
    {
        $timerJob = new RunTimerTask('timer-1');

        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->once())
            ->method('dispatch')
            ->with($timerJob)
            ->willReturn(42);

        $dispatcher = new ServiceModeBusDispatcher($inner);
        $result = $dispatcher->dispatch($timerJob);

        $this->assertSame(42, $result);
    }

    public function test_it_passes_through_other_jobs(): void
    {
        $job = new \stdClass();

        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->once())
            ->method('dispatch')
            ->with($job)
            ->willReturn('dispatched');

        $dispatcher = new ServiceModeBusDispatcher($inner);
        $result = $dispatcher->dispatch($job);

        $this->assertSame('dispatched', $result);
    }

    public function test_it_suppresses_sync_dispatch_of_workflow_and_activity_tasks(): void
    {
        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->never())->method('dispatchSync');

        $dispatcher = new ServiceModeBusDispatcher($inner);

        $this->assertNull($dispatcher->dispatchSync(new RunWorkflowTask('task-3')));
        $this->assertNull($dispatcher->dispatchSync(new RunActivityTask('task-4')));
    }

    public function test_it_delegates_chain_to_inner(): void
    {
        $inner = $this->createMock(Dispatcher::class);
        $inner->expects($this->once())
            ->method('chain')
            ->with([])
            ->willReturn('chain-result');

        $dispatcher = new ServiceModeBusDispatcher($inner);

        $this->assertSame('chain-result', $dispatcher->chain([]));
    }
}
