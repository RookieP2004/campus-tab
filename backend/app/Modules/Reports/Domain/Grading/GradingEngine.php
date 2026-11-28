<?php

namespace App\Modules\Reports\Domain\Grading;

/**
 * Works out a student's yearly result from the school's scheme and the
 * marks of LOCKED papers only (the caller passes nothing else).
 *
 * Exact arithmetic throughout: marks become whole half-marks and every
 * step is a Rational. The scheme's rounding rule is applied once, at the
 * end, to each subject's percentage and to the overall percentage. Nothing
 * in between is rounded.
 *
 * For each subject:
 *  1. Per term and component, the papers of that component (an assessment
 *     names its term and component). AB counts as 0 out of the maximum; ML
 *     (medical) leaves the paper out of both. With rules.best_of = n, a
 *     component with more than n papers keeps the n best (by fraction).
 *  2. Component fraction = marks obtained / maximum, summed over its papers.
 *  3. Term percentage = sum of weight x fraction over the components that
 *     have papers, scaled back to 100 when some are missing (the subject is
 *     then flagged incomplete).
 *  4. Subject percentage = the mean of its terms that have marks (equal
 *     weight per term).
 *  5. Grace: below the pass mark by no more than rules.grace_marks
 *     percentage points lifts it to the pass mark (flagged, with the amount).
 *  6. Rounded once with rules.rounding; grade and pass from the rounded value.
 *
 * Overall: the mean of the subjects' exact percentages (after grace),
 * rounded once. Result: pass when every subject passes; incomplete when a
 * subject has no marks at all.
 */
class GradingEngine
{
    /**
     * @param  array{terms?: list<string>, components: list<array{key: string, name: string, max?: float|int, weight: float|int|string}>, grade_scale: list<array{grade: string, min: float|int}>, rules: array<string, mixed>}  $scheme
     * @param  list<array{subject_id: string, subject: string, term: string|null, component: string|null, max_marks: float|int, value: float|int|null, code: string|null}>  $entries
     * @return array{subjects: list<array<string, mixed>>, overall: array{percent: string|null, grade: string|null, result: string}, ignored: int}
     */
    public function compute(array $scheme, array $entries): array
    {
        $terms = array_values($scheme['terms'] ?? []);
        $components = [];
        foreach ($scheme['components'] as $c) {
            $components[$c['key']] = ['name' => $c['name'], 'weight' => Rational::fromDecimal($c['weight'])];
        }
        $rules = $scheme['rules'] + ['pass_percent' => 33, 'rounding' => 'half_up', 'best_of' => null, 'grace_marks' => 0];
        $rounding = (string) $rules['rounding'];
        $pass = Rational::fromDecimal($rules['pass_percent']);
        $grace = Rational::fromDecimal($rules['grace_marks'] ?? 0);
        $bestOf = isset($rules['best_of']) ? (int) $rules['best_of'] : null;

        // subject => term => component => list of [obtained, max] in half-marks
        $grid = [];
        $names = [];
        $ignored = 0;

        foreach ($entries as $e) {
            $term = $e['term'] ?? null;
            if ($term === null && count($terms) === 1) {
                $term = $terms[0];
            }
            $component = (string) ($e['component'] ?? '');
            if (! isset($components[$component]) || $term === null || ($terms !== [] && ! in_array($term, $terms, true))) {
                $ignored++;

                continue;
            }
            $names[$e['subject_id']] = $e['subject'];
            $grid[$e['subject_id']][$term][$component] ??= [];
            if (($e['code'] ?? null) === 'ML') {
                continue;
            }
            $max = self::halves($e['max_marks']);
            $obtained = ($e['code'] ?? null) === 'AB' || $e['value'] === null ? 0 : self::halves($e['value']);
            if ($max > 0) {
                $grid[$e['subject_id']][$term][$component][] = [$obtained, $max];
            }
        }

        $subjects = [];
        $overallSum = Rational::zero();
        $counted = 0;
        $allPass = true;
        $incompleteOverall = false;

        foreach ($grid as $subjectId => $byTerm) {
            $termRows = [];
            $termSum = Rational::zero();
            $termCount = 0;
            $incomplete = false;

            foreach (($terms !== [] ? $terms : array_keys($byTerm)) as $term) {
                $rows = [];
                $weighted = Rational::zero();
                $weightPresent = Rational::zero();

                foreach ($components as $key => $c) {
                    $papers = $byTerm[$term][$key] ?? [];
                    if ($bestOf !== null && count($papers) > $bestOf) {
                        usort($papers, fn (array $a, array $b) => Rational::of($b[0], $b[1])->compare(Rational::of($a[0], $a[1])));
                        $papers = array_slice($papers, 0, $bestOf);
                    }
                    $obtained = array_sum(array_column($papers, 0));
                    $max = array_sum(array_column($papers, 1));
                    $rows[] = [
                        'key' => $key, 'name' => $c['name'], 'weight' => $c['weight']->toDecimal(4),
                        'obtained' => $max > 0 ? Rational::of($obtained, 2)->toDecimal(1) : null,
                        'max' => $max > 0 ? Rational::of($max, 2)->toDecimal(1) : null,
                        'papers' => count($papers),
                    ];
                    if ($max > 0) {
                        $weighted = $weighted->add($c['weight']->mul(Rational::of($obtained, $max)));
                        $weightPresent = $weightPresent->add($c['weight']);
                    } else {
                        $incomplete = true;
                    }
                }

                if ($weightPresent->isZero()) {
                    $termRows[] = ['term' => $term, 'components' => $rows, 'has_marks' => false];

                    continue;
                }
                $termSum = $termSum->add($weighted->mul(Rational::of(100))->div($weightPresent));
                $termCount++;
                $termRows[] = ['term' => $term, 'components' => $rows, 'has_marks' => true];
            }

            if ($termCount === 0) {
                $incompleteOverall = true;
                $subjects[] = ['subject_id' => (string) $subjectId, 'subject' => $names[$subjectId], 'terms' => $termRows, 'percent' => null, 'exact' => null, 'grade' => null, 'passed' => false, 'grace' => null, 'incomplete' => true];

                continue;
            }

            $exact = $termSum->div(Rational::of($termCount));
            $graceUsed = null;
            if ($exact->lessThan($pass) && ! $grace->isZero() && $pass->sub($exact)->compare($grace) <= 0) {
                $graceUsed = $pass->sub($exact);
                $exact = $pass;
            }
            $rounded = $exact->round($rounding);
            $passed = $rounded->compare($pass) >= 0;
            $allPass = $allPass && $passed;
            $overallSum = $overallSum->add($exact);
            $counted++;

            $subjects[] = [
                'subject_id' => (string) $subjectId,
                'subject' => $names[$subjectId],
                'terms' => $termRows,
                'percent' => $rounded->toDecimal(2),
                'exact' => $exact->num.'/'.$exact->den,
                'grade' => self::grade($scheme['grade_scale'], $rounded),
                'passed' => $passed,
                'grace' => $graceUsed?->toDecimal(4),
                'incomplete' => $incomplete,
            ];
        }

        usort($subjects, fn (array $a, array $b) => strcmp((string) $a['subject'], (string) $b['subject']));

        if ($counted === 0) {
            return ['subjects' => $subjects, 'overall' => ['percent' => null, 'grade' => null, 'result' => 'incomplete'], 'ignored' => $ignored];
        }

        $overall = $overallSum->div(Rational::of($counted))->round($rounding);

        return [
            'subjects' => $subjects,
            'overall' => [
                'percent' => $overall->toDecimal(2),
                'grade' => self::grade($scheme['grade_scale'], $overall),
                'result' => $incompleteOverall ? 'incomplete' : ($allPass ? 'pass' : 'fail'),
            ],
            'ignored' => $ignored,
        ];
    }

    /** A mark in whole half-marks (marks are whole or end in .5). */
    public static function halves(float|int|string $value): int
    {
        $twice = Rational::fromDecimal(is_string($value) ? $value : (float) $value)->mul(Rational::of(2))->round('half_up');

        return intdiv($twice->num, $twice->den);
    }

    /**
     * @param  list<array{grade: string, min: float|int}>  $scale
     */
    private static function grade(array $scale, Rational $percent): ?string
    {
        usort($scale, fn (array $a, array $b) => Rational::fromDecimal($b['min'])->compare(Rational::fromDecimal($a['min'])));
        foreach ($scale as $band) {
            if ($percent->compare(Rational::fromDecimal($band['min'])) >= 0) {
                return $band['grade'];
            }
        }

        return null;
    }
}
