<?php

declare(strict_types=1);

use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;

require '/app/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function tagged(array $value): mixed
{
    return match ($value['type']) {
        'null' => null,
        'boolean' => $value['value'],
        'long' => (int) $value['value'],
        'double' => (float) $value['value'],
        'bytes' => AvroBinaryValue::fromBytes(base64_decode($value['base64'], true)),
        'string' => $value['value'],
        'array' => array_map(tagged(...), $value['items']),
        'map' => AvroMapValue::fromPairs(array_map(
            static fn (array $entry): array => [$entry['key'], tagged($entry['value'])],
            $value['entries'],
        )),
        default => throw new RuntimeException('Unknown tagged fixture value.'),
    };
}

function sameValue(mixed $expected, mixed $actual): bool
{
    if ($expected instanceof AvroBinaryValue) {
        return $actual instanceof AvroBinaryValue && $expected->bytes === $actual->bytes;
    }
    if ($expected instanceof AvroMapValue) {
        $pairs = $actual instanceof AvroMapValue ? $actual->pairs
            : (is_array($actual) && ! array_is_list($actual)
                ? array_map(null, array_keys($actual), array_values($actual)) : null);
        if ($pairs === null || count($pairs) !== count($expected->pairs)) {
            return false;
        }
        foreach ($expected->pairs as [$key, $value]) {
            $matches = array_values(array_filter($pairs, static fn (array $pair): bool => $pair[0] === $key));
            if (count($matches) !== 1 || ! sameValue($value, $matches[0][1])) {
                return false;
            }
        }

        return true;
    }
    if (is_array($expected)) {
        if (! is_array($actual) || ! array_is_list($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $value) {
            if (! sameValue($value, $actual[$index])) {
                return false;
            }
        }

        return true;
    }
    if (is_float($expected)) {
        return is_float($actual) && pack('d', $expected) === pack('d', $actual);
    }

    return $expected === $actual;
}

check($argc === 3, 'Usage: codec.php FIXTURE RUST_OBSERVATIONS');
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$other = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
check($fixture['fixture_schema'] === 'durable-workflow.server-parity-codec/v1', 'Invalid fixture schema.');
check($other['fixture_schema'] === $fixture['fixture_schema'], 'Invalid observation schema.');
check($fixture['protocol']['codec'] === 'avro', 'Invalid codec.');
check($fixture['protocol']['fingerprint'] === Avro::valueSchemaFingerprint(), 'Schema fingerprint differs.');
$inputs = [];
foreach ($other['observations'] as $record) {
    check(! array_key_exists($record['id'], $inputs), 'Duplicate observed id.');
    $inputs[$record['id']] = $record['blob'];
}
check(count($inputs) === count($fixture['cases']), 'Observation count differs.');
$observations = [];
foreach ($fixture['cases'] as $case) {
    $id = $case['id'];
    $expected = tagged($case['value']);
    $blob = Avro::serialize($expected);
    check(sameValue($expected, Avro::unserialize($blob)), "$id: PHP round trip differs.");
    check(array_key_exists($id, $inputs), "$id: missing observation.");
    check(sameValue($expected, Avro::unserialize($inputs[$id])), "$id: Rust/PHP cross-decode differs.");
    unset($inputs[$id]);
    if (isset($case['wire_base64'])) {
        check($blob === $case['wire_base64'], "$id: reviewed golden wire differs.");
    }
    $observations[] = ['id' => $id, 'blob' => $blob];
}
check($inputs === [], 'Unexpected observations.');
echo json_encode(['fixture_schema' => $fixture['fixture_schema'], 'observations' => $observations], JSON_THROW_ON_ERROR).PHP_EOL;
