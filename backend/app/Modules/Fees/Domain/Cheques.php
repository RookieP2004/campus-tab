<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Cheques and DDs stay "pending clearance" until the bank clears them.
 * A bounced one reverses its payment in the ledger (the dues are owed
 * again), its receipt keeps its number marked bounced, and the school's
 * bounce fine is added if it has one.
 */
class Cheques
{
    public function __construct(private readonly Ledger $ledger) {}

    public function clear(User $user, Payment $payment): Payment
    {
        $this->assertPending($payment);
        $payment->fill(['status' => Payment::CLEARED, 'outcome' => ['action' => 'cleared', 'by' => self::who($user), 'at' => now()->toIso8601String()]])->save();

        return $payment;
    }

    public function bounce(User $user, Payment $payment, string $reason): Payment
    {
        $this->assertPending($payment);
        $reason = trim($reason);
        if (mb_strlen($reason) < (int) config('fees.reason_min', 10)) {
            throw new ApiException('reason_required', 'Write what the bank said (at least '.config('fees.reason_min', 10).' characters).', 422);
        }

        DB::connection('mongodb')->transaction(function () use ($user, $payment, $reason): void {
            $this->ledger->reversePayment((string) $payment->getKey(), 'Cheque bounced: '.$reason, $user);
            $fine = (int) config('fees.bounce_fine_paise', 0);
            if ($fine > 0) {
                $first = FeeLedgerEntry::query()->where('payment_id', (string) $payment->getKey())->whereNull('reverses_id')->first();
                $this->ledger->post(['student_id' => $payment->student_id, 'year_id' => $payment->year_id, 'type' => 'fine', 'amount_paise' => $fine,
                    'demand_id' => $first?->demand_id, 'head_id' => 'fine', 'payment_id' => (string) $payment->getKey(), 'reason' => 'Bounced cheque fine'], $user);
            }
            $payment->fill(['status' => Payment::BOUNCED, 'outcome' => ['action' => 'bounced', 'by' => self::who($user), 'reason' => $reason, 'at' => now()->toIso8601String()]])->save();
            Receipt::query()->findOrFail($payment->receipt_id)->fill(['status' => 'bounced'])->save();
        }, 5);

        return Payment::query()->findOrFail($payment->getKey());
    }

    private function assertPending(Payment $payment): void
    {
        if ($payment->status !== Payment::PENDING) {
            throw new ApiException('not_pending', 'Only a cheque or DD waiting for clearance can be cleared or marked bounced.', 409, ['status' => $payment->status]);
        }
    }

    /**
     * @return array{id: string, name: string}
     */
    public static function who(User $user): array
    {
        return ['id' => (string) $user->getKey(), 'name' => $user->name];
    }
}
