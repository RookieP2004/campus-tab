<?php

namespace App\Modules\Audit\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use LogicException;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Query\Builder as QueryBuilder;

/**
 * One entry in a school's audit log. Append-only:
 *
 *  - saving an existing entry throws, and so does deleting one;
 *  - its query builder (AuditLogBuilder) throws on update, delete,
 *    increment, upsert and touch, so no bulk path can change it either;
 *  - it has no soft deletes, so it never goes to the recycle bin;
 *  - there is no endpoint that changes or removes it.
 *
 * before/after never hold passwords, OTPs, TOTP secrets, recovery codes or
 * tokens (Domain\Redactor strips them and lists the field names instead).
 *
 * @property string $id
 * @property string $tenant_id
 * @property array{type: string, id: string|null, name: string|null} $actor
 * @property string|null $actor_id the same id, stored on its own for filtering
 * @property string|null $support_grant_id
 * @property string $action
 * @property string $category academic|admin|security|fees
 * @property string|null $entity
 * @property string|null $entity_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property list<string> $redacted
 * @property array<string, mixed> $meta
 * @property string|null $ip
 * @property array{session_id: string|null, user_agent: string|null}|null $device
 * @property Carbon $at
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'at';

    protected $connection = 'mongodb';

    protected $table = 'audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'actor', 'actor_id', 'support_grant_id', 'action', 'category', 'entity', 'entity_id',
        'before', 'after', 'redacted', 'meta', 'ip', 'device',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only: entries are never changed.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only: entries are never deleted.'));
    }

    /**
     * @param  QueryBuilder  $query
     * @return AuditLogBuilder<static>
     */
    public function newEloquentBuilder($query): AuditLogBuilder
    {
        /** @var AuditLogBuilder<static> $builder */
        $builder = new AuditLogBuilder($query);

        return $builder;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
