<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Workflow\Serializers\Avro;
use Workflow\V2\Support\ExternalPayloads;

final class PayloadCodecDeploymentPreflight
{
    private const CODEC_COLUMNS = [
        'workflow_runs' => ['payload_codec', 'output_payload_codec'],
        'activity_executions' => ['payload_codec'],
        'workflow_commands' => ['payload_codec'],
        'workflow_signal_records' => ['payload_codec'],
        'workflow_updates' => ['payload_codec'],
        'workflow_service_calls' => ['payload_codec'],
        'workflow_update_validation_tasks' => ['payload_codec'],
        'workflow_durable_stream_items' => ['payload_codec'],
    ];

    private const PAYLOAD_COLUMNS = [
        'workflow_runs' => [
            'payload_codec' => ['arguments'],
            'output_payload_codec' => ['output'],
        ],
        'activity_executions' => ['payload_codec' => ['arguments', 'result', 'exception']],
        'workflow_commands' => ['payload_codec' => ['payload']],
        'workflow_signal_records' => ['payload_codec' => ['arguments']],
        'workflow_updates' => ['payload_codec' => ['arguments', 'result']],
        'workflow_update_validation_tasks' => ['payload_codec' => ['arguments']],
    ];

    private const HISTORY_PAYLOAD_FIELDS = [
        'arguments',
        'result',
        'output',
        'value',
        'request_payload',
        'response_payload',
        'details',
    ];

    /**
     * Inventory every persisted codec tag and the framing of every inline Avro
     * payload before an Avro-only Server deployment is allowed to proceed.
     *
     * @return array{codec_counts: array<string, array<string, int>>, inspected_frames: int}
     */
    public function assertReady(): array
    {
        $counts = [];
        $failures = [];
        $inspectedFrames = 0;

        foreach (self::CODEC_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $inventory = DB::table($table)
                    ->whereNotNull($column)
                    ->selectRaw($column.' as codec, count(*) as aggregate')
                    ->groupBy($column)
                    ->pluck('aggregate', 'codec')
                    ->map(static fn (mixed $count): int => (int) $count)
                    ->all();
                $counts[$table.'.'.$column] = $inventory;

                foreach ($inventory as $codec => $count) {
                    if ($codec !== 'avro') {
                        $failures[] = sprintf('%s.%s=%s (%d row%s)', $table, $column, (string) $codec, $count, $count === 1 ? '' : 's');
                    }
                }
            }
        }

        foreach (self::PAYLOAD_COLUMNS as $table => $codecPayloads) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            foreach ($codecPayloads as $codecColumn => $payloadColumns) {
                if (! Schema::hasColumn($table, $codecColumn)) {
                    continue;
                }

                foreach ($payloadColumns as $payloadColumn) {
                    if (! Schema::hasColumn($table, $payloadColumn)) {
                        continue;
                    }

                    DB::table($table)
                        ->select(['id', $codecColumn, $payloadColumn])
                        ->whereNotNull($payloadColumn)
                        ->where(function ($query) use ($codecColumn): void {
                            $query->where($codecColumn, 'avro')
                                ->orWhereNull($codecColumn)
                                ->orWhere($codecColumn, '');
                        })
                        ->orderBy('id')
                        ->chunkById(250, function ($rows) use ($table, $codecColumn, $payloadColumn, &$failures, &$inspectedFrames): void {
                            foreach ($rows as $row) {
                                $payload = $row->{$payloadColumn};
                                if (! is_string($payload) || $payload === '') {
                                    continue;
                                }

                                if ($row->{$codecColumn} === null || $row->{$codecColumn} === '') {
                                    $failures[] = sprintf(
                                        '%s[%s].%s: non-null durable payload requires explicit payload_codec=avro',
                                        $table,
                                        (string) $row->id,
                                        $payloadColumn,
                                    );

                                    continue;
                                }

                                $this->inspectAvroPayload(
                                    $payload,
                                    sprintf('%s[%s].%s', $table, (string) $row->id, $payloadColumn),
                                    $failures,
                                    $inspectedFrames,
                                );
                            }
                        }, 'id');
                }
            }
        }

        if (Schema::hasTable('workflow_history_events') && Schema::hasColumn('workflow_history_events', 'payload')) {
            DB::table('workflow_history_events')
                ->select(['id', 'payload'])
                ->orderBy('id')
                ->chunkById(250, function ($rows) use (&$failures, &$inspectedFrames): void {
                    foreach ($rows as $row) {
                        $payload = is_string($row->payload)
                            ? json_decode($row->payload, true)
                            : $row->payload;
                        $this->inspectHistoryPayload($payload, 'workflow_history_events['.$row->id.'].payload', $failures, $inspectedFrames);
                    }
                }, 'id');
        }

        if ($failures !== []) {
            throw new RuntimeException(
                "unsupported_payload_codec: Avro-only deployment preflight blocked.\n"
                .implode("\n", array_slice($failures, 0, 50))
                ."\nRemediation: stop deployment and retain customer history. Drain or export each affected active or replay-relevant run with the currently deployed prerelease, re-encode it with the fixed Avro Value schema and single-object framing (fingerprint crc64-avro:"
                .Avro::VALUE_SCHEMA_FINGERPRINT_HEX
                .'), verify the inventory is clean, then retry. Do not delete history. JSON remains the HTTP document transport, not a workflow payload codec.',
            );
        }

        return ['codec_counts' => $counts, 'inspected_frames' => $inspectedFrames];
    }

    /**
     * History payloads also contain customer-owned memo, search-attribute,
     * context, and diagnostic maps. Inspect only protocol-owned payload slots
     * and their explicit envelopes; codec-looking customer keys are data.
     *
     * @param  list<string>  $failures
     */
    private function inspectHistoryPayload(mixed $value, string $path, array &$failures, int &$inspectedFrames): void
    {
        if (! is_array($value)) {
            return;
        }

        $this->inspectDeclaredPayloadFields(
            $value,
            $path,
            'payload_codec',
            self::HISTORY_PAYLOAD_FIELDS,
            $failures,
            $inspectedFrames,
        );

        foreach (['command', 'activity'] as $snapshotField) {
            if (! is_array($value[$snapshotField] ?? null)) {
                continue;
            }

            $snapshotPayloadFields = $snapshotField === 'command'
                ? ['payload']
                : ['arguments', 'result', 'exception'];
            $this->inspectDeclaredPayloadFields(
                $value[$snapshotField],
                $path.'.'.$snapshotField,
                'payload_codec',
                $snapshotPayloadFields,
                $failures,
                $inspectedFrames,
            );
        }

        if (is_array($value['exception'] ?? null)) {
            $this->inspectDeclaredPayloadFields(
                $value['exception'],
                $path.'.exception',
                'details_payload_codec',
                ['details'],
                $failures,
                $inspectedFrames,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $container
     * @param  list<string>  $payloadFields
     * @param  list<string>  $failures
     */
    private function inspectDeclaredPayloadFields(
        array $container,
        string $path,
        string $codecField,
        array $payloadFields,
        array &$failures,
        int &$inspectedFrames,
    ): void {
        $declaredCodec = array_key_exists($codecField, $container)
            ? $this->inspectCodecDeclaration($container[$codecField], $path.'.'.$codecField, $failures)
            : null;

        foreach ($payloadFields as $payloadField) {
            $payload = $container[$payloadField] ?? null;
            if (is_string($payload) && $payload !== '') {
                if ($declaredCodec === 'avro') {
                    $this->inspectAvroPayload($payload, $path.'.'.$payloadField, $failures, $inspectedFrames);
                }

                continue;
            }

            if (is_array($payload)) {
                $this->inspectPayloadEnvelope($payload, $path.'.'.$payloadField, $failures, $inspectedFrames);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $failures
     */
    private function inspectPayloadEnvelope(
        array $envelope,
        string $path,
        array &$failures,
        int &$inspectedFrames,
    ): void {
        if (! array_key_exists('codec', $envelope)
            && ! array_key_exists('blob', $envelope)
            && ! array_key_exists('external_storage', $envelope)
        ) {
            return;
        }

        if (array_key_exists('external_storage', $envelope)) {
            $this->inspectExternalPayloadEnvelope($envelope, $path, $failures);

            return;
        }

        $codec = array_key_exists('codec', $envelope)
            ? $this->inspectCodecDeclaration($envelope['codec'], $path.'.codec', $failures)
            : null;

        if (array_key_exists('blob', $envelope) && is_string($envelope['blob'])) {
            if ($codec !== 'avro') {
                $failures[] = sprintf('%s.blob: durable payload requires explicit payload_codec=avro', $path);
            } else {
                $this->inspectAvroPayload($envelope['blob'], $path.'.blob', $failures, $inspectedFrames);
            }
        }
    }

    /** @param list<string> $failures */
    private function inspectCodecDeclaration(mixed $codec, string $path, array &$failures): ?string
    {
        if (! is_string($codec) || $codec === '') {
            $failures[] = sprintf('%s: durable payload requires explicit payload_codec=avro', $path);

            return null;
        }

        if ($codec !== 'avro') {
            $failures[] = sprintf('%s=%s', $path, $codec);

            return null;
        }

        return $codec;
    }

    /** @param list<string> $failures */
    private function inspectAvroPayload(string $payload, string $path, array &$failures, int &$inspectedFrames): void
    {
        if (ExternalPayloads::isStoredReference($payload)) {
            try {
                $envelope = ExternalPayloads::storedEnvelope($payload);
            } catch (InvalidArgumentException $exception) {
                $failures[] = sprintf('%s: invalid_external_payload_reference: %s', $path, $exception->getMessage());

                return;
            }

            if ($envelope === null) {
                $failures[] = sprintf('%s: invalid_external_payload_reference', $path);

                return;
            }

            $this->inspectExternalPayloadEnvelope($envelope, $path, $failures);

            return;
        }

        $this->inspectAvroFrame($payload, $path, $failures, $inspectedFrames);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $failures
     */
    private function inspectExternalPayloadEnvelope(array $envelope, string $path, array &$failures): void
    {
        try {
            $storedReference = ExternalPayloads::encodeStoredEnvelope($envelope);
            $canonicalEnvelope = ExternalPayloads::storedEnvelope($storedReference);
        } catch (InvalidArgumentException $exception) {
            $failures[] = sprintf('%s: invalid_external_payload_reference: %s', $path, $exception->getMessage());

            return;
        }

        if ($canonicalEnvelope === null) {
            $failures[] = sprintf('%s: invalid_external_payload_reference', $path);

            return;
        }

        if (($canonicalEnvelope['codec'] ?? null) !== 'avro') {
            $failures[] = sprintf('%s.codec=%s', $path, (string) ($canonicalEnvelope['codec'] ?? ''));
        }

        if (($canonicalEnvelope['external_storage']['codec'] ?? null) !== 'avro') {
            $failures[] = sprintf(
                '%s.external_storage.codec=%s',
                $path,
                (string) ($canonicalEnvelope['external_storage']['codec'] ?? ''),
            );
        }
    }

    /** @param list<string> $failures */
    private function inspectAvroFrame(string $blob, string $path, array &$failures, int &$inspectedFrames): void
    {
        $inspectedFrames++;
        $diagnostic = Avro::payloadMetadata($blob)['diagnostic'];
        if ($diagnostic !== null) {
            $failures[] = sprintf('%s: %s', $path, $diagnostic);
        }
    }
}
