<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowNamespace extends Model
{
    public const RETENTION_MODE_BOUNDED = 'bounded';

    public const RETENTION_MODE_FOREVER = 'forever';

    protected $table = 'workflow_namespaces';

    protected $fillable = [
        'name',
        'description',
        'retention_mode',
        'retention_days',
        'status',
        'external_payload_storage',
    ];

    protected $casts = [
        'retention_days' => 'integer',
        'external_payload_storage' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(static function (WorkflowNamespace $namespace): void {
            $mode = $namespace->retention_mode;
            $days = $namespace->retention_days;

            if (! in_array($mode, self::retentionModes(), true)) {
                throw new \InvalidArgumentException("Unsupported namespace retention mode [{$mode}].");
            }

            if ($mode === self::RETENTION_MODE_BOUNDED && $days === null) {
                throw new \InvalidArgumentException('Bounded namespace retention requires retention_days.');
            }

            if ($mode === self::RETENTION_MODE_FOREVER && $days !== null) {
                throw new \InvalidArgumentException('Forever namespace retention cannot define retention_days.');
            }
        });
    }

    /**
     * @return list<string>
     */
    public static function retentionModes(): array
    {
        return [
            self::RETENTION_MODE_BOUNDED,
            self::RETENTION_MODE_FOREVER,
        ];
    }

    public function getRetentionModeAttribute(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : self::RETENTION_MODE_BOUNDED;
    }

    public function retainsHistoryForever(): bool
    {
        return $this->retention_mode === self::RETENTION_MODE_FOREVER;
    }

    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = strtolower($value);
    }
}
