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

    private const PAYLOAD_REFERENCE_FIELDS = [
        'payload_reference',
        'input_payload_reference',
        'output_payload_reference',
        'failure_payload_reference',
        'result_payload_reference',
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
                $response->setData($this->outgoing($response->getData(), $namespace));
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
        $request->replace($this->incoming($request->all(), $namespace, $claimed));

        if ($claimed) {
            $request->attributes->set(self::ATTRIBUTE_CLAIMED, true);
        }
    }

    private function incoming(mixed $value, string $namespace, bool &$claimed): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

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

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::PAYLOAD_REFERENCE_FIELDS, true) && $item !== null) {
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

            $value[$key] = $this->incoming($item, $namespace, $claimed);
        }

        return $value;
    }

    private function outgoing(mixed $value, string $namespace): mixed
    {
        if (is_object($value)) {
            return (object) $this->outgoing(get_object_vars($value), $namespace);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (
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
            if (is_string($key) && in_array($key, self::PAYLOAD_REFERENCE_FIELDS, true) && is_string($item) && $item !== '') {
                $value[$key] = $this->registry->referenceForUri($namespace, $item);

                continue;
            }

            $value[$key] = $this->outgoing($item, $namespace);
        }

        return $value;
    }
}
