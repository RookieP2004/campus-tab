<?php

namespace Database\Seeders;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Assessment\Domain\Markbook;
use App\Modules\Assessment\Domain\PaperLifecycle;
use App\Modules\Assessment\Models\AssessmentScheme;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Domain\ReportCards;
use App\Modules\Reports\Models\ReportCard;
use App\Modules\Reports\Models\ReportTemplate;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Phase 7 demo data for Sunrise Public School, through the real services
 * (made-up marks only):
 *
 *  - an active scheme for Classes 6 to 8 (the CBSE-style VI-VIII preset),
 *  - a "Half-yearly exam" (Term 1, term exam component) for 7-B Mathematics
 *    and Science, entered, verified and locked,
 *  - a report card template from the CBSE I-VIII preset,
 *  - 7-B's cards made, Anjali Verma's class remark on the first two, and
 *    the first card reviewed and published (needs the gotenberg container
 *    for the PDF; without it the cards stay drafts and a warning says so).
 *
 * DemoSchoolSeeder calls it. It does nothing if the school already has a
 * report card template.
 */
class DemoReportsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoReportsSeeder runs only when APP_ENV is local or testing.');
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
        if (ReportTemplate::query()->exists()) {
            $this->command?->warn('The demo school already has report card templates. Nothing was changed.');

            return;
        }
        // Background work runs here and now, so the demo is ready when the seeder ends.
        config(['queue.default' => 'sync']);

        $user = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
        [$principal, $clerk, $anjali, $rakesh] = [$user('principal@sunrise.test'), $user('clerk@sunrise.test'), $user('anjali.verma@sunrise.test'), $user('rakesh.sharma@sunrise.test')];
        $section = Section::query()->where('label', '7-B')->firstOrFail();
        $subject = fn (string $code) => (string) Subject::query()->where('code', $code)->firstOrFail()->getKey();

        $preset = (array) config('assessment.presets.cbse_6_8');
        if (! AssessmentScheme::query()->where('status', 'active')->exists()) {
            AssessmentScheme::create([
                'year_id' => $section->year_id, 'name' => 'Classes 6 to 8', 'grades' => ['6', '7', '8'], 'preset' => 'cbse_6_8', 'status' => 'active',
                'terms' => $preset['terms'], 'components' => $preset['components'], 'grade_scale' => $preset['grade_scale'], 'rules' => $preset['rules'],
            ]);
        }

        $assessments = app(Assessments::class);
        $lifecycle = app(PaperLifecycle::class);
        $markbook = app(Markbook::class);
        $exam = $assessments->create($clerk, [
            'kind' => 'exam', 'type' => 'half_yearly', 'name' => 'Half-yearly exam', 'term' => 'Term 1', 'component' => 'exam', 'from' => '2026-09-21', 'to' => '2026-09-30',
            'papers' => [
                ['section_id' => (string) $section->getKey(), 'subject_id' => $subject('MATH'), 'max_marks' => 80, 'date' => '2026-09-21'],
                ['section_id' => (string) $section->getKey(), 'subject_id' => $subject('SCI'), 'max_marks' => 80, 'date' => '2026-09-23'],
            ],
        ]);
        $locked = [];
        foreach (Paper::query()->where('assessment_id', (string) $exam->getKey())->get() as $i => $paper) {
            $paper = $lifecycle->open($clerk, $paper);
            $teacher = $paper->subject_name === 'Mathematics' ? $anjali : $rakesh;
            $marks = [];
            foreach ($markbook->roll($paper) as $n => $s) {
                $marks[$s['id']] = ['value' => 38 + (($n * 11 + $i * 5) % 40) + ($n % 2 === 0 ? 0.5 : 0)];
            }
            $paper = $markbook->save($teacher, $paper, $paper->version, $marks);
            $paper = $lifecycle->submit($teacher, $paper);
            $lifecycle->verify($anjali, $paper);
            $locked[] = (string) $paper->getKey();
        }
        $lifecycle->lock($clerk, $locked);

        $blocks = array_map(fn (string $type) => ['type' => $type, 'options' => []], (array) config('reports.template_presets.cbse_1_8.blocks'));
        ReportTemplate::create(['name' => 'Classes 6 to 8', 'grades' => ['6', '7', '8'], 'blocks' => $blocks, 'preset' => 'cbse_1_8']);

        $cards = app(ReportCards::class);
        $cards->startGenerate($principal, $section);
        $made = ReportCard::query()->where('section_id', (string) $section->getKey())->orderBy('student_name')->get();
        foreach ($made->take(2) as $n => $card) {
            $cards->saveRemarks($anjali, $card, [
                'class_teacher' => $n === 0 ? 'Works steadily and helps classmates with Mathematics.' : 'Curious in Science; keep practising written answers.',
                'co_scholastic' => ['discipline' => 'A', 'art_education' => $n === 0 ? 'B' : 'A'],
            ]);
        }
        $first = $made->first();
        if ($first instanceof ReportCard) {
            $cards->review($principal, ReportCard::query()->findOrFail($first->getKey()));
            $run = $cards->startPublish($principal, $section);
            if ($run->status === 'failed') {
                $this->command?->warn('The report card PDF could not be made (is the gotenberg container running?). The card stays reviewed; publish it from the Report cards screen.');
            }
        }
    }
}
