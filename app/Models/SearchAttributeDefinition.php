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
        'text',
        'int',
        'double',
        'bool',
        'datetime',
        'keyword_list',
    ];

    public const SYSTEM_ATTRIBUTES = [
        'WorkflowType' => 'keyword',
        'WorkflowId' => 'keyword',
        'RunId' => 'keyword',
        'Status' => 'keyword',
        'StartTime' => 'datetime',
        'CloseTime' => 'datetime',
        'TaskQueue' => 'keyword',
        'BuildId' => 'keyword',
    ];
}
