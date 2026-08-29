<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path) || filesize($path) === 0) {
    fwrite(STDERR, "operator_runtime_artifact_missing: setup must produce a non-empty runtime artifact.\n");
    exit(1);
}

try {
    $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'operator_runtime_artifact_malformed: '.$exception->getMessage()."\n");
    exit(1);
}

if (! is_array($payload)) {
    fwrite(STDERR, "operator_runtime_artifact_malformed: runtime artifact must contain a JSON object.\n");
    exit(1);
}

foreach (['namespace', 'workflow_id', 'run_id', 'worker_id', 'task_queue'] as $field) {
    if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
        fwrite(STDERR, "operator_runtime_identity_invalid: {$field} must be a non-empty string.\n");
        exit(1);
    }
    $payload[$field] = trim($payload[$field]);
}

fwrite(STDOUT, json_encode([
    'namespace' => $payload['namespace'],
    'workflow_id' => $payload['workflow_id'],
    'run_id' => $payload['run_id'],
    'worker_id' => $payload['worker_id'],
    'task_queue' => $payload['task_queue'],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
