<?php

use App\Modules\Assessment\AssessmentServiceProvider;
use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\Fees\FeesServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Leave\LeaveServiceProvider;
use App\Modules\People\PeopleServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AssessmentServiceProvider::class,
    AttendanceServiceProvider::class,
    AuditServiceProvider::class,
    CommunicationServiceProvider::class,
    HorizonServiceProvider::class,
    FeesServiceProvider::class,
    IdentityServiceProvider::class,
    LeaveServiceProvider::class,
    PeopleServiceProvider::class,
    ReportsServiceProvider::class,
    TenancyServiceProvider::class,
];
