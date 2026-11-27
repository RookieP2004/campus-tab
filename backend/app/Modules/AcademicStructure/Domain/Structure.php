<?php

namespace App\Modules\AcademicStructure\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Staff;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Years, classes, sections, subjects and who teaches what. Runs inside the
 * school (every query is tenant-scoped).
 */
class Structure
{
    /** The named year, or the current one. */
    public function year(mixed $id = null): AcademicYear
    {
        $year = is_string($id) && $id !== '' ? AcademicYear::query()->find($id) : AcademicYear::current();

        return $year instanceof AcademicYear
            ? $year
            : throw new ApiException('no_current_year', 'Set up the academic year first (for example 2026-27).', 409);
    }

    /** One current year: the previous current one becomes closed. */
    public function makeCurrent(AcademicYear $year): void
    {
        DB::connection('mongodb')->transaction(function () use ($year): void {
            foreach (AcademicYear::query()->where('status', AcademicYear::STATUS_CURRENT)->get() as $previous) {
                if ((string) $previous->getKey() !== (string) $year->getKey()) {
                    $previous->forceFill(['status' => AcademicYear::STATUS_CLOSED])->save();
                }
            }

            $year->forceFill(['status' => AcademicYear::STATUS_CURRENT])->save();
        });
    }

    /**
     * @param  array{grade: string, name: string, order?: int, wing?: string|null, sections?: list<string>}  $input
     */
    public function createClass(AcademicYear $year, array $input): SchoolClass
    {
        if (SchoolClass::query()->where('year_id', (string) $year->getKey())->where('grade', $input['grade'])->exists()) {
            throw new ApiException('class_exists', "Class {$input['grade']} already exists in {$year->name}.", 422);
        }

        $class = SchoolClass::create([
            'year_id' => (string) $year->getKey(),
            'grade' => $input['grade'],
            'name' => $input['name'],
            'order' => $input['order'] ?? (int) SchoolClass::query()->where('year_id', (string) $year->getKey())->count(),
            'wing' => $input['wing'] ?? null,
        ]);

        foreach ($input['sections'] ?? [] as $name) {
            $this->createSection($class, ['name' => $name]);
        }

        return $class;
    }

    /**
     * @param  array{name: string, class_teacher_id?: string|null, wing?: string|null, room?: string|null}  $input
     */
    public function createSection(SchoolClass $class, array $input): Section
    {
        $name = strtoupper(trim($input['name']));

        if (Section::query()->where('class_id', (string) $class->getKey())->where('name', $name)->exists()) {
            throw new ApiException('section_exists', "Section {$name} already exists in {$class->name}.", 422);
        }

        if (! empty($input['class_teacher_id'])) {
            $this->assertTeacher($input['class_teacher_id']);
        }

        return Section::create([
            'year_id' => $class->year_id,
            'class_id' => (string) $class->getKey(),
            'name' => $name,
            'label' => self::label($class, $name),
            'wing' => $input['wing'] ?? $class->wing,
            'class_teacher_id' => $input['class_teacher_id'] ?? null,
            'room' => $input['room'] ?? null,
        ]);
    }

    public function deleteSection(Section $section): void
    {
        if (Enrolment::query()->where('section_id', (string) $section->getKey())->exists()) {
            throw new ApiException('section_has_students', 'Move the students out of this section before removing it.', 409);
        }

        TeachingAssignment::query()->where('section_id', (string) $section->getKey())->get()->each->delete();
        $section->delete();
    }

    public function deleteClass(SchoolClass $class): void
    {
        foreach (Section::query()->where('class_id', (string) $class->getKey())->get() as $section) {
            $this->deleteSection($section);
        }

        $class->delete();
    }

    public function assign(string $sectionId, string $subjectId, string $teacherId): TeachingAssignment
    {
        $section = Section::query()->findOrFail($sectionId);
        Subject::query()->findOrFail($subjectId);
        $this->assertTeacher($teacherId);

        $existing = TeachingAssignment::query()->where('section_id', $sectionId)->where('subject_id', $subjectId)->first();

        if ($existing instanceof TeachingAssignment) {
            $existing->fill(['teacher_id' => $teacherId])->save();

            return $existing;
        }

        return TeachingAssignment::create([
            'year_id' => $section->year_id,
            'section_id' => $sectionId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
        ]);
    }

    public function assertTeacher(string $staffId): void
    {
        $staff = Staff::query()->find($staffId);

        if (! $staff instanceof Staff || $staff->status !== Staff::STATUS_ACTIVE) {
            throw new ApiException('teacher_not_found', 'Choose an active staff member.', 422);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function classesWithSections(AcademicYear $year): array
    {
        $classes = SchoolClass::query()->where('year_id', (string) $year->getKey())->orderBy('order')->get();
        $sections = Section::query()->where('year_id', (string) $year->getKey())->orderBy('name')->get()->groupBy('class_id');
        $teachers = $this->staffNames($sections->flatten()->pluck('class_teacher_id')->filter()->all());

        return $classes->map(fn (SchoolClass $c) => array_merge(self::schoolClass($c), [
            'sections' => ($sections[(string) $c->getKey()] ?? collect())->map(fn (Section $s) => self::section($s, $teachers))->values()->all(),
        ]))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentClass(SchoolClass $class): array
    {
        $sections = Section::query()->where('class_id', (string) $class->getKey())->orderBy('name')->get();

        return array_merge(self::schoolClass($class), ['sections' => $sections->map(fn (Section $s) => self::section($s))->values()->all()]);
    }

    /**
     * @param  Collection<int, TeachingAssignment>  $assignments
     * @return list<array<string, mixed>>
     */
    public function presentAssignments(Collection $assignments): array
    {
        $teachers = $this->staffNames($assignments->pluck('teacher_id')->all());
        $subjects = Subject::query()->whereIn('_id', $assignments->pluck('subject_id')->unique()->values()->all())->get()->keyBy(fn (Subject $s) => (string) $s->getKey());
        $sections = Section::query()->whereIn('_id', $assignments->pluck('section_id')->unique()->values()->all())->get()->keyBy(fn (Section $s) => (string) $s->getKey());

        return $assignments->map(fn (TeachingAssignment $a) => [
            'id' => (string) $a->getKey(),
            'year_id' => $a->year_id,
            'section_id' => $a->section_id,
            'section_label' => $sections[$a->section_id]->label ?? null,
            'subject_id' => $a->subject_id,
            'subject_name' => $subjects[$a->subject_id]->name ?? null,
            'teacher_id' => $a->teacher_id,
            'teacher_name' => $teachers[$a->teacher_id] ?? null,
        ])->values()->all();
    }

    /**
     * @param  list<mixed>  $ids
     * @return array<string, string>
     */
    private function staffNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));

        return $ids === [] ? [] : Staff::query()->whereIn('_id', $ids)->get()
            ->mapWithKeys(fn (Staff $s) => [(string) $s->getKey() => $s->name])->all();
    }

    public static function label(?SchoolClass $class, string $section): string
    {
        return ($class !== null ? $class->grade : '?').'-'.strtoupper($section);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentYear(AcademicYear $y): array
    {
        return ['id' => (string) $y->getKey(), 'name' => $y->name, 'starts_on' => $y->starts_on, 'ends_on' => $y->ends_on, 'status' => $y->status];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schoolClass(SchoolClass $c): array
    {
        return ['id' => (string) $c->getKey(), 'year_id' => $c->year_id, 'grade' => $c->grade, 'name' => $c->name, 'order' => $c->order, 'wing' => $c->wing];
    }

    /**
     * @param  array<string, string>  $teachers
     * @return array<string, mixed>
     */
    public static function section(Section $s, array $teachers = []): array
    {
        return [
            'id' => (string) $s->getKey(),
            'class_id' => $s->class_id,
            'name' => $s->name,
            'label' => $s->label,
            'wing' => $s->wing,
            'room' => $s->room,
            'class_teacher_id' => $s->class_teacher_id,
            'class_teacher_name' => $s->class_teacher_id ? ($teachers[$s->class_teacher_id] ?? null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subject(Subject $s): array
    {
        return ['id' => (string) $s->getKey(), 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'elective' => $s->elective];
    }
}
