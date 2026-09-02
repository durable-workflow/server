<?php

namespace App\Support;

use InvalidArgumentException;

final class RuntimeExternalPayloadReference
{
    public const SCHEMA = 'durable-workflow.v2.runtime-external-payload-reference.v1';

    public const TRANSPORT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $input
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    public static function validate(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);

        if ($keys !== ['codec', 'reference_id', 'schema', 'sha256', 'size_bytes']) {
            throw new InvalidArgumentException('Runtime external payload references must contain exactly schema, reference_id, codec, size_bytes, and sha256.');
        }

        if (($input['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('Unsupported runtime external payload reference schema.');
        }

        $referenceId = $input['reference_id'] ?? null;
        if (! is_string($referenceId) || preg_match('/\Aep_[0-9A-HJKMNP-TV-Z]{26}\z/', $referenceId) !== 1) {
            throw new InvalidArgumentException('Runtime external payload reference_id is malformed.');
        }

        $codec = PayloadCodecContract::canonicalize($input['codec'] ?? null);
        $sizeBytes = $input['size_bytes'] ?? null;
        if (! is_int($sizeBytes) || $sizeBytes < 0) {
            throw new InvalidArgumentException('Runtime external payload size_bytes must be a non-negative integer.');
        }

        $sha256 = $input['sha256'] ?? null;
        if (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/i', $sha256) !== 1) {
            throw new InvalidArgumentException('Runtime external payload sha256 must be a hexadecimal SHA-256 digest.');
        }

        return [
            'schema' => self::SCHEMA,
            'reference_id' => $referenceId,
            'codec' => $codec,
            'size_bytes' => $sizeBytes,
            'sha256' => strtolower($sha256),
        ];
    }

    /** @return array<string, mixed> */
    public static function transportManifest(): array
    {
        $maxBytes = max(1, (int) config('server.external_payload_transport.max_payload_bytes'));

        return [
            'schema' => 'durable-workflow.v2.runtime-external-payload-transport.v1',
            'version' => self::TRANSPORT_VERSION,
            'reference_schema' => self::SCHEMA,
            'mode' => 'authenticated_namespace_runtime',
            'upload' => [
                'method' => 'POST',
                'path' => '/api/external-payloads/v1',
                'content_type' => 'application/octet-stream',
                'declared_metadata_headers' => [
                    'X-Durable-Workflow-Payload-Codec',
                    'X-Durable-Workflow-Payload-Size',
                    'X-Durable-Workflow-Payload-SHA256',
                ],
                'idempotency' => 'content_addressed_per_namespace',
            ],
            'fetch' => [
                'method' => 'GET',
                'path_template' => '/api/external-payloads/v1/{referenceId}',
                'content_type' => 'application/octet-stream',
                'cache_control' => 'immutable, max-age=60, private',
            ],
            'authorization' => [
                'authentication' => 'normal_runtime_role_credential',
                'namespace_header' => 'X-Namespace',
                'roles' => ['worker', 'operator', 'admin'],
                'cross_namespace_lookup' => 'not_found_without_existence_disclosure',
                'provider_credentials_exposed' => false,
            ],
            'limits' => [
                'max_payload_bytes' => $maxBytes,
                'buffering' => 'bounded_by_declared_and_observed_size',
                'request_timeout_seconds' => max(1, (int) config('server.external_payload_transport.request_timeout_seconds')),
                'abandoned_upload_expiry_seconds' => max(1, (int) config('server.external_payload_transport.abandoned_upload_expiry_seconds')),
            ],
            'integrity' => [
                'algorithm' => 'sha256',
                'upload_verified_before_reference_commit' => true,
                'fetch_verified_before_response' => true,
                'sdk_must_verify_before_decode' => true,
            ],
            'retention' => [
                'authority' => 'namespace_runtime',
                'client_delete_supported' => false,
                'sdk_cache_may_delete_backing_object' => false,
                'reference_claim' => 'first_verified_state-bearing_request',
            ],
            'direct_provider_adapters' => [
                'required' => false,
                'default_enabled' => false,
                'self_hosted_only' => true,
                'capability_negotiated' => true,
            ],
            'typed_outcomes' => [
                'external_payload_not_found' => ['status' => 404, 'retryable' => false],
                'external_payload_expired' => ['status' => 410, 'retryable' => false],
                'external_payload_unauthorized' => ['statuses' => [401, 403], 'retryable' => false],
                'external_payload_unavailable' => ['status' => 503, 'retryable' => true],
                'external_payload_oversized' => ['status' => 413, 'retryable' => false],
                'external_payload_unsupported' => ['statuses' => [415, 422], 'retryable' => false],
                'external_payload_integrity_mismatch' => ['status' => 422, 'retryable' => false],
                'external_payload_namespace_bytes_exhausted' => ['status' => 429, 'retryable' => true],
                'external_payload_namespace_objects_exhausted' => ['status' => 429, 'retryable' => true],
                'external_payload_namespace_quota_unavailable' => ['status' => 503, 'retryable' => true],
            ],
            'audit_events' => [
                'external_payload.uploaded',
                'external_payload.fetched',
                'external_payload.claimed',
                'external_payload.rejected',
            ],
            'secrets_in_references_or_logs' => false,
        ];
    }
}
