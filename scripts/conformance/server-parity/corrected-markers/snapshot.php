<?php

declare(strict_types=1);

require getenv('DW_PARITY_SDK_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/observation-values.php';
[$script, $url, $workflowId, $runId] = $argv;
$client = new \DurableWorkflow\Client($url, namespace: 'default', token: getenv('DW_PARITY_TOKEN') ?: null);
$run = $client->describeWorkflow($workflowId, $runId);
echo json_encode(['workflow_id' => $workflowId, 'run_id' => $run->runId,
    'workflow_type' => $run->workflowType, 'namespace' => $run->namespace, 'task_queue' => $run->taskQueue,
    'status' => $run->status, 'payload_codec' => $run->raw['payload_codec'], 'execution' => $run->raw,
    'input' => $run->input, 'output' => $run->output,
    'typed_input' => typedValue($run->input), 'typed_output' => typedValue($run->output),
    'events' => decodeHistory(httpHistory($client, $workflowId, $runId), $client->payloadCodec()->decodeEnvelope(...))],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
