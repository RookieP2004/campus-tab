<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Attendance\Models\CalendarEvent;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;

/**
 * How many leave days a date range is.
 *
 *  - Working days count. Days off are the weekly off days
 *    (config leave.weekly_off, Sunday) and approved holidays.
 *  - The sandwich rule is per leave type: when it is on, days off that fall
 *    inside the leave (between its first and last working day) count too.
 *    Days off at either end never count.
 *  - A one-day request may be for half a day (0.5) if the type allows it.
 *  - A range with no working day is refused.
 */
class LeaveDays
{
    /**
     * @return array{days: float, working: list<string>, counted: list<string>, off: list<array{date: string, why: string}>}
     */
    public function count(string $from, string $to, bool $sandwich, ?string $halfDay = null): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        if ($end->lessThan($start)) {
            throw new ApiException('leave_dates_invalid', 'The last day must be on or after the first day.', 422);
        }

        if ($start->diffInDays($end) + 1 > (int) config('leave.max_span_days', 180)) {
            throw new ApiException('leave_too_long', 'One request can cover at most '.config('leave.max_span_days', 180).' days. Split it into two.', 422);
        }

        if ($halfDay !== null && $from !== $to) {
            throw new ApiException('half_day_single_date', 'A half day is for one date only.', 422);
        }

        $holidays = $this->holidays($from, $to);
        $weeklyOff = array_map('intval', (array) config('leave.weekly_off', [7]));

        $working = [];
        $off = [];
        $all = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $all[] = $date;
            if (isset($holidays[$date])) {
                $off[] = ['date' => $date, 'why' => $holidays[$date]];
            } elseif (in_array($d->isoWeekday(), $weeklyOff, true)) {
                $off[] = ['date' => $date, 'why' => 'weekly_off'];
            } else {
                $working[] = $date;
            }
        }

        if ($working === []) {
            throw new ApiException('no_working_days', 'Every day in these dates is a holiday or a weekly off day. Choose dates that include a working day.', 422);
        }

        $first = $working[0];
        $last = $working[count($working) - 1];
        $counted = array_values(array_filter($all, fn (string $date) => in_array($date, $working, true)
            || ($sandwich && $date > $first && $date < $last)));

        $days = $halfDay !== null ? 0.5 : (float) count($counted);

        return ['days' => $days, 'working' => $working, 'counted' => $counted, 'off' => $off];
    }

    /**
     * Approved holidays between two dates, by date.
     *
     * @return array<string, string> date => title
     */
    public function holidays(string $from, string $to): array
    {
        $out = [];
        $events = CalendarEvent::query()->where('type', 'holiday')->where('status', 'approved')
            ->where('date', '<=', $to)->where('end_date', '>=', $from)->get();

        foreach ($events as $event) {
            for ($d = CarbonImmutable::parse(max($event->date, $from)); $d->toDateString() <= min($event->end_date, $to); $d = $d->addDay()) {
                $out[$d->toDateString()] = $event->title;
            }
        }

        return $out;
    }
}
