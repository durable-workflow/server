<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmbeddedV2ImportController
{
    private const IMPORTER = 'Workflow\\V2\\Support\\EmbeddedV2HistoryImport';

    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        if (! class_exists(self::IMPORTER)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Embedded v2 import is not available in the installed workflow package.',
                'reason' => 'embedded_v2_import_unavailable',
            ], 503);
        }

        $body = $request->json()->all();
        $bundle = is_array($body['bundle'] ?? null) ? $body['bundle'] : $body;

        if (! is_array($bundle) || $bundle === []) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Import request body must be a history-export bundle or {"bundle": ...}.',
                'reason' => 'invalid_import_bundle',
            ], 422);
        }

        $importer = self::IMPORTER;
        $report = $importer::import($bundle, [
            'dry_run' => (bool) $request->boolean('dry_run'),
            'namespace' => $this->namespace($request),
            'import_id' => $this->stringInput($request, 'import_id'),
            'require_signature' => (bool) $request->boolean('require_signature'),
            'signing_key' => $this->stringInput($request, 'signing_key'),
        ]);

        return ControlPlaneProtocol::jsonForRequest(
            $request,
            $report,
            $this->statusCode($report),
        );
    }

    private function namespace(Request $request): ?string
    {
        $namespace = $request->attributes->get('namespace');

        return is_string($namespace) && $namespace !== ''
            ? $namespace
            : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function statusCode(array $report): int
    {
        return match ($report['status'] ?? null) {
            'imported' => 201,
            'already_imported', 'dry_run' => 200,
            default => 422,
        };
    }
}
