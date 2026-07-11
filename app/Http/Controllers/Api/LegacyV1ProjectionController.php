<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\LegacyV1Projection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LegacyV1ProjectionController
{
    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'source_id' => ['required', 'string', 'max:64'],
            'workflow' => ['required', 'array'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $report = LegacyV1Projection::import(
            $validated['source_id'],
            $validated['workflow'],
            $this->namespace($request),
            (bool) ($validated['dry_run'] ?? false),
        );

        return ControlPlaneProtocol::jsonForRequest(
            $request,
            $report,
            match ($report['status'] ?? null) {
                'projected' => 201,
                'already_projected', 'dry_run' => 200,
                default => 409,
            },
        );
    }

    private function namespace(Request $request): ?string
    {
        $namespace = $request->attributes->get('namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }
}
