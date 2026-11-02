<?php

declare(strict_types=1);

use DurableWorkflow\Client;
use GuzzleHttp\Client as WorkflowImportHttp;
use GuzzleHttp\HandlerStack;

// History import (POST /api/workflows/import/embedded-v2) over raw HTTP in
// fresh namespaces: the request gates, the body shapes, a dry run, an
// import, idempotent and conflicting repeats, integrity and signature
// refusals, the eligibility rules, request normalization, a write failure,
// non-terminal imports, a cross-namespace instance, and the imported run
// through describe, runs, history and list.
//
// Bundles come from the fixture's templates, which are real published
// exports with every ULID as an order-preserving token. Each label gets its
// own fixed-length ids, so the store never sees a prefix-dependent length,
// and the probe computes the canonical checksum and signature itself. Run
// identities, checksums and signatures are recorded as labels; generated
// import ids, import times and raw database messages as placeholders.
function finishHttpWorkflowImport(Client $admin, array $fixture, string $workflowId, string $url): array
{
    $definition = $fixture['workflow_import'];
    $namespace = strtolower($workflowId.'-imp');
    $alt = strtolower($workflowId.'-alt');
    $admin->createNamespace($namespace, 'workflow import λ', 30);
    $admin->createNamespace($alt, 'workflow import alt', 30);
    $token = getenv('DW_PARITY_TOKEN') ?: null;
    $stack = HandlerStack::create();
    $stack->push(LockPressurePollRetries::middleware(), 'lock_pressure_poll_retry');
    $http = new WorkflowImportHttp(['base_uri' => rtrim($url, '/'), 'http_errors' => false,
        'connect_timeout' => 1, 'timeout' => 30, 'handler' => $stack]);

    // One raw request. Options: namespace, auth (false drops the token),
    // version (a header value, or false to drop it), raw (a literal body)
    // with content_type, and headers (response headers to record).
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
        $recorded = ['status' => $response->getStatusCode(), 'response' => json_decode((string) $response->getBody(), true)];
        foreach ($options['headers'] ?? [] as $name) {
            $recorded['headers'][$name] = $response->getHeaderLine($name);
        }

        return $recorded;
    };
    $ok = static function (array $recorded, int $status, string $operation): array {
        if ($recorded['status'] !== $status) {
            throw new RuntimeException('Workflow import '.$operation.': '.json_encode($recorded));
        }

        return $recorded['response'];
    };

    // PHP's canonical form (BundleIntegrityVerifier::canonicalJson): lists
    // keep their order, maps are key-sorted as strings, and the top-level
    // integrity block is excluded.
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
    $canonical = static fn (array $bundle): string => json_encode($canonicalize(array_diff_key($bundle, ['integrity' => true])),
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    // Laravel's global TrimStrings (Str::trim) and ConvertEmptyStringsToNull,
    // which rewrite every string of a JSON request before the importer sees it.
    $invisible = '\x{0009}\x{0020}\x{00A0}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{2000}\x{2001}\x{2002}\x{2003}\x{2004}\x{2005}\x{2006}\x{2007}\x{2008}\x{2009}\x{200A}\x{200B}\x{200C}\x{200D}\x{200E}\x{200F}\x{202F}\x{205F}\x{2060}\x{2061}\x{2062}\x{2063}\x{2064}\x{2065}\x{206A}\x{206B}\x{206C}\x{206D}\x{206E}\x{206F}\x{3000}\x{2800}\x{3164}\x{FEFF}\x{FFA0}\x{1D159}\x{1D173}\x{1D174}\x{1D175}\x{1D176}\x{1D177}\x{1D178}\x{1D179}\x{1D17A}\x{E0020}';
    $space = '[\s'.$invisible." \n\r\t\v\0".']';
    $normalize = static function (mixed $value) use (&$normalize, $space): mixed {
        if (is_array($value)) {
            return array_map($normalize, $value);
        }
        if (! is_string($value)) {
            return $value;
        }
        $value = preg_replace('~^'.$space.'+|'.$space.'(?<!'.$space.$space.')'.$space.'*+$~u', '', $value) ?? trim($value);

        return $value === '' ? null : $value;
    };

    // Labels for every run-specific identity, in order of first use.
    $labels = [];
    $labelIndex = [];
    $hash = hash('sha256', $workflowId);
    $instanceFor = static function (string $label) use (&$labelIndex, $hash): string {
        $labelIndex[$label] ??= count($labelIndex) + 1;

        return sprintf('wi-%02d-%s', $labelIndex[$label], substr($hash, 0, 16));
    };
    // A bundle from template $template for label $label. Options:
    // instance_of (another label's instance id), mutate (before the
    // checksum), sign (an HMAC key), checksum_raw (checksum the bundle as
    // sent, not as the server normalizes it), tamper (after the checksum).
    $bundle = static function (string $template, string $label, array $options = []) use ($definition, &$labels, &$labelIndex, $hash, $instanceFor, $canonical, $normalize): array {
        $instance = $instanceFor($options['instance_of'] ?? $label);
        $instanceFor($label);
        $index = $labelIndex[$label];
        $tokens = ['{instance}' => $instance];
        $text = json_encode($definition['bundles'][$template], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        preg_match_all('/\{([uU]):(\d+)\}/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [$token, $case, $rank]) {
            $id = sprintf('0%02d%s%013d', $index, substr($hash, 16, 10), (int) $rank);
            $tokens[$token] = $case === 'U' ? strtoupper($id) : $id;
            $labels[$tokens[$token]] = '{'.$label.'.'.$case.$rank.'}';
        }
        $labels[$instance] = '{'.($options['instance_of'] ?? $label).'.instance}';
        $document = json_decode(strtr($text, $tokens), true, 512, JSON_THROW_ON_ERROR);
        $labels[$document['workflow']['run_id']] = '{'.$label.'.run}';
        if (isset($options['mutate'])) {
            $options['mutate']($document);
        }
        $signed = ($options['checksum_raw'] ?? false) ? $document : $normalize($document);
        $checksum = hash('sha256', $canonical($signed));
        $document['integrity'] = ['canonicalization' => 'json-recursive-ksort-v1', 'checksum_algorithm' => 'sha256',
            'checksum' => $checksum, 'signature_algorithm' => null, 'signature' => null, 'key_id' => null];
        $labels[$checksum] = '{'.$label.'.checksum}';
        if (isset($options['sign'])) {
            $signature = hash_hmac('sha256', $canonical($signed), $options['sign']);
            $document['integrity'] = [...$document['integrity'], 'signature_algorithm' => 'hmac-sha256',
                'signature' => $signature, 'key_id' => 'parity-key-1'];
            $labels[$signature] = '{'.$label.'.signature}';
        }
        if (isset($options['tamper'])) {
            $options['tamper']($document);
        }
        // What the server recomputes from the normalized document it receives.
        $actual = hash('sha256', $canonical($normalize($document)));
        $labels[$actual] ??= '{'.$label.'.recomputed}';

        return $document;
    };
    // An import report, with generated import ids, import times and raw
    // database messages as placeholders. Values in any other form stay.
    $report = static function (array $recorded): array {
        $body = $recorded['response'];
        if (is_array($body)) {
            if (is_string($body['imported_at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z$/', $body['imported_at'])) {
                $body['imported_at'] = '{imported_at}';
            }
            if (is_string($body['import_id'] ?? null) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $body['import_id'])) {
                $body['import_id'] = '{generated_ulid}';
            }
            foreach ($body['eligibility']['errors'] ?? [] as $index => $error) {
                if (($error['rule'] ?? null) === 'target.write_failed' && is_string($error['message'] ?? null)) {
                    $body['eligibility']['errors'][$index]['message'] = '{database_message}';
                }
            }
        }

        return ['status' => $recorded['status'], 'response' => $body] + array_intersect_key($recorded, ['headers' => true]);
    };
    // The published SQLite reference shares its file with its maintenance
    // loop, and its importer reports a refused lock upgrade as a write
    // failure. Nothing was committed, so only that refusal is retried, and
    // each retry is reported on stderr.
    $embedded = static function (mixed $body, array $options = []) use ($raw, $report): array {
        for ($attempt = 1; ; $attempt++) {
            $recorded = $raw('POST', '/api/workflows/import/embedded-v2', $body, $options);
            $errors = $recorded['response']['eligibility']['errors'] ?? [];
            $last = is_array($errors) && $errors !== [] ? $errors[array_key_last($errors)] : null;
            if ($attempt >= 10 || $recorded['status'] !== 422 || ($last['rule'] ?? null) !== 'target.write_failed'
                || ! str_contains((string) ($last['message'] ?? ''), 'database is locked')) {
                return $report($recorded);
            }
            fwrite(STDERR, json_encode(['workflow_import_lock_retry' => $attempt], JSON_THROW_ON_ERROR).PHP_EOL);
            usleep(250_000);
        }
    };
    $state = ['applicable' => true, 'namespace' => $namespace, 'alternate_namespace' => $alt];

    // Gates, before the controller.
    $state['gate_unauthenticated'] = $embedded([], ['auth' => false]);
    $state['gate_missing_version'] = $embedded([], ['version' => false]);
    $state['gate_unsupported_version'] = $embedded([], ['version' => '3']);
    $state['gate_unknown_namespace'] = $embedded([], ['namespace' => $workflowId.'-missing']);
    $state['gate_text_plain'] = $embedded(null, ['raw' => 'bundle', 'content_type' => 'text/plain']);
    $state['gate_malformed_json'] = $embedded(null, ['raw' => '{bad']);

    // Body shapes: an empty bundle is refused before the importer; anything
    // else that is not {"bundle": {...}} is imported as the bundle itself.
    $state['shape_empty_object'] = $embedded(null, ['raw' => '{}']);
    $state['shape_empty_list'] = $embedded(null, ['raw' => '[]']);
    $state['shape_empty_bundle'] = $embedded(null, ['raw' => '{"bundle":{}}']);
    $state['shape_bundle_list'] = $embedded(null, ['raw' => '{"bundle":[]}']);
    $state['shape_null'] = $embedded(null, ['raw' => 'null']);
    $state['shape_options_only'] = $embedded(['dry_run' => true]);
    $state['shape_bundle_string'] = $embedded(['bundle' => 'not a bundle', 'import_id' => 'x']);

    // A dry run, the import, and idempotent repeats. import_id plays no part
    // in idempotency.
    $rich = $bundle('rich', 'rich');
    $state['dry_run'] = $embedded(['bundle' => $rich, 'dry_run' => true], ['headers' => ['X-Durable-Workflow-Control-Plane-Version']]);
    $state['import'] = $embedded(['bundle' => $rich, 'import_id' => '  imp-1  '], ['headers' => ['X-Durable-Workflow-Control-Plane-Version']]);
    $state['duplicate'] = $embedded(['bundle' => $rich, 'import_id' => 'imp-2']);
    $state['duplicate_dry_run'] = $embedded(['bundle' => $rich, 'dry_run' => 'yes']);
    // The same bundle offered to another namespace, which holds no such run.
    $state['duplicate_other_namespace'] = $embedded(['bundle' => $rich], ['namespace' => $alt]);
    $state['other_namespace_list'] = $raw('GET', '/api/workflows', null, ['namespace' => $alt]);
    // The same run id from another bundle, then the probe's own native run.
    $state['conflict_changed_bundle'] = $embedded(['bundle' => $bundle('rich', 'rich', ['mutate' => static function (array &$document): void {
        $document['dedupe_key'] = 'changed';
    }])]);
    $main = $ok($raw('GET', '/api/workflows/'.rawurlencode($workflowId), null, ['namespace' => 'default']), 200, 'main describe');
    $labels[$main['run_id']] = '{main.run}';
    // The whole bundle names the native run: Core 2.15.12 (Server 2.11.8)
    // checks that each command's and signal's resolved_run_id names the
    // imported run before it reaches the run id conflict.
    $state['conflict_native_run'] = $embedded(['bundle' => $bundle('rich', 'native-run', ['mutate' => static function (array &$document) use ($main): void {
        $original = $document['workflow']['run_id'];
        $rename = static function (array &$node) use (&$rename, $original, $main): void {
            foreach ($node as $key => &$value) {
                if (is_array($value)) {
                    $rename($value);
                } elseif (is_string($key) && str_ends_with($key, 'run_id') && $value === $original) {
                    $value = $main['run_id'];
                }
            }
        };
        $rename($document);
    }]), 'dry_run' => true]);

    // Integrity and signatures. No signing key is configured on the target.
    $state['tampered'] = $embedded(['bundle' => $bundle('rich', 'tampered', ['tamper' => static function (array &$document): void {
        $document['workflow']['business_key'] = 'bk-tampered';
    }])]);
    $state['signature_required_unsigned'] = $embedded(['bundle' => $bundle('rich', 'unsigned'), 'require_signature' => true]);
    $state['signature_required_no_key'] = $embedded(['bundle' => $bundle('rich', 'signed-no-key', ['sign' => 'k1']), 'require_signature' => true]);
    $state['signature_wrong_key'] = $embedded(['bundle' => $bundle('rich', 'signed-wrong-key', ['sign' => 'k1']),
        'signing_key' => 'wrong', 'require_signature' => '1']);
    $state['signature_verified'] = $embedded(['bundle' => $bundle('rich', 'signed', ['sign' => 'k1']), 'signing_key' => ' k1 ']);
    $state['signature_required_yes_no_key'] = $embedded(['bundle' => $bundle('rich', 'signed-yes', ['sign' => 'k1']),
        'require_signature' => 'yes', 'signing_key' => '   ']);

    // Eligibility, each rule on its own.
    $state['terminal_history_incomplete'] = $embedded(['bundle' => $bundle('terminated', 'incomplete', ['mutate' => static function (array &$document): void {
        $document['history_complete'] = false;
    }])]);
    $state['status_unsupported'] = $embedded(['bundle' => $bundle('rich', 'bogus-status', ['mutate' => static function (array &$document): void {
        $document['workflow']['status'] = 'bogus';
    }])]);
    $state['identity_missing'] = $embedded(['bundle' => $bundle('rich', 'no-identity', ['mutate' => static function (array &$document): void {
        unset($document['workflow']['instance_id'], $document['workflow']['run_id']);
    }])]);
    $state['source_runtime_unsupported'] = $embedded(['bundle' => $bundle('rich', 'standalone', ['mutate' => static function (array &$document): void {
        $document['workflow']['source_runtime'] = 'standalone_server';
    }])]);
    $state['payload_codec_unsupported'] = $embedded(['bundle' => $bundle('rich', 'json-codec', ['mutate' => static function (array &$document): void {
        $document['payloads']['codec'] = 'json';
        $document['commands'][0]['payload_codec'] = 'json';
        $document['signals'][0]['payload_codec'] = 5;
        $document['payload_manifest']['entries'][1]['codec'] = 'json';
    }])]);
    $state['memo_projection_mismatch'] = $embedded(['bundle' => $bundle('rich', 'memo-mismatch', ['mutate' => static function (array &$document): void {
        $document['workflow']['memo']['note'] = 'edited';
    }])]);
    $state['leased_task'] = $embedded(['bundle' => $bundle('rich', 'leased', ['mutate' => static function (array &$document): void {
        $document['tasks'][1]['status'] = 'leased';
        $document['activities'][0]['attempts'][0]['status'] = 'running';
    }])]);
    $state['running_attempt'] = $embedded(['bundle' => $bundle('rich', 'running-attempt', ['mutate' => static function (array &$document): void {
        $document['activities'][0]['attempts'][0]['status'] = 'running';
    }])]);
    // Real exports list redacted paths as strings, which the redaction rule
    // never sees; only a non-scalar path element is refused.
    $state['redaction_string_paths'] = $embedded(['bundle' => $bundle('rich', 'redacted-strings', ['mutate' => static function (array &$document): void {
        $document['redaction'] = ['applied' => true, 'policy' => 'parity', 'paths' => ['payloads.arguments.data']];
    }]), 'dry_run' => true]);
    $state['redaction_object_paths'] = $embedded(['bundle' => $bundle('rich', 'redacted-objects', ['mutate' => static function (array &$document): void {
        $document['redaction'] = ['applied' => true, 'policy' => 'parity', 'paths' => [['path' => 'payloads.arguments.data']]];
    }]), 'dry_run' => true]);
    $state['redaction_applied_without_paths'] = $embedded(['bundle' => $bundle('rich', 'redacted-empty', ['mutate' => static function (array &$document): void {
        $document['redaction'] = ['applied' => true, 'policy' => 'parity', 'paths' => []];
    }]), 'dry_run' => true]);

    // Request normalization: the server trims every string and turns empty
    // strings into null before verifying, so only a checksum over that form
    // verifies.
    $edges = static function (array &$document): void {
        $document['workflow']['business_key'] = '  bk-normalized  ';
        $document['workflow']['archive_reason'] = '';
    };
    $state['normalized_checksum'] = $embedded(['bundle' => $bundle('rich', 'normalized', ['mutate' => $edges])]);
    $state['unnormalized_checksum'] = $embedded(['bundle' => $bundle('rich', 'unnormalized', ['mutate' => $edges, 'checksum_raw' => true])]);

    // A bare bundle imports with default options; options beside it become
    // part of the bundle and break its checksum.
    $state['bare_bundle'] = $embedded($bundle('rich', 'bare'));
    $state['bare_bundle_with_option'] = $embedded($bundle('rich', 'bare-option', ['tamper' => static function (array &$document): void {
        $document['dry_run'] = true;
    }]));

    // A timestamp the importer cannot parse fails the write; nothing stays.
    $state['write_failed'] = $embedded(['bundle' => $bundle('rich', 'bad-time', ['mutate' => static function (array &$document): void {
        $document['workflow']['started_at'] = 'not a time';
    }])]);
    $state['write_failed_describe'] = $raw('GET', '/api/workflows/'.$instanceFor('bad-time'));

    // Terminated and non-terminal runs.
    // "no" is false, and a non-string import_id is ignored for a generated one.
    $state['terminated'] = $embedded(['bundle' => $bundle('terminated', 'terminated'), 'dry_run' => 'no', 'import_id' => 12345]);
    $state['non_terminal_not_current'] = $embedded(['bundle' => $bundle('running', 'not-current', ['mutate' => static function (array &$document): void {
        $document['workflow']['is_current_run'] = false;
    }])]);
    $state['running'] = $embedded(['bundle' => $bundle('running', 'running')]);
    // The imported ready task keeps its exported dispatch time, so it is
    // overdue at once; a repair pass may redispatch it before this read.
    $running = $raw('GET', '/api/workflows/'.$instanceFor('running'));
    $ready = ['Workflow task ready but dispatch is overdue', 'Workflow task ready'];
    if (in_array($running['response']['wait_reason'] ?? null, $ready, true)) {
        $running['response']['wait_reason'] = '{workflow_task_ready}';
    }
    if (in_array($running['response']['control_plane']['wait_reason'] ?? null, $ready, true)) {
        $running['response']['control_plane']['wait_reason'] = '{workflow_task_ready}';
    }
    $state['running_describe'] = $running;

    // The imported run reads like the exported one.
    $instance = $instanceFor('rich');
    $runId = $rich['workflow']['run_id'];
    $state['read_describe'] = $raw('GET', '/api/workflows/'.$instance);
    $state['read_runs'] = $raw('GET', '/api/workflows/'.$instance.'/runs');
    $state['read_run'] = $raw('GET', '/api/workflows/'.$instance.'/runs/'.$runId);
    $state['read_history'] = $raw('GET', '/api/workflows/'.$instance.'/runs/'.$runId.'/history');
    $state['read_terminated_history'] = $raw('GET', '/api/workflows/'.$instanceFor('terminated').'/runs/'
        .$bundle('terminated', 'terminated')['workflow']['run_id'].'/history');
    $state['read_list'] = $raw('GET', '/api/workflows?status=completed');

    // A run of an instance that belongs to another namespace.
    $state['attach_source'] = $embedded(['bundle' => $bundle('terminated', 'attach-source')], ['namespace' => $alt]);
    $before = $raw('GET', '/api/workflows/'.$instanceFor('attach-source'), null, ['namespace' => $alt]);
    $state['attach'] = $embedded(['bundle' => $bundle('terminated', 'attach', ['instance_of' => 'attach-source', 'mutate' => static function (array &$document): void {
        $document['workflow']['run_number'] = 2;
    }])]);
    $state['attach_source_describe_before'] = $before;
    $state['attach_source_describe_after'] = $raw('GET', '/api/workflows/'.$instanceFor('attach-source'), null, ['namespace' => $alt]);
    $state['attach_describe'] = $raw('GET', '/api/workflows/'.$instanceFor('attach-source'));

    return json_decode(strtr(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $labels),
        true, 512, JSON_THROW_ON_ERROR);
}
