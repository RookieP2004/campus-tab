<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every school model uses this trait (docs/CONVENTIONS.md).
 *
 *  - Reads: TenantScope filters every query by the current tenant.
 *  - Creates: tenant_id is filled from the context; a record that names a
 *    different school is refused.
 *  - Updates and deletes: the save query also filters by tenant_id (Eloquent
 *    builds those queries without global scopes), and a record from another
 *    school is refused before anything is sent to the database.
 *
 * @property string $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            $current = $context->require();
            $given = $model->getAttribute('tenant_id');

            if ($given !== null && $given !== $current) {
                throw new CrossTenantWrite($model::class, $given, $current);
            }

            $model->setAttribute('tenant_id', $current);
        });

        $guardExisting = function (Model $model): void {
            $context = app(TenantContext::class);
            $current = $context->require();
            $original = $model->getOriginal('tenant_id');

            if ($original !== $current || $model->getAttribute('tenant_id') !== $current) {
                throw new CrossTenantWrite($model::class, $original, $current);
            }
        };

        static::updating($guardExisting);
        static::deleting($guardExisting);
    }

    /**
     * Make the update/delete query for this record carry tenant_id too.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $query = parent::setKeysForSaveQuery($query);

        $query->where('tenant_id', $this->getOriginal('tenant_id') ?? $this->getAttribute('tenant_id'));

        // laravel-mongodb returns its own Builder subclass, which Larastan cannot match to Builder<static>.
        return $query; // @phpstan-ignore return.type
    }
}
