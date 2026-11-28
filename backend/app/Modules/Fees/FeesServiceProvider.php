<?php

namespace App\Modules\Fees;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Fees\Domain\FeeAccess;
use App\Modules\Fees\Policies\FeesPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Fees: heads, categories and plans; dues raised from plans; the
 * append-only ledger; counter payments with receipts; cheques; day-end
 * closings; adjustments and receipt cancellations (proposer never
 * approves); online payments (Razorpay test mode, a local fake driver,
 * the signed idempotent webhook, nightly reconciliation); reports.
 *
 * Every check is one of the abilities below (FeesPolicy); the domain
 * classes check the separation-of-duty rules again.
 */
class FeesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $policy = fn (): FeesPolicy => $this->app->make(FeesPolicy::class);

        foreach (['setup', 'collect', 'office', 'reports', 'gateway-view', 'gateway-edit', 'adjust-propose', 'adjust-any', 'cancel-request', 'closing-confirm'] as $ability) {
            Gate::define('fees.'.$ability, fn (User $user) => $policy()->check($user, $ability));
        }
        Gate::define('fees.view-student', fn (User $user, Student $student) => $policy()->viewStudent($user, $student));
        Gate::define('fees.pay', fn (User $user, Student $student) => $this->app->make(FeeAccess::class)->mayPayFor($user, $student));
        Gate::define('fees.section-status', fn (User $user, Section $section) => $policy()->sectionStatus($user, $section));

        // The gateway's callback: generous, but no endless hammering from one address.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
