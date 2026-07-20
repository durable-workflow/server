<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DurableWorkflow\Exception\TransportException;
use DurableWorkflow\Transport\Psr18Transport;
use DurableWorkflow\Version;

if ($argc < 13) {
    fwrite(
        STDERR,
        "usage: php-sdk-worker-readiness.php <autoload> <server> <namespace> <control-token> <worker-id> <worker-pid> <result-dir> <scope> <started-at> <started-epoch> <attempt> <metadata-file>\n",
    );
    exit(2);
}

[
    $script,
    $autoload,
    $server,
    $namespace,
    $controlToken,
    $workerId,
    $workerPid,
    $resultDir,
    $scope,
    $startedAt,
    $startedEpoch,
    $attempt,
    $metadataFile,
] = $argv;

require $autoload;
require __DIR__.'/php-sdk-started-contract.php';

/** @return array{status: int, payload: array<string, mixed>|null} */
function describe_worker(string $server, string $namespace, string $controlToken, string $workerId): array
{
    $headers = [
        'Accept' => 'application/json',
        'Authorization' => 'Bearer '.$controlToken,
        'X-Namespace' => $namespace,
        'X-Durable-Workflow-Control-Plane-Version' => Version::CONTROL_PLANE_PROTOCOL,
    ];
    try {
        $payload = (new Psr18Transport)->send(
            'GET',
            rtrim($server, '/').'/api/workers/'.rawurlencode($workerId),
            $headers,
        );
    } catch (TransportException $exception) {
        return [
            'status' => $exception->status ?? 0,
            'payload' => is_array($exception->response) && ! array_is_list($exception->response)
                ? $exception->response
                : null,
        ];
    }

    return [
        'status' => 200,
        'payload' => is_array($payload) && ! array_is_list($payload) ? $payload : null,
    ];
}

/**
 * @return list<string>
 */
function command_names(mixed $names): array
{
    if (! is_array($names)) {
        return [];
    }

    $normalized = array_values(array_unique(array_map('strval', $names)));
    sort($normalized, SORT_STRING);

    return $normalized;
}

/** @param array<string, mixed> $registration
 * @param  array<string, mixed>  $required
 */
function registration_has_contract(array $registration, array $required): bool
{
    if ($required['query_contracts'] === []
        && $required['signal_contracts'] === []
        && $required['update_contracts'] === []
    ) {
        return in_array(
            $required['workflow_type'],
            command_names($registration['supported_workflow_types'] ?? null),
            true,
        );
    }

    $contracts = $registration['workflow_command_contracts'] ?? null;
    $contract = is_array($contracts) ? ($contracts[$required['workflow_type']] ?? null) : null;

    return php_sdk_command_contract_matches($contract, $required);
}

/** @param array<string, mixed> $payload */
function write_json_atomically(string $path, array $payload): void
{
    $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Unable to write PHP SDK worker readiness evidence to {$path}.");
    }
}

$required = match ($scope) {
    'namespace' => [
        'workflow_type' => 'php.sdk.simple',
        'queries' => [],
        'query_contracts' => [],
        'signals' => [],
        'signal_contracts' => [],
        'updates' => [],
        'update_contracts' => [],
    ],
    'search-attributes' => [
        'workflow_type' => 'php.sdk.search-attributes',
        'queries' => [],
        'query_contracts' => [],
        'signals' => [],
        'signal_contracts' => [],
        'updates' => [],
        'update_contracts' => [],
    ],
    default => php_sdk_waiting_command_contract(),
};
$response = describe_worker($server, $namespace, $controlToken, $workerId);
$observedAt = gmdate('Y-m-d\TH:i:s\Z');
$observationFile = $resultDir.'/php-sdk-worker-'.$workerId.'.readiness-observation.json';
$previousObservation = [];
if (is_file($observationFile)) {
    $decoded = json_decode((string) file_get_contents($observationFile), true);
    $previousObservation = is_array($decoded) ? $decoded : [];
}
$lastServerObservation = [
    'observed_at' => $observedAt,
    'http_status' => $response['status'],
    'payload' => $response['payload'],
];
if ($response['status'] === 404) {
    $previousObservation['required_workflow_command_contract'] = $required;
    $previousObservation['readiness_mismatch'] = [
        'reason' => 'worker_registration_not_visible',
        'expected_http_status' => '2xx',
        'observed_http_status' => 404,
    ];
    $previousObservation['last_server_observation'] = $lastServerObservation;
    write_json_atomically($observationFile, $previousObservation);
    exit(1);
}
if ($response['status'] < 200 || $response['status'] >= 300 || ! is_array($response['payload'])) {
    $previousObservation['required_workflow_command_contract'] = $required;
    $previousObservation['readiness_mismatch'] = [
        'reason' => 'worker_readiness_http_response',
        'expected_http_status' => '2xx',
        'observed_http_status' => $response['status'],
        'public_reason' => is_array($response['payload'])
            ? ($response['payload']['reason'] ?? $response['payload']['error'] ?? $response['payload']['message'] ?? null)
            : null,
    ];
    $previousObservation['last_server_observation'] = $lastServerObservation;
    write_json_atomically($observationFile, $previousObservation);
    fwrite(STDERR, sprintf("Worker readiness lookup failed with HTTP %d.\n", $response['status']));
    exit(2);
}

$registration = $response['payload'];
$contracts = is_array($registration['workflow_command_contracts'] ?? null)
    ? $registration['workflow_command_contracts']
    : [];
$contractFree = $contracts === [];
$workflowContract = is_array($contracts[$required['workflow_type']] ?? null)
    ? $contracts[$required['workflow_type']]
    : [];
$nameOnly = command_names($workflowContract['queries'] ?? null) === $required['queries']
    && command_names($workflowContract['signals'] ?? null) === $required['signals']
    && command_names($workflowContract['updates'] ?? null) === $required['updates']
    && (($required['query_contracts'] !== [] && ($workflowContract['query_contracts'] ?? []) === [])
        || ($required['signal_contracts'] !== [] && ($workflowContract['signal_contracts'] ?? []) === [])
        || ($required['update_contracts'] !== [] && ($workflowContract['update_contracts'] ?? []) === []));
$contractMatches = registration_has_contract($registration, $required);
$observation = [
    'first_server_registration_observed_at' => $previousObservation['first_server_registration_observed_at'] ?? $observedAt,
    'last_server_registration_observed_at' => $observedAt,
    'contract_free_registration_observed' => ($previousObservation['contract_free_registration_observed'] ?? false)
        || $contractFree,
    'name_only_registration_observed' => ($previousObservation['name_only_registration_observed'] ?? false)
        || $nameOnly,
    'first_observed_workflow_command_contracts' => $previousObservation['first_observed_workflow_command_contracts']
        ?? $contracts,
    'last_observed_workflow_command_contracts' => $contracts,
    'required_workflow_command_contract' => $required,
    'readiness_mismatch' => $contractMatches ? null : [
        'reason' => 'authoritative_workflow_command_contract_mismatch',
        'workflow_type' => $required['workflow_type'],
        'expected_contract' => $required,
        'observed_contract' => $workflowContract,
    ],
    'last_server_registration' => $registration,
    'last_server_observation' => $lastServerObservation,
];
write_json_atomically($observationFile, $observation);

if (! $contractMatches) {
    exit(1);
}

$observedEpoch = microtime(true);
$readiness = [
    'probe_started_at' => $startedAt,
    'first_server_registration_observed_at' => $observation['first_server_registration_observed_at'],
    'authoritative_registration_observed_at' => $observedAt,
    'wait_ms' => max(0, (int) round(($observedEpoch - (float) $startedEpoch) * 1000)),
    'attempts' => (int) $attempt,
    'required_workflow_command_contract' => $required,
    'server_visible_workflow_command_contracts' => $contracts,
    'contract_free_registration_observed' => $observation['contract_free_registration_observed'],
    'name_only_registration_observed' => $observation['name_only_registration_observed'],
    'client_release_after_authoritative_registration' => true,
];
$metadata = [
    'worker_id' => $workerId,
    'process_id' => (int) $workerPid,
    'host' => gethostname(),
    'php_version' => PHP_VERSION,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk')
        ?: InstalledVersions::getVersion('durable-workflow/sdk'),
    'namespace' => $namespace,
    'scope' => $scope,
    'server_visible_registration' => $registration,
    'readiness' => $readiness,
];
write_json_atomically($metadataFile, $metadata);
