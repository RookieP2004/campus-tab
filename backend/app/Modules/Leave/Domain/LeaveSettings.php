<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Attendance\Domain\AttendanceSettings;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;

/**
 * The school's leave settings (school config policies.leave) with defaults,
 * and the school's clock.
 */
class LeaveSettings
{
    public function __construct(
        private readonly SchoolConfigService $configs,
        private readonly AttendanceSettings $attendance,
    ) {}

    /**
     * @return array{chain: list<string>, clerk_can_reject: bool, remind_after_hours: int, escalate_after_hours: int, student_principal_after_days: int}
     */
    public function policy(): array
    {
        $l = (array) ($this->configs->current()->body['policies']['leave'] ?? []);

        return [
            'chain' => array_values(array_map('strval', (array) ($l['chain'] ?? ['clerk', 'principal']))),
            'clerk_can_reject' => (bool) ($l['clerk_can_reject'] ?? false),
            'remind_after_hours' => (int) ($l['remind_after_hours'] ?? 24),
            'escalate_after_hours' => (int) ($l['escalate_after_hours'] ?? 48),
            'student_principal_after_days' => (int) ($l['student_principal_after_days'] ?? 3),
        ];
    }

    public function now(): CarbonImmutable
    {
        return $this->attendance->now();
    }

    public function today(): string
    {
        return $this->attendance->today();
    }
}
