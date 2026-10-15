<?php

declare(strict_types=1);

// PHP's signed 64-bit JSON integers preserve the fixture's value above 2^53.
function checkExternalConsumers(array $records, array $artifacts): array
{
    $require = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $require(PHP_INT_SIZE === 8, 'Use a 64-bit reader');
    $number = 9007199254740993;
    $textBytes = 3 * 1024 * 1024;
    $summary = static fn (string $letter): array => ['text_bytes' => $textBytes,
        'text_sha256' => hash('sha256', str_repeat($letter, $textBytes)),
        'number' => $number, 'optional' => null, 'unicode' => 'rangeλ'];
    $joinedRuns = [];
    foreach (['php', 'native'] as $target) {
        $data = $records[$target];
        foreach ($data as $label => $record) {
            $require(($record['outcome'] ?? null) === 'pass', "$target/$label did not pass");
            $require(is_string($record['run_id'] ?? null) && $record['run_id'] !== '', "$target/$label lost its run");
            if (isset($record['sdk_version'])) {
                $require($record['sdk_version'] === $artifacts['sdk_php_version'], "$target/$label SDK version");
            }
            if (isset($record['sdk_source'])) {
                $require($record['sdk_source'] === $artifacts['sdk_php_source'], "$target/$label SDK source");
            }
            if (isset($record['original_budget_seconds'])) {
                $require($record['original_budget_seconds'] === 30 && $record['elapsed_seconds'] >= 0
                    && $record['elapsed_seconds'] < 30, "$target/$label original budget");
            }
        }
        $input = $data['input'];
        $require($input['scope'] === 'actual_installed_worker_external_input'
            && $input['workflow_invocations'] === 1 && $input['workflow_completions'] === 1
            && $input['sdk_payload_gets'] >= 1, "$target input execution");
        $require($input['sdk_version'] === $artifacts['sdk_php_version']
            && $input['sdk_source'] === $artifacts['sdk_php_source']
            && $input['sdk_lock_sha256'] === $artifacts['sdk_php_lock_sha256'], "$target installed input SDK");
        $require($input['result'] === ['bytes' => $textBytes, 'sha256' => $summary('x')['text_sha256'],
            'number' => $number, 'optional' => null, 'unicode' => 'rangeλ'], "$target typed input result");
        $runs = ['input' => $input['run_id']];
        foreach (['workflow', 'activity', 'arguments-1', 'arguments-2'] as $case) {
            $author = $data[$case.'-author'];
            $reader = $data[$case.'-reader'];
            $require($author['run_id'] === $reader['run_id'], "$target/$case original successor");
            $require($author['original_deadline'] - $author['started_at'] === 30.0, "$target/$case author deadline");
            $require($reader['original_budget_seconds'] === 30 && $reader['elapsed_seconds'] >= 0
                && $reader['elapsed_seconds'] < 30 && $reader['history_unchanged'] === true, "$target/$case cold read budget/history");
            $require($reader['encoded_bytes'] === $author['encoded_bytes']
                && $reader['encoded_sha256'] === $author['encoded_sha256'], "$target/$case encoded identity");
            $require($reader['sdk_version'] === $artifacts['sdk_php_version'], "$target/$case cold SDK");
            if (str_starts_with($case, 'arguments-')) {
                $count = (int) substr($case, -1);
                $activity = $data[$case.'-activity'];
                $require($activity['run_id'] === $author['run_id'] && $activity['activity_invocations'] === $count
                    && $activity['activity_completions'] === $count && $activity['sdk_payload_gets'] >= $count,
                    "$target/$case actual activities");
                $expected = $count === 1 ? $summary('x') : [$summary('x'), $summary('y')];
                $require($reader['result'] === $expected && $activity['result'] === $expected, "$target/$case typed results");
                $require(count($author['references']) === $count
                    && array_column($author['references'], 'sha256') === $author['encoded_sha256']
                    && array_sum(array_column($author['references'], 'size_bytes')) === $author['encoded_bytes'],
                    "$target/$case reference batch");
            } else {
                $require($author['reference']['sha256'] === $author['encoded_sha256']
                    && $author['reference']['size_bytes'] === $author['encoded_bytes'], "$target/$case reference identity");
                $require($reader['sdk_source'] === $artifacts['sdk_php_source'], "$target/$case cold SDK source");
                if ($case === 'workflow') {
                    $require($reader['describe_unchanged'] === true && $reader['result_text_bytes'] === $textBytes
                        && $reader['result_text_sha256'] === $summary('x')['text_sha256'] && $reader['number'] === $number
                        && $reader['optional'] === null && $reader['unicode'] === 'rangeλ', "$target typed workflow output");
                } else {
                    $require($reader['activity_completions'] === 1 && $reader['result'] === $summary('x'), "$target typed activity output");
                }
            }
            $require($author['encoded_bytes'] > $textBytes, "$target/$case real large payload");
            $runs[$case] = $author['run_id'];
        }
        $completion = $data['completion-upload'];
        $require(str_contains($completion['lease_owner'] ?? '', 'λ😀'), "$target actual UTF-8 completion owner");
        $require($completion['scope'] === 'raw_http_activity_completion_upload'
            && $completion['parent_run_id'] === $completion['run_id']
            && $completion['task_id'] !== $completion['parent_task_id']
            && $completion['activity_completion_count'] === 1 && $completion['workflow_status'] === 'completed',
            "$target completion original/task/history");
        $require($completion['result'] === [$number, null, 'rangeλ'], "$target typed completion result");
        $require($completion['lease_expires_before'] === $completion['lease_expires_after']
            && is_string($completion['lease_expires_before']) && $completion['lease_expires_before'] !== '',
            "$target upload cannot renew the lease");
        $require($completion['original_deadline'] - $completion['started_at'] === 30.0
            && $completion['finished_at'] < $completion['original_deadline']
            && $completion['elapsed_seconds'] === $completion['finished_at'] - $completion['started_at'],
            "$target completion original budget");
        $require($completion['reference']['codec'] === 'avro' && $completion['reference']['size_bytes'] === 44
            && $completion['reference']['sha256'] === '72f7246e42b2f948968398c4718ce8567ad4417aee4901f8975eefd5963b9ba5',
            "$target completion byte identity");
        $require(count($completion['reference']) === 5 && count($completion['duplicate_reference']) === 5,
            "$target exact reference members");
        foreach (['schema', 'reference_id', 'codec', 'size_bytes', 'sha256'] as $field) {
            $require(array_key_exists($field, $completion['reference'])
                && array_key_exists($field, $completion['duplicate_reference'])
                && $completion['reference'][$field] === $completion['duplicate_reference'][$field],
                "$target duplicate reference $field identity/type");
        }
        $require($completion['duplicate_key_order_changed'] === (array_keys($completion['reference']) !== array_keys($completion['duplicate_reference'])),
            "$target reference member order observation");
        $require($completion['controls'] === ['identical_upload', 'conflicting_slot', 'foreign_owner',
            'closed_upload_reconciliation', 'cold_parent', 'utf8_completion_context'], "$target completion controls");
        $runs['completion-upload'] = $completion['run_id'];
        $require(count(array_unique($runs)) === 6, "$target distinct originals");
        $joinedRuns[$target] = $runs;
    }
    $require(count(array_unique(array_merge(array_values($joinedRuns['php']), array_values($joinedRuns['native'])))) === 12,
        'Implementations must have independent original runs');
    foreach (['input', 'workflow-author', 'activity-author', 'arguments-1-author', 'arguments-2-author'] as $label) {
        foreach (['encoded_bytes', 'encoded_sha256'] as $field) {
            $require($records['php'][$label][$field] === $records['native'][$label][$field], "$label matched encoded $field");
        }
    }

    return $joinedRuns;
}

[$script, $directory, $profilePath] = $argv;
$artifacts = json_decode(file_get_contents($profilePath), true, flags: JSON_THROW_ON_ERROR)['artifacts'];
$labels = ['input', 'workflow-author', 'workflow-reader', 'activity-author', 'activity-reader',
    'arguments-1-author', 'arguments-1-activity', 'arguments-1-reader',
    'arguments-2-author', 'arguments-2-activity', 'arguments-2-reader', 'completion-upload'];
$records = [];
foreach (['php', 'native'] as $target) {
    foreach ($labels as $label) {
        $records[$target][$label] = json_decode(file_get_contents($directory.'/'.$target.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
$runs = checkExternalConsumers($records, $artifacts);
$mutations = [
    'rounded_int64' => static function (array &$bad): void { $bad['native']['input']['result']['number'] = 9007199254740992; },
    'other_run' => static function (array &$bad): void { $bad['native']['workflow-reader']['run_id'] = 'other'; },
    'extended_deadline' => static function (array &$bad): void { $bad['php']['activity-author']['original_deadline']++; },
    'changed_history' => static function (array &$bad): void { $bad['native']['arguments-2-reader']['history_unchanged'] = false; },
    'lost_activity' => static function (array &$bad): void { $bad['native']['arguments-2-activity']['activity_completions'] = 1; },
    'changed_reference' => static function (array &$bad): void { $bad['php']['workflow-author']['reference']['sha256'] = str_repeat('0', 64); },
    'expired_observation' => static function (array &$bad): void { $bad['native']['workflow-reader']['elapsed_seconds'] = 30; },
    'completion_other_parent' => static function (array &$bad): void { $bad['native']['completion-upload']['parent_run_id'] = 'other'; },
    'completion_lease_renewal' => static function (array &$bad): void { $bad['php']['completion-upload']['lease_expires_after'] .= 'changed'; },
    'completion_rounded_int64' => static function (array &$bad): void { $bad['native']['completion-upload']['result'][0] = 9007199254740992; },
    'completion_duplicate_reference' => static function (array &$bad): void { $bad['php']['completion-upload']['duplicate_reference']['reference_id'] = 'other'; },
    'completion_duplicate_size_type' => static function (array &$bad): void { $bad['native']['completion-upload']['duplicate_reference']['size_bytes'] = '44'; },
];
foreach ($mutations as $label => $mutate) {
    $bad = $records;
    $mutate($bad);
    try {
        checkExternalConsumers($bad, $artifacts);
    } catch (RuntimeException) {
        continue;
    }
    throw new RuntimeException('Reader accepted '.$label);
}
echo json_encode(['outcome' => 'pass', 'scope' => 'installed_service_external_consumers',
    'original_runs' => $runs, 'scenarios_per_target' => 6, 'original_budget_seconds' => 30,
    'reader_refusals' => array_keys($mutations), 'embedded_transport' => 'inapplicable'], JSON_THROW_ON_ERROR)."\n";
