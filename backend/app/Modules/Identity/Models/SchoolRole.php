<?php

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Domain\Auditable;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Support\Tenancy\BelongsToTenant;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;

/**
 * A permission bundle in one school (collection `roles`). The eleven system
 * roles are seeded for every school; the Chief can edit their permissions
 * and add custom roles below their own level.
 *
 * @property string $tenant_id
 * @property string $name
 * @property string $label
 * @property int|null $level null for a duty (Fee Collector, Attendance Officer)
 * @property string|null $duty the duty name, whose allow-list is in config('permissions.duties')
 * @property list<string> $permissions
 * @property bool $is_system
 */
class SchoolRole extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $connection = 'mongodb';

    protected $table = 'roles';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'label', 'level', 'duty', 'permissions', 'is_system'];

    /** @var array<string, mixed> */
    protected $attributes = ['permissions' => [], 'is_system' => false, 'duty' => null];

    protected static function booted(): void
    {
        $flush = fn () => app(RoleRegistry::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['level' => 'integer', 'is_system' => 'boolean'];
    }

    public function isDuty(): bool
    {
        return $this->duty !== null;
    }

    public function auditCategory(): string
    {
        return 'admin';
    }
}
