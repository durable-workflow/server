<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneProtocol;
use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadException;
use App\Support\RuntimeExternalPayloadRegistry;
use App\Support\WorkerProtocol;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RuntimeExternalPayloadTransport
{
    public const ATTRIBUTE_CLAIMED = 'runtime_external_payload.claimed';

    /** @var array<string, list<list<string>>> */
    private const INCOMING_ENVELOPE_PATHS = [
        'ActivityController@start' => [['input']],
        'ActivityTaskController@complete' => [['result']],
        'ActivityTaskController@fail' => [['failure', 'details']],
        'BridgeAdapterController@webhook' => [['input']],
        'MessageStreamController@append' => [['input']],
        'ScheduleController@store' => [['action', 'input']],
        'ScheduleController@update' => [['action', 'input']],
        'ServiceCatalogController@executeOperation' => [['arguments']],
        'WorkerController@completeQueryTask' => [['result_envelope']],
        'WorkerController@completeWorkflowTask' => [
            ['commands', '*', 'arguments'],
            ['commands', '*', 'entries'],
            ['commands', '*', 'exception', 'details'],
            ['commands', '*', 'result'],
        ],
        'WorkflowController@query' => [['input']],
        'WorkflowController@queryRun' => [['input']],
        'WorkflowController@signal' => [['input']],
        'WorkflowController@signalRun' => [['input']],
        'WorkflowController@start' => [['input']],
        'WorkflowController@update' => [['input']],
        'WorkflowController@updateRun' => [['input']],
    ];

    /** @var array<string, list<list<string>>> */
    private const INCOMING_REFERENCE_PATHS = [
        'WorkerController@completeWorkflowTask' => [
            ['commands', '*', 'workflow_stream', 'items', '*', 'payload_reference'],
        ],
        'WorkflowStreamController@append' => [['items', '*', 'payload_reference']],
    ];

    /** @var array<string, list<list<string>>> */
    private const OUTGOING_ENVELOPE_PATHS = [
        'ActivityController@show' => [['result']],
        'ActivityTaskController@poll' => [['task', 'arguments']],
        'ScheduleController@index' => [['schedules', '*', 'action', 'input']],
        'ScheduleController@show' => [['action', 'input']],
        'HistoryController@show' => [
            ['events', '*', 'payload', 'activity', 'arguments'],
            ['events', '*', 'payload', 'activity', 'result'],
            ['events', '*', 'payload', 'arguments'],
            ['events', '*', 'payload', 'command', 'payload'],
            ['events', '*', 'payload', 'exception', 'details'],
            ['events', '*', 'payload', 'output'],
            ['events', '*', 'payload', 'result'],
        ],
        'WorkerController@pollQueryTasks' => [
            ['task', 'query_arguments'],
            ['task', 'workflow_arguments'],
            ['task', 'history_events', '*', 'payload', 'activity', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'activity', 'result'],
            ['task', 'history_events', '*', 'payload', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'command', 'payload'],
            ['task', 'history_events', '*', 'payload', 'exception', 'details'],
            ['task', 'history_events', '*', 'payload', 'output'],
            ['task', 'history_events', '*', 'payload', 'result'],
        ],
        'WorkerController@pollWorkflowTasks' => [
            ['task', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'activity', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'activity', 'result'],
            ['task', 'history_events', '*', 'payload', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'command', 'payload'],
            ['task', 'history_events', '*', 'payload', 'exception', 'details'],
            ['task', 'history_events', '*', 'payload', 'output'],
            ['task', 'history_events', '*', 'payload', 'result'],
            ['task', 'signal_arguments'],
            ['task', 'update_arguments'],
            ['task', 'workflow_arguments'],
        ],
        'WorkerController@pollUpdateValidationTasks' => [
            ['task', 'history_events', '*', 'payload', 'activity', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'activity', 'result'],
            ['task', 'history_events', '*', 'payload', 'arguments'],
            ['task', 'history_events', '*', 'payload', 'command', 'payload'],
            ['task', 'history_events', '*', 'payload', 'exception', 'details'],
            ['task', 'history_events', '*', 'payload', 'output'],
            ['task', 'history_events', '*', 'payload', 'result'],
            ['task', 'update_arguments'],
            ['task', 'workflow_arguments'],
        ],
        'WorkerController@workflowTaskHistory' => [
            ['history_events', '*', 'payload', 'activity', 'arguments'],
            ['history_events', '*', 'payload', 'activity', 'result'],
            ['history_events', '*', 'payload', 'arguments'],
            ['history_events', '*', 'payload', 'command', 'payload'],
            ['history_events', '*', 'payload', 'exception', 'details'],
            ['history_events', '*', 'payload', 'output'],
            ['history_events', '*', 'payload', 'result'],
        ],
        'WorkflowController@query' => [['result_envelope']],
        'WorkflowController@queryRun' => [['result_envelope']],
        'WorkflowController@show' => [['input_envelope'], ['output_envelope']],
        'WorkflowController@showRun' => [['input_envelope'], ['output_envelope']],
        'WorkflowController@update' => [['result_envelope']],
        'WorkflowController@updateRun' => [['result_envelope']],
    ];

    /** @var array<string, list<list<string>>> */
    private const OUTGOING_REFERENCE_PATHS = [
        'ServiceCatalogController@executeOperation' => [
            ['failure_payload_reference'],
            ['input_payload_reference'],
            ['output_payload_reference'],
        ],
        'ServiceCatalogController@serviceCallShow' => [
            ['failure_payload_reference'],
            ['input_payload_reference'],
            ['output_payload_reference'],
        ],
        'ServiceCatalogController@serviceCallCancel' => [
            ['failure_payload_reference'],
            ['input_payload_reference'],
            ['output_payload_reference'],
        ],
        'WorkflowStreamController@items' => [['items', '*', 'payload_reference']],
    ];

    /** @var list<list<string>> */
    private const HISTORY_EXPORT_ENVELOPE_PATHS = [
        ['activities', '*', 'arguments'],
        ['activities', '*', 'result'],
        ['commands', '*', 'payload'],
        ['history_events', '*', 'payload', 'activity', 'arguments'],
        ['history_events', '*', 'payload', 'activity', 'result'],
        ['history_events', '*', 'payload', 'arguments'],
        ['history_events', '*', 'payload', 'command', 'payload'],
        ['history_events', '*', 'payload', 'exception', 'details'],
        ['history_events', '*', 'payload', 'output'],
        ['history_events', '*', 'payload', 'result'],
        ['payloads', 'arguments', 'data'],
        ['payloads', 'output', 'data'],
        ['signals', '*', 'arguments'],
        ['timeline', '*', 'command', 'payload'],
        ['updates', '*', 'arguments'],
        ['updates', '*', 'result'],
    ];

    public function __construct(
        private readonly RuntimeExternalPayloadRegistry $registry,
        private readonly RuntimeExternalPayloadAudit $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $namespace = strtolower((string) $request->header(
            'X-Namespace',
            $request->query('namespace', config('server.default_namespace')),
        ));

        try {
            $response = $next($request);

            if ($request->attributes->getBoolean(self::ATTRIBUTE_CLAIMED) && $response->getStatusCode() < 400) {
                $this->audit->record($request, 'external_payload.claimed');
            }

            if ($response instanceof JsonResponse) {
                $action = $this->action($request);
                $response->setData($this->outgoing(
                    $response->getData(),
                    $namespace,
                    $this->outgoingEnvelopePaths($action),
                    self::OUTGOING_REFERENCE_PATHS[$action] ?? [],
                ));
            }

            return $response;
        } catch (RuntimeExternalPayloadException $exception) {
            $this->audit->record($request, 'external_payload.rejected', [
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ]);

            $payload = [
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ];

            return WorkerProtocol::isWorkerPlaneRequest($request)
                ? WorkerProtocol::json($payload, $exception->status)
                : ControlPlaneProtocol::jsonForRequest($request, $payload, $exception->status);
        }
    }

    public function resolveIncoming(Request $request): void
    {
        if (! $request->isJson()) {
            return;
        }

        $namespace = (string) $request->attributes->get(
            'namespace',
            config('server.default_namespace'),
        );
        $claimed = false;
        $action = $this->action($request);
        $request->replace($this->incoming(
            $request->all(),
            $namespace,
            $claimed,
            self::INCOMING_ENVELOPE_PATHS[$action] ?? [],
            self::INCOMING_REFERENCE_PATHS[$action] ?? [],
        ));

        if ($claimed) {
            $request->attributes->set(self::ATTRIBUTE_CLAIMED, true);
        }
    }

    /**
     * @param  list<list<string>>  $envelopePaths
     * @param  list<list<string>>  $referencePaths
     * @param  list<string>  $path
     */
    private function incoming(
        mixed $value,
        string $namespace,
        bool &$claimed,
        array $envelopePaths,
        array $referencePaths,
        array $path = [],
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->pathMatchesAny($path, $envelopePaths)) {
            return $this->incomingEnvelope($value, $namespace, $claimed);
        }

        foreach ($value as $key => $item) {
            $itemPath = [...$path, (string) $key];

            if ($this->pathMatchesAny($itemPath, $referencePaths) && $item !== null) {
                if (! is_array($item)) {
                    throw new RuntimeExternalPayloadException(
                        'external_payload_unsupported',
                        422,
                        false,
                        'Payload reference fields require an opaque runtime external payload reference object.',
                    );
                }

                $resolved = $this->registry->resolveAndClaim($namespace, $item);
                $claimed = true;
                $value[$key] = $resolved['external_storage']['uri'];

                continue;
            }

            $value[$key] = $this->incoming(
                $item,
                $namespace,
                $claimed,
                $envelopePaths,
                $referencePaths,
                $itemPath,
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    private function incomingEnvelope(array $value, string $namespace, bool &$claimed): array
    {
        if (array_key_exists('external_storage', $value)) {
            throw new RuntimeExternalPayloadException(
                'external_payload_unsupported',
                422,
                false,
                'Provider-specific external_storage references are not accepted by the runtime transport.',
            );
        }

        if (array_key_exists('external_payload', $value)) {
            $keys = array_keys($value);
            sort($keys);
            if ($keys !== ['codec', 'external_payload'] || ! is_array($value['external_payload'])) {
                throw new RuntimeExternalPayloadException(
                    'external_payload_unsupported',
                    422,
                    false,
                    'External payload envelopes must contain exactly codec and external_payload.',
                );
            }

            $resolved = $this->registry->resolveAndClaim($namespace, $value['external_payload']);
            $claimed = true;
            if (($value['codec'] ?? null) !== $resolved['codec']) {
                throw new RuntimeExternalPayloadException(
                    'external_payload_integrity_mismatch',
                    422,
                    false,
                    'External payload envelope codec does not match the runtime reference.',
                );
            }

            return $resolved;
        }

        return $value;
    }

    /**
     * @param  list<list<string>>  $envelopePaths
     * @param  list<list<string>>  $referencePaths
     * @param  list<string>  $path
     */
    private function outgoing(
        mixed $value,
        string $namespace,
        array $envelopePaths,
        array $referencePaths,
        array $path = [],
    ): mixed {
        if (is_object($value)) {
            return (object) $this->outgoing(
                get_object_vars($value),
                $namespace,
                $envelopePaths,
                $referencePaths,
                $path,
            );
        }

        if (! is_array($value)) {
            return $value;
        }

        if (
            $this->pathMatchesAny($path, $envelopePaths)
            &&
            isset($value['codec'], $value['external_storage'])
            && (is_array($value['external_storage']) || is_object($value['external_storage']))
        ) {
            return [
                'codec' => $value['codec'],
                'external_payload' => $this->registry->referenceForInternal(
                    $namespace,
                    (array) $value['external_storage'],
                ),
            ];
        }

        foreach ($value as $key => $item) {
            $itemPath = [...$path, (string) $key];

            if ($this->pathMatchesAny($itemPath, $referencePaths) && is_string($item) && $item !== '') {
                $value[$key] = $this->registry->referenceForUri($namespace, $item);

                continue;
            }

            $value[$key] = $this->outgoing(
                $item,
                $namespace,
                $envelopePaths,
                $referencePaths,
                $itemPath,
            );
        }

        return $value;
    }

    private function action(Request $request): string
    {
        $action = $request->route()?->getActionName();

        return is_string($action)
            ? str_replace('App\\Http\\Controllers\\Api\\', '', $action)
            : '';
    }

    /** @return list<list<string>> */
    private function outgoingEnvelopePaths(string $action): array
    {
        $paths = self::OUTGOING_ENVELOPE_PATHS[$action] ?? [];
        $historyExportPrefix = match ($action) {
            'HistoryController@export' => [],
            'WorkerController@pollQueryTasks',
            'WorkerController@pollUpdateValidationTasks',
            'WorkerController@pollWorkflowTasks' => ['task', 'history_export'],
            default => null,
        };

        if ($historyExportPrefix === null) {
            return $paths;
        }

        foreach (self::HISTORY_EXPORT_ENVELOPE_PATHS as $path) {
            $paths[] = [...$historyExportPrefix, ...$path];
        }

        return $paths;
    }

    /**
     * @param  list<string>  $path
     * @param  list<list<string>>  $patterns
     */
    private function pathMatchesAny(array $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (count($path) !== count($pattern)) {
                continue;
            }

            foreach ($pattern as $index => $segment) {
                if ($segment !== '*' && $segment !== $path[$index]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }
}
