<?php

namespace App\Modules\Leave\Console;

use App\Modules\Leave\Domain\LeaveReminders;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan leave:remind — the hourly leave reminders and escalations,
 * run inside each active school in turn. One school failing never stops
 * the others.
 */
class SendLeaveRemindersCommand extends Command
{
    protected $signature = 'leave:remind {--school= : only this school code}';

    protected $description = 'Remind approvers about waiting leave requests and escalate old ones';

    public function handle(TenantContext $context): int
    {
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);
        if (is_string($this->option('school'))) {
            $query->where('code', $this->option('school'));
        }

        foreach ($query->get() as $tenant) {
            try {
                $result = $context->run($tenant->id, fn () => app(LeaveReminders::class)->run());
                $this->line("{$tenant->code}: {$result['reminded']} reminded, {$result['escalated']} escalated");
            } catch (Throwable $e) {
                report($e);
                $this->error("{$tenant->code}: failed");
            }
        }

        return self::SUCCESS;
    }
}
