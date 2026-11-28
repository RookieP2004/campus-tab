<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * One student's dues for one instalment, raised from their plan. Its
 * amounts are fixed when raised (the ledger holds the matching "demand"
 * entries). Only the fine fields change: a late fine accrues by the plan's
 * rule until it is posted to the ledger (at the first payment on this
 * instalment, or when a fine waiver is approved) and then stays fixed.
 *
 *   lines[]     {head_id, head, amount_paise}   after any override
 *   discounts[] {label, head_id, amount_paise}
 *
 * @property string $student_id
 * @property string $student_name
 * @property string $year_id
 * @property string|null $section_id
 * @property string|null $section_label
 * @property string $plan_id
 * @property string $instalment_key
 * @property string $label
 * @property string $due_on
 * @property list<array{head_id: string, head: string, amount_paise: int}> $lines
 * @property list<array{label: string, head_id: string, amount_paise: int}> $discounts
 * @property int $total_paise
 * @property array{mode: string, amount_paise: int, grace_days: int, cap_paise: int|null} $fine_rule
 * @property int|null $fine_posted_paise
 */
class FeeDemand extends SchoolModel
{
    protected $table = 'fee_demands';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'student_id', 'student_name', 'year_id', 'section_id', 'section_label', 'plan_id', 'instalment_key', 'label', 'due_on',
        'lines', 'discounts', 'total_paise', 'fine_rule', 'fine_posted_paise',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['discounts' => [], 'fine_posted_paise' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total_paise' => 'integer'];
    }
}
