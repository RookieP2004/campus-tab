<?php

namespace App\Modules\Fees\Console;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Fees\Domain\DemandRaiser;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan fees:raise-demands — raises every instalment that has come
 * within fees.raise_days_before of its due date, in each active school
 * with fees switched on. Runs at year start and every night; raising is
 * once per instalment, so running it again changes nothing.
 */
class RaiseDemandsCommand extends Command
{
    protected $signature = 'fees:raise-demands {--school= : only this school code}';

    protected $description = 'Raise fee dues from each student\'s plan';

    public function handle(TenantContext $context, DemandRaiser $raiser, SchoolConfigService $configs): int
    {
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE);
        if ($this->option('school')) {
            $query->where('code', (string) $this->option('school'));
        }
        foreach ($query->get() as $tenant) {
            try {
                $context->run($tenant->id, function () use ($tenant, $raiser, $configs): void {
                    $year = AcademicYear::current();
                    if (! ($configs->current()->body['modules']['fees'] ?? false) || ! $year instanceof AcademicYear) {
                        return;
                    }
                    $result = $raiser->raise($year);
                    $this->line("{$tenant->code}: {$result['raised']} instalments raised");
                });
            } catch (Throwable $e) {
                report($e);
                $this->error("{$tenant->code}: failed");
            }
        }

        return self::SUCCESS;
    }
}
