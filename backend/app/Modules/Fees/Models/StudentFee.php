<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A student's fee set-up for one year: their category, an explicit plan
 * (or the class's plan for that category), when they joined (mid-year
 * joiners are charged from their joining month) and one-off overrides.
 *
 *   overrides[] {head_id, from: Y-m-d|null, to: Y-m-d|null, amount_paise}
 *   e.g. transport not charged until August: {transport, null, 2026-07-31, 0}
 *
 * @property string $student_id
 * @property string $year_id
 * @property string|null $plan_id
 * @property string|null $category_id
 * @property string|null $joined_on
 * @property list<array{head_id: string, from: string|null, to: string|null, amount_paise: int}> $overrides
 */
class StudentFee extends SchoolModel
{
    protected $table = 'student_fees';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'student_id', 'year_id', 'plan_id', 'category_id', 'joined_on', 'overrides'];

    /** @var array<string, mixed> */
    protected $attributes = ['plan_id' => null, 'category_id' => null, 'joined_on' => null, 'overrides' => []];
}
