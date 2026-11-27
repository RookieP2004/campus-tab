<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\ImportBatch;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use Illuminate\Support\Carbon;

/**
 * People cards, each from the records the viewed role covers:
 *   school    students and staff on roll; recent admissions and imports (office)
 *   class     the class teacher's own section: strength, birthdays this week
 *   subject   the sections and subjects a subject teacher teaches
 *   child     a parent's children, for the child switcher
 *   own       a student's own class
 */
class PeopleCards implements CardProvider
{
    public function __construct(private readonly Structure $structure) {}

    public function cards(User $user, DashboardContext $context): array
    {
        $cards = [];
        $year = AcademicYear::current();
        $mine = $user->accessScope();

        if ($context->can('students.view', 'school')) {
            $cards[] = [
                'id' => 'on_roll', 'kind' => 'kpi', 'title' => 'dashboard.cards.onRoll', 'priority' => 10, 'link' => '/students',
                'data' => ['students' => Student::query()->where('status', 'active')->count(), 'staff' => Staff::query()->where('status', Staff::STATUS_ACTIVE)->count()],
            ];
        }

        if ($context->can('students.admit')) {
            $since = Carbon::now()->startOfMonth();
            $imports = ImportBatch::query()->orderBy('created_at', 'desc')->limit(3)->get();
            $cards[] = [
                'id' => 'admissions', 'kind' => 'list', 'title' => 'dashboard.cards.admissions', 'priority' => 12, 'link' => '/students/new',
                'data' => [
                    'this_month' => Student::query()->where('created_at', '>=', $since)->count(),
                    'items' => $imports->map(fn (ImportBatch $b) => [
                        'label' => $b->file_name, 'detail' => $b->entity, 'status' => $b->status, 'link' => '/imports/'.$b->entity.'?batch='.$b->getKey(),
                        'at' => $b->created_at?->toIso8601String(),
                    ])->values()->all(),
                ],
            ];
        }

        if ($context->role === 'class_teacher' && $year instanceof AcademicYear && $mine['class_sections'] !== []) {
            foreach (Section::query()->whereIn('_id', $mine['class_sections'])->get() as $section) {
                $studentIds = Enrolment::query()->where('year_id', (string) $year->getKey())->where('section_id', (string) $section->getKey())->pluck('student_id')->all();
                $students = Student::query()->whereIn('_id', $studentIds)->where('status', 'active')->get(['name', 'dob']);
                $cards[] = [
                    'id' => 'my_class.'.$section->getKey(), 'kind' => 'list', 'title' => 'dashboard.cards.myClass', 'params' => ['section' => $section->label],
                    'priority' => 3, 'link' => '/students?section_id='.$section->getKey(),
                    'data' => [
                        'section_id' => (string) $section->getKey(),
                        'section' => $section->label,
                        'strength' => $students->count(),
                        'items' => $students->filter(fn (Student $s) => self::birthdayThisWeek($s->dob))->map(fn (Student $s) => ['label' => $s->name, 'detail' => substr($s->dob, 5)])->values()->all(),
                    ],
                ];
            }
        }

        if ($context->role === 'subject_teacher' && $year instanceof AcademicYear) {
            $staff = Staff::query()->where('user_id', (string) $user->getKey())->first();
            $assignments = $staff instanceof Staff
                ? TeachingAssignment::query()->where('year_id', (string) $year->getKey())->where('teacher_id', (string) $staff->getKey())->get()
                : collect();
            $cards[] = [
                'id' => 'my_classes', 'kind' => 'list', 'title' => 'dashboard.cards.myClasses', 'priority' => 3, 'link' => '/students',
                'data' => ['items' => array_map(fn (array $a) => ['label' => $a['section_label'].' · '.$a['subject_name'], 'link' => '/students?section_id='.$a['section_id']], $this->structure->presentAssignments($assignments))],
            ];
        }

        if ($context->role === 'parent' && $mine['children'] !== []) {
            $children = Student::query()->whereIn('_id', $mine['children'])->where('status', 'active')->orderBy('name')->get();
            $sections = $this->sectionsOf($children->map(fn (Student $s) => (string) $s->getKey())->all(), $year);
            $cards[] = [
                'id' => 'children', 'kind' => 'children', 'title' => 'dashboard.cards.children', 'priority' => 0, 'link' => null,
                'data' => ['items' => $children->map(fn (Student $s) => [
                    'id' => (string) $s->getKey(), 'label' => $s->name, 'detail' => $sections[(string) $s->getKey()] ?? null, 'link' => '/students/'.$s->getKey(),
                ])->values()->all()],
            ];
        }

        if ($context->role === 'student') {
            $student = Student::query()->where('user_id', (string) $user->getKey())->first();
            if ($student instanceof Student) {
                $section = $this->sectionsOf([(string) $student->getKey()], $year)[(string) $student->getKey()] ?? null;
                $cards[] = ['id' => 'my_record', 'kind' => 'kpi', 'title' => 'dashboard.cards.myRecord', 'priority' => 0, 'link' => '/students/'.$student->getKey(), 'data' => ['name' => $student->name, 'section' => $section]];
            }
        }

        return $cards;
    }

    /**
     * @param  list<string>  $studentIds
     * @return array<string, string>
     */
    private function sectionsOf(array $studentIds, ?AcademicYear $year): array
    {
        if ($year === null || $studentIds === []) {
            return [];
        }

        $enrolments = Enrolment::query()->where('year_id', (string) $year->getKey())->whereIn('student_id', $studentIds)->get();
        $labels = Section::query()->whereIn('_id', $enrolments->pluck('section_id')->all())->get()->mapWithKeys(fn (Section $s) => [(string) $s->getKey() => $s->label]);

        return $enrolments->mapWithKeys(fn (Enrolment $e) => [$e->student_id => (string) ($labels[$e->section_id] ?? '')])->all();
    }

    private static function birthdayThisWeek(string $dob): bool
    {
        $today = Carbon::today('Asia/Kolkata');

        for ($i = 0; $i < 7; $i++) {
            if ($today->copy()->addDays($i)->format('m-d') === substr($dob, 5, 5)) {
                return true;
            }
        }

        return false;
    }
}
