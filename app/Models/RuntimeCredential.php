<?php

namespace App\Models;

use App\Auth\Principal;
use Illuminate\Database\Eloquent\Model;

final class RuntimeCredential extends Model
{
    public const ROLE_OPERATOR = 'operator';

    public const ROLE_WORKER = 'worker';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'subject',
        'roles',
        'tenant',
        'claims',
        'token_prefix',
        'token_hash',
        'expires_at',
        'revoked_at',
        'rotated_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'roles' => 'array',
        'claims' => 'array',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'rotated_at' => 'immutable_datetime',
    ];

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [self::ROLE_OPERATOR, self::ROLE_WORKER];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function prefixFor(string $token): string
    {
        return substr($token, 0, 12);
    }

    public static function activeForToken(string $token): ?self
    {
        $credential = self::query()
            ->where('token_hash', self::hashToken($token))
            ->first();

        if (! $credential instanceof self
            || $credential->revoked_at !== null
            || ($credential->expires_at !== null && $credential->expires_at->isPast())
        ) {
            return null;
        }

        return $credential;
    }

    public function principal(): Principal
    {
        return new Principal(
            subject: $this->subject,
            roles: $this->roles ?? [],
            method: 'runtime-token',
            tenant: $this->tenant,
            claims: $this->claims ?? [],
        );
    }
}
