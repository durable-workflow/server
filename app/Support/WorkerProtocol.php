<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\WorkerProtocolVersion;

class WorkerProtocol
{
    public const VERSION = WorkerProtocolVersion::VERSION;

    public const HEADER = 'X-Durable-Workflow-Protocol-Version';

    public static function requestVersion(Request $request): ?string
    {
        $version = $request->header(self::HEADER);

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version === '' ? null : $version;
    }

    public static function isWorkerPlaneRequest(Request $request): bool
    {
        return $request->is('api/worker') || $request->is('api/worker/*');
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
                'requested_version' => null,
                'remediation' => sprintf(
                    'Send the %s: %s header on worker protocol requests.',
                    self::HEADER,
                    $supported,
                ),
            ], 400);
        }

        return self::json([
            'error' => 'Unsupported worker protocol version.',
            'reason' => 'unsupported_protocol_version',
            'supported_version' => $supported,
            'requested_version' => $version,
            'remediation' => sprintf(
                'Worker requested protocol version %s; this server only supports %s. Upgrade the worker to a release that targets worker-protocol %s, or connect to a server that supports %s.',
                $version,
                $supported,
                $supported,
                $version,
            ),
        ], 400);
    }

    /**
     * @return list<string>
     */
    public static function supportedWorkflowTaskCommands(): array
    {
        return array_values(array_merge(
            WorkerProtocolVersion::terminalCommandTypes(),
            WorkerProtocolVersion::nonTerminalCommandTypes(),
        ));
    }

    /**
     * @return array{
     *     long_poll_timeout: int,
     *     supported_workflow_task_commands: list<string>,
     *     workflow_task_poll_request_idempotency: bool,
     *     history_page_size_default: int,
     *     history_page_size_max: int,
     *     query_tasks: bool,
     *     activity_retry_policy: bool,
     *     activity_timeouts: bool,
     *     child_workflow_retry_policy: bool,
     *     child_workflow_timeouts: bool,
     *     parent_close_policy: bool,
     *     non_retryable_failures: bool,
     *     response_compression: list<string>,
     *     history_compression: array{supported_encodings: list<string>, compression_threshold: int},
     *     external_task_input: array<string, mixed>,
     * }
     */
    public static function serverCapabilities(): array
    {
        return [
            'long_poll_timeout' => (int) config(
                'server.polling.timeout',
                WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
            ),
            'supported_workflow_task_commands' => self::supportedWorkflowTaskCommands(),
            'workflow_task_poll_request_idempotency' => true,
            'history_page_size_default' => (int) config(
                'server.worker_protocol.history_page_size_default',
                WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            ),
            'history_page_size_max' => (int) config(
                'server.worker_protocol.history_page_size_max',
                WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ),
            'query_tasks' => true,
            'activity_retry_policy' => true,
            'activity_timeouts' => true,
            'child_workflow_retry_policy' => true,
            'child_workflow_timeouts' => true,
            'parent_close_policy' => true,
            'non_retryable_failures' => true,
            'response_compression' => (bool) config('server.compression.enabled', true)
                ? ['gzip', 'deflate']
                : [],
            'history_compression' => [
                'supported_encodings' => WorkerProtocolVersion::supportedHistoryEncodings(),
                'compression_threshold' => WorkerProtocolVersion::COMPRESSION_THRESHOLD,
            ],
            'external_task_input' => [
                'schema' => ExternalTaskInputContract::SCHEMA,
                'version' => ExternalTaskInputContract::VERSION,
            ],
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
            'external_task_input_contract' => ExternalTaskInputContract::manifest(),
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
