<?php

use App\Modules\Platform\Jobs\CollectSchoolStats;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. The `scheduler` container runs `php artisan schedule:work`.
*/

// The platform console's per-school numbers (usage, security counts, alerts).
Schedule::job(new CollectSchoolStats)->everyFifteenMinutes()->name('platform:collect-stats')->withoutOverlapping();

// Leave: reminders after 24 h and escalation after 48 h (both from each school's config).
Schedule::command('leave:remind')->hourly()->name('leave:remind')->withoutOverlapping();

// Fees: raise instalments as they come due (also covers the year-start run),
// and match yesterday's gateway settlements against our online payments.
Schedule::command('fees:raise-demands')->dailyAt('01:30')->timezone('Asia/Kolkata')->name('fees:raise-demands')->withoutOverlapping();
Schedule::command('fees:reconcile')->dailyAt('03:00')->timezone('Asia/Kolkata')->name('fees:reconcile')->withoutOverlapping();
