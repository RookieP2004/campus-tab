<?php

namespace App\Modules\Leave\Models;

use App\Support\Models\SchoolModel;

/**
 * A kind of staff leave the Principal sets up: casual, sick, earned, duty,
 * leave without pay and so on.
 *
 *   quota                days a year; null means no limit (duty leave, leave without pay)
 *   sandwich             do holidays and Sundays inside a leave count as leave days?
 *   half_day             may a one-day request be for half a day?
 *   document_after_days  a supporting document is required above this many days (null: never)
 *   status               active | archived (archived types take no new requests)
 *
 * @property string $code
 * @property string $name
 * @property float|null $quota
 * @property bool $sandwich
 * @property bool $half_day
 * @property int|null $document_after_days
 * @property string $status
 */
class LeaveType extends SchoolModel
{
    protected $table = 'leave_types';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'code', 'name', 'quota', 'sandwich', 'half_day', 'document_after_days', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['quota' => null, 'sandwich' => false, 'half_day' => true, 'document_after_days' => null, 'status' => 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sandwich' => 'boolean', 'half_day' => 'boolean'];
    }

    public function auditCategory(): string
    {
        return 'admin';
    }

    public function needsDocument(float $days): bool
    {
        return $this->document_after_days !== null && $days > (int) $this->document_after_days;
    }
}
