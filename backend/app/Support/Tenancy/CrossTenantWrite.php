<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when code tries to create, change or delete a record that belongs
 * to a different school from the one in the current tenant context.
 */
class CrossTenantWrite extends RuntimeException
{
    public function __construct(string $modelClass, ?string $recordTenant, string $contextTenant)
    {
        parent::__construct(sprintf(
            'Refusing to write %s for tenant [%s] while acting for tenant [%s].',
            class_basename($modelClass),
            $recordTenant ?? 'none',
            $contextTenant,
        ));
    }
}
