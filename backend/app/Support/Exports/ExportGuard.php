<?php

namespace App\Support\Exports;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Every Excel or PDF export of school data (Phase 9 security pass):
 *
 *  - watermark(): who, when and which school, written into the file
 *    (the first row of a sheet, the footer and a faint diagonal of a PDF,
 *    and the file's properties). Anyone holding the file can see where it
 *    came from.
 *  - record(): an audit entry (export.made) with the rows, and an alert to
 *    the Chief (in the app, generic push and email text) when the export is
 *    large (config exports.large_rows, 1,000 by default; the school can
 *    lower it in policies.exports.chief_alert_rows).
 */
class ExportGuard
{
    public const MARK = 'Campus TAB ·';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    /** @return array{text: string, school: string, by: string, at: string} */
    public function watermark(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Asia/Kolkata');
        $tenant = Tenant::query()->find($this->tenant->require());
        $school = $tenant instanceof Tenant ? $tenant->name : 'School';
        $role = ucfirst(str_replace('_', ' ', (string) ($user->roles[0] ?? 'staff')));
        $at = $now->format('d M Y, H:i').' IST';

        return [
            'text' => self::MARK." {$school} · exported by {$user->name} ({$role}) on {$at} · confidential: do not share outside the school",
            'school' => $school,
            'by' => "{$user->name} ({$role})",
            'at' => $at,
        ];
    }

    /** @param array<string, mixed> $meta */
    public function record(User $user, string $what, string $format, int $rows, array $meta = []): void
    {
        $threshold = $this->threshold();
        $large = $threshold > 0 && $rows >= $threshold;
        $this->audit->record('export.made', 'security', 'export', $what, null, ['format' => $format, 'rows' => $rows, 'large' => $large], $meta);

        if (! $large) {
            return;
        }
        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $chiefs = User::query()->where('roles', Role::Chief->value)->where('status', User::STATUS_ACTIVE)->get();
            $this->notifier->notify($tenant, $chiefs, 'export.large', "Large export: {$what} ({$rows} rows) by {$user->name}", null, '/audit', []);
        }
        $this->audit->record('export.large_alert', 'security', 'export', $what, null, ['rows' => $rows, 'threshold' => $threshold, 'by' => $user->name]);
    }

    private function threshold(): int
    {
        $school = app(SchoolConfigService::class)->current()->body['policies']['exports']['chief_alert_rows'] ?? null;

        return is_numeric($school) ? (int) $school : (int) config('exports.large_rows', 1000);
    }
}
