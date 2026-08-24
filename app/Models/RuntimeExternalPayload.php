<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuntimeExternalPayload extends Model
{
    public const UPLOAD_READY = 'ready';

    public const UPLOAD_WRITING = 'writing';

    public $incrementing = false;

    protected $table = 'runtime_external_payloads';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'namespace',
        'storage_uri',
        'storage_uri_sha256',
        'codec',
        'sha256',
        'size_bytes',
        'upload_status',
        'retained_at',
        'expires_at',
        'last_fetched_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'retained_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'last_fetched_at' => 'immutable_datetime',
    ];
}
