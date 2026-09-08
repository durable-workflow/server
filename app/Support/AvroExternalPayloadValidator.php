<?php

declare(strict_types=1);

namespace App\Support;

use Apache\Avro\Datum\AvroIODatumReader;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;
use Workflow\Serializers\Avro;

final class AvroExternalPayloadValidator
{
    public static function validate(ExternalPayloadStream $payload, string $field): void
    {
        $decoded = tmpfile();
        if ($decoded === false) {
            throw new ExternalPayloadStorageUnavailable('Cannot create Avro validation stream.');
        }
        try {
            $size = self::decodeBase64($payload->rewind(), $decoded);
            rewind($decoded);
            $input = new AvroStreamInput($decoded, $size);
            if ($input->read(10) !== Avro::SINGLE_OBJECT_MAGIC.Avro::VALUE_SCHEMA_FINGERPRINT) {
                throw new UnexpectedValueException('Invalid Avro Value single-object frame.');
            }
            AvroIODatumReader::skipData(
                Avro::parseSchema(Avro::valueSchemaJson()),
                new AvroValidationDecoder($input),
            );
            if (! $input->isEof()) {
                throw new UnexpectedValueException('Trailing bytes after Avro Value datum.');
            }
        } catch (ExternalPayloadStorageUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => ['The payload must contain one complete Avro Value encoded with the official codec. JSON is only the HTTP document transport.'],
            ]);
        } finally {
            fclose($decoded);
        }
    }

    /** @param resource $source
     * @param  resource  $destination
     */
    private static function decodeBase64($source, $destination): int
    {
        $buffer = '';
        $size = 0;
        while (! feof($source)) {
            $chunk = fread($source, 8192);
            if ($chunk === false || ($chunk === '' && ! feof($source))) {
                throw new ExternalPayloadStorageUnavailable('Cannot read Avro validation source.');
            }
            $buffer .= str_replace(["\r", "\n", "\t", ' '], '', $chunk);
            // Keep the last quartet for the native decoder's final padding check.
            $length = intdiv(max(0, strlen($buffer) - 1), 4) * 4;
            if ($length === 0) {
                continue;
            }
            $block = substr($buffer, 0, $length);
            if (str_contains($block, '=')) {
                throw new UnexpectedValueException('Base64 padding must end the payload.');
            }
            $size += self::writeDecoded($destination, $block);
            $buffer = substr($buffer, $length);
        }

        return $size + self::writeDecoded($destination, $buffer);
    }

    /** @param resource $destination */
    private static function writeDecoded($destination, string $encoded): int
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new UnexpectedValueException('Invalid base64 Avro payload.');
        }
        if (fwrite($destination, $bytes) !== strlen($bytes)) {
            throw new ExternalPayloadStorageUnavailable('Cannot write Avro validation stream.');
        }

        return strlen($bytes);
    }
}
