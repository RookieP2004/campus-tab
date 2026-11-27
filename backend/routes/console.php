<?php

use App\Modules\Platform\Jobs\CollectSchoolStats;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. The `scheduler` container runs `php artisan schedule:work`.
*/

// The platform console's per-school numbers (usage, security counts, alerts).
Schedule::job(new CollectSchoolStats)->everyFifteenMinutes()->name('platform:collect-stats')->withoutOverlapping();
