<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

class RuntimeTrackedExternalPayloadStorage implements ExternalPayloadStorageDriver
{
    public function __construct(
        private readonly string $namespace,
        private readonly ExternalPayloadStorageDriver $inner,
    ) {}

    public function put(string $data, string $sha256, string $codec): string
    {
        $uri = $this->inner->put($data, $sha256, $codec);

        app(RuntimeExternalPayloadRegistry::class)->trackRetained(
            $this->namespace,
            $uri,
            $codec,
            strtolower($sha256),
            strlen($data),
        );

        return $uri;
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
        $sharedOwnerExists = RuntimeExternalPayload::query()
            ->where('storage_uri_sha256', hash('sha256', $uri))
            ->where('storage_uri', $uri)
            ->where('namespace', '!=', $this->namespace)
            ->exists();

        if (! $sharedOwnerExists) {
            $this->inner->delete($uri);
        }

        app(RuntimeExternalPayloadRegistry::class)->forgetUri($this->namespace, $uri);
    }
}
