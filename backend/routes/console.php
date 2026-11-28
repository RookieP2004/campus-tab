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

// Push: devices with no sign-in for 30 days stop getting push (Phase 9).
Schedule::command('push:forget-stale')->dailyAt('02:15')->timezone('Asia/Kolkata')->name('push:forget-stale')->withoutOverlapping();

// Privacy (Phase 9): a dry-run retention report every night. It deletes
// nothing; the Chief confirms each report on the Privacy screen.
Schedule::command('privacy:retention-report')->dailyAt('02:45')->timezone('Asia/Kolkata')->name('privacy:retention-report')->withoutOverlapping();
