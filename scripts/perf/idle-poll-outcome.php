<?php

declare(strict_types=1);

/** Classify the worker protocol's Task and EmptyPoll envelopes. */
function idlePollOutcome(array $response): string
{
    if (isset($response['task']) && is_array($response['task'])) {
        return 'task';
    }

    return array_key_exists('task', $response) && $response['task'] === null
        && in_array($response['poll_status'] ?? null, ['empty', 'timeout'], true)
        ? 'empty' : 'unexpected';
}
