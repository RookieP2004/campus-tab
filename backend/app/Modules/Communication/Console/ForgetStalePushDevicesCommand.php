<?php

namespace App\Modules\Communication\Console;

use App\Modules\Communication\Models\PushDevice;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: a device nobody has signed in on for 30 days
 * (communication.push.forget_after_days) stops getting push.
 */
class ForgetStalePushDevicesCommand extends Command
{
    protected $signature = 'push:forget-stale';

    protected $description = 'Stop push to devices with no sign-in for 30 days';

    public function handle(TenantContext $context): int
    {
        $before = now()->subDays((int) config('communication.push.forget_after_days', 30));
        $total = 0;
        foreach (Tenant::query()->get() as $tenant) {
            $total += (int) $context->run((string) $tenant->getKey(), fn () => PushDevice::query()
                ->where(fn ($q) => $q->where('last_seen_at', '<', $before)->orWhereNull('last_seen_at'))->delete());
        }
        $this->info("Forgot {$total} push device(s).");

        return self::SUCCESS;
    }
}
