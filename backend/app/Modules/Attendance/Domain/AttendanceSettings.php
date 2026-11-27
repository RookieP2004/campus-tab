<?php

namespace App\Modules\Attendance\Domain;

use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;

/**
 * The school's attendance settings (school config policies.attendance),
 * with defaults for keys a school has not set, and the school's own clock
 * (locale.timezone, Asia/Kolkata by default).
 */
class AttendanceSettings
{
    public function __construct(private readonly SchoolConfigService $configs) {}

    /**
     * @return array{student_mode: string, edit_window_hours: int, min_percent_alert: int, late_entry_days: int, school_starts_at: string, periods_per_day: int, staff: array{late_after: string, geofence: array{enabled: bool, lat?: float, lng?: float, radius_m?: int}}}
     */
    public function all(): array
    {
        $body = $this->configs->current()->body;
        $a = (array) ($body['policies']['attendance'] ?? []);

        return [
            'student_mode' => (string) ($a['student_mode'] ?? 'daily'),
            'edit_window_hours' => (int) ($a['edit_window_hours'] ?? 4),
            'min_percent_alert' => (int) ($a['min_percent_alert'] ?? 75),
            'late_entry_days' => (int) ($a['late_entry_days'] ?? 7),
            'school_starts_at' => (string) ($a['school_starts_at'] ?? '08:00'),
            'periods_per_day' => (int) ($a['periods_per_day'] ?? 8),
            'staff' => [
                'late_after' => (string) ($a['staff']['late_after'] ?? '08:15'),
                'geofence' => (array) ($a['staff']['geofence'] ?? ['enabled' => false]),
            ],
        ];
    }

    public function timezone(): string
    {
        return (string) ($this->configs->current()->body['locale']['timezone'] ?? 'Asia/Kolkata');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function today(): string
    {
        return $this->now()->toDateString();
    }

    /** When teachers stop being able to change a date's register on their own. */
    public function windowClosesAt(string $date): CarbonImmutable
    {
        $settings = $this->all();

        return CarbonImmutable::parse($date.' '.$settings['school_starts_at'], $this->timezone())->addHours($settings['edit_window_hours']);
    }
}
