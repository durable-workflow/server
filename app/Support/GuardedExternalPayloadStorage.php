<?php

namespace App\Support;

use Throwable;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

class GuardedExternalPayloadStorage implements RuntimeExternalPayloadStorageDriver
{
    public function __construct(
        private readonly RuntimeExternalPayloadStorageDriver $inner,
    ) {}

    public function uriFor(string $sha256, string $codec): string
    {
        try {
            return $this->inner->uriFor($sha256, $codec);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        try {
            return $this->inner->put($data, $sha256, $codec);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function get(string $uri): string
    {
        try {
            return $this->inner->get($uri);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function delete(string $uri): void
    {
        try {
            $this->inner->delete($uri);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }
}
