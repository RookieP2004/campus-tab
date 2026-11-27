<?php

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Domain\SchoolStatsCollector;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The scheduled job (every 15 minutes, routes/console.php): works out each
 * active school's usage and security numbers inside that school and keeps
 * only the numbers on the platform side. One school failing never stops
 * the others.
 */
class CollectSchoolStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function __construct(public readonly ?string $tenantId = null) {}

    public function handle(SchoolStatsCollector $collector): void
    {
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);

        if ($this->tenantId !== null) {
            $query->where('_id', $this->tenantId);
        }

        foreach ($query->get() as $tenant) {
            try {
                $collector->collect($tenant);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
