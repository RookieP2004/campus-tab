<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Assessment\Domain\PaperAccess;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Tests and marks cards (Phase 6):
 *
 *   marks_to_enter        teachers: papers open for my marks
 *   marks_to_verify       class teachers: papers submitted for my class
 *   marks_progress        Principal and Clerk: papers by state this year
 *   self_approved_marks   Chief: corrections the Principal raised and approved themself
 */
class AssessmentCards implements CardProvider
{
    public function __construct(
        private readonly PaperAccess $papers,
        private readonly SchoolConfigService $configs,
    ) {}

    public function cards(User $user, DashboardContext $context): array
    {
        $year = AcademicYear::current();
        if (! (bool) ($this->configs->current()->body['modules']['assessment'] ?? false) || ! $year instanceof AcademicYear) {
            return [];
        }

        $cards = [];
        $all = Paper::query()->where('year_id', (string) $year->getKey())->whereIn('status', [Paper::OPEN, Paper::SUBMITTED])->get();

        // Teachers only: the office (school-wide entry) follows progress in marks_progress instead.
        if (($context->can('marks.enter', 'subject') || $context->can('marks.enter', 'class')) && ! $context->can('marks.enter', 'school')) {
            $mine = $all->filter(fn (Paper $p) => $p->status === Paper::OPEN && $this->papers->mayEnter($user, $p));
            if ($mine->isNotEmpty()) {
                $cards[] = [
                    'id' => 'marks_to_enter', 'kind' => 'todo', 'title' => 'dashboard.cards.marksToEnter', 'priority' => 3, 'link' => '/tests',
                    'data' => ['count' => $mine->count(), 'items' => $mine->take(5)->map(fn (Paper $p) => [
                        'kind' => 'marks', 'label' => $p->subject_name.' '.$p->section_label, 'detail' => $p->date, 'link' => '/tests/papers/'.$p->getKey(),
                    ])->values()->all()],
                ];
            }
        }

        if ($context->can('marks.verify', 'class') && ! $context->can('marks.verify', 'school')) {
            $toVerify = $all->filter(fn (Paper $p) => $p->status === Paper::SUBMITTED && $this->papers->mayVerify($user, $p));
            if ($toVerify->isNotEmpty()) {
                $cards[] = [
                    'id' => 'marks_to_verify', 'kind' => 'todo', 'title' => 'dashboard.cards.marksToVerify', 'priority' => 3, 'link' => '/tests',
                    'data' => ['count' => $toVerify->count(), 'items' => $toVerify->take(5)->map(fn (Paper $p) => [
                        'kind' => 'marks', 'label' => $p->subject_name.' '.$p->section_label, 'detail' => $p->date, 'link' => '/tests/papers/'.$p->getKey(),
                    ])->values()->all()],
                ];
            }
        }

        if ($context->can('marks.lock', 'school')) {
            $counts = Paper::query()->where('year_id', (string) $year->getKey())->get(['status', 'published_at'])
                ->countBy(fn (Paper $p) => $p->published_at !== null ? 'published' : $p->status)->all();
            $cards[] = [
                'id' => 'marks_progress', 'kind' => 'list', 'title' => 'dashboard.cards.marksProgress', 'priority' => 8, 'link' => '/tests',
                'data' => ['items' => array_map(fn (string $status) => [
                    'label' => $status, 'detail' => (string) ($counts[$status] ?? 0), 'label_key' => 'tests.paperStatus.'.$status, 'link' => null,
                ], ['open', 'submitted', 'verified', 'locked', 'published'])],
            ];
        }

        if ($context->can('audit.view', 'school')) {
            $self = CorrectionRequest::query()->where('self_approved', true)->orderBy('decided_at', 'desc')->limit(5)->get();
            if ($self->isNotEmpty()) {
                $cards[] = [
                    'id' => 'self_approved_marks', 'kind' => 'list', 'title' => 'dashboard.cards.selfApprovedMarks', 'priority' => 4, 'link' => '/tests/corrections?self_approved=1',
                    'data' => ['count' => CorrectionRequest::query()->where('self_approved', true)->count(), 'items' => $self->map(fn (CorrectionRequest $c) => [
                        'label' => $c->label.' · '.$c->student_name, 'detail' => null, 'by' => $c->decided_by['name'] ?? null, 'at' => $c->decided_at?->toIso8601String(),
                        'tone' => 'warning', 'link' => '/tests/corrections?self_approved=1',
                    ])->values()->all()],
                ];
            }
        }

        return $cards;
    }
}
