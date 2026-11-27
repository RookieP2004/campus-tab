<?php

use App\Modules\Dashboard\Domain\Cards\AssessmentCards;
use App\Modules\Dashboard\Domain\Cards\AttendanceCards;
use App\Modules\Dashboard\Domain\Cards\LeaveCards;
use App\Modules\Dashboard\Domain\Cards\NoticeCards;
use App\Modules\Dashboard\Domain\Cards\PeopleCards;
use App\Modules\Dashboard\Domain\Cards\SchoolAdminCards;

/*
| Dashboard card providers ("My Dashboard, by role"). Each builds, on the
| server, only the cards the viewed role may see. Later phases add theirs
| (attendance, leave, marks, fees).
*/

return [

    'providers' => [
        SchoolAdminCards::class,
        PeopleCards::class,
        NoticeCards::class,
        AttendanceCards::class,
        LeaveCards::class,
        AssessmentCards::class,
    ],

];
