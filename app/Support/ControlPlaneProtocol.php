<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlPlaneProtocol
{
    public const VERSION = '2';

    public const HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    public static function requestVersion(Request $request): ?string
    {
        $version = $request->header(self::HEADER);

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version === '' ? null : $version;
    }

    public static function rejectUnsupported(Request $request): ?JsonResponse
    {
        $version = self::requestVersion($request);

        if ($version === self::VERSION) {
            return null;
        }

        if ($version === null) {
            return self::jsonForRequest($request, [
                'message' => 'Missing control-plane version header.',
                'reason' => 'missing_control_plane_version',
                'supported_version' => self::VERSION,
                'requested_version' => null,
                'remediation' => sprintf(
                    'Send the %s: %s header on control-plane requests.',
                    self::HEADER,
                    self::VERSION,
                ),
            ], 400);
        }

        return self::jsonForRequest($request, [
            'message' => 'Unsupported control-plane version.',
            'reason' => 'unsupported_control_plane_version',
            'supported_version' => self::VERSION,
            'requested_version' => $version,
            'remediation' => sprintf(
                'Client requested control-plane version %s; this server only supports %s. Upgrade the client to a release that targets control-plane %s, or connect to a server that supports %s.',
                $version,
                self::VERSION,
                self::VERSION,
                $version,
            ),
        ], 400);
    }

    public static function jsonForRequest(Request $request, array $payload, int $status = 200): JsonResponse
    {
        $operation = ControlPlaneOperation::fromRequest($request);

        if ($operation instanceof ControlPlaneOperation) {
            $payload = $operation->attach($payload);
        }

        return self::json($payload, $status);
    }

    public static function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header(self::HEADER, self::VERSION);
    }

    /**
     * @return array{
     *     version: string,
     *     header: string,
     *     response_contract: array<string, mixed>,
     *     request_contract: array<string, mixed>,
     * }
     */
    public static function info(): array
    {
        return [
            'version' => self::VERSION,
            'header' => self::HEADER,
            'response_contract' => ControlPlaneResponseContract::manifest(),
            'request_contract' => ControlPlaneRequestContract::manifest(),
        ];
    }
}
