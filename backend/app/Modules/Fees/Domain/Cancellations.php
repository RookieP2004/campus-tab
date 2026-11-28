<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Fees\Models\ReceiptCancellation;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a receipt: a reversal, never an edit or delete.
 *
 *   Fee Collector   request   (fees.receipt_cancel_request)    -> requested
 *   Clerk           propose   (fees.receipt_cancel_propose), or forward a request -> proposed
 *   Principal       approve   (fees.receipt_cancel_approve, step-up)  -> approved
 *   Chief           any of these (fees.receipt_cancel)
 *
 * Nobody approves a cancellation they requested or proposed (owner's rule
 * 5). Approving reverses the payment's ledger entries in one transaction;
 * the receipt keeps its number, marked cancelled.
 */
class Cancellations
{
    public function __construct(private readonly AccessControl $access, private readonly Ledger $ledger) {}

    public function raise(User $user, Receipt $receipt, string $reason): ReceiptCancellation
    {
        $reason = self::reason($reason);
        if ($receipt->status !== 'valid') {
            throw new ApiException('receipt_not_valid', 'This receipt is already cancelled or bounced.', 409);
        }
        if (ReceiptCancellation::query()->where('receipt_id', (string) $receipt->getKey())->whereIn('status', ['requested', 'proposed'])->exists()) {
            throw new ApiException('cancellation_pending', 'A cancellation for this receipt is already waiting.', 409);
        }
        $proposes = $this->mayPropose($user);
        if (! $proposes && ! $this->access->allows($user, 'fees.receipt_cancel_request')) {
            throw new ApiException('permission_denied', 'You may not ask for a receipt to be cancelled.', 403);
        }

        return ReceiptCancellation::create([
            'receipt_id' => (string) $receipt->getKey(), 'receipt_no' => $receipt->number, 'payment_id' => $receipt->payment_id,
            'student_id' => $receipt->student_id, 'student_name' => $receipt->student_name, 'amount_paise' => $receipt->amount_paise,
            'reason' => $reason, 'status' => $proposes ? 'proposed' : 'requested',
            'requested_by' => Cheques::who($user), 'proposed_by' => $proposes ? Cheques::who($user) : null,
        ]);
    }

    /** The Clerk forwards a collector's request to the Principal. */
    public function forward(User $user, ReceiptCancellation $c): ReceiptCancellation
    {
        if (! $this->mayPropose($user)) {
            throw new ApiException('permission_denied', 'Only the Clerk (or the Chief) forwards a cancellation request.', 403);
        }
        if ($c->status !== 'requested') {
            throw new ApiException('cancellation_not_requested', 'Only a request waiting for the office can be forwarded.', 409);
        }
        $c->fill(['status' => 'proposed', 'proposed_by' => Cheques::who($user)])->save();

        return $c;
    }

    public function approve(User $user, ReceiptCancellation $c, string $reason): ReceiptCancellation
    {
        $reason = self::reason($reason);
        $this->assertDecider($user, $c);

        DB::connection('mongodb')->transaction(function () use ($user, $c, $reason): void {
            $payment = Payment::query()->findOrFail($c->payment_id);
            if (in_array($payment->status, [Payment::CANCELLED, Payment::BOUNCED], true)) {
                throw new ApiException('payment_already_reversed', 'This payment was already reversed.', 409);
            }
            $this->ledger->reversePayment((string) $payment->getKey(), 'Receipt '.$c->receipt_no.' cancelled: '.$c->reason, $user);
            $payment->fill(['status' => Payment::CANCELLED, 'outcome' => ['action' => 'cancelled', 'by' => Cheques::who($user), 'reason' => $c->reason, 'at' => now()->toIso8601String()]])->save();
            Receipt::query()->findOrFail($c->receipt_id)->fill(['status' => 'cancelled'])->save();
            $c->fill(['status' => 'approved', 'decided_by' => Cheques::who($user), 'decision_reason' => $reason, 'decided_at' => now()->toIso8601String()])->save();
        }, 5);

        return ReceiptCancellation::query()->findOrFail($c->getKey());
    }

    public function reject(User $user, ReceiptCancellation $c, string $reason): ReceiptCancellation
    {
        $reason = self::reason($reason);
        if (! in_array($c->status, ['requested', 'proposed'], true)) {
            throw new ApiException('cancellation_decided', 'This cancellation was already decided.', 409);
        }
        $officeTurnsDownRequest = $c->status === 'requested' && $this->mayPropose($user);
        if (! $officeTurnsDownRequest && ! $this->mayApprove($user)) {
            throw new ApiException('permission_denied', 'You may not decide this cancellation.', 403);
        }
        if ($c->involves((string) $user->getKey())) {
            throw new ApiException('own_request', 'Someone else decides a cancellation you asked for.', 403);
        }
        $c->fill(['status' => 'rejected', 'decided_by' => Cheques::who($user), 'decision_reason' => $reason, 'decided_at' => now()->toIso8601String()])->save();

        return $c;
    }

    public function mayPropose(User $user): bool
    {
        return $this->access->allows($user, 'fees.receipt_cancel_propose') || $this->access->allows($user, 'fees.receipt_cancel');
    }

    public function mayApprove(User $user): bool
    {
        return $this->access->allows($user, 'fees.receipt_cancel_approve') || $this->access->allows($user, 'fees.receipt_cancel');
    }

    public function assertDecider(User $user, ReceiptCancellation $c): void
    {
        if (! in_array($c->status, ['requested', 'proposed'], true)) {
            throw new ApiException('cancellation_decided', 'This cancellation was already decided.', 409);
        }
        if (! $this->mayApprove($user)) {
            throw new ApiException('permission_denied', 'Only the Principal (or the Chief) approves cancelling a receipt.', 403);
        }
        if ($c->involves((string) $user->getKey())) {
            throw new ApiException('own_request', 'Someone else approves a cancellation you asked for or proposed.', 403);
        }
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < (int) config('fees.reason_min', 10)) {
            throw new ApiException('reason_required', 'Write the reason (at least '.config('fees.reason_min', 10).' characters).', 422);
        }

        return $reason;
    }
}
