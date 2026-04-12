<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerProtocol
{
    public const VERSION = '1';

    public const HEADER = 'X-Durable-Workflow-Protocol-Version';

    /**
     * @var list<string>
     */
    private const SUPPORTED_WORKFLOW_TASK_COMMANDS = [
        'complete_workflow',
        'fail_workflow',
        'continue_as_new',
        'schedule_activity',
        'start_timer',
        'start_child_workflow',
    ];

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
        $supported = (string) config('server.worker_protocol.version', self::VERSION);

        if ($version === $supported) {
            return null;
        }

        if ($version === null) {
            return self::json([
                'error' => 'Missing worker protocol version header.',
                'reason' => 'missing_protocol_version',
                'supported_version' => $supported,
            ], 400);
        }

        return self::json([
            'error' => 'Unsupported worker protocol version.',
            'reason' => 'unsupported_protocol_version',
            'supported_version' => $supported,
        ], 400);
    }

    /**
     * @return list<string>
     */
    public static function supportedWorkflowTaskCommands(): array
    {
        return self::SUPPORTED_WORKFLOW_TASK_COMMANDS;
    }

    /**
     * @return array{
     *     long_poll_timeout: int,
     *     supported_workflow_task_commands: list<string>,
     *     workflow_task_poll_request_idempotency: bool,
     *     history_page_size_default: int,
     *     history_page_size_max: int,
     * }
     */
    public static function serverCapabilities(): array
    {
        return [
            'long_poll_timeout' => (int) config('server.polling.timeout', 30),
            'supported_workflow_task_commands' => self::supportedWorkflowTaskCommands(),
            'workflow_task_poll_request_idempotency' => true,
            'history_page_size_default' => (int) config('server.worker_protocol.history_page_size_default', 500),
            'history_page_size_max' => (int) config('server.worker_protocol.history_page_size_max', 1000),
        ];
    }

    /**
     * @return array{
     *     version: string,
     *     server_capabilities: array{
     *         long_poll_timeout: int,
     *         supported_workflow_task_commands: list<string>,
     *         workflow_task_poll_request_idempotency: bool,
     *     },
     * }
     */
    public static function info(): array
    {
        return [
            'version' => (string) config('server.worker_protocol.version', self::VERSION),
            'server_capabilities' => self::serverCapabilities(),
        ];
    }

    public static function json(array $payload, int $status = 200): JsonResponse
    {
        $version = (string) config('server.worker_protocol.version', self::VERSION);

        $payload['protocol_version'] ??= $version;
        $payload['server_capabilities'] ??= self::serverCapabilities();

        return response()
            ->json($payload, $status)
            ->header(self::HEADER, $version);
    }
}
