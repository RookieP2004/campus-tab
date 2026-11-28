<?php

namespace App\Modules\Reports;

use App\Modules\Reports\Domain\Pdf\FakePdfRenderer;
use App\Modules\Reports\Domain\Pdf\GotenbergRenderer;
use App\Modules\Reports\Domain\Pdf\PdfRenderer;
use App\Support\Models\ClosedYears;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Reports: the grading engine, report card templates, background
 * generation and publishing (PDF with a QR code), public verification,
 * versioned re-issue and the year-end promotion wizard.
 */
class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One lookup per year per request or job.
        $this->app->scoped(ClosedYears::class);

        $this->app->bind(PdfRenderer::class, fn () => config('reports.pdf.driver') === 'fake' && $this->app->environment('testing')
            ? new FakePdfRenderer
            : new GotenbergRenderer);
    }

    public function boot(): void
    {
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute((int) config('reports.verification.per_minute', 30))->by($request->ip()));
    }
}
