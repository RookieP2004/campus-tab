<?php

namespace App\Modules\Fees\Domain;

use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * Reads and writes the append-only fee ledger. Every balance is worked out
 * from the entries; nothing stores a running total.
 *
 * Per instalment (demand):
 *   due       = its demand entries (the lines less discounts)
 *   fine      = the fine posted, or (not posted yet) what has accrued today
 *   paid      = minus its payment entries (net of reversals)
 *   adjusted  = minus its adjustment entries (net of reversals)
 *   balance   = due + fine - paid - adjusted
 */
class Ledger
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  User|array{id: string, name: string}|null  $by
     */
    public function post(array $fields, User|array|null $by): FeeLedgerEntry
    {
        $who = $by instanceof User ? ['id' => (string) $by->getKey(), 'name' => $by->name] : $by;

        return FeeLedgerEntry::create($fields + ['by' => $who]);
    }

    /** The student's balance for a year: positive = owed, negative = credit. */
    public function balance(string $studentId, string $yearId): int
    {
        return (int) FeeLedgerEntry::query()->where('student_id', $studentId)->where('year_id', $yearId)->get()->sum('amount_paise');
    }

    /**
     * @param  iterable<FeeDemand>  $demands
     * @return array<string, array{due: int, fine: int, fine_posted: bool, paid: int, adjusted: int, balance: int, status: string}>
     */
    public function demandStates(iterable $demands, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $byId = [];
        foreach ($demands as $d) {
            $byId[(string) $d->getKey()] = $d;
        }
        if ($byId === []) {
            return [];
        }
        $entries = FeeLedgerEntry::query()->whereIn('demand_id', array_keys($byId))->get();
        $reversed = $entries->whereNotNull('reverses_id')->pluck('reverses_id')->flip();
        $out = [];

        foreach ($byId as $id => $demand) {
            $own = $entries->where('demand_id', $id);
            $sum = fn (string $type) => (int) $own->where('type', $type)->reject(fn ($e) => isset($reversed[(string) $e->getKey()]))->sum('amount_paise');
            $due = $sum('demand');
            $paid = -$sum('payment');
            $adjusted = -$sum('adjustment');
            $finePosted = $demand->fine_posted_paise !== null;
            $fine = $finePosted ? $sum('fine') : $this->accruedFine($demand, max(0, $due - $paid - $adjusted), $today);
            $balance = $due + $fine - $paid - $adjusted;
            $out[$id] = [
                'due' => $due, 'fine' => $fine, 'fine_posted' => $finePosted, 'paid' => $paid, 'adjusted' => $adjusted, 'balance' => $balance,
                'status' => match (true) {
                    $balance <= 0 => 'paid',
                    $paid > 0 || $adjusted > 0 => 'part_paid',
                    $demand->due_on < $today->toDateString() => 'overdue',
                    default => 'due',
                },
            ];
        }

        return $out;
    }

    /**
     * The late fine accrued on an instalment today, by the plan's rule copied
     * onto it: none, a flat amount, or an amount per day late; after a grace
     * period and up to a cap. Nothing accrues once the dues are cleared.
     */
    public function accruedFine(FeeDemand $demand, int $outstanding, CarbonImmutable $today): int
    {
        $rule = $demand->fine_rule + ['mode' => 'none', 'amount_paise' => 0, 'grace_days' => 0, 'cap_paise' => null];
        if ($rule['mode'] === 'none' || $outstanding <= 0 || (int) $rule['amount_paise'] <= 0) {
            return 0;
        }
        $starts = CarbonImmutable::parse($demand->due_on, 'Asia/Kolkata')->addDays((int) $rule['grace_days']);
        if ($today->lessThanOrEqualTo($starts)) {
            return 0;
        }
        $fine = $rule['mode'] === 'flat' ? (int) $rule['amount_paise'] : (int) $rule['amount_paise'] * (int) $starts->diffInDays($today);

        return $rule['cap_paise'] !== null ? min($fine, (int) $rule['cap_paise']) : $fine;
    }

    /**
     * Reverses every live entry of a payment (for a cancelled receipt or a
     * bounced cheque). Returns the reversal entries.
     *
     * @param  User|array{id: string, name: string}|null  $by
     * @return list<FeeLedgerEntry>
     */
    public function reversePayment(string $paymentId, string $reason, User|array|null $by): array
    {
        $entries = FeeLedgerEntry::query()->where('payment_id', $paymentId)->get();
        $already = $entries->whereNotNull('reverses_id')->pluck('reverses_id')->all();
        $out = [];
        foreach ($entries->whereNull('reverses_id') as $entry) {
            if (in_array((string) $entry->getKey(), $already, true)) {
                continue;
            }
            $out[] = $this->post([
                'student_id' => $entry->student_id, 'year_id' => $entry->year_id, 'type' => 'reversal', 'amount_paise' => -$entry->amount_paise,
                'demand_id' => $entry->demand_id, 'head_id' => $entry->head_id, 'payment_id' => $paymentId, 'receipt_no' => $entry->receipt_no,
                'reverses_id' => (string) $entry->getKey(), 'reason' => $reason,
            ], $by);
        }

        return $out;
    }
}
