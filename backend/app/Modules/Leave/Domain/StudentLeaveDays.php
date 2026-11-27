<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Leave\Models\LeaveRequest;

/**
 * Which students are on approved leave on a date, for the register
 * (Attendance pre-fills LV for them).
 */
class StudentLeaveDays
{
    /**
     * @param  list<string>  $studentIds
     * @return list<string> the ids on approved leave that day
     */
    public function onLeave(array $studentIds, string $date): array
    {
        if ($studentIds === []) {
            return [];
        }

        return LeaveRequest::query()->where('kind', 'student')->where('status', LeaveRequest::APPROVED)
            ->whereIn('applicant_student_id', $studentIds)->get()
            ->filter(fn (LeaveRequest $r) => in_array($date, (array) ($r->approved['working'] ?? []), true))
            ->map(fn (LeaveRequest $r) => (string) $r->applicant_student_id)->unique()->values()->all();
    }
}
