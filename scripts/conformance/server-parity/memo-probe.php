<?php

declare(strict_types=1);

require __DIR__.'/corrected-marker-probe.php';

function memoObservation(array $fixture, array $options): array
{
    $directory = getenv('DW_PARITY_FAILURE_EVIDENCE_DIR');
    if (! is_string($directory) || ! is_dir($directory)) {
        throw new RuntimeException('Memo observation requires its owned output directory');
    }
    $mode = $options['mode'];
    $selected = match ($mode) {
        'http' => $options['url'],
        'embedded' => $options['application-root'],
        default => throw new InvalidArgumentException('Unsupported memo observation mode'),
    };
    $output = $directory.'/'.$options['workflow-id'].'-memo.json';
    $record = correctedMarkerPhase([PHP_BINARY, __DIR__.'/memo-'.$mode.'-probe.php', $selected,
        $options['workflow-id'], $options['fixture'], $output], $directory, $options['workflow-id'],
        'memo', microtime(true) + $fixture['memo_replay']['overall_budget_seconds']);
    $final = $record['phases']['replacement'];
    $final['memo_replay'] = $record;

    return $final;
}
