<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A school (platform level, not tenant-scoped). Created by our platform
 * team as "pending"; activation with the licence key makes it "active".
 *
 * @property string $id e.g. t_01JD8K…
 * @property string $code e.g. SUNRISE-BPL
 * @property string $name
 * @property string|null $group_id a trust with several branches (dashboard in v2)
 * @property string $status pending | active | suspended
 * @property int $config_version
 * @property array{email?: string|null, phone?: string|null} $contact
 * @property Carbon|null $created_at
 */
class Tenant extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $connection = 'mongodb';

    protected $table = 'tenants';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'group_id', 'status', 'config_version', 'contact'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'group_id' => null,
        'status' => self::STATUS_PENDING,
        'config_version' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->id ??= 't_'.Str::ulid();
            $tenant->code = self::normaliseCode($tenant->code);
        });
    }

    public static function normaliseCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', self::normaliseCode($code))->first();
    }

    public static function findActiveByCode(string $code): ?self
    {
        $tenant = self::findByCode($code);

        return $tenant?->status === self::STATUS_ACTIVE ? $tenant : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
