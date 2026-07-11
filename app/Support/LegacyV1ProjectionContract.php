<?php

namespace App\Support;

final class LegacyV1ProjectionContract
{
    public const SCHEMA = 'durable-workflow.v2.waterline-v1-projection.contract';

    public const REPORT_SCHEMA = 'durable-workflow.v2.waterline-v1-projection.report';

    public const VERSION = 1;

    public const IMPORT_SOURCE = 'waterline_v1_projection';

    public const ENGINE_SOURCE = 'v1_projection';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'operation' => [
                'server_endpoint' => 'POST /api/workflows/import/waterline-v1',
                'cli_command' => 'dw workflow:migrate-v1 <waterline-detail.json> --source-id=<stable-source-id>',
                'source_shape' => 'GET /waterline/api/flows/v1:<workflow-id>',
                'report_schema' => self::REPORT_SCHEMA,
                'dry_run_option' => '--dry-run',
            ],
            'identity' => [
                'waterline' => 'source_id plus qualified workflow id v1:<workflow-id>',
                'standalone' => 'deterministic, source-qualified workflow_id and run_id',
                'namespace_binding' => 'the first projection binds the deterministic identity to one standalone namespace',
                'collision_policy' => 'reject any existing native v2 identity or a repeat targeting another namespace; never overwrite',
            ],
            'semantics' => [
                'projection_only' => true,
                'source_of_execution_truth' => 'v1',
                'standalone_execution' => 'disabled',
                'repeat_same_projection' => 'already_projected only when the stored instance and run namespaces match the requested namespace',
                'changed_projection' => 'rejected; create a fresh migration snapshot after resolving the prior projection',
            ],
            'visibility' => [
                'describe' => 'GET /api/workflows/{workflowId}',
                'history' => 'GET /api/workflows/{workflowId}/runs/{runId}/history',
                'history_export' => 'GET /api/workflows/{workflowId}/runs/{runId}/history/export',
                'unsupported_fields' => 'stable reason and remediation objects on reports, describe responses, and history exports',
            ],
        ];
    }
}
