<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;
use LogicException;

/**
 * The student's fee ledger: append-only. An entry is never edited or
 * deleted; a wrong one is cancelled by a "reversal" entry that names it.
 * Balances are always worked out from the entries.
 *
 * amount_paise is signed: positive adds to what the family owes (demand,
 * fine, refund paid out), negative takes away (payment, rebate, waiver,
 * scholarship, carry-forward out). A reversal carries the opposite amount
 * of the entry it reverses.
 *
 * @property string $student_id
 * @property string $year_id
 * @property string $type demand | fine | payment | adjustment | reversal
 * @property int $amount_paise
 * @property string|null $demand_id
 * @property string|null $head_id
 * @property string|null $payment_id
 * @property string|null $receipt_no
 * @property string|null $adjustment_id
 * @property string|null $adjustment_kind
 * @property string|null $reverses_id
 * @property string|null $reason
 * @property array{id: string, name: string}|null $by
 */
class FeeLedgerEntry extends SchoolModel
{
    protected $table = 'fee_ledger';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'student_id', 'year_id', 'type', 'amount_paise', 'demand_id', 'head_id', 'payment_id', 'receipt_no',
        'adjustment_id', 'adjustment_kind', 'reverses_id', 'reason', 'by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'demand_id' => null, 'head_id' => null, 'payment_id' => null, 'receipt_no' => null, 'adjustment_id' => null,
        'adjustment_kind' => null, 'reverses_id' => null, 'reason' => null, 'by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fee ledger entries are append-only: post a reversal instead of editing.'));
        static::deleting(fn () => throw new LogicException('Fee ledger entries are append-only: post a reversal instead of deleting.'));
    }
}
