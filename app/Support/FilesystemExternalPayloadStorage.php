<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

class FilesystemExternalPayloadStorage implements ExternalPayloadStorageDriver
{
    public function __construct(
        private readonly string $disk,
        private readonly string $scheme,
        private readonly string $bucket,
        private readonly string $prefix = '',
    ) {}

    public function put(string $data, string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);

        $key = $this->keyFor($sha256, $codec);

        if (Storage::disk($this->disk)->put($key, $data) === false) {
            throw new RuntimeException(sprintf('Unable to write external payload [%s].', $this->uriFor($key)));
        }

        return $this->uriFor($key);
    }

    public function get(string $uri): string
    {
        $key = $this->keyFromUri($uri);

        if (! Storage::disk($this->disk)->exists($key)) {
            throw new RuntimeException(sprintf('Unable to read external payload [%s].', $uri));
        }

        return Storage::disk($this->disk)->get($key);
    }

    public function delete(string $uri): void
    {
        $key = $this->keyFromUri($uri);

        if (Storage::disk($this->disk)->exists($key)) {
            Storage::disk($this->disk)->delete($key);
        }
    }

    private function keyFor(string $sha256, string $codec): string
    {
        $codecSegment = $this->safeCodecSegment($codec);

        return $this->prefix.$codecSegment.'/'.substr($sha256, 0, 2).'/'.$sha256;
    }

    private function uriFor(string $key): string
    {
        return $this->scheme.'://'.$this->bucket.'/'.$key;
    }

    private function keyFromUri(string $uri): string
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? null) !== $this->scheme) {
            throw new InvalidArgumentException(sprintf(
                'External payload URI scheme must be [%s].',
                $this->scheme,
            ));
        }

        if (($parts['host'] ?? '') !== $this->bucket) {
            throw new InvalidArgumentException(sprintf(
                'External payload URI bucket must be [%s].',
                $this->bucket,
            ));
        }

        $key = ltrim(rawurldecode($parts['path'] ?? ''), '/');

        if ($key === '' || str_contains($key, '..') || ! str_starts_with($key, $this->prefix)) {
            throw new InvalidArgumentException('External payload URI is outside the configured storage prefix.');
        }

        return $key;
    }

    private function validateSha256(string $sha256): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $sha256)) {
            throw new InvalidArgumentException('sha256 must be a hex digest.');
        }
    }

    private function safeCodecSegment(string $codec): string
    {
        if (! preg_match('/\A[A-Za-z0-9_.-]+\z/', $codec)) {
            throw new InvalidArgumentException('Codec contains characters that are unsafe for external storage keys.');
        }

        return $codec;
    }
}
