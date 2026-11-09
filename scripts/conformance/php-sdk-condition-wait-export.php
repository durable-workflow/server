<?php

declare(strict_types=1);

// Retain the raw Native exports of runs started by the installed qualifier.
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;

try {
    if ($argc !== 1) {
        throw new RuntimeException('condition_export_usage');
    }
    $root = '/result';
    $url = (string) getenv('DW_PHP_SDK_CONFORMANCE_SERVER_URL');
    if (preg_match('/\Ahttp:\/\/[A-Za-z0-9_.-]+:8080\z/D', $url) !== 1) {
        throw new RuntimeException('condition_export_server');
    }
    require '/result/php-sdk-project/vendor/autoload.php';
    $report = json_decode((string) file_get_contents($root.'/php-condition-waits.json'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($report) || !is_string($report['namespace'] ?? null)
        || preg_match('/\Aphp-cwn-[a-f0-9]{12}\z/D', $report['namespace']) !== 1) {
        throw new RuntimeException('condition_export_namespace');
    }
    $runs = [];
    foreach ($report['cases'] ?? [] as $case) {
        foreach ($case['runs'] ?? [] as $run) {
            if (!is_string($run['run_id'] ?? null) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', $run['run_id']) !== 1
                || !is_string($run['workflow_id'] ?? null) || preg_match('/\Ac[1-5]-[a-z0-9-]+\z/D', $run['workflow_id']) !== 1
                || isset($runs[$run['run_id']])) {
                throw new RuntimeException('condition_export_run_identity');
            }
            $runs[$run['run_id']] = $run['workflow_id'];
        }
    }
    if (count($runs) !== 9 || array_keys($runs) !== ($report['run_ids'] ?? null)) {
        throw new RuntimeException('condition_export_inventory');
    }
    $client = new Client($url, namespace: $report['namespace'], controlToken: (string) getenv('DW_PHP_SDK_CONFORMANCE_TOKEN'));
    foreach ($runs as $runId => $workflowId) {
        $export = $client->exportWorkflowHistory($workflowId, $runId);
        $handle = fopen($root.'/php-condition-wait-export-'.$runId.'.json', 'xb');
        if ($handle === false) {
            throw new RuntimeException('condition_export_output_exists');
        }
        $bytes = json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('condition_export_short_write');
        }
        fclose($handle);
    }
    echo json_encode(['exports' => count($runs), 'outcome' => 'pass'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $message = 'Condition export failed: '.get_class($error);
    if ($error instanceof ServerException) {
        $message .= ' status='.$error->status;
        if (preg_match('/\A[a-z0-9_]{1,80}\z/D', (string) $error->reason) === 1) {
            $message .= ' reason='.$error->reason;
        }
    } elseif (preg_match('/\Acondition_export_[a-z_]{1,60}\z/D', $error->getMessage()) === 1) {
        $message .= ' reason='.$error->getMessage();
    }
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
