<?php

declare(strict_types=1);

namespace App\Support;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIODatumReader;
use UnexpectedValueException;
use Workflow\Serializers\ValueDatumDecoder;

/** Apache schema traversal without constructing arrays/maps or copying bytes. */
final class AvroValidationDecoder extends AvroIOBinaryDecoder
{
    private readonly ValueDatumDecoder $strict;

    public function __construct(private readonly AvroStreamInput $input)
    {
        parent::__construct($input);
        $this->strict = new ValueDatumDecoder($input);
    }

    public function readLong(): int
    {
        return $this->strict->readLong();
    }

    public function skipBoolean(): void
    {
        $this->strict->readBoolean();
    }

    public function skipLong(): void
    {
        $this->strict->readLong();
    }

    public function skipDouble(): void
    {
        $this->strict->readDouble();
    }

    public function skipString(): void
    {
        $this->strict->readString();
    }

    public function skipBytes(): void
    {
        $length = $this->readLong();
        $this->input->checkLength($length);
        $this->skip($length);
    }

    public function skipArray($writers_schema, AvroIOBinaryDecoder $decoder): void
    {
        $this->skipCollection($writers_schema->items(), false);
    }

    public function skipMap($writers_schema, AvroIOBinaryDecoder $decoder): void
    {
        $this->skipCollection($writers_schema->values(), true);
    }

    private function skipCollection($schema, bool $map): void
    {
        while (($count = $this->readLong()) !== 0) {
            $blockEnd = null;
            if ($count < 0) {
                if ($count === PHP_INT_MIN) {
                    throw new UnexpectedValueException('Invalid Avro collection count.');
                }
                $count = -$count;
                $size = $this->readLong();
                $this->input->checkLength($size);
                $blockEnd = $this->input->tell() + $size;
            }
            // Apache's ordinary skip skips negative-count blocks wholesale.
            // Validation must still inspect their values and declared length.
            for ($index = 0; $index < $count; $index++) {
                if ($map) {
                    $this->skipString();
                }
                AvroIODatumReader::skipData($schema, $this);
                if ($blockEnd !== null && $this->input->tell() > $blockEnd) {
                    throw new UnexpectedValueException('Avro collection exceeds its block.');
                }
            }
            if ($blockEnd !== null && $this->input->tell() !== $blockEnd) {
                throw new UnexpectedValueException('Avro collection block size does not match.');
            }
        }
    }
}
