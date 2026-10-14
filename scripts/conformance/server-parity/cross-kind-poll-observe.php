<?php
declare(strict_types=1);
$root = dirname(__DIR__, 3);
$adapter = $root.'/benchmarks/capacity/v1/bindings/php/capacity_adapter.php';
require $adapter;
if (getenv('DW_PARITY_SDK_AUTOLOAD')) require getenv('DW_PARITY_SDK_AUTOLOAD');
else capacityAutoload();
if (count($argv) !== 5) throw new InvalidArgumentException('Usage: cross-kind-poll-observe.php URL WORKFLOW_PREFIX PROFILE_JSON OUTPUT_DIRECTORY');
[$script, $runtimeUrl, $workflowPrefix, $profilePath, $directory] = $argv;
if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $workflowPrefix) || !is_dir($directory)) {
    throw new InvalidArgumentException('Use a unique workflow prefix and an existing owned output directory');
}
$profile = json_decode(file_get_contents($profilePath), true, flags:JSON_THROW_ON_ERROR);
$artifacts = $profile['artifacts'];
if (Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== $artifacts['sdk_php_version']
    || Composer\InstalledVersions::getReference('durable-workflow/sdk') !== $artifacts['sdk_php_source']
    || hash_file('sha256', $adapter) !== $profile['workload']['adapter_sha256']) {
    throw new RuntimeException('Unexpected installed SDK');
}
function crossKindPollRecords(string $path): array {
    if (!is_file($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $value = json_decode($line, true);
        if (is_array($value)) $out[] = $value;
    }
    return $out;
}
function crossKindPollStopWorker($process): int {
    proc_terminate($process, SIGTERM);
    $deadline = microtime(true) + 20;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            proc_close($process);
            return $exit;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    proc_terminate($process, SIGKILL);
    proc_close($process);
    throw new RuntimeException('Managed worker shutdown deadline expired');
}
foreach (['activity', 'query'] as $kind) {
    $prefix = $directory.'/'.$workflowPrefix.'-'.$kind;
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $journal = $prefix.'.http.jsonl';
    $arrivals = $prefix.'.arrivals.jsonl';
    if (file_exists($journal) || file_exists($arrivals)) throw new RuntimeException('Use fresh output paths');
    touch($journal); touch($arrivals);
    $environment = getenv();
    $environment['DW_PARITY_HTTP_REQUEST_ARRIVALS'] = $arrivals;
    $environment['DW_PARITY_HTTP_CAPTURE_TIMING'] = '1';
    $environment['DW_PARITY_HTTP_CAPTURE_NAMESPACE'] = '1';
    $proxy = proc_open(['node', __DIR__.'/published-sdk-patch/observe-http.mjs',
        $runtimeUrl, $journal, '--forwarding-state'],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file',$prefix.'.proxy.stderr','w']], $pipes, null, $environment);
    if (!is_resource($proxy)) throw new RuntimeException('Proxy setup failed');
    fclose($pipes[0]); stream_set_blocking($pipes[1], false);
    $worker = null;
    $summary = ['kind'=>$kind, 'namespace'=>'default', 'original_budget_seconds'=>35,
        'sdk_version'=>Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source'=>Composer\InstalledVersions::getReference('durable-workflow/sdk'),
        'adapter_sha256'=>hash_file('sha256',$adapter)];
    $summary['case_started_at'] = microtime(true);
    $deadline = $summary['case_started_at'] + 35;
    try {
        $url = '';
        while (!str_contains($url, "\n") && microtime(true)<$deadline) {
            $url .= stream_get_contents($pipes[1]); usleep(10000);
        }
        $url = trim($url);
        if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $url)) throw new RuntimeException('Proxy did not bind');
        $queue = $workflowPrefix.'-'.$kind;
        $environment['DURABLE_WORKFLOW_RUNTIME_URL'] = $url;
        $environment['DURABLE_WORKFLOW_TASK_QUEUE'] = $queue;
        $environment['DURABLE_WORKFLOW_NAMESPACE'] = 'default';
        $environment['DURABLE_WORKFLOW_TOKEN'] = $token ?? '';
        $environment['DURABLE_WORKFLOW_CAPACITY_CELL'] = 'one-activity';
        $worker = proc_open([PHP_BINARY, __DIR__.'/cross-kind-poll-worker.php'],
            [0=>['pipe','r'],1=>['file',$prefix.'.worker.stdout','w'],2=>['file',$prefix.'.worker.stderr','w']],
            $workerPipes, null, $environment);
        if (!is_resource($worker)) throw new RuntimeException('Worker setup failed');
        fclose($workerPipes[0]);
        $held = null;
        do {
            $responses = array_column(crossKindPollRecords($journal), null, 'request_observation_id');
            foreach (crossKindPollRecords($arrivals) as $arrival) {
                $body = json_decode($arrival['request_body'] ?? '', true);
                if ($arrival['path'] === '/api/worker/'.$kind.'-tasks/poll'
                    && ($body['timeout_seconds'] ?? 0)>0
                    && !isset($responses[$arrival['request_observation_id']])) $held = $arrival;
            }
            if ($held !== null) break;
            if (!proc_get_status($worker)['running']) throw new RuntimeException('Worker exited before held poll');
            usleep(20000);
        } while (microtime(true)<$deadline);
        if ($held === null) throw new RuntimeException('Original held-poll barrier expired');
        usleep(50000);
        $responses = array_column(crossKindPollRecords($journal), null, 'request_observation_id');
        if (isset($responses[$held['request_observation_id']])) throw new RuntimeException('Poll ended before admission');
        $summary['held_request_id'] = $held['request_observation_id'];
        $summary['held_observed_at'] = microtime(true);
        $client = new DurableWorkflow\Client($url, namespace:'default', token:$token);
        $id = $workflowPrefix.'-'.$kind.'-'.bin2hex(random_bytes(8));
        $blob = str_repeat('s',1024);
        $input = ['blob'=>$blob,'payload_contract'=>['workflow_input_bytes'=>1024,'workflow_result_bytes'=>1024,
            'activity_input_bytes'=>1024,'activity_result_bytes'=>1024,'signal_bytes'=>0]];
        $summary['start_request_at'] = microtime(true);
        $handle = $client->startWorkflow('capacity.v1.one_activity',$id,$queue,[$input]);
        $summary['start_accepted_at'] = microtime(true);
        do {
            $execution = $handle->describe();
            if ($execution->status === 'completed') break;
            usleep(250000);
        } while (microtime(true)<$deadline);
        if ($execution->status !== 'completed' || $execution->output !== $blob) throw new RuntimeException('Original workflow output/deadline failed');
        $history = $client->workflowHistory($id,$handle->selectedRunId);
        $events = array_column($history['events'] ?? [],'event_type');
        $expected = ['WorkflowStarted','ActivityScheduled','ActivityStarted','ActivityCompleted','WorkflowCompleted'];
        if (array_values(array_intersect($events,$expected)) !== $expected) throw new RuntimeException('Semantic event order changed');
        $summary += ['workflow_id'=>$id,'run_id'=>$handle->selectedRunId,'closed_at'=>$execution->closedAt,
            'history_events'=>$events,'output_bytes'=>strlen($execution->output),'output_sha256'=>hash('sha256',$execution->output)];
        $summary['history_times'] = [];
        foreach ($history['events'] as $event) $summary['history_times'][$event['event_type']] = $event['timestamp'];
        $summary['observed_at'] = microtime(true);
        if ($summary['observed_at'] > $deadline) throw new RuntimeException('Original observation budget expired');
        $summary['outcome'] = 'observed';
    } catch (Throwable $error) {
        $summary['outcome'] = 'fail'; $summary['error'] = $error::class.': '.$error->getMessage();
    } finally {
        if (is_resource($worker)) {
            try { $summary['worker_exit'] = crossKindPollStopWorker($worker); }
            catch(Throwable $error) { $summary['shutdown_error'] = $error->getMessage(); }
        }
        $summary['polls'] = [];
        foreach (crossKindPollRecords($journal) as $record) {
            if ($record['path'] === '/api/worker/register') {
                $registration = json_decode($record['request_body'],true);
                $summary['cross_kind_capability'] = in_array('cross_kind_poll_wake',$registration['capabilities']??[],true);
                $summary['worker_id'] = $registration['worker_id']??null;
                $summary['task_queue'] = $registration['task_queue']??null;
            }
            if (!str_ends_with($record['path'],'/poll')) continue;
            $body = json_decode($record['request_body'],true);
            $reply = json_decode($record['response_body'],true);
            $summary['polls'][] = ['request_id'=>$record['request_observation_id'], 'path'=>$record['path'],
                'requested_seconds'=>$body['timeout_seconds']??null,'started_at'=>$record['request_started_at']??null,
                'responded_at'=>$record['response_observed_at']??null,'http'=>$record['status'],
                'poll_status'=>$reply['poll_status']??null,'task_id'=>$reply['task']['task_id']??null,
                'workflow_task_attempt'=>$reply['task']['workflow_task_attempt']??null,
                'task_is_null'=>is_array($reply)&&array_key_exists('task',$reply)&&$reply['task']===null,
                'worker_id'=>$body['worker_id']??null,'task_queue'=>$body['task_queue']??null,
                'namespace_header'=>$record['namespace']??null];
        }
        proc_terminate($proxy, SIGTERM); fclose($pipes[1]); proc_close($proxy);
        file_put_contents($prefix.'.summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        echo json_encode($summary,JSON_THROW_ON_ERROR)."\n";
    }
    if (($summary['outcome']??null)!=='observed' || ($summary['worker_exit']??null)!==0) exit(1);
}
