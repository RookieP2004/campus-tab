<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Families;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Taking money: at the counter (Fee Collector duty, Clerk, Principal,
 * Chief) or from a verified gateway webhook. One MongoDB transaction does
 * it all, or nothing:
 *
 *   1. a fine that has accrued on a chosen instalment is posted (and fixed),
 *   2. the amount is spread over the chosen dues, oldest first, fine first,
 *      then head by head, as ledger "payment" entries,
 *   3. the receipt number is taken from the school's counter for the year,
 *   4. the payment and its receipt are written.
 *
 * Nobody can pay more than is owed on the chosen dues. The family is then
 * told only that a payment was received, never the amount.
 */
class Payments
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FeeCounters $counters,
        private readonly SchoolConfigService $configs,
        private readonly TenantContext $tenant,
        private readonly Notifier $notifier,
        private readonly Families $families,
    ) {}

    /**
     * @param  array{mode: string, amount_paise: int, reference?: string|null, bank?: string|null, demand_ids?: list<string>|null}  $input
     * @param  array{id: string, name: string}|null  $collector
     * @param  array{provider: string, order_id: string, payment_id: string}|null  $online
     * @param  User|array{id: string, name: string}|null  $by
     * @param  (\Closure(Payment): void)|null  $alsoInside  more writes in the same transaction (the webhook's idempotency record)
     */
    public function take(Student $student, array $input, ?array $collector, ?array $online = null, User|array|null $by = null, ?\Closure $alsoInside = null): Payment
    {
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $yearId = (string) $year->getKey();
        $amount = (int) $input['amount_paise'];
        if ($amount <= 0) {
            throw new ApiException('amount_invalid', 'The amount must be more than zero.', 422);
        }
        $mode = $input['mode'];
        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $prefix = $this->configs->current()->body['policies']['fees']['receipt_prefix'] ?? null;

        $payment = DB::connection('mongodb')->transaction(function () use ($student, $input, $collector, $online, $by, $yearId, $year, $amount, $mode, $today, $prefix, $alsoInside): Payment {
            $demands = $this->openDemands((string) $student->getKey(), $yearId, $input['demand_ids'] ?? null);
            $states = $this->ledger->demandStates($demands, $today);
            $owed = array_sum(array_map(fn ($s) => max(0, $s['balance']), $states));
            if ($owed === 0) {
                throw new ApiException('nothing_due', 'Nothing is due on the chosen instalments.', 409);
            }
            if ($amount > $owed) {
                throw new ApiException('amount_above_dues', 'The amount is more than what is due on the chosen instalments ('.Money::format($owed).').', 422, ['due_paise' => $owed]);
            }

            $seq = $this->counters->next('receipt:'.$yearId);
            $number = FeeCounters::receiptNumber(is_string($prefix) ? $prefix : null, $year->name, $seq);
            $payment = Payment::create([
                'student_id' => (string) $student->getKey(), 'year_id' => $yearId, 'mode' => $mode, 'amount_paise' => $amount,
                'reference' => $input['reference'] ?? null, 'bank' => $input['bank'] ?? null,
                'status' => in_array($mode, (array) config('fees.clearance_modes'), true) ? Payment::PENDING : Payment::RECEIVED,
                'collector' => $collector, 'collector_id' => $collector['id'] ?? null, 'online' => $online, 'receipt_no' => $number, 'received_on' => $today->toDateString(),
            ]);

            $left = $amount;
            $allocations = [];
            foreach ($demands as $demand) {
                if ($left === 0) {
                    break;
                }
                $state = $states[(string) $demand->getKey()];
                if ($state['balance'] <= 0) {
                    continue;
                }
                $this->postFine($demand, $state, $by);
                foreach ($this->remainingByHead($demand) as $headId => [$head, $remaining]) {
                    $take = min($left, $remaining);
                    if ($take <= 0) {
                        continue;
                    }
                    $this->ledger->post([
                        'student_id' => (string) $student->getKey(), 'year_id' => $yearId, 'type' => 'payment', 'amount_paise' => -$take,
                        'demand_id' => (string) $demand->getKey(), 'head_id' => $headId, 'payment_id' => (string) $payment->getKey(), 'receipt_no' => $number,
                        'reason' => $mode === 'online' ? 'Online payment' : 'Counter payment ('.$mode.')',
                    ], $by);
                    $allocations[] = ['demand_id' => (string) $demand->getKey(), 'label' => $demand->label, 'head_id' => $headId, 'head' => $head, 'amount_paise' => $take];
                    $left -= $take;
                    if ($left === 0) {
                        break;
                    }
                }
            }

            $receipt = Receipt::create([
                'number' => $number, 'seq' => $seq, 'year_id' => $yearId, 'payment_id' => (string) $payment->getKey(),
                'student_id' => (string) $student->getKey(), 'student_name' => $student->name, 'section_label' => $demands->first()?->section_label,
                'amount_paise' => $amount, 'mode' => $mode,
                'lines' => array_map(fn ($a) => ['label' => $a['label'], 'head' => $a['head'], 'amount_paise' => $a['amount_paise']], $allocations),
                'issued_by' => $collector, 'issued_on' => $today->toDateString(),
            ]);
            $payment->fill(['allocations' => $allocations, 'receipt_id' => (string) $receipt->getKey()])->save();
            if ($alsoInside !== null) {
                $alsoInside($payment);
            }

            return $payment;
        }, 5);

        $this->tellFamily([(string) $student->getKey()]);

        return $payment;
    }

    /**
     * Posts (and fixes) the fine accrued on an instalment, if any.
     *
     * @param  array{fine: int, fine_posted: bool}  $state
     * @param  User|array{id: string, name: string}|null  $by
     */
    public function postFine(FeeDemand $demand, array $state, User|array|null $by): void
    {
        if ($state['fine_posted'] || $state['fine'] <= 0) {
            return;
        }
        $this->ledger->post([
            'student_id' => $demand->student_id, 'year_id' => $demand->year_id, 'type' => 'fine', 'amount_paise' => $state['fine'],
            'demand_id' => (string) $demand->getKey(), 'head_id' => 'fine', 'reason' => 'Late fine',
        ], $by);
        $demand->fill(['fine_posted_paise' => $state['fine']])->save();
    }

    /**
     * What is still owed on an instalment, head by head (fine first), from
     * its ledger entries.
     *
     * @return array<string, array{0: string, 1: int}> head id => [name, paise]
     */
    public function remainingByHead(FeeDemand $demand): array
    {
        $sums = FeeLedgerEntry::query()->where('demand_id', (string) $demand->getKey())->get()->groupBy('head_id')->map(fn ($g) => (int) $g->sum('amount_paise'));
        $out = [];
        if (($sums['fine'] ?? 0) > 0) {
            $out['fine'] = ['Late fine', $sums['fine']];
        }
        foreach ($demand->lines as $line) {
            $out[$line['head_id']] = [$line['head'], (int) ($sums[$line['head_id']] ?? 0)];
        }

        return $out;
    }

    /**
     * The student's raised instalments this year, oldest first (only the
     * chosen ones, when given; any not theirs is refused).
     *
     * @param  list<string>|null  $ids
     * @return Collection<int, FeeDemand>
     */
    public function openDemands(string $studentId, string $yearId, ?array $ids): Collection
    {
        $query = FeeDemand::query()->where('student_id', $studentId)->where('year_id', $yearId);
        if ($ids !== null && $ids !== []) {
            $query->whereIn('_id', $ids);
        }
        $demands = $query->orderBy('due_on')->orderBy('instalment_key')->get()->values();
        if ($ids !== null && $ids !== [] && $demands->count() !== count(array_unique($ids))) {
            throw new ApiException('demand_unknown', 'One of the chosen instalments is not this student\'s.', 422);
        }

        return $demands;
    }

    /**
     * Generic text only (owner's rule 7): no amount, no name.
     *
     * @param  list<string>  $studentIds
     */
    public function tellFamily(array $studentIds): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $this->notifier->notify($tenant, $this->families->users($studentIds), 'fees.payment_received', 'A payment was received', null, '/fees/my', []);
        }
    }
}
