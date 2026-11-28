<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * The Principal's confirmation, in the year-end wizard, that a student's
 * unpaid balance stays in the year being closed instead of being carried
 * forward. It holds the amount confirmed: if the balance changes afterwards
 * (a payment, a reversal) the confirmation no longer counts and the year
 * cannot close until the Principal decides again. A later carry-forward of
 * the same balance marks it superseded.
 *
 * @property string $year_id
 * @property string $student_id
 * @property string $student_name
 * @property int $amount_paise
 * @property array{id: string, name: string} $decided_by
 * @property string $decided_at
 * @property string|null $superseded_at
 */
class FeeYearEndDecision extends SchoolModel
{
    protected $table = 'fee_year_end_decisions';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'year_id', 'student_id', 'student_name', 'amount_paise', 'decided_by', 'decided_at', 'superseded_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['superseded_at' => null];

    public function auditCategory(): string
    {
        return 'fees';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }
}
