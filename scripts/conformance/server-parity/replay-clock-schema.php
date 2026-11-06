<?php

declare(strict_types=1);

// Project only the exact native index before comparing with frozen PHP.
function projectSqliteReplayClockIndex(array $rows): array
{
    $expected = ['type' => 'index', 'name' => 'workflow_history_events_run_type_sequence_index',
        'tbl_name' => 'workflow_history_events',
        'sql' => 'CREATE INDEX workflow_history_events_run_type_sequence_index ON workflow_history_events (workflow_run_id, event_type, sequence)'];
    $found = 0;
    $result = [];
    foreach ($rows as $row) {
        if ($row['type'] === 'index' && $row['name'] === $expected['name']) {
            if ($row !== $expected) {
                throw new RuntimeException('Native replay clock index definition mismatch.');
            }
            $found++;
        } else {
            $result[] = $row;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException('Native replay clock index missing or duplicated.');
    }

    return $result;
}

function projectMysqlReplayClockIndex(array $rows): array
{
    $index = ",\n  KEY `workflow_history_events_run_type_sequence_index` (`workflow_run_id`,`event_type`,`sequence`)";
    $found = 0;
    foreach ($rows as &$row) {
        if ($row[0] === 'workflow_history_events') {
            if (substr_count($row[1], $index) !== 1) {
                throw new RuntimeException('Native replay clock index definition mismatch.');
            }
            $row[1] = str_replace($index, '', $row[1]);
            $found++;
        }
    }
    unset($row);
    if ($found !== 1) {
        throw new RuntimeException('Native history table missing or duplicated.');
    }

    return $rows;
}

function projectPostgresReplayClockIndex(array $rows): array
{
    $expected = ['index', 'workflow_history_events_run_type_sequence_index', [
        'ready' => true, 'valid' => true, 'unique' => false, 'primary' => false,
        'definition' => 'CREATE INDEX workflow_history_events_run_type_sequence_index ON public.workflow_history_events USING btree (workflow_run_id, event_type, sequence)',
    ]];
    $found = 0;
    $result = [];
    foreach ($rows as $row) {
        if ($row[0] === 'index' && $row[1] === $expected[1]) {
            if ($row !== $expected) {
                throw new RuntimeException('Native replay clock index definition mismatch.');
            }
            $found++;
        } else {
            $result[] = $row;
        }
    }
    if ($found !== 1) {
        throw new RuntimeException('Native replay clock index missing or duplicated.');
    }

    return $result;
}
