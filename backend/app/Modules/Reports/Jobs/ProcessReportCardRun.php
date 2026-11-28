<?php

namespace App\Modules\Reports\Jobs;

use App\Modules\Reports\Domain\ReportCards;
use App\Modules\Reports\Models\ReportCardRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generates, publishes or re-issues report cards in the background (Horizon),
 * inside the school that asked. The run record carries the progress.
 */
class ProcessReportCardRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $tenantId, public readonly string $runId) {}

    public function handle(TenantContext $context, ReportCards $cards): void
    {
        $context->run($this->tenantId, function () use ($cards): void {
            $run = ReportCardRun::query()->find($this->runId);
            if ($run instanceof ReportCardRun && $run->status === 'queued') {
                $cards->process($run);
            }
        });
    }
}
