<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class ServerDiscoveryController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service' => 'Durable Workflow Server',
            'links' => [
                'health' => '/api/health',
                'readiness' => '/api/ready',
                'cluster_info' => '/api/cluster/info',
                'setup' => 'https://durable-workflow.com/docs/2.0/quickstart/',
            ],
        ]);
    }
}
