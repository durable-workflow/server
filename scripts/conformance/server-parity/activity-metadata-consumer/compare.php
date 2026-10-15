<?php

declare(strict_types=1);

[$script, $phpPath, $nativePath] = $argv;
$formats = [];
$normalize = static function (string $path, string $actor) use (&$formats): array {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $identities = [$state['run_id'] => '@run', $state['activity_id'] => '@activity'];
    foreach ($state['attempt_ids'] as $index => $id) {
        $identities[$id] = '@attempt/'.($index + 1);
        $identities[$state['task_ids'][$index]] = '@activity-task/'.($index + 1);
    }
    foreach ($state['snapshots'] as $response) {
        if ($response['run_id'] !== $state['run_id'] || $response['workflow_id'] !== $state['workflow_id']) {
            throw new RuntimeException('Metadata escaped its original run.');
        }
        foreach ($response['activities'] as $activity) {
            if ($activity['id'] !== $state['activity_id'] || $activity['idempotency_key'] !== $state['activity_id']) {
                throw new RuntimeException('Metadata escaped its original activity.');
            }
            foreach ($activity['attempts'] as $attempt) {
                $number = array_search($attempt['id'], $state['attempt_ids'], true);
                if ($number === false || $attempt['attempt_number'] !== $number + 1
                    || $attempt['task_id'] !== $state['task_ids'][$number]) {
                    throw new RuntimeException('Attempt order differs from actual task delivery.');
                }
            }
        }
    }
    $walk = static function (mixed $value, string $key = '') use (&$walk, $identities, $actor, &$formats): mixed {
        if (is_array($value)) {
            $list = array_is_list($value);
            foreach ($value as $childKey => $child) {
                $value[$childKey] = $walk($child, (string) $childKey);
            }
            if (! $list) {
                ksort($value);
            }

            return $value;
        }
        if (is_string($value) && str_ends_with($key, '_at')) {
            if (! preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.(\d{3}|\d{6})Z$/D', $value, $match)
                || DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC')) === false) {
                throw new RuntimeException('Invalid original metadata timestamp: '.$key);
            }
            $formats[$actor][strlen($match[1])] = true;

            return '@independent-clock';
        }

        return is_string($value) ? ($identities[$value] ?? $value) : $value;
    };

    return $walk($state['snapshots']);
};
$php = $normalize($phpPath, 'php');
$native = $normalize($nativePath, 'native');
if ($php !== $native) {
    $differences = [];
    $diff = static function (mixed $left, mixed $right, string $path) use (&$diff, &$differences): void {
        if ($left === $right || count($differences) >= 20) {
            return;
        }
        if (is_array($left) && is_array($right)) {
            foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
                if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) {
                    $differences[] = ['path' => $path.'/'.$key, 'reason' => 'missing_key'];
                } else {
                    $diff($left[$key], $right[$key], $path.'/'.$key);
                }
            }
        } else {
            $differences[] = ['path' => $path, 'php' => $left, 'native' => $right];
        }
    };
    $diff($php, $native, '');
    throw new RuntimeException('Metadata differs: '.json_encode($differences, JSON_THROW_ON_ERROR));
}
echo json_encode(['outcome' => 'pass', 'snapshots' => count($php),
    'independent_ids_bound_to_actual_tasks' => true, 'clock_values_compared' => false,
    'timestamp_fraction_lengths' => array_map(static fn (array $lengths): array => array_keys($lengths), $formats),
    'business_fields_identical' => true], JSON_THROW_ON_ERROR)."\n";
