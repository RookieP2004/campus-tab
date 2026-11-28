<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Rebates, waivers (fee maaf), fine waivers, refunds, scholarship credits
 * and carrying unpaid dues into the next year.
 *
 *  - The Clerk proposes (fees.adjust_propose); the Chief may too (fees.adjust).
 *  - The Principal approves (fees.adjust_approve, step-up), or only the
 *    Chief when the school set policies.fees.waiver_by = chief. The Chief
 *    (fees.adjust) may always approve.
 *  - Nobody approves an adjustment they proposed (owner's rule 5).
 *  - Nothing reaches the ledger until it is approved; the amount is checked
 *    again then against what is owed at that moment.
 *  - A waiver, rebate, fine waiver or scholarship above
 *    policies.fees.chief_alert_paise alerts the Chief (no amount, no name).
 */
class Adjustments
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly Ledger $ledger,
        private readonly Payments $payments,
        private readonly SchoolConfigService $configs,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenant,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{kind: string, demand_id?: string|null, amount_paise?: int|null, percent_bp?: int|null, reason: string}  $input
     */
    public function propose(User $user, Student $student, array $input): FeeAdjustment
    {
        if (! $this->mayPropose($user)) {
            throw new ApiException('permission_denied', 'Only the Clerk (or the Chief) proposes a fee adjustment.', 403);
        }
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $reason = trim($input['reason']);
        if (mb_strlen($reason) < (int) config('fees.reason_min', 10)) {
            throw new ApiException('reason_required', 'Write the reason (at least '.config('fees.reason_min', 10).' characters).', 422);
        }
        $kind = $input['kind'];
        $demand = $this->demand($student, (string) $year->getKey(), $input['demand_id'] ?? null);
        if ($kind === 'fine_waiver' && $demand === null) {
            throw new ApiException('demand_required', 'Choose the instalment whose fine is waived.', 422);
        }
        $percent = $kind === 'rebate' && isset($input['percent_bp']) ? (int) $input['percent_bp'] : null;
        if ($percent !== null && $demand === null) {
            throw new ApiException('demand_required', 'A percentage rebate is on one instalment: choose it.', 422);
        }
        $amount = $percent !== null && $demand !== null ? Money::percentOf($demand->total_paise, $percent) : (int) ($input['amount_paise'] ?? 0);
        if ($amount <= 0) {
            throw new ApiException('amount_invalid', 'The amount must be more than zero.', 422);
        }
        $this->assertWithin($kind, $student, (string) $year->getKey(), $demand, $amount);

        return FeeAdjustment::create([
            'student_id' => (string) $student->getKey(), 'student_name' => $student->name, 'year_id' => (string) $year->getKey(),
            'kind' => $kind, 'demand_id' => $demand ? (string) $demand->getKey() : null, 'amount_paise' => $amount, 'percent_bp' => $percent,
            'reason' => $reason, 'proposed_by' => Cheques::who($user),
        ]);
    }

    public function approve(User $user, FeeAdjustment $adj, string $reason): FeeAdjustment
    {
        $this->assertDecider($user, $adj);
        $student = Student::query()->findOrFail($adj->student_id);
        $demand = $adj->demand_id ? FeeDemand::query()->findOrFail($adj->demand_id) : null;

        DB::connection('mongodb')->transaction(function () use ($user, $adj, $reason, $student, $demand): void {
            $this->assertWithin($adj->kind, $student, $adj->year_id, $demand, $adj->amount_paise);
            $this->apply($user, $adj, $demand);
            $adj->fill(['status' => 'approved', 'decided_by' => Cheques::who($user), 'decision_reason' => trim($reason), 'decided_at' => now()->toIso8601String()])->save();
        }, 5);

        $threshold = (int) ($this->configs->current()->body['policies']['fees']['chief_alert_paise'] ?? 500000);
        if ($threshold > 0 && $adj->amount_paise > $threshold && in_array($adj->kind, ['rebate', 'waiver', 'fine_waiver', 'scholarship'], true)) {
            $tenant = Tenant::query()->find($this->tenant->require());
            if ($tenant instanceof Tenant) {
                $chiefs = User::query()->where('roles', Role::Chief->value)->where('status', User::STATUS_ACTIVE)->get();
                $this->notifier->notify($tenant, $chiefs, 'fees.waiver_alert', 'A large fee waiver was approved', null, '/fees/adjustments', []);
            }
            $this->audit->record('fees.waiver_alert', 'fees', 'fee_adjustment', (string) $adj->getKey(), null, ['amount_paise' => $adj->amount_paise, 'threshold_paise' => $threshold]);
        }

        return FeeAdjustment::query()->findOrFail($adj->getKey());
    }

    public function reject(User $user, FeeAdjustment $adj, string $reason): FeeAdjustment
    {
        $this->assertDecider($user, $adj);
        $adj->fill(['status' => 'rejected', 'decided_by' => Cheques::who($user), 'decision_reason' => trim($reason), 'decided_at' => now()->toIso8601String()])->save();

        return $adj;
    }

    public function mayPropose(User $user): bool
    {
        return $this->access->allows($user, 'fees.adjust_propose') || $this->access->allows($user, 'fees.adjust');
    }

    public function mayApprove(User $user): bool
    {
        if ($this->access->allows($user, 'fees.adjust')) {
            return true;
        }

        return ($this->configs->current()->body['policies']['fees']['waiver_by'] ?? 'principal') !== 'chief'
            && $this->access->allows($user, 'fees.adjust_approve');
    }

    private function assertDecider(User $user, FeeAdjustment $adj): void
    {
        if ($adj->status !== 'proposed') {
            throw new ApiException('adjustment_decided', 'This adjustment was already decided.', 409);
        }
        if (! $this->mayApprove($user)) {
            throw new ApiException('permission_denied', 'Only the Principal (or the Chief, if the school says so) approves fee adjustments.', 403);
        }
        if (($adj->proposed_by['id'] ?? null) === (string) $user->getKey()) {
            throw new ApiException('own_proposal', 'Someone else approves an adjustment you proposed.', 403);
        }
    }

    private function demand(Student $student, string $yearId, ?string $id): ?FeeDemand
    {
        if ($id === null || $id === '') {
            return null;
        }
        $demand = FeeDemand::query()->find($id);
        if (! $demand instanceof FeeDemand || $demand->student_id !== (string) $student->getKey() || $demand->year_id !== $yearId) {
            throw new ApiException('demand_unknown', 'That instalment is not this student\'s.', 422);
        }

        return $demand;
    }

    private function assertWithin(string $kind, Student $student, string $yearId, ?FeeDemand $demand, int $amount): void
    {
        $balance = $this->ledger->balance((string) $student->getKey(), $yearId);
        $state = $demand ? $this->ledger->demandStates([$demand])[(string) $demand->getKey()] : null;
        $limit = match ($kind) {
            'refund' => max(0, -$balance),
            // The fine still owed: once posted, what is left of it; before that, all of it.
            'fine_waiver' => $state === null || $demand === null ? 0 : ($state['fine_posted'] ? max(0, $this->payments->remainingByHead($demand)['fine'][1] ?? 0) : $state['fine']),
            'carry_forward' => max(0, $balance),
            default => $state ? max(0, $state['balance'] - $state['fine']) : max(0, $balance),
        };
        if ($amount > $limit) {
            $message = match ($kind) {
                'refund' => 'A refund can be at most the credit on the account ('.Money::format($limit).').',
                'fine_waiver' => 'A fine waiver can be at most the fine on that instalment ('.Money::format($limit).').',
                default => 'The amount is more than what is owed ('.Money::format($limit).').',
            };
            throw new ApiException('amount_above_limit', $message, 422, ['limit_paise' => $limit]);
        }
    }

    private function apply(User $user, FeeAdjustment $adj, ?FeeDemand $demand): void
    {
        $base = ['student_id' => $adj->student_id, 'year_id' => $adj->year_id, 'type' => 'adjustment', 'adjustment_id' => (string) $adj->getKey(), 'adjustment_kind' => $adj->kind, 'reason' => $adj->reason];

        if ($adj->kind === 'refund') {
            $this->ledger->post($base + ['amount_paise' => $adj->amount_paise], $user);

            return;
        }
        if ($adj->kind === 'fine_waiver' && $demand !== null) {
            $this->payments->postFine($demand, $this->ledger->demandStates([$demand])[(string) $demand->getKey()], $user);
            $this->ledger->post($base + ['amount_paise' => -$adj->amount_paise, 'demand_id' => (string) $demand->getKey(), 'head_id' => 'fine'], $user);

            return;
        }
        if ($adj->kind === 'carry_forward') {
            $this->carryInto($user, $adj, $this->nextYear($adj));

            return;
        }

        // Rebate, waiver, scholarship: spread over the chosen instalment's heads
        // (or the oldest dues first), never over the fine.
        $left = $adj->amount_paise;
        $demands = $demand ? collect([$demand]) : $this->payments->openDemands($adj->student_id, $adj->year_id, null);
        foreach ($demands as $d) {
            foreach ($this->payments->remainingByHead($d) as $headId => [, $remaining]) {
                if ($headId === 'fine' || $remaining <= 0 || $left === 0) {
                    continue;
                }
                $take = min($left, $remaining);
                $this->ledger->post($base + ['amount_paise' => -$take, 'demand_id' => (string) $d->getKey(), 'head_id' => $headId], $user);
                $left -= $take;
            }
        }
        if ($left > 0) {
            // Owed without an instalment to put it on (e.g. a bounced-cheque fine).
            $this->ledger->post($base + ['amount_paise' => -$left], $user);
        }
    }

    private function nextYear(FeeAdjustment $adj): AcademicYear
    {
        $from = AcademicYear::query()->findOrFail($adj->year_id);
        $next = AcademicYear::query()->where('starts_on', '>', $from->starts_on)->where('status', '!=', AcademicYear::STATUS_CLOSED)->orderBy('starts_on')->first();

        return $next instanceof AcademicYear ? $next : throw new ApiException('no_next_year', 'Make the next academic year first (School setup), then carry the dues into it.', 422);
    }

    /**
     * Unpaid dues leave this year's ledger and are raised in the next year as
     * one "carried forward" instalment. Also used by the year-end wizard
     * (YearEndDues), which names the new year itself.
     */
    public function carryInto(User $user, FeeAdjustment $adj, AcademicYear $next): void
    {
        $from = AcademicYear::query()->findOrFail($adj->year_id);
        $base = ['student_id' => $adj->student_id, 'year_id' => $adj->year_id, 'type' => 'adjustment', 'adjustment_id' => (string) $adj->getKey(), 'adjustment_kind' => $adj->kind, 'reason' => $adj->reason];
        $this->ledger->post($base + ['amount_paise' => -$adj->amount_paise], $user);
        $demand = FeeDemand::create([
            'student_id' => $adj->student_id, 'student_name' => $adj->student_name, 'year_id' => (string) $next->getKey(), 'section_id' => null, 'section_label' => null,
            'plan_id' => 'carry_forward', 'instalment_key' => 'carry:'.$adj->year_id.':'.$adj->getKey(), 'label' => 'Carried forward from '.$from->name, 'due_on' => $next->starts_on,
            'lines' => [['head_id' => 'carry_forward', 'head' => 'Carried forward', 'amount_paise' => $adj->amount_paise]], 'total_paise' => $adj->amount_paise,
            'fine_rule' => ['mode' => 'none', 'amount_paise' => 0, 'grace_days' => 0, 'cap_paise' => null],
        ]);
        $this->ledger->post(['student_id' => $adj->student_id, 'year_id' => (string) $next->getKey(), 'type' => 'demand', 'amount_paise' => $adj->amount_paise,
            'demand_id' => (string) $demand->getKey(), 'head_id' => 'carry_forward', 'adjustment_id' => (string) $adj->getKey(), 'reason' => 'Carried forward from '.$from->name], $user);
    }
}
