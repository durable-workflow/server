<?php

namespace App\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RuntimeLocalExternalPayloadStorage implements StreamingExternalPayloadStorageDriver
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false) {
            if (! mkdir($root, 0775, true) && ! is_dir($root)) {
                throw new RuntimeException(sprintf('Unable to create external payload storage root [%s].', $root));
            }

            $resolved = realpath($root);
        }

        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf(
                'External payload storage root [%s] is not a directory.',
                $root,
            ));
        }

        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        return $this->commit(
            $this->uriFor($sha256, $codec),
            $sha256,
            strlen($data),
            static fn ($output) => fwrite($output, $data),
        );
    }

    public function uriFor(string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);
        $codecSegment = $this->safeCodecSegment($codec);
        $path = $this->root.DIRECTORY_SEPARATOR.$codecSegment.DIRECTORY_SEPARATOR.substr(
            $sha256,
            0,
            2,
        ).DIRECTORY_SEPARATOR.$sha256;

        return self::pathToFileUri($path);
    }

    public function get(string $uri): string
    {
        $stream = $this->readStream($uri);

        try {
            return BoundedExternalPayloadReader::read($stream, self::maxPayloadBytes());
        } finally {
            fclose($stream);
        }
    }

    public function putStream($stream, string $sha256, string $codec): string
    {
        return $this->commit(
            $this->uriFor($sha256, $codec),
            $sha256,
            fstat($stream)['size'] ?? null,
            static fn ($output) => stream_copy_to_stream($stream, $output),
        );
    }

    private function commit(string $uri, string $sha256, ?int $expectedSize, Closure $write): string
    {
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create external payload directory.');
        }

        // The registry owns this URI before writing. A partial write is repaired
        // on retry and remains discoverable for cleanup if the request dies.
        $output = fopen($path, 'c+b');
        if ($output === false) {
            throw new RuntimeException('Unable to open external payload for writing.');
        }

        try {
            if (! flock($output, LOCK_EX)) {
                throw new RuntimeException('Unable to lock external payload bytes.');
            }

            // Never truncate an already accepted object on an idempotent retry.
            $existingHash = hash_init('sha256');
            $matches = fstat($output)['size'] === $expectedSize
                && hash_update_stream($existingHash, $output) === $expectedSize
                && hash_equals($sha256, hash_final($existingHash));

            if (! $matches && (! rewind($output) || ! ftruncate($output, 0)
                || ($written = $write($output)) === false
                || ($expectedSize !== null && $written !== $expectedSize))) {
                throw new RuntimeException('Unable to commit external payload bytes.');
            }

            // A retry may follow a failed sync even when the bytes already match.
            if (! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('Unable to commit external payload bytes.');
            }
        } finally {
            fclose($output);
        }

        return $uri;
    }

    public function readStream(string $uri)
    {
        $path = $this->pathFromUri($uri);
        if ($path === null || ! is_file($path)) {
            throw new ExternalPayloadObjectMissing('External payload object does not exist.');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open external payload object for reading.');
        }

        return $stream;
    }

    public function delete(string $uri): void
    {
        $path = $this->pathFromUri($uri, missingIsAbsent: true);

        if ($path === null) {
            return;
        }

        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Unable to delete external payload object.');
        }
    }

    private function pathFromUri(string $uri, bool $missingIsAbsent = false): ?string
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? null) !== 'file') {
            throw new InvalidArgumentException('Local external storage can only read file:// URIs.');
        }

        $host = $parts['host'] ?? '';
        if ($host !== '' && $host !== 'localhost') {
            throw new InvalidArgumentException('Local external storage can only read file://localhost URIs.');
        }

        $path = rawurldecode($parts['path'] ?? '');
        $resolved = realpath($path);
        if ($resolved === false) {
            if ($missingIsAbsent && $this->isLexicallyContained($path)) {
                return null;
            }

            $parent = realpath(dirname($path));
            if ($parent !== false && str_starts_with($parent, $this->root.DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        if ($path === '' || $resolved === false || ! str_starts_with($resolved, $this->root.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('External payload URI is outside the local storage root.');
        }

        return $resolved;
    }

    private function isLexicallyContained(string $path): bool
    {
        $prefix = $this->root.DIRECTORY_SEPARATOR;
        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        foreach (explode(DIRECTORY_SEPARATOR, substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function pathToFileUri(string $path): string
    {
        return 'file://'.implode('/', array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $path)));
    }

    private static function maxPayloadBytes(): int
    {
        return max(1, (int) config('server.external_payload_transport.max_payload_bytes'));
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
            throw new InvalidArgumentException('Codec contains characters that are unsafe for local storage paths.');
        }

        return $codec;
    }
}
