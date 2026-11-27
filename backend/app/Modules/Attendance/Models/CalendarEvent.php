<?php

namespace App\Modules\Attendance\Models;

use App\Support\Models\SchoolModel;

/**
 * A holiday or school event. The Clerk keeps the calendar; the Principal
 * approves. Only an approved holiday closes the register for its dates.
 *
 * @property string $date Y-m-d
 * @property string $end_date Y-m-d (same as date for one day)
 * @property string $type holiday | event
 * @property string $title
 * @property string $status pending | approved | rejected
 * @property array{id: string, name: string} $created_by
 * @property array{id: string, name: string}|null $decided_by
 * @property string|null $decision_note
 */
class CalendarEvent extends SchoolModel
{
    protected $table = 'calendar_events';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'date', 'end_date', 'type', 'title', 'status', 'created_by', 'decided_by', 'decision_note'];

    /** @var array<string, mixed> */
    protected $attributes = ['type' => 'holiday', 'status' => 'pending', 'decided_by' => null, 'decision_note' => null];

    public function auditCategory(): string
    {
        return 'admin';
    }

    /** An approved holiday covering this date? */
    public static function holidayOn(string $date): ?self
    {
        return self::query()->where('type', 'holiday')->where('status', 'approved')
            ->where('date', '<=', $date)->where('end_date', '>=', $date)->first();
    }
}
