<?php

namespace App\Modules\Reports\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Fees\Domain\YearEndDues;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use App\Modules\Reports\Models\ReportCard;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * The year-end promotion wizard (Phase 7 item 5, owner's rule 6).
 *
 *  1. Preview: every student of the current year with what will happen to
 *     them: promote (to the same section name in the next class), detain
 *     (same class), leave, or graduate (the last class). The default comes
 *     from the student's published report card (a "needs improvement"
 *     result proposes detain); the Principal changes any of them. The
 *     preview also counts the classes, sections and teaching assignments
 *     that roll into the new year, and ends with a digest of the whole plan.
 *  2. Apply: the same plan (the digest must match, so nothing changed since
 *     the preview) runs in ONE MongoDB transaction: the new year's classes,
 *     sections and assignments are created as drafts, every student gets a
 *     new enrolment or is marked left, the old enrolments record the
 *     outcome, the new year becomes current and the old year is closed.
 *     After that the old year is read-only (ClosedYears).
 *  2a. Unpaid dues: the preview lists every student whose fee balance for
 *     the year is above zero. Before the year can close, the Principal
 *     approves the list once (step-up fees_carry_forward): each balance is
 *     carried forward into the new year or left in the closed year
 *     (YearEndDues). Apply refuses while any balance is undecided.
 *  3. The whole run is audited (year.promoted, with the counts), on top of
 *     the audit entry of every record it writes.
 */
class Promotion
{
    public const ACTIONS = ['promote', 'detain', 'leave', 'graduate'];

    public function __construct(private readonly AuditRecorder $audit, private readonly YearEndDues $dues) {}

    /**
     * @param  array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}  $target
     * @param  array<string, string>  $exceptions  student id => action
     * @return array<string, mixed>
     */
    public function preview(array $target, array $exceptions = []): array
    {
        $from = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year to close.', 422);
        $to = $this->target($from, $target);
        $classes = SchoolClass::query()->where('year_id', (string) $from->getKey())->orderBy('order')->orderBy('grade')->get()->values();
        $sections = Section::query()->where('year_id', (string) $from->getKey())->get();
        $enrolments = Enrolment::query()->where('year_id', (string) $from->getKey())->get();
        $students = Student::query()->whereIn('_id', $enrolments->pluck('student_id')->all())->where('status', 'active')->get()->keyBy(fn ($s) => (string) $s->getKey());
        $results = ReportCard::query()->where('year_id', (string) $from->getKey())->where('status', ReportCard::PUBLISHED)->get()
            ->mapWithKeys(fn (ReportCard $c) => [$c->student_id => $c->snapshot['overall']['result'] ?? null]);

        foreach ($exceptions as $id => $action) {
            if (! in_array($action, self::ACTIONS, true)) {
                throw new ApiException('action_invalid', 'Each student is promoted, detained, leaving or graduating.', 422, ['student_id' => $id]);
            }
        }

        // The class a section moves into: the next class in the school's
        // order, except that a numbered class moves to the next number. A
        // missing number (Class 3 when the school has no Class 4 yet) is
        // made as a draft class on apply (listed under new_classes), never
        // skipped over.
        $next = [];
        foreach ($classes as $i => $class) {
            $following = $classes[$i + 1] ?? null;
            $next[(string) $class->getKey()] = $following instanceof SchoolClass ? self::nextGrade($class->grade, $following->grade) : null;
        }

        $rows = [];
        $counts = ['promote' => 0, 'detain' => 0, 'leave' => 0, 'graduate' => 0];
        foreach ($sections->sortBy('label', SORT_NATURAL)->values() as $section) {
            $class = $classes->first(fn ($c) => (string) $c->getKey() === $section->class_id);
            $nextGrade = $class instanceof SchoolClass ? $next[(string) $class->getKey()] : null;
            $list = [];

            foreach ($enrolments->where('section_id', (string) $section->getKey()) as $enrolment) {
                $student = $students[$enrolment->student_id] ?? null;
                if (! $student instanceof Student) {
                    continue;
                }
                $id = (string) $student->getKey();
                $suggested = ($results[$id] ?? null) === 'fail' ? 'detain' : ($nextGrade === null ? 'graduate' : 'promote');
                $action = $exceptions[$id] ?? $suggested;
                if ($action === 'promote' && $nextGrade === null) {
                    $action = 'graduate';
                }
                if ($action === 'graduate' && $nextGrade !== null) {
                    throw new ApiException('graduate_last_class', "Only students of the last class graduate ({$student->name} is in {$section->label}).", 422, ['student_id' => $id]);
                }
                $toGrade = match ($action) {
                    'promote' => $nextGrade,
                    'detain' => $class?->grade,
                    default => null,
                };
                $counts[$action]++;
                $list[] = [
                    'id' => $id, 'name' => $student->name, 'roll_no' => $enrolment->roll_no,
                    'action' => $action, 'suggested' => $suggested, 'result' => $results[$id] ?? null,
                    'to_section' => $toGrade === null ? null : $toGrade.'-'.$section->name,
                ];
            }
            usort($list, fn ($a, $b) => [$a['roll_no'] ?? PHP_INT_MAX, $a['name']] <=> [$b['roll_no'] ?? PHP_INT_MAX, $b['name']]);
            $rows[] = ['section_id' => (string) $section->getKey(), 'section' => $section->label, 'next_class' => $nextGrade, 'students' => $list];
        }

        // Sections the plan moves students into that the next year will not
        // get from the roll-forward (created as drafts on apply).
        $existing = $sections->map(fn (Section $s) => $s->label)->all();
        $newSections = collect($rows)->flatMap(fn ($r) => array_column($r['students'], 'to_section'))->filter()->unique()
            ->reject(fn ($label) => in_array($label, $existing, true))->sort(SORT_NATURAL)->values()->all();

        $grades = $classes->pluck('grade')->map(fn ($g) => (string) $g)->all();
        $newClasses = collect($rows)->pluck('next_class')->filter()->unique()->reject(fn ($g) => in_array($g, $grades, true))->sort(SORT_NATURAL)->values()->all();

        $assignments = TeachingAssignment::query()->where('year_id', (string) $from->getKey())->count();
        $plan = [
            'from' => ['id' => (string) $from->getKey(), 'name' => $from->name],
            'to' => $to,
            'counts' => $counts,
            'roll_forward' => ['classes' => $classes->count(), 'sections' => $sections->count(), 'assignments' => $assignments, 'new_classes' => $newClasses, 'new_sections' => $newSections],
            'sections' => $rows,
        ];

        // Students with unpaid fees (shown with their section); not part of
        // the digest: apply checks them directly.
        $sectionOf = [];
        foreach ($rows as $r) {
            foreach ($r['students'] as $st) {
                $sectionOf[$st['id']] = $r['section'];
            }
        }
        $dues = $this->dues->unpaid((string) $from->getKey());
        $dues['students'] = array_map(fn ($d) => $d + ['section' => $sectionOf[$d['id']] ?? null], $dues['students']);

        return $plan + ['dues' => $dues, 'digest' => hash('sha256', (string) json_encode([$plan['to'], $counts, array_map(fn ($r) => [$r['section_id'], array_map(fn ($s) => [$s['id'], $s['action']], $r['students'])], $rows), $plan['roll_forward']]))];
    }

    /**
     * @param  array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}  $target
     * @param  array<string, string>  $exceptions
     * @return array<string, mixed>
     */
    public function apply(User $user, array $target, array $exceptions, string $digest): array
    {
        $plan = $this->preview($target, $exceptions);
        if (! hash_equals($plan['digest'], $digest)) {
            throw new ApiException('preview_stale', 'Something changed since the preview (a student, a section or a choice). Look at the preview again, then apply.', 409);
        }
        $open = $this->dues->unresolved($plan['from']['id']);
        if ($open > 0) {
            throw new ApiException('dues_unresolved', "{$open} unpaid fee balance(s) are not decided yet. Carry them forward or confirm leaving them in {$plan['from']['name']}, then apply.", 422, ['count' => $open]);
        }

        $result = DB::connection('mongodb')->transaction(function () use ($user, $plan): array {
            $from = AcademicYear::query()->findOrFail($plan['from']['id']);
            $to = $plan['to']['id'] !== null
                ? AcademicYear::query()->findOrFail($plan['to']['id'])
                : AcademicYear::create(['name' => $plan['to']['name'], 'starts_on' => $plan['to']['starts_on'], 'ends_on' => $plan['to']['ends_on'], 'status' => AcademicYear::STATUS_PLANNED]);
            $toId = (string) $to->getKey();

            // Classes, sections and teaching assignments roll forward as drafts.
            $classMap = [];
            foreach (SchoolClass::query()->where('year_id', (string) $from->getKey())->get() as $class) {
                $existing = SchoolClass::query()->where('year_id', $toId)->where('grade', $class->grade)->first();
                $classMap[$class->grade] = $existing ?? SchoolClass::create(['year_id' => $toId, 'grade' => $class->grade, 'name' => $class->name, 'order' => $class->order, 'wing' => $class->wing]);
            }
            $sectionMap = [];
            $oldSections = Section::query()->where('year_id', (string) $from->getKey())->get();
            $oldClasses = SchoolClass::query()->where('year_id', (string) $from->getKey())->get()->keyBy(fn ($c) => (string) $c->getKey());
            foreach ($oldSections as $section) {
                $grade = $oldClasses[$section->class_id]->grade ?? null;
                if ($grade === null) {
                    continue;
                }
                $sectionMap[$grade.'-'.$section->name] = $this->sectionIn($classMap[$grade], $section, $toId);
            }
            $byOldId = $oldSections->keyBy(fn ($s) => (string) $s->getKey());
            foreach (TeachingAssignment::query()->where('year_id', (string) $from->getKey())->get() as $assignment) {
                $old = $byOldId[$assignment->section_id] ?? null;
                $grade = $old instanceof Section ? ($oldClasses[$old->class_id]->grade ?? null) : null;
                $new = $grade === null ? null : ($sectionMap[$grade.'-'.$old->name] ?? null);
                if ($new instanceof Section && ! TeachingAssignment::query()->where('section_id', (string) $new->getKey())->where('subject_id', $assignment->subject_id)->exists()) {
                    TeachingAssignment::create(['year_id' => $toId, 'section_id' => (string) $new->getKey(), 'subject_id' => $assignment->subject_id, 'teacher_id' => $assignment->teacher_id, 'draft' => true]);
                }
            }

            // Numbered classes the school skipped (preview: new_classes) are made
            // as drafts next to the class the students come from.
            foreach ($plan['sections'] as $row) {
                $grade = $row['next_class'];
                $source = $byOldId[$row['section_id']] ?? null;
                $sourceClass = $source instanceof Section ? ($oldClasses[$source->class_id] ?? null) : null;
                if ($grade !== null && ! isset($classMap[$grade]) && $sourceClass instanceof SchoolClass) {
                    $classMap[$grade] = SchoolClass::query()->where('year_id', $toId)->where('grade', $grade)->first()
                        ?? SchoolClass::create(['year_id' => $toId, 'grade' => $grade, 'name' => 'Class '.$grade, 'order' => $sourceClass->order, 'wing' => $sourceClass->wing]);
                }
            }

            // Students.
            foreach ($plan['sections'] as $row) {
                foreach ($row['students'] as $s) {
                    $enrolment = Enrolment::query()->where('year_id', (string) $from->getKey())->where('student_id', $s['id'])->firstOrFail();
                    $enrolment->fill(['result' => match ($s['action']) {
                        'promote' => 'promoted', 'detain' => 'detained', 'leave' => 'left', default => 'graduated',
                    }])->save();

                    if ($s['to_section'] !== null) {
                        // A section the next class does not have yet (9-B moving up
                        // when Class 10 has only A) is created as a draft, as the
                        // preview lists under new_sections.
                        [$grade, $name] = explode('-', $s['to_section'], 2);
                        $target = $sectionMap[$s['to_section']] ??= isset($classMap[$grade])
                            ? $this->sectionIn($classMap[$grade], new Section(['name' => $name]), $toId)
                            : throw new ApiException('section_missing', "There is no Class {$grade} to move into.", 422);
                        Enrolment::create(['student_id' => $s['id'], 'year_id' => $toId, 'section_id' => (string) $target->getKey(), 'roll_no' => null, 'custom' => []]);
                    } else {
                        Student::query()->findOrFail($s['id'])->fill(['status' => 'left'])->save();
                    }
                }
            }

            // The new year becomes current; the old one closes and is read-only from now on.
            $to->forceFill(['status' => AcademicYear::STATUS_CURRENT])->save();
            $from->forceFill(['status' => AcademicYear::STATUS_CLOSED, 'closed_at' => now()->toIso8601String(), 'closed_by' => ['id' => (string) $user->getKey(), 'name' => $user->name]])->save();

            return ['from' => ['id' => (string) $from->getKey(), 'name' => $from->name], 'to' => ['id' => $toId, 'name' => $to->name]];
        }, 5);

        $this->audit->record('year.promoted', 'academic', 'academic_year', $result['from']['id'], null, [
            'to_year' => $result['to']['name'], 'counts' => $plan['counts'], 'roll_forward' => $plan['roll_forward'],
        ], ['digest' => $digest]);

        return $result + ['counts' => $plan['counts'], 'roll_forward' => $plan['roll_forward']];
    }

    /**
     * The dues step: one approval for every unpaid balance. The new year is
     * created here (as planned) when the wizard names a new one, so the
     * carried dues have a year to open in.
     *
     * @param  array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}  $target
     * @param  array<string, string>  $decisions  student id => carry | leave
     * @return array<string, mixed>
     */
    public function carryForward(User $user, array $target, array $decisions, string $duesDigest): array
    {
        $from = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year to close.', 422);
        $to = $this->target($from, $target);

        return DB::connection('mongodb')->transaction(function () use ($user, $from, $to, $decisions, $duesDigest): array {
            $year = $to['id'] !== null
                ? AcademicYear::query()->findOrFail($to['id'])
                : AcademicYear::create(['name' => $to['name'], 'starts_on' => $to['starts_on'], 'ends_on' => $to['ends_on'], 'status' => AcademicYear::STATUS_PLANNED]);
            $result = $this->dues->settle($user, $from, $year, $decisions, $duesDigest);

            return ['to' => ['id' => (string) $year->getKey(), 'name' => $year->name]] + $result;
        }, 5);
    }

    /** The grade a class moves into, given the next class in the school's order. */
    private static function nextGrade(string $grade, string $following): string
    {
        if (ctype_digit($grade) && ctype_digit($following) && (int) $following > (int) $grade + 1) {
            return (string) ((int) $grade + 1);
        }

        return $following;
    }

    private function sectionIn(SchoolClass $class, Section $old, string $toId): Section
    {
        $existing = Section::query()->where('class_id', (string) $class->getKey())->where('name', $old->name)->first();

        return $existing ?? Section::create([
            'year_id' => $toId, 'class_id' => (string) $class->getKey(), 'name' => $old->name, 'label' => $class->grade.'-'.$old->name,
            'wing' => $class->wing ?? $old->wing, 'class_teacher_id' => $old->exists ? $old->class_teacher_id : null, 'room' => $old->exists ? $old->room : null, 'draft' => true,
        ]);
    }

    /**
     * @param  array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}  $target
     * @return array{id: string|null, name: string, starts_on: string, ends_on: string}
     */
    private function target(AcademicYear $from, array $target): array
    {
        if (! empty($target['to_year_id'])) {
            $year = AcademicYear::query()->find($target['to_year_id']);
            if (! $year instanceof AcademicYear || $year->status !== AcademicYear::STATUS_PLANNED) {
                throw new ApiException('target_year_invalid', 'Choose a planned year to move into.', 422);
            }

            return ['id' => (string) $year->getKey(), 'name' => $year->name, 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on];
        }
        $name = trim((string) ($target['name'] ?? ''));
        if ($name === '' || empty($target['starts_on']) || empty($target['ends_on'])) {
            throw new ApiException('target_year_required', 'Name the new year and give its first and last day.', 422);
        }
        if ($target['starts_on'] <= $from->starts_on) {
            throw new ApiException('target_year_dates', 'The new year must start after the current one.', 422);
        }
        if (AcademicYear::query()->where('name', $name)->exists()) {
            throw new ApiException('year_exists', "A year named {$name} already exists; choose it instead.", 422);
        }

        return ['id' => null, 'name' => $name, 'starts_on' => (string) $target['starts_on'], 'ends_on' => (string) $target['ends_on']];
    }
}
