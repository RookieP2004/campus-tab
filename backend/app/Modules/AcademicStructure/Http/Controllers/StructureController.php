<?php

namespace App\Modules\AcademicStructure\Http\Controllers;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The school's academic structure.
 *
 *   GET    /academic-years                     POST /academic-years { name, starts_on, ends_on, make_current? }
 *   POST   /academic-years/{id}/make-current
 *   GET    /classes ?year_id                   classes of a year with their sections
 *   POST   /classes { grade, name, order?, wing?, sections?: ["A","B"] }
 *   PATCH  /classes/{id}                       DELETE /classes/{id}
 *   POST   /sections { class_id, name, class_teacher_id?, wing?, room? }
 *   PATCH  /sections/{id}                      DELETE /sections/{id}
 *   GET    /subjects                           POST /subjects { code, name, kind?, elective? }
 *   PATCH  /subjects/{id}                      DELETE /subjects/{id}
 *   GET    /teaching-assignments ?section_id ?teacher_id
 *   POST   /teaching-assignments { section_id, subject_id, teacher_id }
 *   DELETE /teaching-assignments/{id}
 *
 * Classes, sections and assignments belong to a year (the current one when
 * none is named). Viewing needs academic.view, changes academic.manage.
 */
class StructureController
{
    public function __construct(private readonly Structure $structure) {}

    public function years(): JsonResponse
    {
        Gate::authorize('viewAny', AcademicYear::class);

        return response()->json(['data' => AcademicYear::query()->orderBy('starts_on', 'desc')->get()->map(fn (AcademicYear $y) => Structure::presentYear($y))->values()]);
    }

    public function storeYear(Request $request): JsonResponse
    {
        Gate::authorize('manage', AcademicYear::class);

        $input = $request->validate([
            'name' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'make_current' => ['sometimes', 'boolean'],
        ], ['name.regex' => 'A year is named like 2026-27.']);

        if (AcademicYear::query()->where('name', $input['name'])->exists()) {
            throw new ApiException('year_exists', 'This academic year already exists.', 422);
        }

        $year = AcademicYear::create(['name' => $input['name'], 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on']]);

        if (($input['make_current'] ?? false) || AcademicYear::current() === null) {
            $this->structure->makeCurrent($year);
        }

        return response()->json(['data' => Structure::presentYear(AcademicYear::query()->findOrFail($year->getKey()))], 201);
    }

    public function makeCurrent(string $id): JsonResponse
    {
        Gate::authorize('manage', AcademicYear::class);

        $year = AcademicYear::query()->findOrFail($id);
        $this->structure->makeCurrent($year);

        return response()->json(['data' => Structure::presentYear($year)]);
    }

    public function classes(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SchoolClass::class);
        $year = $this->structure->year($request->query('year_id'));

        return response()->json(['data' => $this->structure->classesWithSections($year)]);
    }

    public function storeClass(Request $request): JsonResponse
    {
        Gate::authorize('manage', SchoolClass::class);

        $input = $request->validate([
            'year_id' => ['sometimes', 'string', 'max:30'],
            'grade' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:60'],
            'order' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'wing' => ['nullable', 'string', 'max:40'],
            'sections' => ['sometimes', 'array', 'max:26'],
            'sections.*' => ['string', 'max:10', 'distinct'],
        ]);

        $year = $this->structure->year($input['year_id'] ?? null);
        $class = $this->structure->createClass($year, $input);

        return response()->json(['data' => $this->structure->presentClass($class)], 201);
    }

    public function updateClass(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', SchoolClass::class);

        $input = $request->validate([
            'grade' => ['sometimes', 'string', 'max:20'],
            'name' => ['sometimes', 'string', 'max:60'],
            'order' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'wing' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $class = SchoolClass::query()->findOrFail($id);
        $class->fill($input)->save();

        return response()->json(['data' => $this->structure->presentClass($class)]);
    }

    public function destroyClass(string $id): JsonResponse
    {
        Gate::authorize('manage', SchoolClass::class);

        $class = SchoolClass::query()->findOrFail($id);
        $this->structure->deleteClass($class);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function storeSection(Request $request): JsonResponse
    {
        Gate::authorize('manage', Section::class);

        $input = $request->validate([
            'class_id' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:10'],
            'class_teacher_id' => ['nullable', 'string', 'max:30'],
            'wing' => ['nullable', 'string', 'max:40'],
            'room' => ['nullable', 'string', 'max:30'],
        ]);

        $class = SchoolClass::query()->findOrFail($input['class_id']);
        $section = $this->structure->createSection($class, $input);

        return response()->json(['data' => Structure::section($section)], 201);
    }

    public function updateSection(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', Section::class);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:10'],
            'class_teacher_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'wing' => ['sometimes', 'nullable', 'string', 'max:40'],
            'room' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $section = Section::query()->findOrFail($id);

        if (array_key_exists('class_teacher_id', $input) && $input['class_teacher_id'] !== null) {
            $this->structure->assertTeacher($input['class_teacher_id']);
        }

        if (isset($input['name'])) {
            $class = SchoolClass::query()->find($section->class_id);
            $input['label'] = Structure::label($class, $input['name']);
        }

        $section->fill($input)->save();

        return response()->json(['data' => Structure::section($section)]);
    }

    public function destroySection(string $id): JsonResponse
    {
        Gate::authorize('manage', Section::class);

        $this->structure->deleteSection(Section::query()->findOrFail($id));

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function subjects(): JsonResponse
    {
        Gate::authorize('viewAny', Subject::class);

        return response()->json(['data' => Subject::query()->orderBy('name')->get()->map(fn (Subject $s) => Structure::subject($s))->values()]);
    }

    public function storeSubject(Request $request): JsonResponse
    {
        Gate::authorize('manage', Subject::class);

        $input = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(['scholastic', 'co_scholastic'])],
            'elective' => ['sometimes', 'boolean'],
        ]);

        $input['code'] = strtoupper($input['code']);

        if (Subject::query()->where('code', $input['code'])->exists()) {
            throw new ApiException('subject_exists', 'A subject with this code already exists.', 422);
        }

        return response()->json(['data' => Structure::subject(Subject::create($input))], 201);
    }

    public function updateSubject(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', Subject::class);

        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(['scholastic', 'co_scholastic'])],
            'elective' => ['sometimes', 'boolean'],
        ]);

        $subject = Subject::query()->findOrFail($id);
        $subject->fill($input)->save();

        return response()->json(['data' => Structure::subject($subject)]);
    }

    public function destroySubject(string $id): JsonResponse
    {
        Gate::authorize('manage', Subject::class);

        Subject::query()->findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function assignments(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TeachingAssignment::class);

        $year = $this->structure->year($request->query('year_id'));
        $query = TeachingAssignment::query()->where('year_id', (string) $year->getKey());

        foreach (['section_id', 'teacher_id', 'subject_id'] as $filter) {
            $value = $request->query($filter);
            if (is_string($value) && $value !== '') {
                $query->where($filter, $value);
            }
        }

        return response()->json(['data' => $this->structure->presentAssignments($query->get())]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        Gate::authorize('manage', TeachingAssignment::class);

        $input = $request->validate([
            'section_id' => ['required', 'string', 'max:30'],
            'subject_id' => ['required', 'string', 'max:30'],
            'teacher_id' => ['required', 'string', 'max:30'],
        ]);

        $assignment = $this->structure->assign($input['section_id'], $input['subject_id'], $input['teacher_id']);

        return response()->json(['data' => $this->structure->presentAssignments(collect([$assignment]))[0]], 201);
    }

    public function destroyAssignment(string $id): JsonResponse
    {
        Gate::authorize('manage', TeachingAssignment::class);

        TeachingAssignment::query()->findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
