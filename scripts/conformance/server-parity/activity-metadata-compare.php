<?php

declare(strict_types=1);

[$script, $phpPath, $nativePath] = $argv;
$normalize = static function (string $path): array {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $labels = ['pending', 'running', 'heartbeat', 'retry', 'resumed', 'completed', 'closed', 'cold'];
    if (array_keys($state['snapshots']) !== $labels || count($state['claims']) !== 2) {
        throw new RuntimeException('Incomplete original lifecycle evidence.');
    }
    $identities = [$state['run_id'] => '<run>', $state['activity_id'] => '<activity>'];
    $tasks = [];
    foreach ($state['claims'] as $index => $claim) {
        $identities[$claim['activity_attempt_id']] = '<attempt-'.($index + 1).'>';
        // Preserve whether retries reuse a task or create another task.
        if (! isset($tasks[$claim['task_id']])) {
            $tasks[$claim['task_id']] = '<activity-task-'.(count($tasks) + 1).'>';
        }
        $identities[$claim['task_id']] = $tasks[$claim['task_id']];
    }
    $timestamps = ['created_at', 'started_at', 'closed_at', 'last_heartbeat_at', 'lease_expires_at', 'reused_recorded_at'];
    $walk = static function (mixed $value, string $key = '') use (&$walk, $identities, $timestamps, $state): mixed {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $field => $entry) {
                $result[$field] = $walk($entry, (string) $field);
            }
            if (! array_is_list($result)) {
                ksort($result);
            }

            return $result;
        }
        if ($value !== null && in_array($key, $timestamps, true)) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $value)) {
                throw new RuntimeException('Metadata timestamp shape differs: '.$key);
            }
            $instant = (float) (new DateTimeImmutable($value))->format('U.u');
            if ($instant < $state['started_at'] - 2 || $instant > $state['original_deadline'] + 600) {
                throw new RuntimeException('Metadata timestamp outside the authored lifecycle: '.$key);
            }

            return '<timestamp>';
        }

        return is_string($value) ? ($identities[$value] ?? $value) : $value;
    };
    foreach ($state['snapshots'] as $snapshot) {
        foreach ($snapshot['activities'][0]['attempts'] as $attempt) {
            $start = new DateTimeImmutable($attempt['started_at']);
            foreach (['last_heartbeat_at', 'closed_at', 'lease_expires_at'] as $field) {
                if ($attempt[$field] !== null && new DateTimeImmutable($attempt[$field]) < $start) {
                    throw new RuntimeException('Attempt timestamp precedes its own start: '.$field);
                }
            }
        }
    }

    return $walk($state['snapshots']);
};
$php = $normalize($phpPath);
$native = $normalize($nativePath);
$differences = [];
$compare = static function (mixed $left, mixed $right, string $path) use (&$compare, &$differences): void {
    if (is_array($left) && is_array($right)) {
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) {
                $differences[] = $path.'.'.$key.' (missing)';
            } else {
                $compare($left[$key], $right[$key], $path.'.'.$key);
            }
        }
    } elseif ($left !== $right) {
        $differences[] = $path;
    }
};
$compare($php, $native, 'snapshots');
echo json_encode(['outcome' => $differences === [] ? 'pass' : 'product-fail',
    'compared_snapshots' => count($php), 'differences' => $differences,
    'php_normalized_sha256' => hash('sha256', json_encode($php, JSON_THROW_ON_ERROR)),
    'native_normalized_sha256' => hash('sha256', json_encode($native, JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR)."\n";
exit($differences === [] ? 0 : 1);
