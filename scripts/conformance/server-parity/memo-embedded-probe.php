<?php

declare(strict_types=1);

require __DIR__.'/corrected-marker-probe.php';
[$script, $root, $workflowId, $fixturePath, $output] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$spec = $fixture['memo_replay'];
$directory = dirname($output);
$deadline = microtime(true) + $spec['overall_budget_seconds'];
$state = ['fixture_sha256' => hash_file('sha256', $fixturePath), 'phase' => 'start', 'outcome' => 'pending'];
$runId = '';
try {
    foreach ($spec['phase_order'] as $phase) {
        $state['phase'] = $phase;
        $snapshot = correctedMarkerPhase([PHP_BINARY, __DIR__.'/memo-embedded.php', $root, $phase, $workflowId,
            $runId, $fixturePath], $directory, $workflowId, $phase,
            min($deadline, microtime(true) + $spec['phase_budget_seconds']));
        $runId = $snapshot['run_id'];
        $state['phases'][$phase] = $snapshot;
        file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type' => $error::class, 'message' => $error->getMessage()];
} finally {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
}
exit($state['outcome'] === 'observed' ? 0 : 1);
