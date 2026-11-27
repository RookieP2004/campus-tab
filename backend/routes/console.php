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
