<?php

declare(strict_types=1);

namespace App\Support;

use Apache\Avro\AvroIO;
use UnderflowException;
use UnexpectedValueException;

/** Read-only Apache Avro IO over a caller-owned, seekable temporary stream. */
final class AvroStreamInput extends AvroIO
{
    /** @param resource $stream */
    public function __construct(private $stream, private readonly int $size) {}

    public function read($len): string
    {
        $this->checkLength($len);
        if ($len === 0) {
            return '';
        }
        $bytes = stream_get_contents($this->stream, $len);
        if ($bytes === false || strlen($bytes) !== $len) {
            throw new UnderflowException('Truncated Avro Value datum.');
        }

        return $bytes;
    }

    public function tell(): int
    {
        $position = ftell($this->stream);
        if ($position === false) {
            throw new ExternalPayloadStorageUnavailable('Cannot inspect Avro validation stream.');
        }

        return $position;
    }

    public function seek($offset, $whence = self::SEEK_SET): bool
    {
        $base = match ($whence) {
            self::SEEK_SET => 0,
            self::SEEK_CUR => $this->tell(),
            self::SEEK_END => $this->size,
            default => throw new UnexpectedValueException('Invalid Avro stream seek.'),
        };
        if (! is_int($offset) || $offset < -$base || $offset > $this->size - $base) {
            throw new UnderflowException('Avro Value extends beyond the stream.');
        }
        if (fseek($this->stream, $base + $offset) !== 0) {
            throw new ExternalPayloadStorageUnavailable('Cannot seek Avro validation stream.');
        }

        return true;
    }

    public function isEof(): bool
    {
        return $this->tell() === $this->size;
    }

    public function checkLength(mixed $length): void
    {
        if (! is_int($length) || $length < 0) {
            throw new UnexpectedValueException('Invalid Avro Value byte length.');
        }
        if ($length > $this->size - $this->tell()) {
            throw new UnderflowException('Truncated Avro Value datum.');
        }
    }
}
