<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use LogicException;
use MongoDB\Laravel\Eloquent\Model;

/**
 * One version of a school's config. Versions are append-only: a change or a
 * rollback always writes a new version, so the history is never rewritten
 * (that is also why this model has no soft deletes).
 *
 * @property string $tenant_id
 * @property int $version
 * @property array<string, mixed> $body
 * @property list<array{path: string, from: mixed, to: mixed}> $diff
 * @property array{id: string|null, name: string|null, level: int} $changed_by
 * @property string|null $reason
 * @property int|null $rolled_back_from
 * @property Carbon $created_at
 */
class SchoolConfig extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'school_configs';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'version', 'body', 'diff', 'changed_by', 'reason', 'rolled_back_from'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('School config versions are append-only; save a new version instead.'));
        static::deleting(fn () => throw new LogicException('School config versions are append-only and are never deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'rolled_back_from' => 'integer'];
    }
}
