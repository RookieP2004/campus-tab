<?php

namespace Database\Seeders;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Assessment\Domain\Corrections;
use App\Modules\Assessment\Domain\Markbook;
use App\Modules\Assessment\Domain\PaperLifecycle;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Phase 6 demo data for Sunrise Public School, made through the real
 * services (made-up marks only):
 *
 *  - Unit Test 1: 7-B Mathematics and 7-B Science entered, verified by
 *    Anjali Verma, locked by Ramesh Kumar and published by Dr. Meera Iyer;
 *    Rakesh Sharma has asked to correct one Science mark (waiting for the
 *    Principal).
 *  - Unit Test 2: 8-A Science open, partly entered by Rakesh Sharma (the
 *    markbook design); 8-A English open and empty.
 *
 * DemoSchoolSeeder calls it; on a demo school made before Phase 6 run
 * `php artisan db:seed --class=DemoMarksSeeder`. It does nothing if the
 * school already has assessments.
 */
class DemoMarksSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoMarksSeeder runs only when APP_ENV is local or testing.');
        }

        $context = app(TenantContext::class);

        if ($context->has()) {
            $this->build();

            return;
        }

        $tenant = Tenant::findByCode(DemoSchoolSeeder::CODE) ?? throw new RuntimeException('Load the demo school first (DemoSchoolSeeder).');
        $context->run($tenant->id, fn () => $this->build());
    }

    private function build(): void
    {
        if (Assessment::query()->exists()) {
            $this->command?->warn('The demo school already has assessments. Nothing was changed.');

            return;
        }

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        $section = fn (string $label) => (string) Section::query()->where('label', $label)->firstOrFail()->getKey();
        $subject = fn (string $code) => (string) Subject::query()->where('code', $code)->firstOrFail()->getKey();
        [$principal, $clerk, $anjali, $rakesh] = [$user('principal@sunrise.test'), $user('clerk@sunrise.test'), $user('anjali.verma@sunrise.test'), $user('rakesh.sharma@sunrise.test')];

        $assessments = app(Assessments::class);
        $lifecycle = app(PaperLifecycle::class);
        $markbook = app(Markbook::class);

        // Unit Test 1: done and published.
        $ut1 = $assessments->create($clerk, [
            'kind' => 'exam', 'type' => 'periodic', 'name' => 'Unit Test 1', 'term' => 'Term 1', 'from' => '2026-07-14', 'to' => '2026-07-18',
            'papers' => [
                ['section_id' => $section('7-B'), 'subject_id' => $subject('MATH'), 'max_marks' => 40, 'date' => '2026-07-14'],
                ['section_id' => $section('7-B'), 'subject_id' => $subject('SCI'), 'max_marks' => 40, 'date' => '2026-07-15'],
            ],
        ]);
        $locked = [];
        foreach (Paper::query()->where('assessment_id', (string) $ut1->getKey())->get() as $i => $paper) {
            $paper = $lifecycle->open($clerk, $paper);
            $teacher = $paper->subject_name === 'Mathematics' ? $anjali : $rakesh;
            $marks = [];
            foreach ($markbook->roll($paper) as $n => $s) {
                $marks[$s['id']] = $n === 3 && $i === 1 ? ['code' => 'AB'] : ['value' => 22 + (($n * 7 + $i * 3) % 18)];
            }
            $paper = $markbook->save($teacher, $paper, $paper->version, $marks);
            $paper = $lifecycle->submit($teacher, $paper);
            $lifecycle->verify($anjali, $paper);
            $locked[] = (string) $paper->getKey();
        }
        $lifecycle->lock($clerk, $locked);
        AuditRecorder::muted(fn () => $lifecycle->publish($principal, $locked));

        $science = Paper::query()->where('assessment_id', (string) $ut1->getKey())->where('subject_id', $subject('SCI'))->firstOrFail();
        $first = $markbook->roll($science)[0] ?? null;
        if ($first !== null) {
            $old = (float) ($science->marksByStudent()[$first['id']]['value'] ?? 0);
            app(Corrections::class)->raise($rakesh, $science, $first['id'], ['value' => min(40, $old + 4)], 'Page 3 was not added to the total.');
        }

        // Unit Test 2: open for entry (the markbook design).
        $ut2 = $assessments->create($clerk, [
            'kind' => 'exam', 'type' => 'periodic', 'name' => 'Unit Test 2', 'term' => 'Term 2', 'from' => '2026-11-24', 'to' => '2026-11-28',
            'entry_closes_at' => '2026-12-11T17:00:00+05:30',
            'papers' => [
                ['section_id' => $section('8-A'), 'subject_id' => $subject('SCI'), 'max_marks' => 40, 'date' => '2026-11-24'],
                ['section_id' => $section('8-A'), 'subject_id' => $subject('ENG'), 'max_marks' => 40, 'date' => '2026-11-25'],
            ],
        ]);
        foreach (Paper::query()->where('assessment_id', (string) $ut2->getKey())->get() as $paper) {
            $paper = $lifecycle->open($clerk, $paper);
            if ($paper->subject_name === 'Science') {
                $marks = [];
                foreach (array_slice($markbook->roll($paper), 0, 9) as $n => $s) {
                    $marks[$s['id']] = $n === 3 ? ['code' => 'AB', 'remark' => 'Absent, fever'] : ['value' => [34, 38, 29, 0, 36, 31, 31, 27, 39][$n]];
                }
                $markbook->save($rakesh, $paper, $paper->version, $marks);
            }
        }

    }
}
