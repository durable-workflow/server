<?php

namespace App\Support;

use App\Models\RuntimeExternalPayload;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Support\ExternalPayloadReference;

class RuntimeExternalPayloadRegistry
{
    public function __construct(
        private readonly RuntimeExternalPayloadObjectLock $objectLock,
        private readonly RuntimeExternalPayloadQuota $quota,
    ) {}

    /**
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    public function upload(string $namespace, string $data, string $codec, string $sha256): array
    {
        $namespace = $this->namespace($namespace);
        try {
            $codec = PayloadCodecContract::canonicalize($codec);
        } catch (InvalidArgumentException $exception) {
            throw $this->unsupported($exception->getMessage(), $exception);
        }
        $sha256 = strtolower($sha256);
        $sizeBytes = strlen($data);

        $this->assertSize($sizeBytes);

        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $data))) {
            throw $this->integrityMismatch('Declared external payload integrity metadata does not match the uploaded bytes.');
        }

        $driver = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor($namespace);
        if ($driver === null) {
            throw $this->unavailable('External payload storage is unavailable for this namespace.');
        }

        $expiresAt = now()->addSeconds(max(
            1,
            (int) config('server.external_payload_transport.abandoned_upload_expiry_seconds'),
        ));

        return $this->reference($this->store(
            namespace: $namespace,
            driver: $driver,
            data: $data,
            codec: $codec,
            sha256: $sha256,
            sizeBytes: $sizeBytes,
            retained: false,
            expiresAt: $expiresAt,
        ));
    }

    public function trackRetained(
        string $namespace,
        string $uri,
        string $codec,
        string $sha256,
        int $sizeBytes,
    ): RuntimeExternalPayload {
        try {
            $codec = PayloadCodecContract::canonicalize($codec);
        } catch (InvalidArgumentException $exception) {
            throw $this->unsupported($exception->getMessage(), $exception);
        }

        $namespace = $this->namespace($namespace);

        try {
            return $this->objectLock->transaction(
                $uri,
                fn (): RuntimeExternalPayload => $this->track(
                    $namespace,
                    $uri,
                    $codec,
                    strtolower($sha256),
                    $sizeBytes,
                    true,
                    null,
                    RuntimeExternalPayload::UPLOAD_READY,
                ),
            );
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload reference registration failed.', $exception);
        }
    }

    public function storeRetained(
        string $namespace,
        RuntimeExternalPayloadStorageDriver $driver,
        string $data,
        string $codec,
        string $sha256,
    ): string {
        try {
            $codec = PayloadCodecContract::canonicalize($codec);
        } catch (InvalidArgumentException $exception) {
            throw $this->unsupported($exception->getMessage(), $exception);
        }

        $row = $this->store(
            namespace: $this->namespace($namespace),
            driver: $driver,
            data: $data,
            codec: $codec,
            sha256: strtolower($sha256),
            sizeBytes: strlen($data),
            retained: true,
            expiresAt: now()->addSeconds(max(
                1,
                (int) config('server.external_payload_transport.abandoned_upload_expiry_seconds'),
            )),
        );

        return $row->storage_uri;
    }

    /**
     * @param  array<string, mixed>  $internalReference
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    public function referenceForInternal(string $namespace, array $internalReference): array
    {
        try {
            $reference = ExternalPayloadReference::fromArray($internalReference);
        } catch (InvalidArgumentException $exception) {
            throw $this->unsupported('Server state contains an unsupported external payload reference.', $exception);
        }

        $row = RuntimeExternalPayload::query()
            ->where('namespace', $this->namespace($namespace))
            ->where('storage_uri_sha256', hash('sha256', $reference->uri))
            ->where('storage_uri', $reference->uri)
            ->where('upload_status', RuntimeExternalPayload::UPLOAD_READY)
            ->first();

        if ($row === null) {
            throw $this->unsupported('Server state contains an unregistered external payload reference.');
        }

        if (
            $row->codec !== $reference->codec
            || $row->size_bytes !== $reference->sizeBytes
            || ! hash_equals($row->sha256, $reference->sha256)
        ) {
            throw $this->integrityMismatch('Server state contains conflicting external payload metadata.');
        }

        return $this->reference($row);
    }

    /**
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    public function referenceForUri(string $namespace, string $uri): array
    {
        $row = RuntimeExternalPayload::query()
            ->where('namespace', $this->namespace($namespace))
            ->where('storage_uri_sha256', hash('sha256', $uri))
            ->where('upload_status', RuntimeExternalPayload::UPLOAD_READY)
            ->first();

        if ($row === null || ! hash_equals($row->storage_uri, $uri)) {
            throw $this->unsupported('Server state contains an unregistered external payload reference.');
        }

        return $this->reference($row);
    }

    /**
     * @param  array<string, mixed>  $transportReference
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    public function resolveAndClaim(string $namespace, array $transportReference): array
    {
        $row = $this->rowForReference($namespace, $transportReference);

        return [
            'codec' => $row->codec,
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => $row->storage_uri,
                'sha256' => $row->sha256,
                'size_bytes' => $row->size_bytes,
                'codec' => $row->codec,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $transportReference
     * @return array{data: string, reference: array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}}
     */
    public function fetch(string $namespace, array $transportReference): array
    {
        [$row, $data] = $this->verifiedRow($namespace, $transportReference);

        return [
            'data' => $data,
            'reference' => $this->reference($row),
        ];
    }

    public function verifyFetchedBytesAndClaim(string $namespace, string $uri, string $data): void
    {
        $this->assertSize(strlen($data));
        $namespace = $this->namespace($namespace);

        try {
            $this->objectLock->transaction($uri, function () use ($namespace, $uri, $data): void {
                $row = RuntimeExternalPayload::query()
                    ->where('namespace', $namespace)
                    ->where('storage_uri_sha256', hash('sha256', $uri))
                    ->where('storage_uri', $uri)
                    ->where('upload_status', RuntimeExternalPayload::UPLOAD_READY)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    throw $this->unsupported('External payload storage returned an unregistered provider reference.');
                }

                if ($row->retained_at === null && $row->expires_at !== null && $row->expires_at->isPast()) {
                    throw new RuntimeExternalPayloadException(
                        'external_payload_expired',
                        410,
                        false,
                        'External payload reference has expired.',
                    );
                }

                if (strlen($data) !== $row->size_bytes || ! hash_equals($row->sha256, hash('sha256', $data))) {
                    throw $this->integrityMismatch('Fetched external payload bytes failed runtime integrity verification.');
                }

                $row->forceFill([
                    'retained_at' => $row->retained_at ?? now(),
                    'expires_at' => null,
                    'last_fetched_at' => now(),
                ])->save();
            });
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload reference claim failed.', $exception);
        }
    }

    public function forgetUri(string $namespace, string $uri): void
    {
        $this->objectLock->transaction($uri, function () use ($namespace, $uri): void {
            RuntimeExternalPayload::query()
                ->where('namespace', $this->namespace($namespace))
                ->where('storage_uri_sha256', hash('sha256', $uri))
                ->where('storage_uri', $uri)
                ->lockForUpdate()
                ->delete();
        });
    }

    public function deleteForNamespace(string $namespace): int
    {
        $namespace = $this->namespace($namespace);
        $rows = RuntimeExternalPayload::query()
            ->where('namespace', $namespace)
            ->get(['id', 'storage_uri']);
        $driver = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor($namespace);

        $deleted = 0;
        foreach ($rows as $row) {
            $deleted += $this->deleteRegisteredUri($namespace, $row->storage_uri, $driver);
        }

        return $deleted;
    }

    public function deleteUri(
        string $namespace,
        string $uri,
        RuntimeExternalPayloadStorageDriver $driver,
    ): void {
        $this->deleteRegisteredUri($this->namespace($namespace), $uri, $driver);
    }

    private function deleteRegisteredUri(
        string $namespace,
        string $uri,
        ?RuntimeExternalPayloadStorageDriver $driver,
    ): int {
        try {
            return $this->objectLock->transaction($uri, function () use ($namespace, $uri, $driver): int {
                $owners = RuntimeExternalPayload::query()
                    ->where('storage_uri_sha256', hash('sha256', $uri))
                    ->where('storage_uri', $uri)
                    ->lockForUpdate()
                    ->get();
                $row = $owners->firstWhere('namespace', $namespace);

                if (! $row instanceof RuntimeExternalPayload) {
                    if ($owners->isEmpty()) {
                        if ($driver === null) {
                            throw $this->unavailable('External payload storage is unavailable for namespace cleanup.');
                        }

                        try {
                            $driver->delete($uri);
                        } catch (Throwable $exception) {
                            throw $this->unavailable('External payload storage could not complete namespace cleanup.', $exception);
                        }
                    }

                    return 0;
                }

                if ($owners->count() === 1) {
                    if ($driver === null) {
                        throw $this->unavailable('External payload storage is unavailable for namespace cleanup.');
                    }

                    try {
                        $driver->delete($uri);
                    } catch (Throwable $exception) {
                        throw $this->unavailable('External payload storage could not complete namespace cleanup.', $exception);
                    }
                }

                $row->delete();

                return 1;
            });
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload registry could not complete namespace cleanup.', $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $transportReference
     * @return array{0: RuntimeExternalPayload, 1: string}
     */
    private function verifiedRow(string $namespace, array $transportReference): array
    {
        $row = $this->rowForReference($namespace, $transportReference);
        $driver = app(NamespaceExternalPayloadStorage::class)->untrackedDriverFor($row->namespace);
        if ($driver === null) {
            throw $this->unavailable('External payload storage is unavailable for this namespace.');
        }

        try {
            $data = $driver->get($row->storage_uri);
        } catch (ExternalPayloadObjectOversized $exception) {
            throw $this->oversized($exception);
        } catch (ExternalPayloadObjectMissing|ExternalPayloadIntegrityException $exception) {
            throw new RuntimeExternalPayloadException(
                'external_payload_not_found',
                404,
                false,
                'External payload bytes were not found.',
                $exception,
            );
        } catch (ExternalPayloadStorageUnavailable $exception) {
            throw $this->unavailable('External payload storage is temporarily unavailable.', $exception);
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload storage is temporarily unavailable.', $exception);
        }

        if (strlen($data) !== $row->size_bytes || ! hash_equals($row->sha256, hash('sha256', $data))) {
            throw $this->integrityMismatch('Fetched external payload bytes failed runtime integrity verification.');
        }

        $row->forceFill(['last_fetched_at' => now()])->save();

        return [$row, $data];
    }

    /** @param array<string, mixed> $transportReference */
    private function rowForReference(string $namespace, array $transportReference): RuntimeExternalPayload
    {
        try {
            $reference = RuntimeExternalPayloadReference::validate($transportReference);
        } catch (InvalidArgumentException $exception) {
            throw $this->unsupported($exception->getMessage(), $exception);
        }

        $this->assertSize($reference['size_bytes']);

        $row = RuntimeExternalPayload::query()
            ->where('id', $reference['reference_id'])
            ->where('namespace', $this->namespace($namespace))
            ->where('upload_status', RuntimeExternalPayload::UPLOAD_READY)
            ->first();

        if ($row === null) {
            throw new RuntimeExternalPayloadException(
                'external_payload_not_found',
                404,
                false,
                'External payload reference was not found in this namespace.',
            );
        }

        if ($row->retained_at === null && $row->expires_at !== null && $row->expires_at->isPast()) {
            throw new RuntimeExternalPayloadException(
                'external_payload_expired',
                410,
                false,
                'External payload reference has expired.',
            );
        }

        if (
            $row->codec !== $reference['codec']
            || $row->size_bytes !== $reference['size_bytes']
            || ! hash_equals($row->sha256, $reference['sha256'])
        ) {
            throw $this->integrityMismatch('External payload reference metadata does not match runtime state.');
        }

        $this->assertSize($row->size_bytes);

        return $row;
    }

    private function store(
        string $namespace,
        RuntimeExternalPayloadStorageDriver $driver,
        string $data,
        string $codec,
        string $sha256,
        int $sizeBytes,
        bool $retained,
        mixed $expiresAt,
    ): RuntimeExternalPayload {
        if ($sizeBytes !== strlen($data) || ! hash_equals($sha256, hash('sha256', $data))) {
            throw $this->integrityMismatch('External payload bytes do not match their registry metadata.');
        }

        try {
            $uri = $driver->uriFor($sha256, $codec);
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload storage could not prepare the uploaded object.', $exception);
        }

        try {
            $this->objectLock->transaction($uri, fn (): RuntimeExternalPayload => $this->track(
                $namespace,
                $uri,
                $codec,
                $sha256,
                $sizeBytes,
                false,
                $expiresAt,
                RuntimeExternalPayload::UPLOAD_WRITING,
            ));
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->unavailable('External payload reference registration failed before storage commit.', $exception);
        }

        try {
            return $this->objectLock->transaction($uri, function () use (
                $namespace,
                $uri,
                $driver,
                $data,
                $codec,
                $sha256,
                $sizeBytes,
                $retained,
                $expiresAt,
            ): RuntimeExternalPayload {
                $row = RuntimeExternalPayload::query()
                    ->where('namespace', $namespace)
                    ->where('storage_uri_sha256', hash('sha256', $uri))
                    ->where('storage_uri', $uri)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    throw $this->unavailable('Prepared external payload registration is no longer available.');
                }

                try {
                    $committedUri = $driver->put($data, $sha256, $codec);
                } catch (Throwable $exception) {
                    throw $this->unavailable('External payload storage could not commit the uploaded bytes.', $exception);
                }

                if (! hash_equals($uri, $committedUri)) {
                    throw $this->integrityMismatch('External payload storage did not use its prepared stable object identity.');
                }

                return $this->reconcileTrackedRow(
                    $row,
                    $uri,
                    $codec,
                    $sha256,
                    $sizeBytes,
                    $retained,
                    $expiresAt,
                    RuntimeExternalPayload::UPLOAD_READY,
                );
            });
        } catch (RuntimeExternalPayloadException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The writing row was committed before the backing write. If this
            // final registry update fails, cleanup still owns the URI and can
            // reclaim the object after its bounded upload expiry.
            throw $this->unavailable('External payload reference registration failed after storage commit.', $exception);
        }
    }

    private function track(
        string $namespace,
        string $uri,
        string $codec,
        string $sha256,
        int $sizeBytes,
        bool $retained,
        mixed $expiresAt,
        string $uploadStatus,
    ): RuntimeExternalPayload {
        if (
            $uri === ''
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
            || $sizeBytes < 0
            || ! in_array($uploadStatus, [RuntimeExternalPayload::UPLOAD_READY, RuntimeExternalPayload::UPLOAD_WRITING], true)
        ) {
            throw $this->integrityMismatch('Storage returned invalid external payload metadata.');
        }

        $this->assertSize($sizeBytes);
        $uriHash = hash('sha256', $uri);
        $row = RuntimeExternalPayload::query()
            ->where('namespace', $namespace)
            ->where('storage_uri_sha256', $uriHash)
            ->lockForUpdate()
            ->first();

        if ($row !== null) {
            return $this->reconcileTrackedRow(
                $row,
                $uri,
                $codec,
                $sha256,
                $sizeBytes,
                $retained,
                $expiresAt,
                $uploadStatus,
            );
        }

        $this->quota->admitCreate($namespace, $sizeBytes);

        return RuntimeExternalPayload::query()->create([
            'id' => 'ep_'.Str::ulid(),
            'namespace' => $namespace,
            'storage_uri' => $uri,
            'storage_uri_sha256' => $uriHash,
            'codec' => $codec,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'upload_status' => $uploadStatus,
            'retained_at' => $retained ? now() : null,
            'expires_at' => $retained ? null : $expiresAt,
        ]);
    }

    private function reconcileTrackedRow(
        RuntimeExternalPayload $row,
        string $uri,
        string $codec,
        string $sha256,
        int $sizeBytes,
        bool $retained,
        mixed $expiresAt,
        string $uploadStatus,
    ): RuntimeExternalPayload {
        if (
            ! hash_equals($row->storage_uri, $uri)
            || $row->codec !== $codec
            || $row->size_bytes !== $sizeBytes
            || ! hash_equals($row->sha256, $sha256)
        ) {
            throw $this->integrityMismatch('Stable external payload identity resolved to conflicting metadata.');
        }

        $updates = [];

        if ($uploadStatus === RuntimeExternalPayload::UPLOAD_READY
            && $row->upload_status !== RuntimeExternalPayload::UPLOAD_READY
        ) {
            $updates['upload_status'] = RuntimeExternalPayload::UPLOAD_READY;
        }

        if ($retained && $row->retained_at === null) {
            $updates['retained_at'] = now();
            $updates['expires_at'] = null;
        } elseif (! $retained && $row->retained_at === null) {
            $updates['expires_at'] = $expiresAt;
        }

        if ($updates !== []) {
            $row->forceFill($updates)->save();
        }

        return $row;
    }

    /**
     * @return array{schema: string, reference_id: string, codec: string, size_bytes: int, sha256: string}
     */
    private function reference(RuntimeExternalPayload $row): array
    {
        return [
            'schema' => RuntimeExternalPayloadReference::SCHEMA,
            'reference_id' => $row->id,
            'codec' => $row->codec,
            'size_bytes' => $row->size_bytes,
            'sha256' => $row->sha256,
        ];
    }

    private function assertSize(int $sizeBytes): void
    {
        $maxBytes = max(1, (int) config('server.external_payload_transport.max_payload_bytes'));
        if ($sizeBytes > $maxBytes) {
            throw $this->oversized();
        }
    }

    private function oversized(?Throwable $previous = null): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException(
            'external_payload_oversized',
            413,
            false,
            'External payload exceeds the runtime transport size limit.',
            $previous,
        );
    }

    private function namespace(string $namespace): string
    {
        return strtolower($namespace !== '' ? $namespace : (string) config('server.default_namespace'));
    }

    private function unsupported(string $message, ?Throwable $previous = null): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException('external_payload_unsupported', 422, false, $message, $previous);
    }

    private function integrityMismatch(string $message): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException('external_payload_integrity_mismatch', 422, false, $message);
    }

    private function unavailable(string $message, ?Throwable $previous = null): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException('external_payload_unavailable', 503, true, $message, $previous);
    }
}
