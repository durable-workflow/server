<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchAttributeDefinition extends Model
{
    protected $table = 'search_attribute_definitions';

    protected $fillable = [
        'namespace',
        'name',
        'type',
    ];

    public const ALLOWED_TYPES = [
        'keyword',
        'string',
        'text',
        'int',
        'double',
        'bool',
        'datetime',
        'keyword_list',
    ];

    /** Canonical worker/history type names. Registered aliases normalize to these values. */
    public const CANONICAL_TYPES = [
        'string',
        'keyword',
        'keyword_list',
        'int',
        'float',
        'bool',
        'datetime',
    ];

    public const SYSTEM_ATTRIBUTES = [
        'WorkflowType' => 'keyword',
        'WorkflowId' => 'keyword',
        'RunId' => 'keyword',
        'Status' => 'keyword',
        'ExecutionStatus' => 'keyword',
        'StartTime' => 'datetime',
        'ExecutionTime' => 'datetime',
        'CloseTime' => 'datetime',
        'TaskQueue' => 'keyword',
        'BuildId' => 'keyword',
        'BuildIds' => 'keyword',
    ];

    private const SYSTEM_ATTRIBUTE_ALIASES = [
        'workflow_type',
        'workflow_id',
        'wf_id',
        'run_id',
        'status',
        'execution_status',
        'start_time',
        'execution_time',
        'close_time',
        'task_queue',
        'build_id',
        'build_ids',
    ];

    public static function isReservedName(string $name): bool
    {
        return array_key_exists($name, self::SYSTEM_ATTRIBUTES)
            || in_array(strtolower($name), self::SYSTEM_ATTRIBUTE_ALIASES, true)
            || str_starts_with($name, '__');
    }

    public static function canonicalType(string $type): ?string
    {
        return match ($type) {
            'text' => 'string',
            'double' => 'float',
            default => in_array($type, self::CANONICAL_TYPES, true) ? $type : null,
        };
    }
}
