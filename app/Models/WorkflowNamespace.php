<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowNamespace extends Model
{
    protected $table = 'workflow_namespaces';

    protected $fillable = [
        'name',
        'description',
        'retention_days',
        'status',
        'external_payload_storage',
    ];

    protected $casts = [
        'external_payload_storage' => 'array',
    ];

    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = strtolower($value);
    }
}
