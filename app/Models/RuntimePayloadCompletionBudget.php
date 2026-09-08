<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimePayloadCompletionBudget extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array', 'slots' => 'array', 'objects' => 'array', 'expires_at' => 'immutable_datetime',
    ];
}
