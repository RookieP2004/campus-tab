<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Adds `tenant_id = <current school>` to every query on a school model.
 * Applied when the query runs, so it always uses the context of that moment.
 * With no tenant set (and no documented bypass) it throws instead of
 * returning every school's records.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->bypassing()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->require());
    }
}
