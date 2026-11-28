<?php

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A closed academic year is read-only (Phase 7): every save and delete of a
 * school record checks its year with ClosedYears. A trait boot method, so
 * models with their own booted() keep it.
 */
trait GuardsClosedYear
{
    public static function bootGuardsClosedYear(): void
    {
        static::saving(fn (Model $model) => ClosedYears::guard($model));
        static::deleting(fn (Model $model) => ClosedYears::guard($model));
    }
}
