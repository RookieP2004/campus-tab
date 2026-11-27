<?php

use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
];
