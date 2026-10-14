<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$container = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($container);
$container->instance('config', new Illuminate\Config\Repository([
    'server' => ['external_payload_transport' => ['request_timeout_seconds' => 2]],
]));
$container->instance('validator', new Illuminate\Validation\Factory(
    new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en'), $container,
));
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$header = hex2bin('c301e2a33dff55802237');
$cases = [
    'null' => [$header."\x00", true],
    'false' => [$header."\x02\x00", true],
    'long_zero' => [$header."\x04\x00", true],
    'array_positive' => [$header."\x0c\x02\x04\x00\x00", true],
    'array_negative_exact' => [$header."\x0c\x01\x04\x04\x00\x00", true],
    'array_negative_short' => [$header."\x0c\x01\x02\x04\x00\x00", false],
    'array_negative_long' => [$header."\x0c\x01\x06\x04\x00\x00", false],
    'array_negative_size' => [$header."\x0c\x01\x01\x04\x00\x00", false],
    'invalid_boolean' => [$header."\x02\x02", false],
    'invalid_utf8' => [$header."\x0a\x02\xff", false],
    'nan' => [$header."\x06".pack('e', NAN), false],
    'trailing' => [$header."\x04\x00\x00", false],
    'truncated_union' => [$header, false],
];
$results = [];
foreach ($cases as $name => [$bytes, $expected]) {
    $source = tmpfile();
    fwrite($source, base64_encode($bytes));
    rewind($source);
    $snapshot = App\Support\ExternalPayloadStream::capture($source, 1024);
    try {
        App\Support\AvroExternalPayloadValidator::validate($snapshot, 'input');
        $accepted = true;
    } catch (Illuminate\Validation\ValidationException) {
        $accepted = false;
    } finally {
        $snapshot->close();
        fclose($source);
    }
    if ($accepted !== $expected) {
        throw new RuntimeException('Published external datum validation disagrees: '.$name);
    }
    $results[$name] = ['accepted' => $accepted, 'wire_base64' => base64_encode($bytes)];
}
echo json_encode(['outcome' => 'pass', 'scope' => 'external_datum_validation_only', 'cases' => $results,
    'validator_sha256' => hash_file('sha256', (new ReflectionClass(App\Support\AvroExternalPayloadValidator::class))->getFileName()),
    'decoder_sha256' => hash_file('sha256', (new ReflectionClass(App\Support\AvroValidationDecoder::class))->getFileName())], JSON_THROW_ON_ERROR)."\n";
