<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when school data is queried or written with no tenant set. This is
 * a programming error: the route is missing the `tenant` middleware, or
 * platform code forgot TenantContext::run().
 */
class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant is set for this school-data query. Add the tenant middleware or wrap the code in TenantContext::run().');
    }
}
