<?php

namespace App\Modules\Fees\Console;

use App\Modules\Fees\Domain\OnlinePayments;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan fees:reconcile [--date=Y-m-d] — nightly: each school's
 * gateway settlement report for the day (yesterday by default) against
 * our online payments; mismatches go to the Clerk.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'fees:reconcile {--date= : the day to check (Y-m-d)} {--school= : only this school code}';

    protected $description = 'Match the gateway settlement report against online fee payments';

    public function handle(TenantContext $context, OnlinePayments $online): int
    {
        $date = (string) ($this->option('date') ?: now('Asia/Kolkata')->subDay()->toDateString());
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);
        if ($this->option('school')) {
            $query->where('code', (string) $this->option('school'));
        }
        foreach ($query->get() as $tenant) {
            try {
                $run = $context->run($tenant->id, fn () => $online->reconcile($date));
                if ($run !== null) {
                    $this->line("{$tenant->code}: {$run->status} ({$run->matched} matched, ".count($run->mismatches).' to check)');
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("{$tenant->code}: failed");
            }
        }

        return self::SUCCESS;
    }
}
