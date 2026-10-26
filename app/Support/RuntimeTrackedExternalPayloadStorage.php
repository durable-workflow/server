<?php

namespace App\Support;

use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Support\ExternalPayloads;

class RuntimeTrackedExternalPayloadStorage implements RuntimeExternalPayloadStorageDriver
{
    public function __construct(
        private readonly string $namespace,
        private readonly RuntimeExternalPayloadStorageDriver $inner,
    ) {}

    public function put(string $data, string $sha256, string $codec): string
    {
        $claims = app(RuntimeExternalPayloadWriteClaims::class);
        $uri = $claims->prepare(fn (): string => app(RuntimeExternalPayloadRegistry::class)->storeRetained(
            $this->namespace,
            $this->inner,
            $data,
            $codec,
            strtolower($sha256),
        ));
        $claims->verified($this->namespace, $uri, strlen($data), strtolower($sha256));

        return $uri;
    }

    public function uriFor(string $sha256, string $codec): string
    {
        return $this->inner->uriFor($sha256, $codec);
    }

    /** @param array{codec: string, external_storage: array<string, mixed>} $envelope */
    public function retainEnvelope(array $envelope, ?string $validationField = null): string
    {
        $registry = app(RuntimeExternalPayloadRegistry::class);
        $reference = $registry->referenceForInternal($this->namespace, $envelope['external_storage']);
        if ($reference['codec'] !== PayloadCodecContract::canonicalize($envelope['codec'])) {
            throw new RuntimeExternalPayloadException(
                'external_payload_integrity_mismatch', 422, false,
                'External payload envelope codec does not match its registered reference.',
            );
        }

        $result = $registry->fetch($this->namespace, $reference, stream: true);
        try {
            if ($validationField !== null) {
                AvroExternalPayloadValidator::validate($result['data'], $validationField);
            }
            app(RuntimeExternalPayloadWriteClaims::class)->verifyInput(
                $this->namespace, $envelope['external_storage']['uri'], $result['data'],
            );

            return ExternalPayloads::encodeStoredEnvelope($envelope);
        } finally {
            $result['data']->close();
        }
    }

    public function get(string $uri): string
    {
        $data = $this->fetch($uri);

        app(RuntimeExternalPayloadWriteClaims::class)->verifyInput($this->namespace, $uri, $data);

        return $data;
    }

    /**
     * Fetches and verifies the bytes behind $uri without claiming it. Claim
     * it with claimVerified() in the transaction that records the reference.
     *
     * @return array{size_bytes: int, sha256: string}
     */
    public function verify(string $uri): array
    {
        return app(RuntimeExternalPayloadWriteClaims::class)->prepare(fn (): array => app(RuntimeExternalPayloadRegistry::class)->verifyFetchedBytes(
            $this->namespace,
            $uri,
            $this->fetch($uri),
        ));
    }

    public function claimVerified(string $uri, int $sizeBytes, string $sha256): void
    {
        app(RuntimeExternalPayloadRegistry::class)->claimVerified($this->namespace, $uri, $sizeBytes, $sha256);
    }

    public function claimRegistered(string $uri): void
    {
        app(RuntimeExternalPayloadRegistry::class)->claimRegistered($this->namespace, $uri);
    }

    /**
     * Deletes $uri unless `$isReferenced`, asked under the object's lock,
     * finds a reference to it. Returns false when one kept it.
     *
     * @param  callable(): bool  $isReferenced
     */
    public function deleteUnlessReferenced(string $uri, callable $isReferenced): bool
    {
        return app(RuntimeExternalPayloadRegistry::class)->deleteUriUnlessReferenced($this->namespace, $uri, $this->inner, $isReferenced);
    }

    private function fetch(string $uri): string
    {
        try {
            return $this->inner->get($uri);
        } catch (ExternalPayloadObjectOversized $exception) {
            throw new RuntimeExternalPayloadException(
                'external_payload_oversized',
                413,
                false,
                'External payload exceeds the runtime transport size limit.',
                $exception,
            );
        } catch (ExternalPayloadObjectMissing|ExternalPayloadIntegrityException $exception) {
            throw new RuntimeExternalPayloadException(
                'external_payload_not_found',
                404,
                false,
                'External payload bytes were not found.',
                $exception,
            );
        } catch (ExternalPayloadStorageUnavailable $exception) {
            throw new RuntimeExternalPayloadException(
                'external_payload_unavailable',
                503,
                true,
                'External payload storage is temporarily unavailable.',
                $exception,
            );
        }
    }

    public function delete(string $uri): void
    {
        app(RuntimeExternalPayloadRegistry::class)->deleteUri($this->namespace, $uri, $this->inner);
    }
}
