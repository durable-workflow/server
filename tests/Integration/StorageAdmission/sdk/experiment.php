<?php

declare(strict_types=1);

const STORAGE_ADMISSION_HELPERS_ONLY = true;
require '/experiment.php';

const LANGUAGES = ['php', 'python', 'rust'];

function waitFor(callable $predicate, string $message, int $seconds = 90, string $state = 'normal'): void
{
    $deadline = microtime(true) + $seconds;
    do {
        observe($state);
        clearstatcache();
        if ($predicate()) {
            return;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException($message);
}

function hold(string $state, int $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        observe($state);
        usleep(200000);
    }
}

function attempts(PDO $db): array
{
    return $db->query('SELECT id, workflow_run_id, status, attempt_number FROM activity_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function assertResults(array $runs): void
{
    foreach ($runs as $language => $run) {
        $body = call('GET', '/workflows/storage-'.$language.'/runs/'.$run);
        $value = $language.':'.str_repeat('x', 262144);
        $output = $body['output'] ?? null;
        check($body['status'] === 'completed' && is_array($output), 'Run did not complete: '.$language);
        check(($output['runtime'] ?? null) === $language
            && ($output['bytes'] ?? null) === strlen($value)
            && ($output['sha256'] ?? null) === hash('sha256', $value), 'SDK payload changed: '.$language);
        check(file_get_contents('/observation/'.$language.'.effects') === "executed\n", 'Activity re-executed: '.$language);
    }
}

try {
    $db = database();
    $phase = $argv[1] ?? '';
    if ($phase === 'prepare') {
        check((int) $db->query('SELECT COUNT(*) FROM workflow_runs')->fetchColumn() === 0, 'Use a fresh empty runtime.');
        $policy = ['enabled' => true, 'driver' => 'local', 'threshold_bytes' => 65536];
        $db->prepare('UPDATE workflow_namespaces SET external_payload_storage = ? WHERE name = ?')
            ->execute([json_encode($policy, JSON_THROW_ON_ERROR), 'default']);
        observe('normal');
        call('GET', '/ready');
        echo "SDK fixture ready; runtime payload offload threshold is 64 KiB.\n";
    } elseif ($phase === 'pressure') {
        check(! is_file('/observation/sdk-evidence.json'), 'Use a fresh SDK fixture.');
        waitFor(static fn (): bool => (int) $db->query('SELECT COUNT(*) FROM worker_registrations')->fetchColumn() === 3,
            'All three published SDK workers must register.');
        $runs = [];
        foreach (LANGUAGES as $language) {
            $runs[$language] = call('POST', '/workflows', [
                'workflow_id' => 'storage-'.$language, 'workflow_type' => 'storage.'.$language,
                'task_queue' => 'storage-'.$language, 'input' => [],
            ], false, 201)['run_id'];
        }
        waitFor(static fn (): bool => count(array_filter(LANGUAGES,
            static fn (string $language): bool => is_file('/observation/'.$language.'.effects'))) === 3,
            'All activities must execute before fencing.');
        $before = attempts($db);
        check(count($before) === 3, 'Expected exactly three original activity attempts.');
        observe('fenced');
        call('POST', '/workflows', startBody('fenced-start'), false, 503);
        file_put_contents('/observation/release-activities', 'return computed outcomes');
        waitFor(static fn (): bool => count(array_filter(LANGUAGES,
            static fn (string $language): bool => is_file('/observation/'.$language.'.returned'))) === 3,
            'All handlers must return their computed results while fenced.', 30, 'fenced');
        hold('fenced', 20);
        check(attempts($db) === $before, 'Fenced completion changed an activity attempt.');

        // Inline acknowledgements may drain; new standalone uploads must wait.
        observe('draining');
        waitFor(static function () use ($db, $runs): bool {
            $statuses = array_column(attempts($db), 'status', 'workflow_run_id');
            return ($statuses[$runs['php']] ?? null) === 'completed'
                && ($statuses[$runs['rust']] ?? null) === 'completed';
        }, 'Inline SDK completions did not drain.', 30, 'draining');
        hold('draining', 10);
        $draining = attempts($db);
        $statuses = array_column($draining, 'status', 'workflow_run_id');
        check(($statuses[$runs['python']] ?? null) === 'running', 'Python upload should still be paused while draining.');
        foreach (LANGUAGES as $language) {
            check(file_get_contents('/observation/'.$language.'.effects') === "executed\n", 'Handler was repeated during pressure.');
        }

        observe('normal');
        waitFor(static function () use ($runs): bool {
            foreach ($runs as $language => $run) {
                if (call('GET', '/workflows/storage-'.$language.'/runs/'.$run)['status'] !== 'completed') {
                    return false;
                }
            }
            return true;
        }, 'Published SDK workers did not recover.');
        assertResults($runs);
        $after = attempts($db);
        check(array_column($after, 'id') === array_column($before, 'id'), 'Recovery replaced activity attempt identities.');
        check(count($after) === 3 && array_sum(array_column($after, 'attempt_number')) === 3, 'Recovery retried an activity execution.');
        $evidence = ['runs' => $runs, 'fenced_attempts' => $before, 'draining_attempts' => $draining,
            'completed_attempts' => $after, 'durable_counts' => counts($db),
            'activity_tasks' => $db->query("SELECT id, workflow_run_id FROM workflow_tasks WHERE task_type = 'activity'")->fetchAll(PDO::FETCH_ASSOC)];
        file_put_contents('/observation/sdk-evidence.json', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        echo json_encode(['sdk_recovery' => true, 'handlers_executed' => 3, 'payload_bytes_each' => 262144,
            'separate_python_upload_paused_until_normal' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    } elseif ($phase === 'recover') {
        $before = json_decode(file_get_contents('/observation/sdk-evidence.json'), true, 512, JSON_THROW_ON_ERROR);
        observe('normal');
        assertResults($before['runs']);
        check(attempts($db) === $before['completed_attempts'], 'Attempt state changed during restart.');
        check(counts($db) === $before['durable_counts'], 'Durable rows changed during restart.');
        echo "Fresh-process SDK result verification after database/server restart passed.\n";
    } else {
        throw new RuntimeException('Expected prepare, pressure, or recover.');
    }
} catch (Throwable $error) {
    observe('fenced');
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
