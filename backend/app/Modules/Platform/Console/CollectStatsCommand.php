<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Domain\SchoolStatsCollector;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Works out the console's usage and security numbers now (the scheduler
 * does it every 15 minutes).
 *
 *   php artisan platform:collect-stats            every active school
 *   php artisan platform:collect-stats SUNRISE-BPL
 */
class CollectStatsCommand extends Command
{
    protected $signature = 'platform:collect-stats {code? : one school code}';

    protected $description = 'Work out each school\'s usage and security numbers for the platform console';

    public function handle(SchoolStatsCollector $collector): int
    {
        $code = $this->argument('code');
        $tenants = is_string($code)
            ? array_filter([Tenant::findActiveByCode($code)])
            : Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get()->all();

        foreach ($tenants as $tenant) {
            $stats = $collector->collect($tenant);
            $this->line("{$tenant->code}: {$stats->usage['students']} students, {$stats->usage['staff']} staff, {$stats->usage['active_7d']} active in 7 days.");
        }

        return self::SUCCESS;
    }
}
