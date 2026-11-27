<?php

namespace App\Modules\People\Jobs;

use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Import\Importer;
use App\Modules\People\Models\ImportBatch;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;

/**
 * Runs an import's dry run or its commit on the queue, inside its school
 * and as the person who started it (so permissions, levels and the audit log
 * name them). Progress is written to the batch as it goes.
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $batchId,
        public readonly bool $commit,
    ) {}

    public function handle(Importer $importer, TenantContext $context): void
    {
        $context->run($this->tenantId, function () use ($importer): void {
            $batch = ImportBatch::query()->find($this->batchId);
            $user = $batch instanceof ImportBatch ? User::query()->find($batch->created_by['id']) : null;
            $tenant = Tenant::query()->find($this->tenantId);

            if (! $batch instanceof ImportBatch || ! $user instanceof User || ! $tenant instanceof Tenant) {
                return;
            }

            Auth::guard('web')->setUser($user);

            try {
                $this->commit ? $importer->commit($batch, $user, $tenant) : $importer->validate($batch, $user);
            } finally {
                Auth::guard('web')->forgetUser();
            }
        });
    }
}
