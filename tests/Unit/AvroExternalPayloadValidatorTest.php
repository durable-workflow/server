<?php

namespace Tests\Unit;

use App\Support\AvroExternalPayloadValidator;
use App\Support\ExternalPayloadStream;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;

class AvroExternalPayloadValidatorTest extends TestCase
{
    public function test_official_values_and_whitespace_in_base64_are_validated(): void
    {
        $encoded = Avro::serialize([
            null, false, true, PHP_INT_MIN, PHP_INT_MAX, 7.0,
            AvroBinaryValue::fromBytes("\x00\xff"), 'text', "\xc3\xa9", [],
            ['nested' => ['value' => 42]],
        ]);
        $this->validate($encoded);
        $this->validate(chunk_split($encoded, 3, " \r\n\t"));
        $this->validate(rtrim($encoded, '='));
        $this->addToAssertionCount(3);
    }

    public function test_exact_limit_binary_and_large_collection_do_not_materialize_values(): void
    {
        $encoded = Avro::serialize(AvroBinaryValue::fromBytes(str_repeat('b', 48 * 1024 * 1024 - 15)));
        $this->assertSame(64 * 1024 * 1024, strlen($encoded));
        $source = tmpfile();
        fwrite($source, $encoded);
        unset($encoded);
        rewind($source);
        $snapshot = ExternalPayloadStream::capture($source, 64 * 1024 * 1024);
        fclose($source);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        try {
            AvroExternalPayloadValidator::validate($snapshot, 'result.blob');
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        } finally {
            $snapshot->close();
        }

        $this->validate(Avro::serialize(array_fill(0, 100000, null)));
    }

    public function test_negative_count_blocks_are_traversed_instead_of_skipped(): void
    {
        $this->validate($this->frame(hex2bin('0c01020000'))); // Array with one null in a sized block.
        $this->validate($this->frame(hex2bin('0e010602610000'))); // Map with key a and null value.
        $this->addToAssertionCount(2);
    }

    #[DataProvider('invalidDatums')]
    public function test_invalid_datums_are_rejected(string $hex): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($this->frame(hex2bin($hex)));
    }

    public static function invalidDatums(): array
    {
        return [
            'empty' => [''],
            'trailing value' => ['0000'],
            'invalid union' => ['10'],
            'negative union' => ['01'],
            'invalid boolean' => ['0202'],
            'truncated long' => ['0480'],
            'overflow long' => ['04ffffffffffffffffff02'],
            'overlong long' => ['048080808080808080808000'],
            'infinite double' => ['06000000000000f07f'],
            'truncated double' => ['060000'],
            'negative byte length' => ['0801'],
            'oversized byte length' => ['08feffffffffffffffff01'],
            'truncated bytes' => ['080461'],
            'invalid UTF8' => ['0a04c0af'],
            'truncated text' => ['0a0861'],
            'invalid map key' => ['0e0202ff0000'],
            'sized block invalid value' => ['0c0104020200'],
            'block too short' => ['0c01000000'],
            'block too long' => ['0c01040000'],
            'negative block size' => ['0c01010000'],
            'block count overflow' => ['0cffffffffffffffffff0100'],
        ];
    }

    #[DataProvider('invalidFraming')]
    public function test_invalid_framing_is_rejected(string $encoded): void
    {
        $this->expectException(ValidationException::class);
        $this->validate($encoded);
    }

    public static function invalidFraming(): array
    {
        $valid = base64_encode(Avro::SINGLE_OBJECT_MAGIC.Avro::VALUE_SCHEMA_FINGERPRINT."\x00");

        return [
            'invalid alphabet' => [substr_replace($valid, '!', 3, 1)],
            'bad padding' => [$valid.'='],
            'padding in middle' => [$valid.$valid],
            'padding across chunk boundary' => [str_repeat(' ', 8191).$valid.$valid],
            'one character remainder' => [rtrim($valid, '=').'aa'],
            'vertical tab' => [$valid."\x0b"],
            'empty' => [''],
            'magic' => [base64_encode('bad framing')],
            'fingerprint' => [base64_encode(Avro::SINGLE_OBJECT_MAGIC.str_repeat('x', 8)."\x00")],
        ];
    }

    private function frame(string $datum): string
    {
        return base64_encode(Avro::SINGLE_OBJECT_MAGIC.Avro::VALUE_SCHEMA_FINGERPRINT.$datum);
    }

    private function validate(string $encoded): void
    {
        $source = tmpfile();
        fwrite($source, $encoded);
        rewind($source);
        $snapshot = ExternalPayloadStream::capture($source, 64 * 1024 * 1024);
        fclose($source);
        try {
            AvroExternalPayloadValidator::validate($snapshot, 'result.blob');
        } finally {
            $snapshot->close();
        }
    }
}
