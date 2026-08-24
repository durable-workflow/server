<?php

namespace App\Support;

use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

class RuntimeTrackedExternalPayloadStorage implements RuntimeExternalPayloadStorageDriver
{
    public function __construct(
        private readonly string $namespace,
        private readonly RuntimeExternalPayloadStorageDriver $inner,
    ) {}

    public function put(string $data, string $sha256, string $codec): string
    {
        return app(RuntimeExternalPayloadRegistry::class)->storeRetained(
            $this->namespace,
            $this->inner,
            $data,
            $codec,
            strtolower($sha256),
        );
    }

    public function uriFor(string $sha256, string $codec): string
    {
        return $this->inner->uriFor($sha256, $codec);
    }

    public function get(string $uri): string
    {
        try {
            $data = $this->inner->get($uri);
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

        app(RuntimeExternalPayloadRegistry::class)->verifyFetchedBytesAndClaim(
            $this->namespace,
            $uri,
            $data,
        );

        return $data;
    }

    public function delete(string $uri): void
    {
        app(RuntimeExternalPayloadRegistry::class)->deleteUri($this->namespace, $uri, $this->inner);
    }
}
