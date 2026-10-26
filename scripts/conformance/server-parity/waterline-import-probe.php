<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WaterlineImportHttp;
use GuzzleHttp\HandlerStack;

// Waterline v1 projection import (POST /api/workflows/import/waterline-v1)
// over raw HTTP in fresh namespaces: the request gates, validation, each
// normalization refusal, a dry run, projections of every v1 status,
// idempotent, changed and cross-namespace repeats, a native identity
// collision, the projected runs through describe, runs, history, history
// export and list, and the read-only refusals of every control operation.
//
// Sources come from the fixture's templates, Waterline v1 flow details.
// Projected identities are deterministic hashes of the source id, which
// carries this recording's prefix, so the probe computes them as
// LegacyV1Projection::mappedIdentity does and records each as a label, with
// its event ids. Dedupe keys and export checksums are labelled where first
// seen; import and export times are placeholders.
function finishHttpWaterlineImport(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['waterline_import'];
    $namespace = strtolower($workflowId.'-wl1');
    $alt = strtolower($workflowId.'-wl1alt');
    $other = strtolower($workflowId.'-wl1native');
    $admin->createNamespace($namespace, 'waterline import λ', 30);
    $admin->createNamespace($alt, 'waterline import alt', 30);
    $admin->createNamespace($other, 'waterline import native', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WaterlineImportHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);

    // One raw request. Options: namespace, auth (false drops the token),
    // version (a header value, or false to drop it), and raw (a literal
    // body) with content_type.
    $raw = static function (string $method, string $path, mixed $body = null, array $options = []) use ($http, $token, $namespace): array {
        $version = array_key_exists('version', $options) ? $options['version'] : '2';
        $headers = array_filter(['Authorization' => ($options['auth'] ?? true) && $token !== null ? 'Bearer '.$token : null,
            'Accept' => 'application/json', 'X-Namespace' => $options['namespace'] ?? $namespace,
            'X-Durable-Workflow-Control-Plane-Version' => $version === false ? null : $version], static fn ($value) => $value !== null);
        $request = ['headers' => $headers];
        if (array_key_exists('raw', $options)) {
            $request['headers']['Content-Type'] = $options['content_type'] ?? 'application/json';
            $request['body'] = $options['raw'];
        } elseif ($body !== null) {
            $request['json'] = $body;
        }
        $response = $http->request($method, $path, $request);

        return ['status' => $response->getStatusCode(), 'response' => json_decode((string) $response->getBody(), true)];
    };

    // LegacyV1Projection::mappedIdentity, with Laravel's Str::lower,
    // replaceMatches, trim and limit(32, '').
    $sourceId = $workflowId;
    $mapped = static function (string $source, string $qualified): array {
        $key = trim($source)."\0".trim($qualified);
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', mb_strtolower($source, 'UTF-8')), '-');
        if (mb_strwidth($slug, 'UTF-8') > 32) {
            $slug = rtrim(mb_strimwidth($slug, 0, 32, '', 'UTF-8'));
        }
        $slug = $slug === '' ? 'source' : $slug;

        return [
            'workflow_id' => 'v1:'.$slug.':'.substr(hash('sha256', 'workflow'."\0".$key), 0, 24),
            'run_id' => substr(hash('sha256', 'run'."\0".$key), 0, 26),
            'import_id' => hash('sha256', 'import'."\0".$key),
        ];
    };

    // BundleIntegrityVerifier::canonicalJson: lists keep their order, maps
    // are key-sorted as strings, with unescaped slashes and preserved zero
    // fractions.
    $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($canonicalize, $value);
        }
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $canonicalize($item);
        }
        ksort($sorted, SORT_STRING);

        return $sorted;
    };
    $canonical = static fn (array $bundle): string => json_encode($canonicalize($bundle),
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

    // Labels for every projected identity, its event ids and its dedupe key.
    $labels = [];
    $identities = [];
    $identity = static function (string $label, string $qualified, ?string $source = null) use (&$labels, &$identities, $mapped, $sourceId): array {
        $ids = $mapped($source ?? $sourceId, $qualified);
        if (! isset($identities[$label])) {
            $identities[$label] = $ids;
            $labels[$ids['workflow_id']] = '{'.$label.'.workflow}';
            $labels[$ids['run_id']] = '{'.$label.'.run}';
            $labels[$ids['import_id']] = '{'.$label.'.import}';
            foreach (['WorkflowStarted', 'ActivityCompleted', 'SignalReceived', 'ActivityFailed', 'WorkflowCompleted',
                'WorkflowFailed', 'WorkflowCancelled', 'WorkflowTerminated'] as $type) {
                for ($sequence = 1; $sequence <= 12; $sequence++) {
                    $labels[substr(hash('sha256', $ids['run_id']."\0".$sequence."\0".$type), 0, 26)] = '{'.$label.'.e'.$sequence.'}';
                }
            }
        }

        return $ids;
    };
    // A v1 flow detail from template $template for label $label, with
    // qualified id v1:$legacy. Options: mutate (after the identity is set).
    $source = static function (string $template, string $label, string $legacy, array $options = []) use ($definition, $identity): array {
        $document = $definition['sources'][$template];
        $document['id'] = 'v1:'.$legacy;
        $document['operator_id'] = 'v1:'.$legacy;
        $document['legacy_id'] = $legacy;
        $identity($label, 'v1:'.$legacy, $options['source_id'] ?? null);
        if (isset($options['mutate'])) {
            $options['mutate']($document);
        }

        return $document;
    };
    // A projection report or other recorded answer: import and export times
    // become placeholders, and each new dedupe key or export checksum becomes
    // a label for $label.
    $placeholders = static function (mixed $value) use (&$placeholders): mixed {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (in_array($key, ['imported_at', 'exported_at'], true) && is_string($item)
                && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{1,6})?(Z|[+-]\d\d:\d\d)$/', $item)) {
                $value[$key] = '{'.$key.'}';
            } else {
                $value[$key] = $placeholders($item);
            }
        }

        return $value;
    };
    $record = static function (array $recorded, ?string $label = null) use (&$labels, $placeholders): array {
        $body = $recorded['response'];
        if ($label !== null && is_string($body['dedupe_key'] ?? null) && ! isset($labels[$body['dedupe_key']])) {
            $labels[$body['dedupe_key']] = '{'.$label.'.dedupe}';
        }
        // A projection without source times sorts and exports by its import
        // time, so that time is a label wherever else it appears.
        if ($label !== null && $recorded['status'] === 201 && is_string($body['imported_at'] ?? null)) {
            $labels[$body['imported_at']] ??= '{'.$label.'.imported_at}';
        }

        return ['status' => $recorded['status'], 'response' => $placeholders($body)];
    };
    // The published SQLite reference shares its file with its maintenance
    // loop and may refuse a lock upgrade, which the projection reports as
    // target_write_failed. Nothing was committed, so only that answer is
    // retried, and each retry is reported on stderr.
    $project = static function (mixed $body, ?string $label = null, array $options = []) use ($raw, $record): array {
        for ($attempt = 1; ; $attempt++) {
            $recorded = $raw('POST', '/api/workflows/import/waterline-v1', $body, $options);
            if ($attempt >= 10 || ($recorded['response']['reason'] ?? null) !== 'target_write_failed') {
                return $record($recorded, $label);
            }
            fwrite(STDERR, json_encode(['waterline_import_lock_retry' => $attempt], JSON_THROW_ON_ERROR).PHP_EOL);
            usleep(250_000);
        }
    };
    $state = ['applicable' => true, 'namespace' => $namespace, 'alternate_namespace' => $alt, 'native_namespace' => $other,
        'source_id' => $sourceId];

    // Gates, before the controller.
    $state['gate_unauthenticated'] = $project([], null, ['auth' => false]);
    $state['gate_missing_version'] = $project([], null, ['version' => false]);
    $state['gate_unsupported_version'] = $project([], null, ['version' => '3']);
    $state['gate_unknown_namespace'] = $project([], null, ['namespace' => $workflowId.'-missing']);
    $state['gate_text_plain'] = $project(null, null, ['raw' => 'workflow', 'content_type' => 'text/plain']);
    $state['gate_malformed_json'] = $project(null, null, ['raw' => '{bad']);

    // Validation: source_id required|string|max:64, workflow required|array,
    // dry_run nullable|boolean, after Laravel trims and nulls every string.
    $completed = $source('completed', 'completed', '4101');
    $state['validate_empty'] = $project([]);
    $state['validate_source_id_blank'] = $project(['source_id' => '   ', 'workflow' => $completed]);
    $state['validate_source_id_number'] = $project(['source_id' => 4101, 'workflow' => $completed]);
    $state['validate_source_id_long'] = $project(['source_id' => str_repeat('s', 65), 'workflow' => $completed]);
    $state['validate_workflow_empty'] = $project(['source_id' => $sourceId, 'workflow' => []]);
    $state['validate_workflow_string'] = $project(['source_id' => $sourceId, 'workflow' => 'v1:4101']);
    $state['validate_dry_run'] = $project(['source_id' => $sourceId, 'workflow' => $completed, 'dry_run' => 'yes']);

    // Normalization refusals, each alone and then all at once.
    $state['reject_source_id'] = $project(['source_id' => 'legacy prod/us', 'workflow' => $completed]);
    $state['reject_unqualified'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['operator_id' => '4101', 'id' => '4101', 'legacy_id' => null])]);
    $state['reject_mismatch'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['legacy_id' => 4102])]);
    $state['reject_engine'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['engine_source' => 'v2'])]);
    $state['reject_class'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['class' => '  '])]);
    $state['reject_status'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['status' => 'paused'])]);
    $state['reject_all'] = $project(['source_id' => 'bad id!', 'workflow' => ['operator_id' => 'x', 'legacy_id' => 9, 'status' => 'odd', 'engine_source' => 'v1.5']]);
    // operator_id wins over id.
    $state['operator_id_first'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['id' => 'v1:9999']), 'dry_run' => true], 'completed');

    // A dry run writes nothing.
    $state['dry_run'] = $project(['source_id' => $sourceId, 'workflow' => $completed, 'dry_run' => true], 'completed');
    $state['dry_run_describe'] = $record($raw('GET', '/api/workflows/'.rawurlencode($identities['completed']['workflow_id'])));

    // The projection and its repeats.
    $state['project'] = $project(['source_id' => $sourceId, 'workflow' => $completed, 'dry_run' => false], 'completed');
    $state['repeat'] = $project(['source_id' => $sourceId, 'workflow' => $completed], 'completed');
    $state['repeat_dry_run'] = $project(['source_id' => $sourceId, 'workflow' => $completed, 'dry_run' => 1], 'completed');
    // Trimmed strings and ignored fields leave the dedupe key unchanged.
    $state['repeat_trimmed'] = $project(['source_id' => ' '.$sourceId.' ', 'workflow' => array_merge($completed,
        ['class' => ' '.$completed['class'].' ', 'chart_data' => [], 'extra' => 'ignored'])], 'completed');
    $state['changed'] = $project(['source_id' => $sourceId, 'workflow' => array_merge($completed, ['output' => 's:7:"changed";'])], 'changed');
    $state['other_namespace'] = $project(['source_id' => $sourceId, 'workflow' => $completed], 'completed', ['namespace' => $alt]);

    // Every v1 status, and the timestamp rules.
    $states = ['created' => 'pending', 'pending' => 'pending', 'waiting' => 'waiting', 'failed' => 'failed',
        'cancelled' => 'cancelled', 'terminated' => 'terminated', 'continued' => 'continued'];
    foreach ($states as $v1 => $label) {
        $state['status_'.$v1] = $project(['source_id' => $sourceId, 'workflow' => $source('completed', 'status-'.$v1,
            '43'.str_pad((string) array_search($v1, array_keys($states), true), 2, '0', STR_PAD_LEFT),
            ['mutate' => static function (array &$document) use ($v1, $states): void {
                // Distinct times keep the list order independent of the hashed ids.
                $minute = array_search($v1, array_keys($states), true);
                $document['status'] = strtoupper($v1);
                $document['created_at'] = sprintf('2026-09-30T07:%02d:00.000000Z', $minute);
                $document['updated_at'] = sprintf('2026-09-30T07:%02d:30.000000Z', $minute);
            }])], 'status-'.$v1);
    }
    $running = $source('running', 'running', '4201');
    $state['running'] = $project(['source_id' => $sourceId, 'workflow' => $running], 'running');
    $state['offsets'] = $project(['source_id' => $sourceId, 'workflow' => $source('completed', 'offsets', '4401', ['mutate' => static function (array &$document): void {
        $document['created_at'] = '2026-09-30T10:00:00+02:00';
        $document['updated_at'] = 'not a time';
        $document['logs'][] = 'not a row';
        $document['logs'][] = ['id' => 9003, 'class' => 'App\\Activities\\Untimed', 'result' => ['array' => 'value']];
        $document['signals'][0]['created_at'] = null;
        $document['signals'][0]['received_at'] = '2026-09-30T08:00:00.500000Z';
        $document['signals'][] = ['id' => 7002, 'name' => 'named', 'method' => 'ignored'];
        unset($document['arguments'], $document['connection']);
    }])], 'offsets');
    // The fewest fields a projection accepts.
    $identity('bare', 'v1:4501');
    $state['bare'] = $project(['source_id' => $sourceId, 'workflow' => ['operator_id' => 'v1:4501', 'engine_source' => 'v1',
        'class' => 'App\\Workflows\\Legacy\\Bare', 'status' => 'completed']], 'bare');

    // A native execution already owns the mapped identity. Workflow and run
    // ids are global, so it collides from its own namespace too.
    $collision = $identity('collision', 'v1:4601');
    $native = $raw('POST', '/api/workflows', ['workflow_id' => $collision['workflow_id'], 'workflow_type' => 'parity.v1.echo',
        'task_queue' => strtolower($workflowId.'-wl1-native')], ['namespace' => $other]);
    $state['native_start_status'] = $native['status'];
    $state['native_collision'] = $project(['source_id' => $sourceId, 'workflow' => $source('completed', 'collision', '4601')], 'collision');

    // The projected runs read as published 2.11.1 shows them.
    foreach (['completed' => 'project', 'running' => 'running', 'status-failed' => 'failed', 'offsets' => 'offsets', 'bare' => 'bare'] as $label => $name) {
        $ids = $identities[$label];
        $instance = '/api/workflows/'.rawurlencode($ids['workflow_id']);
        $run = $instance.'/runs/'.$ids['run_id'];
        $state['read_'.$name.'_describe'] = $record($raw('GET', $instance));
        $state['read_'.$name.'_run'] = $record($raw('GET', $run));
        $state['read_'.$name.'_runs'] = $record($raw('GET', $instance.'/runs'));
        $state['read_'.$name.'_history'] = $record($raw('GET', $run.'/history'));
        // The export as served: its checksum is recomputed here over PHP's
        // canonical JSON of the bundle without its integrity block, which
        // LegacyV1Projection::decorateHistoryExport refreshes after adding
        // migration_projection.
        $served = $raw('GET', $run.'/history/export');
        $bundle = is_array($served['response']) ? $served['response'] : [];
        $integrity = $bundle['integrity'] ?? null;
        unset($bundle['integrity']);
        $export = $record($served);
        if (is_array($export['response'])) {
            $export['response']['integrity_verified'] = is_array($integrity)
                && hash('sha256', $canonical($bundle)) === ($integrity['checksum'] ?? null);
            // Timeline, wait and projection-source reporting belong to the
            // general history export, which this fixture does not qualify.
            unset($export['response']['timeline'], $export['response']['waits'], $export['response']['selected_run'],
                $export['response']['links']['projection_source']);
        }
        // The summary's history size counts the source id, namespace and
        // slug the prefix carries; record it without them, so recordings
        // under prefixes of any length compare.
        if (is_int($export['response']['summary']['history_size_bytes'] ?? null)) {
            $slug = explode(':', $ids['workflow_id'])[1];
            $export['response']['summary']['history_size_bytes'] -= strlen($sourceId) + strlen($namespace) + strlen($slug);
        }
        if (is_string($export['response']['integrity']['checksum'] ?? null)) {
            $labels[$export['response']['integrity']['checksum']] ??= '{'.$label.'.export_checksum}';
        }
        $state['read_'.$name.'_export'] = $export;
    }
    $state['read_list'] = $record($raw('GET', '/api/workflows'));
    $state['read_list_alt'] = $record($raw('GET', '/api/workflows', null, ['namespace' => $alt]));

    // Every control operation refuses a projection, terminal or not.
    foreach (['running' => $identities['running'], 'completed' => $identities['completed']] as $label => $ids) {
        $instance = '/api/workflows/'.rawurlencode($ids['workflow_id']);
        $run = $instance.'/runs/'.$ids['run_id'];
        $state['refuse_'.$label] = [
            'signal' => $record($raw('POST', $instance.'/signal/approve', ['input' => ['yes']])),
            'run_signal' => $record($raw('POST', $run.'/signal/approve', ['input' => ['yes']])),
            'query' => $record($raw('POST', $instance.'/query/status', ['input' => []])),
            'update' => $record($raw('POST', $instance.'/update/rename', ['input' => ['x']])),
            'cancel' => $record($raw('POST', $instance.'/cancel', ['reason' => 'operator'])),
            'run_cancel' => $record($raw('POST', $run.'/cancel', ['reason' => 'operator'])),
            'terminate' => $record($raw('POST', $instance.'/terminate', ['reason' => 'operator'])),
            'repair' => $record($raw('POST', $instance.'/repair')),
            'archive' => $record($raw('POST', $instance.'/archive', ['reason' => 'operator'])),
            'redrive' => $record($raw('POST', $run.'/redrive')),
            'request_cancellation' => $record($raw('POST', $instance.'/request-cancellation', ['reason' => 'operator'])),
        ];
    }
    $state['refused_running_describe'] = $record($raw('GET', '/api/workflows/'.rawurlencode($identities['running']['workflow_id'])));

    return json_decode(strtr(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $labels),
        true, 512, JSON_THROW_ON_ERROR);
}
