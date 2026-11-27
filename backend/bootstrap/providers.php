<?php

use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\People\PeopleServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AttendanceServiceProvider::class,
    AuditServiceProvider::class,
    CommunicationServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    PeopleServiceProvider::class,
    TenancyServiceProvider::class,
];
