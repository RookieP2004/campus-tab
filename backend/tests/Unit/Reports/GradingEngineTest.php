<?php

use App\Modules\Reports\Domain\Grading\GradingEngine;
use App\Modules\Reports\Domain\Grading\Rational;

/*
| Phase 7 item 1 (owner's rule 1): exact arithmetic, the rounding rule
| applied once at the end, unit tests per starter preset.
*/

function preset(string $key, array $rules = []): array
{
    $p = config("assessment.presets.{$key}");
    $p['rules'] = array_merge($p['rules'], $rules);

    return $p;
}

function entry(string $subject, ?string $term, string $component, float $max, ?float $value, ?string $code = null): array
{
    return ['subject_id' => strtolower($subject), 'subject' => $subject, 'term' => $term, 'component' => $component, 'max_marks' => $max, 'value' => $value, 'code' => $code];
}

describe('Rational', function () {
    it('stays exact where floating point drifts', function () {
        // 0.1 + 0.2 is 0.30000000000000004 in floating point.
        $sum = Rational::fromDecimal('0.1')->add(Rational::fromDecimal('0.2'));
        expect($sum->compare(Rational::fromDecimal('0.3')))->toBe(0)->and($sum->toDecimal(20))->toBe('0.3');
    });

    it('rounds by each rule', function (string $mode, string $value, string $expected) {
        expect(Rational::fromDecimal($value)->round($mode)->toDecimal(2))->toBe($expected);
    })->with([
        ['half_up', '84.5', '85'], ['half_up', '84.49', '84'], ['half_even', '84.5', '84'], ['half_even', '85.5', '86'],
        ['up', '84.01', '85'], ['down', '84.99', '84'], ['none', '84.125', '84.13'], ['half_up', '-2.5', '-2'],
    ]);
});

describe('CBSE-style VI to VIII preset', function () {
    it('weights the four components, averages the two terms and rounds once', function () {
        $entries = [];
        foreach (['Term 1' => [7, 4, 4.5, 61], 'Term 2' => [8.5, 5, 3, 66.5]] as $term => [$pt, $nb, $se, $exam]) {
            $entries[] = entry('Science', $term, 'pt', 10, $pt);
            $entries[] = entry('Science', $term, 'notebook', 5, $nb);
            $entries[] = entry('Science', $term, 'enrichment', 5, $se);
            $entries[] = entry('Science', $term, 'exam', 80, $exam);
        }
        $r = (new GradingEngine)->compute(preset('cbse_6_8'), $entries);

        // Term 1: 7 + 4 + 4.5 + 61 = 76.5; Term 2: 8.5 + 5 + 3 + 66.5 = 83; mean 79.75 -> 80 (half up, once).
        expect($r['subjects'][0]['exact'])->toBe('319/4')
            ->and($r['subjects'][0]['percent'])->toBe('80')
            ->and($r['subjects'][0]['grade'])->toBe('B1')
            ->and($r['overall'])->toBe(['percent' => '80', 'grade' => 'B1', 'result' => 'pass']);
    });

    it('rounds once, on the exact mean, by the scheme rule', function () {
        $entries = [
            entry('Maths', 'Term 1', 'exam', 80, 32.5), entry('Maths', 'Term 1', 'pt', 10, 3.5), entry('Maths', 'Term 1', 'notebook', 5, 2), entry('Maths', 'Term 1', 'enrichment', 5, 2),
            entry('Maths', 'Term 2', 'exam', 80, 33), entry('Maths', 'Term 2', 'pt', 10, 3.5), entry('Maths', 'Term 2', 'notebook', 5, 2), entry('Maths', 'Term 2', 'enrichment', 5, 2.5),
        ];
        $r = (new GradingEngine)->compute(preset('cbse_6_8'), $entries);
        // Term 1 = 40, Term 2 = 41; mean 40.5 -> 41 with half up, 40 with half even.
        expect($r['subjects'][0]['percent'])->toBe('41');
        expect((new GradingEngine)->compute(preset('cbse_6_8', ['rounding' => 'half_even']), $entries)['subjects'][0]['percent'])->toBe('40');
    });

    it('keeps the best n periodic tests when best_of is set', function () {
        $entries = [
            entry('English', 'Term 1', 'pt', 10, 4), entry('English', 'Term 1', 'pt', 10, 9), entry('English', 'Term 1', 'pt', 20, 18),
            entry('English', 'Term 1', 'exam', 80, 60), entry('English', 'Term 1', 'notebook', 5, 5), entry('English', 'Term 1', 'enrichment', 5, 5),
        ];
        $r = (new GradingEngine)->compute(preset('cbse_6_8', ['best_of' => 2]), $entries);
        // Best two of 4/10, 9/10, 18/20 are 9/10 and 18/20: 27/30 of weight 10 = 9.
        expect($r['subjects'][0]['terms'][0]['components'][0])->toMatchArray(['key' => 'pt', 'obtained' => '27', 'max' => '30', 'papers' => 2])
            ->and($r['subjects'][0]['percent'])->toBe('79');
    });
});

describe('CBSE-style IX and X preset', function () {
    it('adds 20 internal to 80 board-pattern marks', function () {
        $r = (new GradingEngine)->compute(preset('cbse_9_10'), [
            entry('Hindi', null, 'internal', 20, 17.5), entry('Hindi', null, 'board', 80, 54.5),
        ]);
        // One term ("Year"): 17.5 + 54.5 = 72 -> B1.
        expect($r['subjects'][0]['percent'])->toBe('72')->and($r['subjects'][0]['grade'])->toBe('B1')->and($r['overall']['result'])->toBe('pass');
    });

    it('lifts a near miss with grace, flags it, and fails a wider miss', function () {
        $scheme = preset('cbse_9_10', ['grace_marks' => 2]);
        $near = (new GradingEngine)->compute($scheme, [entry('Maths', null, 'internal', 20, 6), entry('Maths', null, 'board', 80, 25.5)]);
        expect($near['subjects'][0])->toMatchArray(['percent' => '33', 'passed' => true, 'grace' => '1.5']);

        $wide = (new GradingEngine)->compute($scheme, [entry('Maths', null, 'internal', 20, 5), entry('Maths', null, 'board', 80, 24)]);
        expect($wide['subjects'][0])->toMatchArray(['percent' => '29', 'passed' => false, 'grace' => null])->and($wide['overall']['result'])->toBe('fail');
    });

    it('counts AB as zero and leaves ML out', function () {
        $absent = (new GradingEngine)->compute(preset('cbse_9_10'), [entry('Science', null, 'internal', 20, 18), entry('Science', null, 'board', 80, null, 'AB')]);
        expect($absent['subjects'][0]['percent'])->toBe('18');

        $medical = (new GradingEngine)->compute(preset('cbse_9_10'), [entry('Science', null, 'internal', 20, 18), entry('Science', null, 'board', 80, null, 'ML')]);
        // Only the internal 18/20 counts, scaled to 100, and the subject is flagged incomplete.
        expect($medical['subjects'][0])->toMatchArray(['percent' => '90', 'incomplete' => true]);
    });
});

describe('Simple two-term preset', function () {
    it('grades on its own five-band scale and averages subjects for the overall result', function () {
        $scheme = preset('generic');
        $entries = [
            entry('Maths', 'Term 1', 'tests', 20, 15), entry('Maths', 'Term 1', 'exam', 80, 62),
            entry('Maths', 'Term 2', 'tests', 20, 17), entry('Maths', 'Term 2', 'exam', 80, 64),
            entry('Art', 'Term 1', 'tests', 20, 9), entry('Art', 'Term 1', 'exam', 80, 21),
            entry('Art', 'Term 2', 'tests', 20, 8.5), entry('Art', 'Term 2', 'exam', 80, 22),
        ];
        $r = (new GradingEngine)->compute($scheme, $entries);
        $bySubject = collect($r['subjects'])->keyBy('subject');

        // Maths: (77 + 81) / 2 = 79 -> B. Art: (30 + 30.5) / 2 = 30.25 -> 30, below 33: E, fail.
        expect($bySubject['Maths'])->toMatchArray(['percent' => '79', 'grade' => 'B', 'passed' => true])
            ->and($bySubject['Art'])->toMatchArray(['percent' => '30', 'grade' => 'E', 'passed' => false])
            // Overall from the exact values: (79 + 30.25) / 2 = 54.625 -> 55 -> C.
            ->and($r['overall'])->toBe(['percent' => '55', 'grade' => 'C', 'result' => 'fail']);
    });

    it('ignores marks whose component or term the scheme does not have', function () {
        $r = (new GradingEngine)->compute(preset('generic'), [entry('Maths', 'Term 1', 'exam', 80, 40), entry('Maths', 'Term 3', 'exam', 80, 80), entry('Maths', 'Term 1', 'quiz', 10, 10)]);
        expect($r['ignored'])->toBe(2)->and($r['subjects'][0]['percent'])->toBe('50');
    });
});
