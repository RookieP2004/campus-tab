<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Mail\SecurityAlertMail;
use App\Modules\Platform\Models\PlatformAlert;
use App\Modules\Platform\Models\SchoolStats;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Mail;

/**
 * Raises an alert for our team when a school's security count jumps: its
 * count in the last hour reaches the metric's minimum and is at least
 * `factor` times its usual hourly rate over the 7 days before (settings in
 * config/identity.php, platform.alerts). One alert per school and metric per
 * repeat window. Every active platform admin gets an email with the counts
 * only; the details stay in the school's own audit log.
 */
class SecurityAlerts
{
    public function __construct(private readonly PlatformAuditRecorder $audit) {}

    public function evaluate(Tenant $tenant, SchoolStats $stats): void
    {
        $factor = (float) config('identity.platform.alerts.factor', 5);
        $repeatHours = (int) config('identity.platform.alerts.repeat_after_hours', 6);
        /** @var array<string, int> $minimums */
        $minimums = config('identity.platform.alerts.min_per_hour', []);

        foreach ($minimums as $metric => $minimum) {
            $lastHour = (int) ($stats->security['last_hour'][$metric] ?? 0);
            $week = (int) ($stats->security['7d'][$metric] ?? 0);
            $baseline = max($week - $lastHour, 0) / 167;

            if ($lastHour < $minimum || $lastHour < $factor * max($baseline, 1)) {
                continue;
            }

            $recent = PlatformAlert::query()->where('school_id', $tenant->id)->where('metric', $metric)
                ->where('raised_at', '>=', now()->subHours($repeatHours))->exists();

            if ($recent) {
                continue;
            }

            $label = str_replace('_', ' ', $metric);
            $alert = PlatformAlert::create([
                'school_id' => $tenant->id,
                'school_code' => $tenant->code,
                'metric' => $metric,
                'count' => $lastHour,
                'baseline' => $baseline,
                'message' => "{$tenant->code}: {$lastHour} {$label} in the last hour (usually ".round($baseline, 1).' an hour).',
                'raised_at' => now(),
            ]);

            $this->audit->record('alert.raised', null, $tenant, 'platform_alerts', (string) $alert->getKey(), meta: [
                'metric' => $metric, 'count' => $lastHour, 'baseline' => round($baseline, 2),
            ]);

            foreach (PlatformAdmin::query()->where('status', PlatformAdmin::STATUS_ACTIVE)->get() as $admin) {
                Mail::to($admin->email)->send(new SecurityAlertMail($alert));
            }
        }
    }
}
