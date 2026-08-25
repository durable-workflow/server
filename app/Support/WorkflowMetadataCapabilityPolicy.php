<?php

namespace App\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;

final class WorkflowMetadataCapabilityPolicy
{
    public const MEMO_UPSERTS = 'memo_upserts';

    public const TYPED_SEARCH_ATTRIBUTES = 'typed_search_attributes';

    public const MEMO_UPSERTS_MINIMUM_PROTOCOL_VERSION = '1.14';

    public const TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION = '1.16';

    public const CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION = '1.17';

    /**
     * @return array<string, array{minimum_protocol_version: string, command_type: string, history_event: string}>
     */
    public static function definitions(): array
    {
        return [
            self::MEMO_UPSERTS => [
                'minimum_protocol_version' => self::MEMO_UPSERTS_MINIMUM_PROTOCOL_VERSION,
                'command_type' => 'upsert_memo',
                'history_event' => HistoryEventType::MemoUpserted->value,
            ],
            self::TYPED_SEARCH_ATTRIBUTES => [
                'minimum_protocol_version' => self::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
                'command_type' => 'upsert_search_attributes',
                'history_event' => HistoryEventType::SearchAttributesUpserted->value,
            ],
        ];
    }

    /**
     * @param  list<string>  $capabilities
     * @return array{capability: string, minimum_protocol_version: string}|null
     */
    public static function firstProtocolMismatch(array $capabilities, ?string $protocolVersion): ?array
    {
        foreach (self::definitions() as $capability => $definition) {
            if (! in_array($capability, $capabilities, true)) {
                continue;
            }

            if (WorkerProtocol::versionMeetsMinimum(
                $protocolVersion,
                $definition['minimum_protocol_version'],
            )) {
                continue;
            }

            return [
                'capability' => $capability,
                'minimum_protocol_version' => $definition['minimum_protocol_version'],
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<string>
     */
    public static function requiredForCommands(array $commands): array
    {
        $required = [];

        foreach ($commands as $command) {
            $type = $command['type'] ?? null;

            if ($type === 'upsert_memo') {
                $required[self::MEMO_UPSERTS] = self::MEMO_UPSERTS;
            }

            if ($type === 'upsert_search_attributes' && array_key_exists('attribute_types', $command)) {
                $required[self::TYPED_SEARCH_ATTRIBUTES] = self::TYPED_SEARCH_ATTRIBUTES;
            }
        }

        return array_values($required);
    }

    /**
     * @return list<string>
     */
    public static function requiredForRun(string $runId): array
    {
        $required = [];
        $events = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::MemoUpserted->value,
                HistoryEventType::SearchAttributesUpserted->value,
            ])
            ->get(['event_type', 'payload']);

        foreach ($events as $event) {
            if ($event->event_type === HistoryEventType::MemoUpserted) {
                $required[self::MEMO_UPSERTS] = self::MEMO_UPSERTS;

                continue;
            }

            if (
                $event->event_type === HistoryEventType::SearchAttributesUpserted
                && is_array($event->payload)
                && array_key_exists('attribute_types', $event->payload)
            ) {
                $required[self::TYPED_SEARCH_ATTRIBUTES] = self::TYPED_SEARCH_ATTRIBUTES;
            }
        }

        return array_values($required);
    }

    /**
     * @param  list<string>  $registeredCapabilities
     * @return list<string>
     */
    public static function missingForCommands(array $commands, array $registeredCapabilities): array
    {
        return self::missing(self::requiredForCommands($commands), $registeredCapabilities, null);
    }

    /**
     * @param  list<string>  $registeredCapabilities
     */
    public static function canReplayRun(
        string $runId,
        array $registeredCapabilities,
        ?string $protocolVersion,
    ): bool {
        if (
            self::runRequiresConditionWaitOccurrenceIdentity($runId)
            && ! WorkerProtocol::versionMeetsMinimum(
                $protocolVersion,
                self::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION,
            )
        ) {
            return false;
        }

        return self::missing(
            self::requiredForRun($runId),
            $registeredCapabilities,
            $protocolVersion,
        ) === [];
    }

    private static function runRequiresConditionWaitOccurrenceIdentity(string $runId): bool
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ConditionWaitOpened->value,
                HistoryEventType::ConditionWaitSatisfied->value,
                HistoryEventType::ConditionWaitTimedOut->value,
                HistoryEventType::TimerScheduled->value,
                HistoryEventType::TimerFired->value,
                HistoryEventType::TimerCancelled->value,
            ])
            ->get(['payload'])
            ->contains(static fn (WorkflowHistoryEvent $event): bool => is_array($event->payload)
                && is_string($event->payload['condition_wait_occurrence_id'] ?? null)
                && trim($event->payload['condition_wait_occurrence_id']) !== '');
    }

    /**
     * @param  list<string>  $requiredCapabilities
     * @param  list<string>  $registeredCapabilities
     * @return list<string>
     */
    private static function missing(
        array $requiredCapabilities,
        array $registeredCapabilities,
        ?string $protocolVersion,
    ): array {
        $definitions = self::definitions();

        return array_values(array_filter(
            $requiredCapabilities,
            static function (string $capability) use (
                $registeredCapabilities,
                $protocolVersion,
                $definitions,
            ): bool {
                if (! in_array($capability, $registeredCapabilities, true)) {
                    return true;
                }

                if ($protocolVersion === null) {
                    return false;
                }

                return ! WorkerProtocol::versionMeetsMinimum(
                    $protocolVersion,
                    $definitions[$capability]['minimum_protocol_version'],
                );
            },
        ));
    }
}
