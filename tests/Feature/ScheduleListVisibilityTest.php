<?php

namespace Tests\Feature;

use App\Models\SearchAttributeDefinition;
use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowSchedule;

class ScheduleListVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
        $this->createNamespace('other');

        foreach ([
            'Region' => 'keyword',
            'Priority' => 'int',
            'Expedited' => 'bool',
            'Tags' => 'keyword_list',
        ] as $name => $type) {
            SearchAttributeDefinition::query()->create([
                'namespace' => 'default',
                'name' => $name,
                'type' => $type,
            ]);
        }
    }

    public function test_filters_status_workflow_type_system_fields_and_search_attributes_with_and_semantics(): void
    {
        $this->schedule('active-orders-eu', status: 'active', workflowType: 'orders.process', attributes: [
            'Region' => 'eu',
            'Priority' => 2,
            'Expedited' => true,
            'Tags' => ['finance', 'urgent'],
        ], note: 'research AND development OR archived');
        $this->schedule('paused-orders-eu', status: 'paused', workflowType: 'orders.process', attributes: [
            'Region' => 'eu',
            'Priority' => 2,
            'Expedited' => false,
            'Tags' => ['finance'],
        ]);
        $this->schedule('active-orders-us', status: 'active', workflowType: 'orders.process', attributes: [
            'Region' => 'us',
            'Priority' => 3,
            'Expedited' => true,
            'Tags' => ['urgent'],
        ]);
        $this->schedule('active-reports-eu', status: 'active', workflowType: 'reports.rollup', attributes: [
            'Region' => 'eu',
            'Priority' => 2,
            'Expedited' => true,
            'Tags' => ['finance'],
        ]);

        $this->assertSame(
            ['active-orders-eu', 'active-orders-us', 'active-reports-eu'],
            $this->listIds(['status' => 'active']),
        );
        $this->assertSame(
            ['active-orders-eu', 'active-orders-us', 'paused-orders-eu'],
            $this->listIds(['workflow_type' => 'orders.process']),
        );
        $this->assertSame(
            ['active-orders-eu', 'active-reports-eu', 'paused-orders-eu'],
            $this->listIds(['query' => 'Region = "eu" AND Priority = 2']),
        );
        $this->assertSame(
            ['active-orders-eu', 'active-reports-eu'],
            $this->listIds(['query' => 'Tags = "finance" AND Expedited = true']),
        );
        $this->assertSame(
            ['active-orders-eu'],
            $this->listIds([
                'status' => 'active',
                'workflow_type' => 'orders.process',
                'query' => 'Region = "eu" AND ScheduleId = "active-orders-eu"',
            ]),
        );
        $this->assertSame(
            ['active-orders-eu'],
            $this->listIds(['query' => 'Note = "research AND development OR archived"']),
        );
    }

    public function test_keyset_pagination_returns_each_matching_schedule_once_and_terminates(): void
    {
        $newest = Carbon::parse('2026-07-14T12:00:00Z');
        $this->schedule('schedule-c', createdAt: $newest);
        $this->schedule('schedule-a', createdAt: $newest);
        $this->schedule('schedule-b', createdAt: $newest);
        $this->schedule('schedule-d', createdAt: $newest->copy()->subMinute());
        $this->schedule('schedule-e', createdAt: $newest->copy()->subMinutes(2));

        $expected = ['schedule-a', 'schedule-b', 'schedule-c', 'schedule-d', 'schedule-e'];
        $seen = [];
        $token = null;
        $pageCount = 0;

        do {
            $query = ['page_size' => 2];
            if ($token !== null) {
                $query['next_page_token'] = $token;
            }

            $response = $this->getJson('/api/schedules?'.http_build_query($query), $this->headers())
                ->assertOk();
            $pageIds = array_column($response->json('schedules'), 'schedule_id');
            $this->assertSame(count($pageIds), count(array_unique($pageIds)));
            array_push($seen, ...$pageIds);
            $token = $response->json('next_page_token');
            $pageCount++;

            $this->assertLessThanOrEqual(3, $pageCount, 'Pagination did not terminate.');
        } while ($token !== null);

        $this->assertSame(3, $pageCount);
        $this->assertSame($expected, $seen);
        $this->assertSame($expected, array_values(array_unique($seen)));
    }

    public function test_list_never_leaks_another_namespace_or_deleted_schedule(): void
    {
        $this->schedule('visible-default');
        $this->schedule('deleted-default', status: 'deleted');
        $this->schedule('other-namespace', namespace: 'other');

        $this->assertSame(['visible-default'], $this->listIds());
        $this->assertSame(['other-namespace'], $this->listIds(namespace: 'other'));
    }

    public function test_invalid_filters_and_queries_return_typed_field_evidence(): void
    {
        $cases = [
            [
                'query' => ['status' => 'deleted'],
                'status' => 422,
                'reason' => 'validation_failed',
                'field' => 'status',
            ],
            [
                'query' => ['page_size' => 0],
                'status' => 422,
                'reason' => 'validation_failed',
                'field' => 'page_size',
            ],
            [
                'query' => ['unknown_filter' => 'value'],
                'status' => 422,
                'reason' => 'unsupported_schedule_list_filter',
                'field' => 'unknown_filter',
            ],
            [
                'query' => ['query' => 'Region STARTS_WITH "e"'],
                'status' => 422,
                'reason' => 'unsupported_schedule_visibility_predicate',
                'field' => 'query',
            ],
            [
                'query' => ['query' => 'Unregistered = "value"'],
                'status' => 422,
                'reason' => 'unsupported_schedule_visibility_field',
                'field' => 'query',
            ],
            [
                'query' => ['query' => 'Priority = "high"'],
                'status' => 422,
                'reason' => 'invalid_schedule_visibility_query',
                'field' => 'query',
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->getJson(
                '/api/schedules?'.http_build_query($case['query']),
                $this->headers(),
            );

            $response->assertStatus($case['status'])
                ->assertJsonPath('reason', $case['reason'])
                ->assertJsonPath('field', $case['field'])
                ->assertJsonPath('last_safe_cursor', null);
            $this->assertNotEmpty($response->json('errors.'.$case['field'].'.0'));
        }
    }

    public function test_explicitly_blank_filters_return_typed_field_evidence(): void
    {
        foreach (['page_size', 'next_page_token', 'status', 'workflow_type', 'query'] as $field) {
            $response = $this->getJson('/api/schedules?'.$field.'=', $this->headers());

            $response->assertUnprocessable()
                ->assertJsonPath('reason', 'validation_failed')
                ->assertJsonPath('field', $field)
                ->assertJsonPath('last_safe_cursor', null);
            $this->assertNotEmpty(
                $response->json('errors.'.$field.'.0'),
                sprintf('Blank schedule-list filter [%s] must include field evidence.', $field),
            );
            $this->assertSame(
                $response->json('errors.'.$field),
                $response->json('validation_errors.'.$field),
                sprintf('Blank schedule-list filter [%s] must preserve validation evidence.', $field),
            );
        }
    }

    public function test_continuation_token_refusals_preserve_reason_field_and_last_safe_cursor(): void
    {
        $this->schedule('active-new', status: 'active', createdAt: Carbon::parse('2026-07-14T12:00:00Z'));
        $this->schedule('active-old', status: 'active', createdAt: Carbon::parse('2026-07-14T11:00:00Z'));

        $first = $this->getJson('/api/schedules?page_size=1', $this->headers())
            ->assertOk();
        $token = (string) $first->json('next_page_token');
        $this->assertNotSame('', $token);

        $this->assertTokenError(
            ['next_page_token' => 'not-an-opaque-token'],
            400,
            'malformed_schedule_page_token',
            null,
        );
        $invalidQuery = $this->getJson('/api/schedules?'.http_build_query([
            'query' => 'Region STARTS_WITH "e"',
            'next_page_token' => $token,
        ]), $this->headers());
        $invalidQuery->assertStatus(422)
            ->assertJsonPath('reason', 'unsupported_schedule_visibility_predicate')
            ->assertJsonPath('field', 'query')
            ->assertJsonPath('last_safe_cursor.schedule_id', 'active-new');
        $this->assertNotEmpty($invalidQuery->json('errors.query.0'));
        $this->assertTokenError(
            ['status' => 'paused', 'next_page_token' => $token],
            409,
            'schedule_page_token_filter_mismatch',
            'active-new',
        );
        $this->assertTokenError(
            ['next_page_token' => $token],
            403,
            'schedule_page_token_namespace_mismatch',
            'active-new',
            'other',
        );

        WorkflowSchedule::query()
            ->where('namespace', 'default')
            ->where('schedule_id', 'active-new')
            ->update(['status' => 'deleted']);

        $this->assertTokenError(
            ['next_page_token' => $token],
            409,
            'stale_schedule_page_token',
            'active-new',
        );
    }

    /** @param array<string, int|string> $query */
    private function assertTokenError(
        array $query,
        int $status,
        string $reason,
        ?string $cursorScheduleId,
        string $namespace = 'default',
    ): void
    {
        $response = $this->getJson(
            '/api/schedules?'.http_build_query($query),
            $this->headers($namespace),
        );

        $response->assertStatus($status)
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('field', 'next_page_token');
        $this->assertNotEmpty($response->json('errors.next_page_token.0'));

        if ($cursorScheduleId === null) {
            $response->assertJsonPath('last_safe_cursor', null);
        } else {
            $response->assertJsonPath('last_safe_cursor.schedule_id', $cursorScheduleId);
            $this->assertNotEmpty($response->json('last_safe_cursor.created_at'));
        }
    }

    /**
     * @param array<string, int|string> $query
     * @return list<string>
     */
    private function listIds(array $query = [], string $namespace = 'default'): array
    {
        $path = '/api/schedules'.($query === [] ? '' : '?'.http_build_query($query));
        $response = $this->getJson($path, $this->headers($namespace))->assertOk();
        $ids = array_column($response->json('schedules'), 'schedule_id');
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed> $attributes */
    private function schedule(
        string $scheduleId,
        string $status = 'active',
        string $workflowType = 'orders.process',
        array $attributes = [],
        string $namespace = 'default',
        ?Carbon $createdAt = null,
        ?string $note = null,
    ): WorkflowSchedule
    {
        $createdAt ??= Carbon::parse('2026-07-14T10:00:00Z');

        return WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'status' => $status,
            'spec' => ['intervals' => [['every' => 'PT1H']], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => $workflowType, 'task_queue' => 'default'],
            'search_attributes' => $attributes,
            'note' => $note,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createNamespace(string $name): void
    {
        WorkflowNamespace::query()->create([
            'name' => $name,
            'description' => $name.' namespace',
            'retention_days' => 30,
            'status' => 'active',
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $namespace = 'default'): array
    {
        return [
            'X-Namespace' => $namespace,
            ControlPlaneProtocol::HEADER => ControlPlaneProtocol::VERSION,
        ];
    }
}
