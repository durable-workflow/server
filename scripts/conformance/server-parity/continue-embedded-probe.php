<?php

declare(strict_types=1);

require __DIR__.'/corrected-marker-probe.php';
[$script, $root, $workflowId, $fixturePath, $output] = $argv;
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$spec = $fixture['continuation'];
$deadline = microtime(true) + $spec['overall_budget_seconds'];
$state = ['fixture_sha256' => hash_file('sha256', $fixturePath), 'phase' => 'start', 'outcome' => 'pending'];
$runId = '';
try {
    foreach ($spec['phase_order'] as $phase) {
        $state['phase'] = $phase;
        $snapshot = correctedMarkerPhase([PHP_BINARY, __DIR__.'/continue-embedded.php', $root, $phase,
            $workflowId, $runId, $fixturePath], dirname($output), $workflowId, $phase,
            min($deadline, microtime(true) + $spec['phase_budget_seconds']));
        $state['phases'][$phase] = $snapshot;
        if ($phase === 'original') {
            $runId = $snapshot['run_id'];
            $state['predecessor_run_id'] = $runId;
            $state['initial'] = $snapshot['initial'];
        }
        if ($phase === 'continuation') {
            $state['inherited'] = $snapshot['inherited'];
            $state['successor_run_id'] = $state['inherited']['run_id'];
            $state['duplicate'] = $snapshot['duplicate'];
            $state['runs_before_duplicate'] = $snapshot['runs_before_duplicate'];
            $state['runs_after_duplicate'] = $snapshot['runs_after_duplicate'];
            $runId = $state['successor_run_id'];
        }
        if ($phase === 'successor') {
            $state['predecessor_final'] = $snapshot['predecessor_final'];
            $state['current_final'] = $snapshot['current_final'];
            $state['runs_final'] = $snapshot['runs_final'];
        }
        file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
    }
    $state['outcome'] = 'observed';
} catch (Throwable $error) {
    $state['outcome'] = 'failed';
    $state['error'] = ['type' => $error::class, 'message' => $error->getMessage()];
} finally {
    file_put_contents($output, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
}
echo file_get_contents($output);
exit($state['outcome'] === 'observed' ? 0 : 1);
