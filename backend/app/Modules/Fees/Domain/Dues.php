<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Fees\Models\FeePlan;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Fees\Models\StudentFee;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;

/**
 * What a student's fee page shows: this year's raised instalments with
 * what is due, paid and fined; the instalments still to come from their
 * plan; the ledger; receipts. The office also gets adjustments and who
 * did what. Families see the same history without staff names.
 */
class Dues
{
    public function __construct(private readonly Ledger $ledger, private readonly DemandRaiser $raiser) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(Student $student, bool $office): array
    {
        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear) {
            return ['student' => ['id' => (string) $student->getKey(), 'name' => $student->name], 'year' => null, 'balance_paise' => 0, 'demands' => [], 'upcoming' => [], 'ledger' => [], 'receipts' => [], 'adjustments' => []];
        }
        $yearId = (string) $year->getKey();
        $sid = (string) $student->getKey();
        $demands = FeeDemand::query()->where('student_id', $sid)->where('year_id', $yearId)->orderBy('due_on')->get();
        $states = $this->ledger->demandStates($demands);
        $entries = FeeLedgerEntry::query()->where('student_id', $sid)->where('year_id', $yearId)->orderBy('created_at')->get();
        $finesUnposted = array_sum(array_map(fn ($s) => $s['fine_posted'] ? 0 : $s['fine'], $states));

        return [
            'student' => ['id' => $sid, 'name' => $student->name, 'admission_no' => $student->admission_no],
            'year' => ['id' => $yearId, 'name' => $year->name],
            'balance_paise' => (int) $entries->sum('amount_paise') + $finesUnposted,
            'demands' => $demands->map(fn (FeeDemand $d) => [
                'id' => (string) $d->getKey(), 'label' => $d->label, 'due_on' => $d->due_on, 'lines' => $d->lines, 'discounts' => $d->discounts,
                'total_paise' => $d->total_paise,
            ] + self::paise($states[(string) $d->getKey()]))->values()->all(),
            'upcoming' => $this->upcoming($student, $year, $demands->pluck('instalment_key')->all()),
            'ledger' => $entries->map(fn (FeeLedgerEntry $e) => [
                'id' => (string) $e->getKey(), 'type' => $e->type, 'amount_paise' => $e->amount_paise, 'demand_id' => $e->demand_id, 'head_id' => $e->head_id,
                'receipt_no' => $e->receipt_no, 'adjustment_kind' => $e->adjustment_kind, 'reverses_id' => $e->reverses_id, 'reason' => $e->reason,
                'by' => $office ? $e->by : null, 'at' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
            'receipts' => Receipt::query()->where('student_id', $sid)->where('year_id', $yearId)->orderBy('seq', 'desc')->get()
                ->map(fn (Receipt $r) => self::receipt($r, $office))->values()->all(),
            'payments_pending' => Payment::query()->where('student_id', $sid)->where('status', Payment::PENDING)->count(),
            'adjustments' => $office ? FeeAdjustment::query()->where('student_id', $sid)->where('year_id', $yearId)->orderBy('created_at', 'desc')->get()
                ->map(fn (FeeAdjustment $a) => self::adjustment($a))->values()->all() : [],
        ];
    }

    /**
     * Paid or pending for each student of a section: no amounts.
     *
     * @return list<array{student_id: string, name: string, status: string}>
     */
    public function sectionStatus(Section $section): array
    {
        $enrolments = Enrolment::query()->where('section_id', (string) $section->getKey())->where('year_id', $section->year_id)->get();
        $students = Student::query()->whereIn('_id', $enrolments->pluck('student_id')->all())->where('status', 'active')->get();
        $demands = FeeDemand::query()->where('year_id', $section->year_id)->whereIn('student_id', $students->map(fn ($s) => (string) $s->getKey())->all())->get();
        $states = $this->ledger->demandStates($demands);
        $pending = [];
        foreach ($demands as $d) {
            if ($states[(string) $d->getKey()]['balance'] > 0) {
                $pending[$d->student_id] = true;
            }
        }

        return $students->sortBy('name', SORT_NATURAL)->map(fn (Student $s) => [
            'student_id' => (string) $s->getKey(), 'name' => $s->name, 'status' => isset($pending[(string) $s->getKey()]) ? 'pending' : 'paid',
        ])->values()->all();
    }

    /**
     * Instalments of the student's plan not raised yet (shown as coming up).
     *
     * @param  list<string>  $raised
     * @return list<array{label: string, due_on: string, total_paise: int}>
     */
    private function upcoming(Student $student, AcademicYear $year, array $raised): array
    {
        $yearId = (string) $year->getKey();
        $enrolment = Enrolment::query()->where('student_id', (string) $student->getKey())->where('year_id', $yearId)->first();
        $section = $enrolment ? Section::query()->find($enrolment->section_id) : null;
        $grade = $section ? SchoolClass::query()->find($section->class_id)?->grade : null;
        $plan = $this->raiser->planFor(FeePlan::query()->where('year_id', $yearId)->where('status', 'active')->get(), StudentFee::query()->where('student_id', (string) $student->getKey())->where('year_id', $yearId)->first(), $grade !== null ? (string) $grade : null);
        if (! $plan instanceof FeePlan) {
            return [];
        }

        return collect($plan->instalments)->reject(fn ($i) => in_array($i['key'], $raised, true))
            ->map(fn ($i) => ['label' => $i['label'], 'due_on' => $i['due_on'], 'total_paise' => array_sum(array_map(fn ($l) => (int) $l['amount_paise'], $i['lines']))])
            ->sortBy('due_on')->values()->all();
    }

    /**
     * @param  array{due: int, fine: int, fine_posted: bool, paid: int, adjusted: int, balance: int, status: string}  $s
     * @return array<string, mixed>
     */
    private static function paise(array $s): array
    {
        return ['due_paise' => $s['due'], 'fine_paise' => $s['fine'], 'fine_posted' => $s['fine_posted'], 'paid_paise' => $s['paid'], 'adjusted_paise' => $s['adjusted'], 'balance_paise' => $s['balance'], 'status' => $s['status']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function receipt(Receipt $r, bool $office): array
    {
        return [
            'id' => (string) $r->getKey(), 'number' => $r->number, 'student' => ['id' => $r->student_id, 'name' => $r->student_name], 'section' => $r->section_label,
            'amount_paise' => $r->amount_paise, 'mode' => $r->mode, 'lines' => $r->lines, 'status' => $r->status, 'issued_on' => $r->issued_on,
            'issued_by' => $office ? $r->issued_by : null, 'payment_id' => $r->payment_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adjustment(FeeAdjustment $a): array
    {
        return [
            'id' => (string) $a->getKey(), 'student' => ['id' => $a->student_id, 'name' => $a->student_name], 'kind' => $a->kind, 'demand_id' => $a->demand_id,
            'amount_paise' => $a->amount_paise, 'percent_bp' => $a->percent_bp, 'reason' => $a->reason, 'status' => $a->status,
            'proposed_by' => $a->proposed_by, 'decided_by' => $a->decided_by, 'decision_reason' => $a->decision_reason, 'decided_at' => $a->decided_at,
            'at' => $a->created_at?->toIso8601String(),
        ];
    }
}
