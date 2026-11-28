<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Fees\Models\FeeDemand;
use App\Modules\Fees\Models\FeeHead;
use App\Modules\Fees\Models\FeePlan;
use App\Modules\Fees\Models\StudentFee;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raises dues (fee_demands plus their ledger entries) from each student's
 * plan. It runs at the start of the year and every night after, raising
 * each instalment once its due date is within fees.raise_days_before:
 *
 *  - The plan is the student's own (StudentFee.plan_id), else the plan for
 *    their class and category (a plan with no categories fits everyone).
 *  - Mid-year joiners are charged from their joining month: an instalment
 *    due before the first day of that month is not raised for them.
 *  - Overrides change a head's amount for instalments due in their range
 *    (0 = not charged), e.g. transport from August.
 *  - Discounts: "always", or "sibling" (every child after the first, by
 *    admission number, who shares a guardian with another enrolled child).
 *  - Raised once: an instalment already raised for a student is skipped
 *    (unique index tenant_id + student_id + year_id + plan_id + instalment_key),
 *    so changing a plan changes only instalments not raised yet.
 */
class DemandRaiser
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return array{raised: int, students: int, skipped_no_plan: int}
     */
    public function raise(AcademicYear $year, ?CarbonImmutable $today = null, ?string $studentId = null): array
    {
        $today ??= CarbonImmutable::now('Asia/Kolkata')->startOfDay();
        $horizon = $today->addDays((int) config('fees.raise_days_before', 30))->toDateString();
        $yearId = (string) $year->getKey();

        $enrolments = Enrolment::query()->where('year_id', $yearId)->when($studentId !== null, fn ($q) => $q->where('student_id', $studentId))->get();
        $students = Student::query()->whereIn('_id', $enrolments->pluck('student_id')->all())->where('status', 'active')->get()->keyBy(fn ($s) => (string) $s->getKey());
        $sections = Section::query()->where('year_id', $yearId)->get()->keyBy(fn ($s) => (string) $s->getKey());
        $grades = SchoolClass::query()->where('year_id', $yearId)->get()->mapWithKeys(fn ($c) => [(string) $c->getKey() => (string) $c->grade]);
        $plans = FeePlan::query()->where('year_id', $yearId)->where('status', 'active')->get();
        $profiles = StudentFee::query()->where('year_id', $yearId)->get()->keyBy('student_id');
        $heads = FeeHead::query()->get()->mapWithKeys(fn ($h) => [(string) $h->getKey() => $h->name]);
        $siblings = $this->laterSiblings($students);
        $raised = 0;
        $skipped = 0;

        foreach ($enrolments as $enrolment) {
            $student = $students[$enrolment->student_id] ?? null;
            if (! $student instanceof Student) {
                continue;
            }
            $section = $sections[$enrolment->section_id] ?? null;
            $profile = $profiles[$enrolment->student_id] ?? null;
            $plan = $this->planFor($plans, $profile, $section instanceof Section ? ($grades[$section->class_id] ?? null) : null);
            if (! $plan instanceof FeePlan) {
                $skipped++;

                continue;
            }
            $joined = $profile->joined_on ?? $enrolment->created_at?->timezone('Asia/Kolkata')->toDateString() ?? $year->starts_on;
            $joinedMonth = CarbonImmutable::parse($joined, 'Asia/Kolkata')->startOfMonth()->toDateString();
            $existing = FeeDemand::query()->where('student_id', $enrolment->student_id)->where('year_id', $yearId)->where('plan_id', (string) $plan->getKey())
                ->pluck('instalment_key')->all();

            foreach ($plan->instalments as $instalment) {
                if (in_array($instalment['key'], $existing, true) || $instalment['due_on'] > $horizon || $instalment['due_on'] < $joinedMonth) {
                    continue;
                }
                $lines = $this->lines($instalment, $profile, $heads);
                $discounts = $this->discounts($plan, $lines, isset($siblings[$enrolment->student_id]));
                $total = array_sum(array_column($lines, 'amount_paise')) - array_sum(array_column($discounts, 'amount_paise'));
                if ($total <= 0 && $lines === []) {
                    continue;
                }

                DB::connection('mongodb')->transaction(function () use ($enrolment, $student, $section, $plan, $instalment, $lines, $discounts, $total, $yearId): void {
                    $demand = FeeDemand::create([
                        'student_id' => $enrolment->student_id, 'student_name' => $student->name, 'year_id' => $yearId,
                        'section_id' => $enrolment->section_id, 'section_label' => $section?->label,
                        'plan_id' => (string) $plan->getKey(), 'instalment_key' => $instalment['key'], 'label' => $instalment['label'], 'due_on' => $instalment['due_on'],
                        'lines' => $lines, 'discounts' => $discounts, 'total_paise' => $total, 'fine_rule' => $plan->fine,
                    ]);
                    foreach ($lines as $line) {
                        if ($line['amount_paise'] === 0) {
                            continue;
                        }
                        $this->ledger->post(['student_id' => $enrolment->student_id, 'year_id' => $yearId, 'type' => 'demand', 'amount_paise' => $line['amount_paise'],
                            'demand_id' => (string) $demand->getKey(), 'head_id' => $line['head_id'], 'reason' => $instalment['label']], null);
                    }
                    foreach ($discounts as $discount) {
                        $this->ledger->post(['student_id' => $enrolment->student_id, 'year_id' => $yearId, 'type' => 'demand', 'amount_paise' => -$discount['amount_paise'],
                            'demand_id' => (string) $demand->getKey(), 'head_id' => $discount['head_id'], 'reason' => $discount['label']], null);
                    }
                }, 3);
                $raised++;
            }
        }

        return ['raised' => $raised, 'students' => $students->count(), 'skipped_no_plan' => $skipped];
    }

    /**
     * @param  Collection<int, FeePlan>  $plans
     */
    public function planFor(Collection $plans, ?StudentFee $profile, ?string $grade): ?FeePlan
    {
        if ($profile?->plan_id !== null) {
            return $plans->first(fn (FeePlan $p) => (string) $p->getKey() === $profile->plan_id);
        }
        $fits = $plans->filter(fn (FeePlan $p) => $grade !== null && in_array($grade, $p->grades, true));
        $category = $profile?->category_id;

        return ($category !== null ? $fits->first(fn (FeePlan $p) => in_array($category, $p->category_ids, true)) : null)
            ?? $fits->first(fn (FeePlan $p) => $p->category_ids === []);
    }

    /**
     * @param  array{key: string, label: string, due_on: string, lines: list<array{head_id: string, amount_paise: int}>}  $instalment
     * @param  Collection<string, string>  $heads
     * @return list<array{head_id: string, head: string, amount_paise: int}>
     */
    private function lines(array $instalment, ?StudentFee $profile, Collection $heads): array
    {
        $out = [];
        foreach ($instalment['lines'] as $line) {
            $amount = (int) $line['amount_paise'];
            foreach ($profile->overrides ?? [] as $o) {
                if ($o['head_id'] === $line['head_id'] && ($o['from'] === null || $instalment['due_on'] >= $o['from']) && ($o['to'] === null || $instalment['due_on'] <= $o['to'])) {
                    $amount = (int) $o['amount_paise'];
                }
            }
            $out[] = ['head_id' => $line['head_id'], 'head' => (string) ($heads[$line['head_id']] ?? ''), 'amount_paise' => $amount];
        }

        return $out;
    }

    /**
     * @param  list<array{head_id: string, head: string, amount_paise: int}>  $lines
     * @return list<array{label: string, head_id: string, amount_paise: int}>
     */
    private function discounts(FeePlan $plan, array $lines, bool $laterSibling): array
    {
        $out = [];
        foreach ($plan->discounts as $rule) {
            if ($rule['condition'] === 'sibling' && ! $laterSibling) {
                continue;
            }
            foreach ($lines as $line) {
                if (($rule['head_ids'] ?? []) !== [] && ! in_array($line['head_id'], $rule['head_ids'], true)) {
                    continue;
                }
                $amount = $rule['kind'] === 'percent' ? Money::percentOf($line['amount_paise'], (int) $rule['value']) : min((int) $rule['value'], $line['amount_paise']);
                if ($amount > 0) {
                    $out[] = ['label' => $rule['label'], 'head_id' => $line['head_id'], 'amount_paise' => $amount];
                }
            }
        }

        return $out;
    }

    /**
     * Students who are not the first child of their family: sharing a
     * guardian with an enrolled child with a smaller admission number.
     *
     * @param  Collection<string, Student>  $students
     * @return array<string, true>
     */
    private function laterSiblings(Collection $students): array
    {
        $byGuardian = [];
        foreach ($students as $id => $s) {
            foreach ($s->guardian_ids ?? [] as $g) {
                $byGuardian[$g][] = $s;
            }
        }
        $later = [];
        foreach ($byGuardian as $children) {
            if (count($children) < 2) {
                continue;
            }
            usort($children, fn (Student $a, Student $b) => strnatcmp((string) $a->admission_no, (string) $b->admission_no));
            foreach (array_slice($children, 1) as $child) {
                $later[(string) $child->getKey()] = true;
            }
        }

        return $later;
    }
}
