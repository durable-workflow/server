<?php

declare(strict_types=1);
require __DIR__.'/corrected-marker-probe.php';

function continueObservation(array $fixture, array $options): array
{
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($directory) || ! is_dir($directory)) {
        throw new RuntimeException('Continuation requires its owned observation directory');
    }
    $mode = $options['mode'];
    $selected = match ($mode) {
        'http' => $options['url'],
        'embedded' => $options['application-root'],
        default => throw new InvalidArgumentException('Unsupported continuation mode'),
    };
    $output = $directory.'/'.$options['workflow-id'].'-continuation.json';
    $record = correctedMarkerPhase([PHP_BINARY, __DIR__.'/continue-'.$mode.'-probe.php', $selected,
        $options['workflow-id'], $options['fixture'], $output], $directory, $options['workflow-id'],
        'continuation-observer', microtime(true) + $fixture['continuation']['overall_budget_seconds']);
    $final = $record['phases']['successor'];
    if ($mode === 'embedded') {
        require_once $options['application-root'].'/vendor/autoload.php';
        $final['input'] = \Workflow\Serializers\Serializer::unserializeWithCodec('avro', $final['execution']['arguments']);
        $final['output'] = \Workflow\Serializers\Serializer::unserializeWithCodec('avro', $final['execution']['output']);
        $final['workflow_package'] = $final['actor']['workflow_package'];
    }
    $final['continuation'] = $record;

    return $final;
}
