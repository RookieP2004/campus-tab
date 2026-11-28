<?php

namespace App\Modules\Fees\Models;

use App\Support\Models\SchoolModel;

/**
 * Cancelling a receipt (its payment is reversed in the ledger; the receipt
 * keeps its number, marked cancelled). A Fee Collector requests, the Clerk
 * proposes (or forwards a request), the Principal approves; nobody approves
 * a cancellation they requested or proposed.
 *
 * @property string $receipt_id
 * @property string $receipt_no
 * @property string $payment_id
 * @property string $student_id
 * @property string $student_name
 * @property int $amount_paise
 * @property string $reason
 * @property string $status requested | proposed | approved | rejected
 * @property array{id: string, name: string} $requested_by
 * @property array{id: string, name: string}|null $proposed_by
 * @property array{id: string, name: string}|null $decided_by
 * @property string|null $decision_reason
 * @property string|null $decided_at
 */
class ReceiptCancellation extends SchoolModel
{
    protected $table = 'receipt_cancellations';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'receipt_id', 'receipt_no', 'payment_id', 'student_id', 'student_name', 'amount_paise', 'reason', 'status', 'requested_by', 'proposed_by', 'decided_by', 'decision_reason', 'decided_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['proposed_by' => null, 'decided_by' => null, 'decision_reason' => null, 'decided_at' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_paise' => 'integer'];
    }

    /** Everyone who asked for this cancellation (none of them may approve it). */
    public function involves(string $userId): bool
    {
        return ($this->requested_by['id'] ?? null) === $userId || ($this->proposed_by['id'] ?? null) === $userId;
    }
}
