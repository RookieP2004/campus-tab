<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A receipt. Its number comes from the school's counter for the year,
 * inside the payment's transaction: in sequence, never reused. A cancelled
 * or bounced receipt keeps its number and stays listed.
 *
 * @property string $number e.g. SPS/2026-27/000042
 * @property int $seq
 * @property string $year_id
 * @property string $payment_id
 * @property string $student_id
 * @property string $student_name
 * @property string|null $section_label
 * @property int $amount_paise
 * @property string $mode
 * @property list<array{label: string, head: string, amount_paise: int}> $lines
 * @property string $status valid | cancelled | bounced
 * @property array{id: string, name: string}|null $issued_by
 * @property string $issued_on
 */
class Receipt extends SchoolModel
{
    protected $table = 'receipts';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'number', 'seq', 'year_id', 'payment_id', 'student_id', 'student_name', 'section_label', 'amount_paise', 'mode', 'lines', 'status', 'issued_by', 'issued_on'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'valid', 'issued_by' => null, 'section_label' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'seq' => 'integer'];
    }
}
