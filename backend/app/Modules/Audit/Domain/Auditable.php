<?php

namespace App\Modules\Audit\Domain;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Records every create, update, delete (to the recycle bin), restore and
 * permanent purge of a school model in the audit log, with before and after
 * values (secrets removed by Redactor).
 *
 * A model may define:
 *   auditCategory(): string            academic | admin | security | fees
 *   auditIgnored(): list<string>       bookkeeping fields that never make an entry
 *   auditSnapshot(array $values): array  what to store instead of the raw fields
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            $after = self::auditFilter($model, $model->getAttributes());
            app(AuditRecorder::class)->forModel($model, 'created', null, self::auditShape($model, $after));
        });

        static::updated(function (Model $model): void {
            $changes = self::auditFilter($model, $model->getChanges());

            if ($changes === []) {
                return;
            }

            $before = array_intersect_key($model->getRawOriginal(), $changes);
            app(AuditRecorder::class)->forModel($model, 'updated', self::auditShape($model, $before), self::auditShape($model, $changes));
        });

        static::deleted(function (Model $model): void {
            $purged = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

            // A purge keeps only the type and id: the record's personal data is not copied into the log.
            app(AuditRecorder::class)->forModel($model, $purged ? 'purged' : 'deleted', null, null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                app(AuditRecorder::class)->forModel($model, 'restored', null, null);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditFilter(Model $model, array $values): array
    {
        $ignored = array_merge(
            ['updated_at', 'created_at', 'deleted_at', '_id', 'id', 'tenant_id'],
            method_exists($model, 'auditIgnored') ? $model->auditIgnored() : [],
        );

        return array_diff_key($values, array_flip($ignored));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditShape(Model $model, array $values): array
    {
        $values = method_exists($model, 'auditSnapshot') ? $model->auditSnapshot($values) : $values;

        return self::auditPlain($values);
    }

    /**
     * Dates become ISO strings and ids become strings, so entries read the
     * same whatever type the driver returned.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function auditPlain(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                $value instanceof UTCDateTime => $value->toDateTime()->format(DATE_ATOM),
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof ObjectId => (string) $value,
                is_array($value) => self::auditPlain($value),
                is_object($value) => json_decode((string) json_encode($value), true),
                default => $value,
            };
        }

        return $values;
    }
}
