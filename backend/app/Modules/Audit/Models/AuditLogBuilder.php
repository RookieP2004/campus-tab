<?php

namespace App\Modules\Audit\Models;

use LogicException;
use MongoDB\Laravel\Eloquent\Builder;

/**
 * The query builder for AuditLog and PlatformAuditLog. Reads and inserts work; every path that
 * would change or remove existing entries throws.
 *
 * @template TModel of \MongoDB\Laravel\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class AuditLogBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $options
     */
    public function update(array $values, array $options = [])
    {
        throw self::appendOnly();
    }

    public function delete()
    {
        throw self::appendOnly();
    }

    public function forceDelete()
    {
        throw self::appendOnly();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw self::appendOnly();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw self::appendOnly();
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw self::appendOnly();
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw self::appendOnly();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw self::appendOnly();
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null)
    {
        throw self::appendOnly();
    }

    private static function appendOnly(): LogicException
    {
        return new LogicException('The audit log is append-only: entries are never changed or deleted.');
    }
}
