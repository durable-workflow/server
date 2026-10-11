<?php

namespace App\Support;

use Workflow\V2\Support\ServiceCatalog;

final class ServiceCatalogReadContract
{
    public const DEFAULT_PAGE_SIZE = 50;

    public const MAX_PAGE_SIZE = 100;

    public const RELATED_LIMIT = 100;

    public static function manifest(): array
    {
        return [
            'schema' => 'durable-workflow.v2.service-catalog-read.contract',
            'version' => 1,
            'control_plane_version' => ControlPlaneProtocol::VERSION,
            'roles' => ['operator', 'admin'],
            'namespace' => 'resolved_authenticated_observer',
            'wire_surface' => [
                'endpoints' => '/api/service-catalog/endpoints',
                'endpoint' => '/api/service-catalog/endpoints/{id}',
                'services' => '/api/service-catalog/services',
                'service' => '/api/service-catalog/services/{id}',
                'operations' => '/api/service-catalog/operations',
                'operation' => '/api/service-catalog/operations/{id}',
                'calls' => '/api/service-catalog/calls',
                'call' => '/api/service-catalog/calls/{id}',
            ],
            'pagination' => [
                'default_per_page' => self::DEFAULT_PAGE_SIZE,
                'max_per_page' => self::MAX_PAGE_SIZE,
                'related_limit' => self::RELATED_LIMIT,
                'stable_ties' => 'id_ascending',
            ],
            'call_scopes' => ServiceCatalog::SCOPES,
            'foreign_payloads' => 'redacted',
            'unknown_or_foreign_detail' => 'catalog_object_not_found',
        ];
    }
}
