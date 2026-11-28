<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * Heads, amounts and instalments for some classes and categories in one
 * year. Changing a plan changes only instalments not raised yet; dues
 * already raised stay as they are unless adjusted.
 *
 *   instalments[] {key, label, due_on: Y-m-d, lines: [{head_id, amount_paise}]}
 *   fine          {mode: none|flat|per_day, amount_paise, grace_days, cap_paise|null}
 *   discounts[]   {key, label, condition: sibling|always, kind: percent|flat,
 *                  value (basis points or paise), head_ids: [] (empty = every head)}
 *
 * @property string $year_id
 * @property string $name
 * @property list<string> $grades
 * @property list<string> $category_ids empty = any category
 * @property list<array{key: string, label: string, due_on: string, lines: list<array{head_id: string, amount_paise: int}>}> $instalments
 * @property array{mode: string, amount_paise: int, grace_days: int, cap_paise: int|null} $fine
 * @property list<array{key: string, label: string, condition: string, kind: string, value: int, head_ids: list<string>}> $discounts
 * @property string $status active | archived
 */
class FeePlan extends SchoolModel
{
    protected $table = 'fee_plans';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'name', 'grades', 'category_ids', 'instalments', 'fine', 'discounts', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'grades' => [], 'category_ids' => [], 'instalments' => [], 'discounts' => [], 'status' => 'active',
        'fine' => ['mode' => 'none', 'amount_paise' => 0, 'grace_days' => 0, 'cap_paise' => null],
    ];
}
