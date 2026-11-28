<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A school's running number, e.g. receipts for 2026-27. Only ever
 * incremented, atomically, inside the transaction that uses the number
 * (FeeCounters::next), so numbers are in sequence and never reused.
 *
 * @property string $key e.g. receipt:<year_id>
 * @property int $value
 */
class FeeCounter extends SchoolModel
{
    protected $table = 'fee_counters';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'key', 'value'];

    /** @var array<string, mixed> */
    protected $attributes = ['value' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['value', 'updated_at'];
    }
}
