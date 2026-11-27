<?php

return [

    /*
    | The tenant query guard (App\Support\Tenancy\TenantQueryGuard).
    |   off    - not registered
    |   log    - write a warning for each school-data query without tenant_id
    |   record - keep each one; the test suite fails the test that caused it
    */
    'query_guard' => env('TENANCY_QUERY_GUARD', 'log'),

    /*
    | Collections that are NOT school data. Everything else is treated as a
    | school collection and must be filtered by tenant_id. Keep this list
    | short and explain each entry in docs/report/decisions/0005.
    */
    'platform_collections' => [
        'migrations',
        'tenants',
        'licences',
        'platform_admins',
        // Looked up by token before the school is known (decision 0005).
        'personal_access_tokens',
        'failed_jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
    ],

];
