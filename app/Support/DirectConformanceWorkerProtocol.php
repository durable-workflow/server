<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonException;

/**
 * Current worker-protocol boundary for published conformance probes that do
 * not run through a released SDK client.
 */
final class DirectConformanceWorkerProtocol
{
    /** @var list<string> */
    public const RELEASE_CRITICAL_PROBES = [
        'activities',
        'worker-versioning',
        'timers',
        'workflow-lifecycle',
        'workflow-updates-operator-diagnostics',
    ];

    private const UNSUPPORTED_CAPABILITY_REASON = 'not_implemented_by_direct_conformance_probe';

    /**
     * @param  list<string>  $workflowTypes
     * @param  list<string>  $activityTypes
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, bool|string>>|null  $capabilityManifest
     * @return array<string, mixed>
     */
    public static function registration(
        string $workerId,
        string $taskQueue,
        string $runtime,
        string $sdkVersion,
        array $workflowTypes,
        array $activityTypes,
        array $capabilities = [],
        array $attributes = [],
        ?array $capabilityManifest = null,
    ): array {
        self::assertNonEmpty($workerId, 'worker_id');
        self::assertNonEmpty($taskQueue, 'task_queue');
        self::assertNonEmpty($runtime, 'runtime');
        self::assertNonEmpty($sdkVersion, 'sdk_version');
        self::assertDeclarations($workflowTypes, 'supported_workflow_types');
        self::assertDeclarations($activityTypes, 'supported_activity_types');
        self::assertDeclarations($capabilities, 'capabilities');

        foreach ([
            'worker_id',
            'task_queue',
            'runtime',
            'sdk_version',
            'supported_workflow_types',
            'supported_activity_types',
            'capabilities',
            'capability_manifest',
        ] as $reserved) {
            if (array_key_exists($reserved, $attributes)) {
                throw new InvalidArgumentException("Direct conformance registration attribute {$reserved} is reserved.");
            }
        }

        $workflowConcurrency = $workflowTypes === [] ? 0 : 1;
        $activityConcurrency = $activityTypes === [] ? 0 : 1;
        $payload = [
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'runtime' => $runtime,
            'sdk_version' => $sdkVersion,
            'supported_workflow_types' => array_values($workflowTypes),
            'supported_activity_types' => array_values($activityTypes),
            'capabilities' => array_values($capabilities),
            'capability_manifest' => $capabilityManifest ?? self::unsupportedCapabilityManifest(),
            'max_concurrent_workflow_tasks' => $workflowConcurrency,
            'max_concurrent_activity_tasks' => $activityConcurrency,
            'task_slots' => [
                'workflow_available' => $workflowConcurrency,
                'activity_available' => $activityConcurrency,
                'session_available' => 0,
            ],
            ...$attributes,
        ];

        self::assertRegistrationPayload($payload);

        return $payload;
    }

    /**
     * @return array<string, array{supported: false, minimum_protocol_version: string, reason: string}>
     */
    public static function unsupportedCapabilityManifest(): array
    {
        return array_fill_keys(WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES, [
            'supported' => false,
            'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'reason' => self::UNSUPPORTED_CAPABILITY_REASON,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function assertRegistrationPayload(array $payload): void
    {
        foreach (['worker_id', 'task_queue', 'runtime', 'sdk_version'] as $field) {
            self::assertNonEmpty($payload[$field] ?? null, $field);
        }
        foreach (['supported_workflow_types', 'supported_activity_types', 'capabilities'] as $field) {
            self::assertDeclarations($payload[$field] ?? null, $field);
        }

        $manifest = $payload['capability_manifest'] ?? null;
        if (! is_array($manifest)) {
            throw new InvalidArgumentException('Direct conformance registration requires capability_manifest.');
        }

        foreach (WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES as $capability) {
            $entry = $manifest[$capability] ?? null;
            if (! is_array($entry) || ! is_bool($entry['supported'] ?? null)) {
                throw new InvalidArgumentException("Direct conformance capability_manifest.{$capability}.supported must be boolean.");
            }
            if (($entry['minimum_protocol_version'] ?? null) !== WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION) {
                throw new InvalidArgumentException("Direct conformance capability_manifest.{$capability} has a stale minimum protocol version.");
            }

            $explanation = ($entry['supported'] ?? false) === true
                ? ($entry['implementation'] ?? null)
                : ($entry['reason'] ?? null);
            self::assertNonEmpty($explanation, "capability_manifest.{$capability}.explanation");
        }
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  list<array<string, mixed>>  $commands
     * @return array{lease_owner: string, workflow_task_attempt: int, commands: list<array<string, mixed>>}
     */
    public static function workflowTaskCompletion(array $task, array $commands): array
    {
        $taskId = $task['task_id'] ?? null;
        self::assertNonEmpty($taskId, 'task.task_id');
        $leaseOwner = $task['lease_owner'] ?? null;
        self::assertNonEmpty($leaseOwner, 'task.lease_owner');
        $attempt = $task['workflow_task_attempt'] ?? null;
        if (! is_int($attempt) || $attempt < 1) {
            throw new InvalidArgumentException('Direct conformance task.workflow_task_attempt must be a positive integer.');
        }
        if ($commands === [] || ! array_is_list($commands)) {
            throw new InvalidArgumentException('Direct conformance workflow completion requires a non-empty command list.');
        }

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                throw new InvalidArgumentException("Direct conformance commands.{$index} must be an object.");
            }
            self::assertCommandValues($command, $index);
        }

        return [
            'lease_owner' => $leaseOwner,
            'workflow_task_attempt' => $attempt,
            'commands' => $commands,
        ];
    }

    /** @param array<string, mixed> $command */
    private static function assertCommandValues(array $command, int $index): void
    {
        $type = $command['type'] ?? null;
        self::assertNonEmpty($type, "commands.{$index}.type");

        $fields = match ($type) {
            'complete_workflow', 'complete_update', 'record_side_effect' => ['result'],
            'continue_as_new', 'schedule_activity', 'start_child_workflow' => ['arguments'],
            default => [],
        };
        foreach ($fields as $field) {
            if (array_key_exists($field, $command)) {
                self::assertAvroPayload($command[$field], "commands.{$index}.{$field}");
            }
        }

        $exception = $command['exception'] ?? null;
        if (is_array($exception) && array_key_exists('details', $exception)) {
            self::assertAvroPayload($exception['details'], "commands.{$index}.exception.details");
        }
    }

    private static function assertAvroPayload(mixed $payload, string $field): void
    {
        if (self::isAvroEnvelope($payload)) {
            self::assertNotJsonShapedString($payload['blob'], "{$field}.blob");

            return;
        }
        if (is_string($payload) && $payload !== '') {
            self::assertNotJsonShapedString($payload, $field);

            return;
        }

        throw new InvalidArgumentException("Direct conformance {$field} must contain an Avro Value payload.");
    }

    private static function isAvroEnvelope(mixed $value): bool
    {
        return is_array($value)
            && ($value['codec'] ?? null) === 'avro'
            && is_string($value['blob'] ?? null)
            && $value['blob'] !== '';
    }

    private static function assertNotJsonShapedString(string $value, string $field): void
    {
        if (self::isJsonShapedString($value)) {
            throw new InvalidArgumentException("Direct conformance {$field} contains json_bytes_labeled_avro.");
        }
    }

    private static function isJsonShapedString(string $value): bool
    {
        $trimmed = trim($value);
        if (preg_match('/\A[\[{\"]/', $trimmed) === 1) {
            return true;
        }

        try {
            json_decode($trimmed, flags: JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }

    private static function assertDeclarations(mixed $values, string $field): void
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException("Direct conformance {$field} must be a list.");
        }
        foreach ($values as $value) {
            self::assertNonEmpty($value, $field);
        }
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException("Direct conformance {$field} must not contain duplicates.");
        }
    }

    private static function assertNonEmpty(mixed $value, string $field): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Direct conformance {$field} must be a non-empty string.");
        }
    }
}
