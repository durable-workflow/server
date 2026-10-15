<?php

declare(strict_types=1);

// The published HistoryBudget::sizeOfEvent contract counts event-type UTF-8
// bytes plus PHP's compact associative payload encoding with these flags.
// This standalone reference requires no application bootstrap or database.
$root = dirname(__DIR__, 3);
$inputs = json_decode(file_get_contents($root.'/tests/Fixtures/HistoryBudget/canonical-json-inputs.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
foreach ($inputs as $input) {
    $payload = json_decode($input['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    $encoded = json_encode($payload ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $cases[] = $input + [
        'canonical_payload' => $encoded,
        'history_size_bytes' => strlen($input['event_type']) + strlen($encoded),
    ];
}
echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
