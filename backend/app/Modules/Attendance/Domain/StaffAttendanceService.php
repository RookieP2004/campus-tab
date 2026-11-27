<?php

namespace App\Modules\Attendance\Domain;

use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Authority;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Staff attendance.
 *
 *  - Check-in by the app button (optional geofence: the location is used to
 *    work out inside or outside and then dropped; only that true or false is
 *    kept) or by scanning the office QR code (a code that changes every few
 *    minutes). Check-out the same way.
 *  - Late: a check-in after the school's late_after time is marked L.
 *  - Biometric devices: a CSV import (employee_code, date, in, out).
 *  - Manual entry for someone who could not check in, with the time and a
 *    reason, by someone at a higher level than that person and never by the
 *    person themself: the same level check as the user-management guard
 *    (Authority::outranks). In practice the Attendance Officer (usually the
 *    Clerk) records teachers and staff, the Vice Principal or Principal the
 *    Clerk, the Principal the Vice Principal, the Chief the Principal.
 */
class StaffAttendanceService
{
    public function __construct(
        private readonly AttendanceSettings $settings,
        private readonly AccessControl $access,
        private readonly Authority $authority,
    ) {}

    /**
     * @param  array{lat: float, lng: float}|null  $location  used once, never stored
     */
    public function checkIn(User $user, string $method, #[SensitiveParameter] ?array $location = null, ?string $qrToken = null): StaffAttendance
    {
        $staff = $this->staffOf($user);
        $now = $this->settings->now();
        $insideArea = null;

        if ($method === 'qr') {
            $this->assertQr((string) $qrToken);
        } else {
            $insideArea = $this->insideArea($location);
        }

        $record = StaffAttendance::query()->where('staff_id', (string) $staff->getKey())->where('date', $now->toDateString())->first();

        if ($record instanceof StaffAttendance && $record->in !== null) {
            throw new ApiException('already_checked_in', 'You have already checked in today, at '.$record->in.'.', 409);
        }

        $late = $now->format('H:i') > $this->settings->all()['staff']['late_after'];

        return $this->write($record, [
            'staff_id' => (string) $staff->getKey(),
            'date' => $now->toDateString(),
            'status' => $late ? 'L' : 'P',
            'in' => $now->format('H:i'),
            'source' => $method === 'qr' ? 'qr' : 'app',
            'inside_area' => $insideArea,
            'late' => $late,
        ]);
    }

    /**
     * @param  array{lat: float, lng: float}|null  $location
     */
    public function checkOut(User $user, string $method, #[SensitiveParameter] ?array $location = null, ?string $qrToken = null): StaffAttendance
    {
        $staff = $this->staffOf($user);
        $now = $this->settings->now();

        if ($method === 'qr') {
            $this->assertQr((string) $qrToken);
        } else {
            $this->insideArea($location);
        }

        $record = StaffAttendance::query()->where('staff_id', (string) $staff->getKey())->where('date', $now->toDateString())->first();

        if (! $record instanceof StaffAttendance || $record->in === null) {
            throw new ApiException('not_checked_in', 'Check in first.', 409);
        }

        $record->fill(['out' => $now->format('H:i')])->save();

        return $record;
    }

    /**
     * @param  array{date: string, status: string, in?: string|null, out?: string|null, reason: string}  $input
     */
    public function manual(User $actor, Staff $staff, array $input): StaffAttendance
    {
        if (! $this->access->allows($actor, 'staff_attendance.enter')) {
            throw new ApiException('permission_denied', 'You do not have permission to do this.', 403);
        }

        $target = is_string($staff->user_id) ? User::query()->find($staff->user_id) : null;

        if (! $target instanceof User) {
            throw new ApiException('staff_has_no_account', 'This staff member has no account to check the level against.', 422);
        }

        if ((string) $target->getKey() === (string) $actor->getKey()) {
            throw new ApiException('own_attendance', 'Nobody can enter their own attendance by hand. Someone above you records it.', 403);
        }

        // The same level check as managing users: only for people below you.
        if (! $this->authority->outranks($actor, $target)) {
            throw new ApiException('level_too_low', 'You can enter attendance only for people below your own level.', 403);
        }

        if ($input['date'] > $this->settings->today()) {
            throw new ApiException('future_date', 'Attendance cannot be entered for a day that has not come yet.', 422);
        }

        $late = $input['status'] === 'L' || (isset($input['in']) && $input['in'] > $this->settings->all()['staff']['late_after']);
        $record = StaffAttendance::query()->where('staff_id', (string) $staff->getKey())->where('date', $input['date'])->first();

        return AuditRecorder::withMeta(['reason' => $input['reason'], 'manual' => true], fn () => $this->write($record, [
            'staff_id' => (string) $staff->getKey(),
            'date' => $input['date'],
            'status' => $late && $input['status'] === 'P' ? 'L' : $input['status'],
            'in' => $input['in'] ?? null,
            'out' => $input['out'] ?? null,
            'source' => 'manual',
            'inside_area' => null,
            'late' => $late,
            'entered_by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name],
            'reason' => $input['reason'],
        ]));
    }

    /**
     * A biometric device's CSV: employee_code, date, in, out. Dry run with
     * commit=false; with commit=true the ok rows are written.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{rows: list<array{row: int, status: string, label: string, messages: list<string>}>, ok: int, errors: int, written: int}
     */
    public function importBiometric(array $rows, bool $commit): array
    {
        $staff = Staff::query()->where('status', Staff::STATUS_ACTIVE)->get()->keyBy('employee_code');
        $lateAfter = $this->settings->all()['staff']['late_after'];
        $today = $this->settings->today();
        $report = [];
        $written = 0;

        foreach ($rows as $i => $row) {
            $messages = [];
            $member = $staff[$row['employee_code'] ?? ''] ?? null;
            $date = $row['date'] ?? '';
            $in = $row['in'] ?? '';
            $out = $row['out'] ?? '';

            if (! $member instanceof Staff) {
                $messages[] = 'No active staff member has employee_code '.($row['employee_code'] ?? '').'.';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date > $today) {
                $messages[] = 'date must be a past or today\'s date, YYYY-MM-DD.';
            }
            foreach (['in' => $in, 'out' => $out] as $name => $time) {
                if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                    $messages[] = $name.' must be a time, HH:MM.';
                }
            }

            $report[] = ['row' => $i + 2, 'status' => $messages === [] ? 'ok' : 'error', 'label' => trim(($row['employee_code'] ?? '').' '.$date), 'messages' => $messages];

            if ($commit && $messages === [] && $member instanceof Staff) {
                $record = StaffAttendance::query()->where('staff_id', (string) $member->getKey())->where('date', $date)->first();
                $late = $in !== '' && $in > $lateAfter;
                $this->write($record, [
                    'staff_id' => (string) $member->getKey(), 'date' => $date, 'status' => $in === '' ? 'A' : ($late ? 'L' : 'P'),
                    'in' => $in ?: null, 'out' => $out ?: null, 'source' => 'biometric', 'inside_area' => null, 'late' => $late,
                ]);
                $written++;
            }
        }

        $ok = count(array_filter($report, fn ($r) => $r['status'] === 'ok'));

        return ['rows' => $report, 'ok' => $ok, 'errors' => count($report) - $ok, 'written' => $written];
    }

    /**
     * The office QR code: a token for the current few-minute window.
     *
     * @return array{token: string, expires_at: string}
     */
    public function qrToken(?CarbonImmutable $at = null): array
    {
        $at ??= $this->settings->now();
        $minutes = (int) config('attendance.qr_minutes', 5);
        $window = intdiv($at->getTimestamp(), $minutes * 60);

        return [
            'token' => $this->qrFor($window),
            'expires_at' => CarbonImmutable::createFromTimestamp(($window + 1) * $minutes * 60)->toIso8601String(),
        ];
    }

    private function assertQr(string $token): void
    {
        $minutes = (int) config('attendance.qr_minutes', 5);
        $window = intdiv($this->settings->now()->getTimestamp(), $minutes * 60);

        // The current code, or the one just before it (the screen may be a minute behind).
        if (! hash_equals($this->qrFor($window), $token) && ! hash_equals($this->qrFor($window - 1), $token)) {
            throw new ApiException('qr_invalid', 'This QR code has expired. Scan the code on the office screen again.', 422);
        }
    }

    private function qrFor(int $window): string
    {
        return substr(hash_hmac('sha256', app(TenantContext::class)->require().'|'.$window, (string) config('app.key')), 0, 32);
    }

    /**
     * Inside the school's area? Null when the geofence is off. The location is
     * only compared here; it is never stored, logged or audited.
     *
     * @param  array{lat: float, lng: float}|null  $location
     */
    private function insideArea(#[SensitiveParameter] ?array $location): ?bool
    {
        $fence = $this->settings->all()['staff']['geofence'];

        if (! ($fence['enabled'] ?? false) || ! isset($fence['lat'], $fence['lng'])) {
            return null;
        }

        if ($location === null) {
            throw new ApiException('location_required', 'This school checks that you are at school: allow location for this check-in, or scan the office QR code.', 422);
        }

        $distance = self::metres((float) $fence['lat'], (float) $fence['lng'], (float) $location['lat'], (float) $location['lng']);

        return $distance <= (int) ($fence['radius_m'] ?? 200);
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function staffOf(User $user): Staff
    {
        return Staff::query()->where('user_id', (string) $user->getKey())->where('status', Staff::STATUS_ACTIVE)->first()
            ?? throw new ApiException('not_staff', 'Only staff check in.', 403);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(?StaffAttendance $record, array $values): StaffAttendance
    {
        if ($record instanceof StaffAttendance) {
            $record->fill($values)->save();

            return $record;
        }

        return StaffAttendance::create($values);
    }
}
