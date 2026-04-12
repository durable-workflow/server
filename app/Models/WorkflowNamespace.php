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
    ];
}
