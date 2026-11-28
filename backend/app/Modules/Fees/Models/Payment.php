<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * Money received for one student: at the counter (cash, cheque, DD, bank
 * transfer, counter UPI) or online. Its ledger entries are the record of
 * what it paid; this document holds how and the receipt.
 *
 *   allocations[] {demand_id, label, head_id, head, amount_paise}  (a fine line has head_id "fine")
 *   status: received (cash, UPI, transfer, online) | pending_clearance (cheque, DD)
 *           | cleared | bounced | cancelled
 *
 * @property string $student_id
 * @property string $year_id
 * @property string $mode
 * @property int $amount_paise
 * @property string|null $reference cheque or DD number, bank or UPI reference
 * @property string|null $bank
 * @property string $status
 * @property array{id: string, name: string}|null $collector
 * @property string|null $collector_id the collector's user id, top level so it can be queried (a nested id is not)
 * @property array{provider: string, order_id: string, payment_id: string}|null $online
 * @property list<array{demand_id: string, label: string, head_id: string, head: string, amount_paise: int}> $allocations
 * @property string|null $receipt_id
 * @property string|null $receipt_no
 * @property string $received_on Y-m-d (India)
 * @property string|null $closing_id
 * @property array<string, mixed>|null $outcome who cleared, bounced or cancelled it, when and why
 */
class Payment extends SchoolModel
{
    public const RECEIVED = 'received';

    public const PENDING = 'pending_clearance';

    public const CLEARED = 'cleared';

    public const BOUNCED = 'bounced';

    public const CANCELLED = 'cancelled';

    protected $table = 'payments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'student_id', 'year_id', 'mode', 'amount_paise', 'reference', 'bank', 'status', 'collector', 'collector_id', 'online',
        'allocations', 'receipt_id', 'receipt_no', 'received_on', 'closing_id', 'outcome',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['reference' => null, 'bank' => null, 'collector' => null, 'collector_id' => null, 'online' => null, 'allocations' => [], 'receipt_id' => null, 'receipt_no' => null, 'closing_id' => null, 'outcome' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }
}
