<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowNamespaceWorkflow extends Model
{
    protected $table = 'workflow_namespace_workflows';

    protected $fillable = [
        'namespace',
        'workflow_instance_id',
        'workflow_type',
    ];
}
