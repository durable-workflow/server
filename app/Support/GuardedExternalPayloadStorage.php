<?php

namespace App\Support;

use Throwable;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

class GuardedExternalPayloadStorage implements StreamingExternalPayloadStorageDriver
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
            app(ExternalPayloadBackupHold::class)->deleting(fn () => $this->inner->delete($uri));
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function putStream($stream, string $sha256, string $codec): string
    {
        try {
            if (! $this->inner instanceof StreamingExternalPayloadStorageDriver) {
                throw new ExternalPayloadStorageUnavailable('External payload driver does not support streaming.');
            }

            return $this->inner->putStream($stream, $sha256, $codec);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function readStream(string $uri)
    {
        try {
            if (! $this->inner instanceof StreamingExternalPayloadStorageDriver) {
                throw new ExternalPayloadStorageUnavailable('External payload driver does not support streaming.');
            }

            return $this->inner->readStream($uri);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadObjectMissing|ExternalPayloadObjectOversized|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }
}
