<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A rebate, waiver, fine waiver, refund, scholarship credit or
 * carry-forward. Proposed by the Clerk (or the Chief), approved by the
 * Principal (or the Chief when the school says so), never by whoever
 * proposed it. It touches the ledger only when approved.
 *
 * @property string $student_id
 * @property string $student_name
 * @property string $year_id
 * @property string $kind
 * @property string|null $demand_id
 * @property int $amount_paise
 * @property int|null $percent_bp a rebate given as a percentage (the amount is worked out from it)
 * @property string $reason
 * @property string $status proposed | approved | rejected
 * @property array{id: string, name: string} $proposed_by
 * @property array{id: string, name: string}|null $decided_by
 * @property string|null $decision_reason
 * @property string|null $decided_at
 * @property string|null $source year_end: carried forward in the year-end wizard (one Principal approval)
 */
class FeeAdjustment extends SchoolModel
{
    protected $table = 'fee_adjustments';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'student_id', 'student_name', 'year_id', 'kind', 'demand_id', 'amount_paise', 'percent_bp', 'reason', 'status', 'proposed_by', 'decided_by', 'decision_reason', 'decided_at', 'source'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'proposed', 'demand_id' => null, 'percent_bp' => null, 'decided_by' => null, 'decision_reason' => null, 'decided_at' => null, 'source' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }
}
