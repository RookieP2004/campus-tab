<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\FeeYearEndDecision;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;

/**
 * Unpaid dues at year end (the promotion wizard's dues step).
 *
 *  - unpaid(): every student whose balance for the year is above zero, with
 *    whether the Principal already confirmed leaving it in that year.
 *  - settle(): ONE approval by the Principal (step-up fees_carry_forward)
 *    for the whole list. Each balance is either carried forward (an
 *    approved carry_forward adjustment: the amount leaves this year's
 *    ledger and opens as a "Carried forward" instalment in the new year) or
 *    left in the closed year (a FeeYearEndDecision holding the amount).
 *    Every student gets their own audit entry. The caller runs it inside
 *    the promotion's transaction.
 *  - unresolved(): balances above zero with neither, which keep the year
 *    from closing.
 *
 * Carrying a balance forward moves it and forgives nothing, so the
 * Principal decides alone here (the owner's rule for this step); a
 * single-student carry-forward still goes Clerk proposes, Principal approves.
 */
class YearEndDues
{
    public const CARRY = 'carry';

    public const LEAVE = 'leave';

    public function __construct(
        private readonly Adjustments $adjustments,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{students: list<array{id: string, name: string, balance_paise: int, status: string}>, total_paise: int, unresolved: int, digest: string}
     */
    public function unpaid(string $yearId): array
    {
        $balances = [];
        foreach (FeeLedgerEntry::query()->where('year_id', $yearId)->get(['student_id', 'amount_paise']) as $entry) {
            $balances[$entry->student_id] = ($balances[$entry->student_id] ?? 0) + (int) $entry->amount_paise;
        }
        $balances = array_filter($balances, fn (int $b) => $b > 0);
        ksort($balances);

        $left = $this->leftDecisions($yearId);
        $names = $balances === [] ? collect() : Student::withTrashed()->whereIn('_id', array_keys($balances))->get()->mapWithKeys(fn (Student $s) => [(string) $s->getKey() => $s->name]);

        $students = [];
        foreach ($balances as $id => $balance) {
            $students[] = [
                'id' => (string) $id,
                'name' => (string) ($names[$id] ?? '—'),
                'balance_paise' => $balance,
                'status' => ($left[$id] ?? null) === $balance ? 'left' : 'open',
            ];
        }
        usort($students, fn ($a, $b) => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return [
            'students' => $students,
            'total_paise' => array_sum($balances),
            'unresolved' => count(array_filter($students, fn ($s) => $s['status'] === 'open')),
            'digest' => hash('sha256', (string) json_encode(array_map(fn ($s) => [$s['id'], $s['balance_paise']], $students))),
        ];
    }

    public function unresolved(string $yearId): int
    {
        return $this->unpaid($yearId)['unresolved'];
    }

    /**
     * @param  array<string, string>  $decisions  student id => carry | leave (a student not named is carried forward)
     * @return array{carried: int, left: int, carried_paise: int, left_paise: int}
     */
    public function settle(User $user, AcademicYear $from, AcademicYear $to, array $decisions, string $digest): array
    {
        $dues = $this->unpaid((string) $from->getKey());
        if (! hash_equals($dues['digest'], $digest)) {
            throw new ApiException('dues_changed', 'A balance changed since the list was shown (a payment or a reversal). Look at the unpaid dues again, then approve.', 409);
        }
        if ($dues['students'] === []) {
            throw new ApiException('no_unpaid_dues', 'Nobody owes anything for this year.', 422);
        }
        foreach ($decisions as $id => $choice) {
            if (! in_array($choice, [self::CARRY, self::LEAVE], true)) {
                throw new ApiException('dues_decision_invalid', 'Each balance is carried forward or left in the closed year.', 422, ['student_id' => $id]);
            }
        }

        $who = Cheques::who($user);
        $now = now()->toIso8601String();
        $out = ['carried' => 0, 'left' => 0, 'carried_paise' => 0, 'left_paise' => 0];

        foreach ($dues['students'] as $s) {
            $choice = $decisions[$s['id']] ?? self::CARRY;
            $amount = $s['balance_paise'];

            if ($choice === self::LEAVE) {
                if ($s['status'] !== 'left') {
                    $this->supersede((string) $from->getKey(), $s['id'], $now);
                    FeeYearEndDecision::create([
                        'year_id' => (string) $from->getKey(), 'student_id' => $s['id'], 'student_name' => $s['name'],
                        'amount_paise' => $amount, 'decided_by' => $who, 'decided_at' => $now,
                    ]);
                    $this->audit->record('fees.dues_left_in_closed_year', 'fees', 'student', $s['id'], null, [
                        'year' => $from->name, 'amount_paise' => $amount,
                    ]);
                }
                $out['left']++;
                $out['left_paise'] += $amount;

                continue;
            }

            $this->supersede((string) $from->getKey(), $s['id'], $now);
            $reason = 'Year-end carry-forward from '.$from->name.' to '.$to->name;
            $adj = FeeAdjustment::create([
                'student_id' => $s['id'], 'student_name' => $s['name'], 'year_id' => (string) $from->getKey(),
                'kind' => 'carry_forward', 'demand_id' => null, 'amount_paise' => $amount, 'percent_bp' => null,
                'reason' => $reason, 'status' => 'approved', 'proposed_by' => $who, 'decided_by' => $who,
                'decision_reason' => 'Approved in the year-end wizard', 'decided_at' => $now, 'source' => 'year_end',
            ]);
            $this->adjustments->carryInto($user, $adj, $to);
            $this->audit->record('fees.carried_forward', 'fees', 'student', $s['id'], null, [
                'from_year' => $from->name, 'to_year' => $to->name, 'amount_paise' => $amount, 'adjustment_id' => (string) $adj->getKey(),
            ]);
            $out['carried']++;
            $out['carried_paise'] += $amount;
        }

        $this->audit->record('fees.year_end_dues_settled', 'fees', 'academic_year', (string) $from->getKey(), null, $out + ['to_year' => $to->name], ['digest' => $digest]);

        return $out;
    }

    /** @return array<string, int> student id => the amount confirmed to stay */
    private function leftDecisions(string $yearId): array
    {
        return FeeYearEndDecision::query()->where('year_id', $yearId)->whereNull('superseded_at')->get()
            ->mapWithKeys(fn (FeeYearEndDecision $d) => [$d->student_id => (int) $d->amount_paise])->all();
    }

    private function supersede(string $yearId, string $studentId, string $now): void
    {
        foreach (FeeYearEndDecision::query()->where('year_id', $yearId)->where('student_id', $studentId)->whereNull('superseded_at')->get() as $d) {
            $d->fill(['superseded_at' => $now])->save();
        }
    }
}
