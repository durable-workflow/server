<?php

use App\Support\EnvAuditor;

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'external-payload-s3' => [
            'driver' => 's3',
            'key' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID',
            ),
            'secret' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY',
            ),
            'token' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_SESSION_TOKEN',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_SESSION_TOKEN',
            ),
            'region' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_REGION',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_REGION',
                'us-east-1',
            ),
            'bucket' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_BUCKET',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_BUCKET',
            ),
            'endpoint' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_ENDPOINT',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_ENDPOINT',
            ),
            'use_path_style_endpoint' => EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT',
                false,
            ),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
