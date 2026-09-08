<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/** A bounded, verified snapshot; backing storage cannot change a response after verification. */
final class ExternalPayloadStream
{
    /** @param resource $stream */
    private function __construct(
        private $stream,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {}

    /** @param resource $source The caller retains ownership of the source. */
    public static function capture($source, int $maxBytes): self
    {
        if (! is_resource($source)) {
            throw new RuntimeException('External payload source is not readable.');
        }

        $snapshot = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($snapshot === false) {
            throw new RuntimeException('Unable to open external payload temporary storage.');
        }

        $limit = max(1, $maxBytes);
        $probeLimit = $limit === PHP_INT_MAX ? PHP_INT_MAX : $limit + 1;
        $size = 0;
        $hash = hash_init('sha256');
        stream_set_timeout($source, max(1, (int) config('server.external_payload_transport.request_timeout_seconds', 30)));

        try {
            while (! feof($source) && $size < $probeLimit) {
                $chunk = fread($source, min(8192, $probeLimit - $size));
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new RuntimeException('External payload stream was interrupted or timed out.');
                }

                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new ExternalPayloadObjectOversized('External payload exceeds the runtime transport size limit.');
                }

                hash_update($hash, $chunk);
                if (fwrite($snapshot, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('External payload temporary storage could not accept the bytes.');
                }
            }

            if (! rewind($snapshot)) {
                throw new RuntimeException('Unable to rewind external payload temporary storage.');
            }

            return new self($snapshot, $size, hash_final($hash));
        } catch (Throwable $exception) {
            fclose($snapshot);
            throw $exception;
        }
    }

    /** @return resource Borrowed stream: do not close or mutate it. */
    public function rewind()
    {
        if (! is_resource($this->stream) || ! rewind($this->stream)) {
            throw new RuntimeException('External payload snapshot is no longer readable.');
        }

        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
