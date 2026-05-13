<?php

namespace App\Support;

use Throwable;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

class GuardedExternalPayloadStorage implements ExternalPayloadStorageDriver
{
    public function __construct(
        private readonly ExternalPayloadStorageDriver $inner,
    ) {}

    public function put(string $data, string $sha256, string $codec): string
    {
        try {
            return $this->inner->put($data, $sha256, $codec);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function get(string $uri): string
    {
        try {
            return $this->inner->get($uri);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function delete(string $uri): void
    {
        try {
            $this->inner->delete($uri);
        } catch (ExternalPayloadStorageUnavailable|ExternalPayloadIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ExternalPayloadStorageUnavailable($exception->getMessage(), 0, $exception);
        }
    }
}
