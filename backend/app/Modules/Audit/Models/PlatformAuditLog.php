<?php

namespace App\Modules\Audit\Models;

use Illuminate\Support\Carbon;
use LogicException;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Query\Builder as QueryBuilder;

/**
 * One entry in the platform audit log: everything our team does in the
 * console or with the platform:* commands. Platform level (no tenant scope),
 * append-only in the same way as a school's AuditLog: saving an existing
 * entry, deleting one and every bulk update or delete path throw.
 *
 * An action on a school (licence issued or revoked, plan, seats or modules
 * changed, sole-Chief recovery) also writes an entry in that school's own
 * audit_logs, so the school sees it; school_id links the two.
 *
 * @property string $id
 * @property array{type: string, id: string|null, name: string|null} $actor
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $school_id the school acted on, if any
 * @property string|null $school_code
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
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'at';

    protected $connection = 'mongodb';

    protected $table = 'platform_audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'actor', 'actor_id', 'action', 'school_id', 'school_code', 'entity', 'entity_id',
        'before', 'after', 'redacted', 'meta', 'ip', 'device',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The platform audit log is append-only: entries are never changed.'));
        static::deleting(fn () => throw new LogicException('The platform audit log is append-only: entries are never deleted.'));
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
