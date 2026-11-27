<?php

namespace App\Modules\People\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Identity\Domain\Scopes\AccessScopeSource;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Staff;

/**
 * The real scope facts, from the school's records (current year only):
 *
 *   child    the students of every guardian record linked to this account
 *            (siblings share one guardian, so one login covers them all)
 *   class    sections whose class_teacher_id is this person's staff record
 *   subject  "<section_id>:<subject_id>" of their teaching assignments
 *   wing     the wings on their staff record (a Vice Principal)
 *
 * Worked out once per user per request (the binding is scoped) and flushed
 * whenever a guardian, section, assignment or staff record changes.
 */
class RecordAccessScopes implements AccessScopeSource
{
    /** @var array<string, array{children: list<string>, class_sections: list<string>, subject_sections: list<string>, wings: list<string>}> */
    private array $cache = [];

    public function for(User $user): array
    {
        $key = $user->tenant_id.':'.$user->getKey();

        return $this->cache[$key] ??= $this->compute($user);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{children: list<string>, class_sections: list<string>, subject_sections: list<string>, wings: list<string>}
     */
    private function compute(User $user): array
    {
        $userId = (string) $user->getKey();

        $children = Guardian::query()->where('user_id', $userId)->get()
            ->flatMap(fn (Guardian $g) => $g->student_ids)->unique()->values()->all();

        $staff = Staff::query()->where('user_id', $userId)->where('status', Staff::STATUS_ACTIVE)->first();
        $year = AcademicYear::current();

        $classSections = [];
        $subjectSections = [];

        if ($staff instanceof Staff && $year instanceof AcademicYear) {
            $classSections = Section::query()->where('year_id', (string) $year->getKey())
                ->where('class_teacher_id', (string) $staff->getKey())->get()->map(fn ($m) => (string) $m->getKey())->values()->all();

            $subjectSections = TeachingAssignment::query()->where('year_id', (string) $year->getKey())
                ->where('teacher_id', (string) $staff->getKey())->get()
                ->map(fn (TeachingAssignment $a) => $a->subjectKey())->unique()->values()->all();
        }

        /** @var list<string> $wings */
        $wings = $staff instanceof Staff ? array_values($staff->wings ?? []) : [];

        return [
            'children' => array_values(array_map('strval', $children)),
            'class_sections' => $classSections,
            'subject_sections' => $subjectSections,
            'wings' => $wings,
        ];
    }
}
