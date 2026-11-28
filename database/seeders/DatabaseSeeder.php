<?php

namespace Database\Seeders;

use App\Models\WorkflowNamespace;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        WorkflowNamespace::firstOrCreate(
            ['name' => 'default'],
            [
                'description' => 'Default namespace',
                'retention_mode' => WorkflowNamespace::RETENTION_MODE_BOUNDED,
                'retention_days' => (int) config('server.history.retention_days', 30),
                'status' => 'active',
            ]
        );
    }
}
