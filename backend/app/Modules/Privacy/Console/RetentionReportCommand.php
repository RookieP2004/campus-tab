<?php

namespace App\Modules\Privacy\Console;

use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Domain\Retention;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Daily: a DRY RUN of every retention rule in every active school. It only
 * lists what is past its time and tells the Chief there is a report to
 * read. It never deletes anything; the Chief confirms each report on the
 * Privacy → Retention screen with a fresh MFA code.
 */
class RetentionReportCommand extends Command
{
    protected $signature = 'privacy:retention-report {--school= : only this school code}';

    protected $description = 'List data past its retention time (dry run only) and tell the Chief';

    public function handle(TenantContext $context, Retention $retention, Notifier $notifier): int
    {
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);
        if ($this->option('school')) {
            $query->where('code', $this->option('school'));
        }
        foreach ($query->get() as $tenant) {
            $found = $context->run($tenant->id, function () use ($retention, $notifier, $tenant): int {
                $total = 0;
                foreach (Retention::kinds() as $kind) {
                    $run = $retention->dryRun($kind, null);
                    $total += $run->count;
                }
                if ($total > 0) {
                    $chiefs = User::query()->where('roles', Role::Chief->value)->where('status', User::STATUS_ACTIVE)->get();
                    $notifier->notify($tenant, $chiefs, 'privacy.retention', "Retention report: {$total} records are past their time. Nothing is deleted until you confirm.", null, '/privacy/retention', []);
                }

                return $total;
            });
            $this->line("{$tenant->code}: {$found} record(s) past their time (dry run, nothing deleted)");
        }

        return self::SUCCESS;
    }
}
