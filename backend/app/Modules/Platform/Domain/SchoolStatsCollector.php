<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\SupportGrant;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\Platform\Models\SchoolStats;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Works out the numbers the console shows about a school, inside that
 * school (TenantContext::run), and stores only the numbers on the platform
 * side (school_stats). No names and no record content leave the school:
 * the security overview is counts of audit events, and support grants are
 * summarised as our agent, scope, times and status.
 *
 * Run by the scheduled job every 15 minutes (platform:collect-stats).
 */
class SchoolStatsCollector
{
    /** Security metrics: the audit actions each one counts. */
    public const SECURITY_METRICS = [
        'failed_sign_ins' => ['sign_in.failed', 'sign_in.locked'],
        'lockouts' => ['sign_in.locked'],
        'password_resets' => ['password.reset_link_sent', 'password.reset'],
        'mfa_resets' => ['mfa.reset', 'platform.chief_recovery.mfa_reset'],
        'chief_changes' => ['chief.added', 'chief.removed'],
    ];

    /** Windows in hours. */
    public const WINDOWS = ['last_hour' => 1, '24h' => 24, '7d' => 168, '30d' => 720];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SecurityAlerts $alerts,
    ) {}

    public function collect(Tenant $tenant): SchoolStats
    {
        $now = Carbon::now();

        $numbers = $this->context->run($tenant->id, fn (): array => [
            'usage' => $this->usage($tenant, $now),
            'security' => $this->security($now),
            'grants' => $this->grants($now),
        ]);

        $stats = SchoolStats::query()->where('school_id', $tenant->id)->first() ?? new SchoolStats(['school_id' => $tenant->id]);
        $stats->fill(array_merge($numbers, ['computed_at' => $now]))->save();

        $this->alerts->evaluate($tenant, $stats);

        return $stats;
    }

    /**
     * @return array{students: int, staff: int, active_7d: int, active_30d: int, last_activity_at: string|null, storage_bytes: int}
     */
    private function usage(Tenant $tenant, Carbon $now): array
    {
        $active = fn (int $days): int => DeviceSession::query()->where('last_seen_at', '>=', $now->copy()->subDays($days))->pluck('user_id')->unique()->count();
        $last = DeviceSession::query()->orderBy('last_seen_at', 'desc')->first();

        return [
            'students' => Seats::studentsUsed(),
            'staff' => Seats::staffUsed(),
            'active_7d' => $active(7),
            'active_30d' => $active(30),
            'last_activity_at' => $last?->last_seen_at?->toIso8601String(),
            'storage_bytes' => $this->storageBytes($tenant),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function security(Carbon $now): array
    {
        $since = $now->copy()->subHours(max(self::WINDOWS));
        $actions = array_values(array_unique(array_merge(...array_values(self::SECURITY_METRICS))));

        $events = AuditLog::query()->where('category', 'security')->whereIn('action', $actions)->where('at', '>=', $since)->get(['action', 'at']);
        $counts = [];

        foreach (self::WINDOWS as $window => $hours) {
            $from = $now->copy()->subHours($hours);
            foreach (self::SECURITY_METRICS as $metric => $metricActions) {
                $counts[$window][$metric] = $events->filter(fn (AuditLog $e) => in_array($e->action, $metricActions, true) && $e->at->greaterThanOrEqualTo($from))->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grants(Carbon $now): array
    {
        return SupportGrant::query()->where('expires_at', '>=', $now->copy()->subDays(90))->orderBy('starts_at', 'desc')->limit(20)->get()
            ->map(fn (SupportGrant $g) => [
                'id' => (string) $g->getKey(),
                'agent_name' => $g->agent_name,
                'agent_email' => $g->agent_email,
                'scope' => $g->scope,
                'read_only' => $g->read_only,
                'starts_at' => $g->starts_at->toIso8601String(),
                'expires_at' => $g->expires_at->toIso8601String(),
                'status' => $g->revoked_at !== null ? 'revoked' : ($g->expires_at->isPast() ? 'ended' : ($g->starts_at->isFuture() ? 'scheduled' : 'active')),
            ])->values()->all();
    }

    private function storageBytes(Tenant $tenant): int
    {
        $disk = Storage::disk('local');
        $total = 0;

        foreach ($disk->allFiles('tenants/'.$tenant->id) as $file) {
            $total += (int) $disk->size($file);
        }

        return $total;
    }
}
