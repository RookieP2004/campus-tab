<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
];
