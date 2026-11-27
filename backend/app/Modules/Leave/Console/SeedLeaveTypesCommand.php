<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Http\Controllers\LeaveSetupController;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * php artisan leave:seed-types --school=CODE — adds the starter leave types
 * (config/leave.php) a school does not have yet. The Principal changes
 * names, quotas and rules afterwards.
 */
class SeedLeaveTypesCommand extends Command
{
    protected $signature = 'leave:seed-types {--school= : the school code}';

    protected $description = 'Add the starter leave types to a school';

    public function handle(TenantContext $context): int
    {
        $tenant = Tenant::query()->where('code', (string) $this->option('school'))->first();

        if (! $tenant instanceof Tenant) {
            $this->error('No school with that code.');

            return self::FAILURE;
        }

        $added = $context->run($tenant->id, fn () => LeaveSetupController::addStarterTypes());
        $this->info(count($added).' leave types added.');

        return self::SUCCESS;
    }
}
