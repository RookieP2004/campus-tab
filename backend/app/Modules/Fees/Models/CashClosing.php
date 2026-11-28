<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * A collector's day-end closing: their counter payments not closed yet,
 * totalled by mode. The Clerk or the Principal confirms the cash handed
 * over; never the collector themself, and a Clerk's own closing only the
 * Principal (or the Chief).
 *
 * @property array{id: string, name: string, roles: list<string>} $collector
 * @property string $collector_id top level so it can be queried
 * @property string $date Y-m-d
 * @property list<string> $payment_ids
 * @property array<string, int> $totals mode => paise
 * @property int $total_paise
 * @property int $cash_paise
 * @property int|null $counted_cash_paise
 * @property string $status submitted | confirmed
 * @property array{id: string, name: string}|null $confirmed_by
 * @property string|null $confirmed_at
 * @property string|null $note
 */
class CashClosing extends SchoolModel
{
    protected $table = 'cash_closings';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'collector', 'collector_id', 'date', 'payment_ids', 'totals', 'total_paise', 'cash_paise', 'counted_cash_paise', 'status', 'confirmed_by', 'confirmed_at', 'note'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'submitted', 'counted_cash_paise' => null, 'confirmed_by' => null, 'confirmed_at' => null, 'note' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total_paise' => 'integer', 'cash_paise' => 'integer'];
    }
}
