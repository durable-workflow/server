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
                'retention_days' => 30,
                'status' => 'active',
            ]
        );
    }
}
