<?php

namespace App\Modules\Reports\Domain;

use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\AssessmentScheme;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Reports\Domain\Grading\GradingEngine;
use App\Modules\Reports\Models\ReportTemplate;
use App\Support\Http\ApiException;

/**
 * Gathers what a report card is built from, for one section:
 *  - the school's active scheme for the section's class grade,
 *  - the marks of LOCKED papers only (owner's rule 2: a paper still open,
 *    submitted or verified never reaches a card, even when published),
 *  - each student's attendance this year,
 * and runs the grading engine on it.
 */
class ResultBuilder
{
    public function __construct(private readonly GradingEngine $engine) {}

    public function grade(Section $section): string
    {
        $class = SchoolClass::query()->find($section->class_id);

        return $class instanceof SchoolClass ? (string) $class->grade : '';
    }

    public function scheme(Section $section): AssessmentScheme
    {
        $grade = $this->grade($section);
        $scheme = AssessmentScheme::query()->where('year_id', $section->year_id)->where('status', 'active')->get()
            ->first(fn (AssessmentScheme $s) => in_array($grade, $s->grades, true));

        return $scheme ?? throw new ApiException('no_scheme', "There is no active assessment scheme for Class {$grade} this year. The Principal sets it up under Tests and marks.", 422);
    }

    public function template(Section $section): ReportTemplate
    {
        $grade = $this->grade($section);
        $templates = ReportTemplate::query()->orderBy('created_at')->get();
        $template = $templates->first(fn (ReportTemplate $t) => in_array($grade, $t->grades, true)) ?? $templates->first(fn (ReportTemplate $t) => $t->grades === []);

        return $template ?? throw new ApiException('no_template', "There is no report card template for Class {$grade}. The Principal makes one under Report cards > Templates.", 422);
    }

    /**
     * Grading-engine entries per student, from the section's locked papers.
     *
     * @return array{entries: array<string, list<array<string, mixed>>>, locked: int, not_locked: int}
     */
    public function entries(Section $section): array
    {
        $papers = Paper::query()->where('year_id', $section->year_id)->where('section_id', (string) $section->getKey())->get();
        $locked = $papers->filter(fn (Paper $p) => $p->status === Paper::LOCKED);
        $assessments = Assessment::query()->whereIn('_id', $locked->pluck('assessment_id')->unique()->values()->all())->get()->keyBy(fn ($a) => (string) $a->getKey());
        $out = [];

        foreach ($locked as $paper) {
            $assessment = $assessments[$paper->assessment_id] ?? null;
            if (! $assessment instanceof Assessment) {
                continue;
            }
            foreach ($paper->marks as $mark) {
                $out[$mark['student_id']][] = [
                    'subject_id' => $paper->subject_id,
                    'subject' => $paper->subject_name,
                    'term' => $assessment->term,
                    'component' => $assessment->component,
                    'max_marks' => $paper->max_marks,
                    'value' => $mark['value'] ?? null,
                    'code' => $mark['code'] ?? null,
                ];
            }
        }

        return ['entries' => $out, 'locked' => $locked->count(), 'not_locked' => $papers->count() - $locked->count()];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function compute(AssessmentScheme $scheme, array $entries): array
    {
        /** @var list<array{subject_id: string, subject: string, term: string|null, component: string|null, max_marks: float|int, value: float|int|null, code: string|null}> $entries */
        $result = $this->engine->compute([
            'terms' => $scheme->terms,
            'components' => $scheme->components,
            'grade_scale' => $scheme->grade_scale,
            'rules' => $scheme->rules,
        ], $entries);

        return $result + [
            'scheme' => ['id' => (string) $scheme->getKey(), 'name' => $scheme->name, 'terms' => $scheme->terms, 'rules' => $scheme->rules],
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Each student's days this year: present (P and L count 1, HD a half,
     * in whole half-days) and working days (holidays left out).
     *
     * @return array<string, array{present_halves: int, working: int}>
     */
    public function attendance(Section $section): array
    {
        $out = [];
        foreach (AttendanceDay::query()->where('section_id', (string) $section->getKey())->where('period', 0)->get() as $day) {
            foreach ($day->statusByStudent() as $studentId => $status) {
                if ($status === null || $status === 'H') {
                    continue;
                }
                $out[$studentId] ??= ['present_halves' => 0, 'working' => 0];
                $out[$studentId]['working']++;
                $out[$studentId]['present_halves'] += match ($status) {
                    'P', 'L' => 2,
                    'HD' => 1,
                    default => 0,
                };
            }
        }

        return $out;
    }
}
