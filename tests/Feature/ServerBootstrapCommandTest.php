<?php

namespace Tests\Feature;

use App\Models\WorkflowNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_server_bootstrap_idempotently(): void
    {
        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertSame(1, WorkflowNamespace::query()->where('name', 'default')->count());

        $this->artisan('server:bootstrap --force')
            ->assertExitCode(0);

        $this->assertSame(1, WorkflowNamespace::query()->where('name', 'default')->count());
    }
}
