<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Reports\Domain\CardAccess;
use App\Modules\Reports\Domain\ReportCards;
use App\Modules\Reports\Domain\ResultBuilder;
use App\Modules\Reports\Models\ReportCard;
use App\Modules\Reports\Models\ReportCardRun;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Report cards.
 *
 *   GET  /report-cards/sections                 sections I may work on, with counts
 *   GET  /report-cards/sections/{id}            the section's cards (latest version each), papers locked or not, the last run
 *   POST /report-cards/sections/{id}/generate   background: draft cards from locked papers
 *   POST /report-cards/sections/{id}/publish    background: publish every reviewed card (PDF + QR)
 *   GET  /report-cards/runs/{id}                a background run's progress
 *   GET  /report-cards/{id}
 *   PUT  /report-cards/{id}/remarks { class_teacher?, co_scholastic?, subjects? }
 *   POST /report-cards/{id}/review
 *   POST /report-cards/{id}/reissue { reason }   the Principal only
 *   POST /report-cards/{id}/link                 a 5-minute signed link to the PDF
 *   GET  /report-cards/students/{id}             a student's cards (families: published versions only)
 */
class ReportCardController
{
    public function __construct(
        private readonly ReportCards $cards,
        private readonly CardAccess $access,
        private readonly ResultBuilder $results,
    ) {}

    public function sections(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $year = AcademicYear::current();
        $sections = $year === null ? collect() : Section::query()->where('year_id', (string) $year->getKey())->get()
            ->filter(fn (Section $s) => $this->access->mayViewSection($user, $s))->sortBy('label', SORT_NATURAL)->values();
        $counts = ReportCard::query()->whereIn('section_id', $sections->map(fn ($s) => (string) $s->getKey())->all())->where('status', '!=', ReportCard::REPLACED)->get()
            ->groupBy('section_id')->map(fn ($g) => $g->countBy('status')->all());

        return response()->json(['data' => $sections->map(fn (Section $s) => [
            'id' => (string) $s->getKey(), 'label' => $s->label, 'counts' => (object) ($counts[(string) $s->getKey()] ?? []),
        ])->values()]);
    }

    public function section(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $section = $this->findSection($user, $id);
        $cards = ReportCard::query()->where('section_id', $id)->where('year_id', $section->year_id)->where('status', '!=', ReportCard::REPLACED)->orderBy('student_name')->get();
        $entries = $this->results->entries($section);
        $run = ReportCardRun::query()->where('section_id', $id)->orderBy('created_at', 'desc')->first();
        $manage = $this->cards->mayManage($user, $section);

        return response()->json(['data' => [
            'section' => ['id' => $id, 'label' => $section->label],
            'papers' => ['locked' => $entries['locked'], 'not_locked' => $entries['not_locked']],
            'cards' => $cards->map(fn (ReportCard $c) => $this->access->present($user, $c, $this->cards))->values(),
            'run' => $run instanceof ReportCardRun ? self::run($run) : null,
            'can' => ['generate' => $manage, 'publish' => $manage],
        ]]);
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => self::run($this->cards->startGenerate($user, $this->findSection($user, $id)))], 202);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => self::run($this->cards->startPublish($user, $this->findSection($user, $id)))], 202);
    }

    public function showRun(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $run = ReportCardRun::query()->findOrFail($id);
        $this->findSection($user, $run->section_id);

        return response()->json(['data' => self::run($run)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->access->present($user, $this->find($user, $id), $this->cards)]);
    }

    public function remarks(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate([
            'class_teacher' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'co_scholastic' => ['sometimes', 'array'],
            'co_scholastic.*' => ['nullable', 'string', 'max:4'],
            'subjects' => ['sometimes', 'array'],
            'subjects.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $card = $this->cards->saveRemarks($user, $this->find($user, $id), $input);

        return response()->json(['data' => $this->access->present($user, $card, $this->cards)]);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->access->present($user, $this->cards->review($user, $this->find($user, $id)), $this->cards)]);
    }

    public function reissue(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return response()->json(['data' => self::run($this->cards->startReissue($user, $this->find($user, $id), $reason))], 202);
    }

    public function link(Request $request, string $id, TenantContext $context): JsonResponse
    {
        $card = $this->find($this->user($request), $id);
        if ($card->pdf === null) {
            throw new ApiException('no_pdf', 'This card has no PDF yet: it is made when the card is published.', 409);
        }

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('files.report-card', now()->addMinutes((int) config('reports.link_minutes', 5)), [
                'tenant' => $context->require(), 'card' => (string) $card->getKey(),
            ], absolute: false),
        ]]);
    }

    /** The PDF, only through the signed, short-lived link above. */
    public function file(string $tenant, string $card, TenantContext $context): Response
    {
        return $context->run($tenant, function () use ($card): Response {
            $record = ReportCard::query()->find($card);
            if (! $record instanceof ReportCard || $record->pdf === null || ! Storage::disk('local')->exists($record->pdf['path'])) {
                throw new ApiException('not_found', 'The record was not found.', 404);
            }
            $name = preg_replace('/[^A-Za-z0-9 -]/', '', $record->student_name) ?: 'student';

            return response((string) Storage::disk('local')->get($record->pdf['path']), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Report card '.$name.' '.$record->year_name.' v'.$record->version.'.pdf"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    public function student(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);
        $student = Student::query()->findOrFail($id);
        $cards = ReportCard::query()->where('student_id', $id)->orderBy('year_name', 'desc')->orderBy('version', 'desc')->get()
            ->filter(fn (ReportCard $c) => $this->access->mayView($user, $c))->values();

        if ($cards->isEmpty() && ! $access->allows($user, 'results.view', $student) && ! $access->allows($user, 'results.publish')) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => [
            'student' => ['id' => $id, 'name' => $student->name],
            'cards' => $cards->map(fn (ReportCard $c) => $this->access->present($user, $c, $this->cards))->values(),
        ]]);
    }

    /** A signed-in student's own published cards. */
    public function mine(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $student = Student::query()->where('user_id', (string) $user->getKey())->first();
        if (! $student instanceof Student) {
            throw new ApiException('not_student', 'Only a student account has its own report cards.', 404);
        }
        $cards = ReportCard::query()->where('student_id', (string) $student->getKey())->orderBy('year_name', 'desc')->orderBy('version', 'desc')->get()
            ->filter(fn (ReportCard $c) => $this->access->mayView($user, $c))->values();

        return response()->json(['data' => [
            'student' => ['id' => (string) $student->getKey(), 'name' => $student->name],
            'cards' => $cards->map(fn (ReportCard $c) => $this->access->present($user, $c, $this->cards))->values(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(ReportCardRun $run): array
    {
        return [
            'id' => (string) $run->getKey(), 'kind' => $run->kind, 'status' => $run->status, 'total' => $run->total, 'done' => $run->done,
            'error' => $run->status === 'failed' ? $run->error : null, 'at' => $run->created_at?->toIso8601String(),
        ];
    }

    private function find(User $user, string $id): ReportCard
    {
        $card = ReportCard::query()->find($id);
        if (! $card instanceof ReportCard || ! $this->access->mayView($user, $card)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return $card;
    }

    private function findSection(User $user, string $id): Section
    {
        $section = Section::query()->find($id);
        if (! $section instanceof Section || ! $this->access->mayViewSection($user, $section)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return $section;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        return $user;
    }
}
