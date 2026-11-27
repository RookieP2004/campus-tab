<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Audit\Domain\Auditable;
use App\Modules\Tenancy\Domain\ConfigSections;
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
    use Auditable, BelongsToTenant;

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
     * A version change is "academic" (visible to the Principal in the audit
     * log) when every changed path is one the Principal may edit.
     */
    public function auditCategory(): string
    {
        $paths = array_column($this->diff ?? [], 'path');

        return $paths !== [] && ConfigSections::forbiddenForPrincipal($paths) === [] ? 'academic' : 'admin';
    }

    /**
     * The audit entry keeps the diff, not the whole body.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function auditSnapshot(array $values): array
    {
        return array_intersect_key($values, array_flip(['version', 'diff', 'reason', 'rolled_back_from']));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'rolled_back_from' => 'integer'];
    }
}
