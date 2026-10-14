<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$fixture = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$observations = [];
foreach ($fixture['cases'] as $case) {
    try {
        $result = App\Support\RuntimeExternalPayloadReference::validate($case['input']);
        if (! array_key_exists('expected', $case) || $result !== $case['expected']) {
            throw new RuntimeException('Published reference outcome disagrees with the selected case '.$case['id']);
        }
        $observations[] = ['id' => $case['id'], 'outcome' => 'accepted', 'reference' => $result];
    } catch (InvalidArgumentException|TypeError $error) {
        if (array_key_exists('expected', $case)) {
            throw $error;
        }
        $observations[] = ['id' => $case['id'], 'outcome' => 'refused'];
    }
}
echo json_encode(['outcome' => 'pass', 'cases' => $observations,
    'validator_sha256' => hash_file('sha256', (new ReflectionClass(App\Support\RuntimeExternalPayloadReference::class))->getFileName()),
    'codec_contract_sha256' => hash_file('sha256', (new ReflectionClass(App\Support\PayloadCodecContract::class))->getFileName())], JSON_THROW_ON_ERROR)."\n";
