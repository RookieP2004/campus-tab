<?php

namespace App\Modules\Reports\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Attendance\Domain\AttendanceRegister;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Domain\Scopes\WingScope;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\Families;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use App\Modules\Reports\Domain\Pdf\PdfRenderer;
use App\Modules\Reports\Jobs\ProcessReportCardRun;
use App\Modules\Reports\Models\ReportCard;
use App\Modules\Reports\Models\ReportCardRun;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The report card workflow for one section:
 *
 *   generate (background)  draft cards from LOCKED papers only, one per
 *                          student; a draft or reviewed card is recomputed,
 *                          keeping its remarks; a published card is never
 *                          touched (it changes only by re-issue)
 *   remarks                the class teacher (class remark, co-scholastic
 *                          grades) and subject teachers (their subject's
 *                          remark); a change sends a reviewed card back to draft
 *   review                 the Principal (results.publish) marks it reviewed
 *   publish (background)   freezes the card, makes its random verification
 *                          code, renders the PDF with the QR code, stores it
 *                          under the school's own folder and tells the
 *                          family only that a report card is ready
 *   re-issue (background)  the Principal only, with a typed reason: a new
 *                          version from the current locked marks; the old
 *                          version stays (marked replaced, still verifiable);
 *                          the family is told only that a new version is available
 */
class ReportCards
{
    public function __construct(
        private readonly ResultBuilder $results,
        private readonly CardRenderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly AccessControl $access,
        private readonly AttendanceRegister $register,
        private readonly SchoolConfigService $configs,
        private readonly TenantContext $tenant,
        private readonly Notifier $notifier,
        private readonly Families $families,
        private readonly AuditRecorder $audit,
    ) {}

    public function mayManage(User $user, Section $section): bool
    {
        return $this->access->allows($user, 'results.publish', self::sectionProbe($section));
    }

    /** Re-issuing a published card is the Principal's alone (owner's rule 5). */
    public function mayReissue(User $user): bool
    {
        return in_array(Role::Principal->value, $user->roles ?? [], true) && $this->access->allows($user, 'report_cards.reissue');
    }

    public function startGenerate(User $user, Section $section): ReportCardRun
    {
        $this->assertManager($user, $section);
        $this->results->scheme($section);
        $this->results->template($section);

        return $this->queue('generate', $user, $section, []);
    }

    public function startPublish(User $user, Section $section): ReportCardRun
    {
        $this->assertManager($user, $section);
        $ids = ReportCard::query()->where('section_id', (string) $section->getKey())->where('year_id', $section->year_id)->where('status', ReportCard::REVIEWED)
            ->get()->map(fn (ReportCard $c) => (string) $c->getKey())->all();

        if ($ids === []) {
            throw new ApiException('nothing_to_publish', 'No reviewed report cards to publish in this section. The Principal reviews each card first.', 409);
        }

        return $this->queue('publish', $user, $section, $ids);
    }

    public function startReissue(User $user, ReportCard $card, string $reason): ReportCardRun
    {
        if (! $this->mayReissue($user)) {
            throw new ApiException('principal_only', 'Only the Principal can re-issue a published report card.', 403);
        }
        if ($card->status !== ReportCard::PUBLISHED) {
            throw new ApiException('not_current_version', 'Only the current published version can be re-issued.', 409, ['status' => $card->status]);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < (int) config('reports.reissue_reason_min', 10)) {
            throw new ApiException('reason_required', 'Write why the card is being re-issued (at least '.config('reports.reissue_reason_min', 10).' characters).', 422);
        }
        if (ReportCardRun::query()->where('kind', 'reissue')->whereIn('status', ['queued', 'running'])->where('card_ids', (string) $card->getKey())->exists()) {
            throw new ApiException('reissue_in_progress', 'This card is already being re-issued.', 409);
        }
        $section = Section::query()->findOrFail($card->section_id);
        $card->fill(['history' => [...$card->history, $this->entry('reissue_requested', $user, $reason)]])->save();
        $this->audit->record('report_card.reissue_requested', 'academic', 'report_card', (string) $card->getKey(), null, ['version' => $card->version], ['reason' => $reason]);

        return $this->queue('reissue', $user, $section, [(string) $card->getKey()], $reason);
    }

    /**
     * Runs a queued job (called by ProcessReportCardRun, inside the school).
     */
    public function process(ReportCardRun $run): void
    {
        $run->fill(['status' => 'running'])->save();

        try {
            $section = Section::query()->findOrFail($run->section_id);
            match ($run->kind) {
                'generate' => $this->generate($run, $section),
                'publish' => $this->publish($run, $section),
                'reissue' => $this->reissue($run, $section),
                default => null,
            };
            $run->fill(['status' => 'done'])->save();
        } catch (Throwable $e) {
            report($e);
            $run->fill(['status' => 'failed', 'error' => $e instanceof ApiException ? $e->getMessage() : 'Something went wrong while making the report cards. Try again; if it keeps failing, tell the school office.'])->save();
        }
    }

    /**
     * @param  array{class_teacher?: string|null, subjects?: array<string, string|null>, co_scholastic?: array<string, string|null>}  $input
     */
    public function saveRemarks(User $user, ReportCard $card, array $input): ReportCard
    {
        if (! in_array($card->status, [ReportCard::DRAFT, ReportCard::REVIEWED], true)) {
            throw new ApiException('card_published', 'A published card does not change. The Principal can re-issue it with a reason.', 409);
        }
        $max = (int) config('reports.remark_max', 400);
        $remarks = $card->remarks;
        $co = $card->co_scholastic;

        if (array_key_exists('class_teacher', $input) || array_key_exists('co_scholastic', $input)) {
            if (! $this->mayRemarkClass($user, $card)) {
                throw new ApiException('permission_denied', 'Only the class teacher (or the Principal) writes the class remark and co-scholastic grades.', 403);
            }
        }
        if (array_key_exists('class_teacher', $input)) {
            $text = trim((string) ($input['class_teacher'] ?? ''));
            if (mb_strlen($text) > $max) {
                throw new ApiException('remark_too_long', "A remark can be at most {$max} characters.", 422);
            }
            $remarks['class_teacher'] = $text === '' ? null : $text;
        }
        foreach ((array) ($input['co_scholastic'] ?? []) as $area => $grade) {
            if (! array_key_exists($area, (array) config('reports.co_scholastic.areas'))) {
                throw new ApiException('area_unknown', 'Unknown co-scholastic area.', 422, ['area' => $area]);
            }
            if ($grade === null || $grade === '') {
                unset($co[$area]);

                continue;
            }
            if (! in_array($grade, (array) config('reports.co_scholastic.grades'), true)) {
                throw new ApiException('grade_invalid', 'Use one of the co-scholastic grades A to E.', 422, ['area' => $area]);
            }
            $co[$area] = $grade;
        }
        foreach ((array) ($input['subjects'] ?? []) as $subjectId => $text) {
            $probe = self::subjectProbe($card, (string) $subjectId);
            if (! $this->access->allows($user, 'remarks.edit', $probe)) {
                throw new ApiException('permission_denied', 'You may write remarks only for your own subjects.', 403);
            }
            $text = trim((string) ($text ?? ''));
            if (mb_strlen($text) > $max) {
                throw new ApiException('remark_too_long', "A remark can be at most {$max} characters.", 422);
            }
            $subjects = $remarks['subjects'] ?? [];
            if ($text === '') {
                unset($subjects[$subjectId]);
            } else {
                $subjects[$subjectId] = $text;
            }
            $remarks['subjects'] = $subjects;
        }

        $card->fill([
            'remarks' => $remarks,
            'co_scholastic' => $co,
            'status' => ReportCard::DRAFT,
            'history' => [...$card->history, $this->entry($card->status === ReportCard::REVIEWED ? 'remarks_changed_after_review' : 'remarks', $user)],
        ])->save();

        return $card;
    }

    /**
     * The class remark and co-scholastic grades: the class teacher, a Vice
     * Principal for the wing, or school-wide (never a subject teacher).
     */
    public function mayRemarkClass(User $user, ReportCard $card): bool
    {
        $probe = self::classProbe($card);
        foreach ($this->access->scopesFor($user, 'remarks.edit') as $scope) {
            $ok = match ($scope) {
                'school' => true,
                'wing' => app(WingScope::class)->covers($user, $probe),
                'class' => app(ClassScope::class)->covers($user, $probe),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    public function review(User $user, ReportCard $card): ReportCard
    {
        $section = Section::query()->findOrFail($card->section_id);
        $this->assertManager($user, $section);
        if ($card->status !== ReportCard::DRAFT) {
            throw new ApiException('card_not_draft', 'Only a draft card can be marked reviewed.', 409, ['status' => $card->status]);
        }
        $card->fill(['status' => ReportCard::REVIEWED, 'history' => [...$card->history, $this->entry('reviewed', $user)]])->save();

        return $card;
    }

    private function generate(ReportCardRun $run, Section $section): void
    {
        $scheme = $this->results->scheme($section);
        $template = $this->results->template($section);
        $year = AcademicYear::query()->findOrFail($section->year_id);
        $roll = $this->register->roll($section);
        $entries = $this->results->entries($section)['entries'];
        $attendance = $this->results->attendance($section);
        $students = Student::query()->whereIn('_id', array_column($roll, 'id'))->get()->keyBy(fn ($s) => (string) $s->getKey());
        $run->fill(['total' => count($roll)])->save();
        $ids = [];

        foreach ($roll as $i => $row) {
            $latest = ReportCard::query()->where('student_id', $row['id'])->where('year_id', $section->year_id)->orderBy('version', 'desc')->first();
            if ($latest instanceof ReportCard && $latest->isPublic()) {
                $this->progress($run, $i + 1, $ids);

                continue;
            }
            $card = $latest ?? new ReportCard(['student_id' => $row['id'], 'year_id' => $section->year_id, 'version' => 1]);
            $card->fill([
                'year_name' => $year->name,
                'student_name' => $row['name'],
                'student_user_id' => $students[$row['id']]->user_id ?? null,
                'section_id' => (string) $section->getKey(),
                'section_label' => $section->label,
                'wing' => $section->wing,
                'template_id' => (string) $template->getKey(),
                'scheme_id' => (string) $scheme->getKey(),
                'snapshot' => $this->results->compute($scheme, $entries[$row['id']] ?? []),
                'attendance' => $attendance[$row['id']] ?? ['present_halves' => 0, 'working' => 0],
                'status' => ReportCard::DRAFT,
                'history' => [...$card->history, $this->entry($card->exists ? 'recomputed' : 'generated', null)],
            ])->save();
            $ids[] = (string) $card->getKey();
            $this->progress($run, $i + 1, $ids);
        }
        $this->progress($run, count($roll), $ids, true);
    }

    private function publish(ReportCardRun $run, Section $section): void
    {
        $run->fill(['total' => count($run->card_ids)])->save();
        $published = [];

        foreach ($run->card_ids as $i => $id) {
            $card = ReportCard::query()->find($id);
            if ($card instanceof ReportCard && $card->status === ReportCard::REVIEWED) {
                $this->issue($card, $run->requested_by, 'published');
                $published[] = $card->student_id;
            }
            $this->progress($run, $i + 1);
        }
        $this->progress($run, count($run->card_ids), null, true);

        $this->tell($published, 'report_card.published', 'A report card is ready');
    }

    private function reissue(ReportCardRun $run, Section $section): void
    {
        $old = ReportCard::query()->findOrFail($run->card_ids[0]);
        if ($old->status !== ReportCard::PUBLISHED) {
            throw new ApiException('not_current_version', 'Only the current published version can be re-issued.', 409);
        }
        $run->fill(['total' => 1])->save();
        $scheme = $this->results->scheme($section);
        $template = $this->results->template($section);
        $entries = $this->results->entries($section)['entries'];
        $reason = (string) ($run->note ?? '');

        $new = new ReportCard(array_merge(collect($old->getAttributes())->only([
            'year_id', 'year_name', 'student_id', 'student_name', 'student_user_id', 'section_id', 'section_label', 'wing', 'remarks', 'co_scholastic',
        ])->all(), [
            'version' => $old->version + 1,
            'template_id' => (string) $template->getKey(),
            'scheme_id' => (string) $scheme->getKey(),
            'snapshot' => $this->results->compute($scheme, $entries[$old->student_id] ?? []),
            'attendance' => $this->results->attendance($section)[$old->student_id] ?? $old->attendance,
            'status' => ReportCard::REVIEWED,
            'reissue_reason' => $reason,
            'replaces_id' => (string) $old->getKey(),
            'history' => [$this->entry('reissued', $run->requested_by, $reason)],
        ]));
        // The new version's PDF is rendered first; then, together, the new
        // version goes live and the old one is marked replaced.
        $this->issue($new, $run->requested_by, 'published', function (ReportCard $issued) use ($old, $run): void {
            $old->fill([
                'status' => ReportCard::REPLACED,
                'replaced_by_id' => (string) $issued->getKey(),
                'history' => [...$old->history, $this->entry('replaced', $run->requested_by)],
            ])->save();
        });
        $run->fill(['done' => 1, 'card_ids' => [(string) $old->getKey(), (string) $new->getKey()]])->save();

        $this->tell([$old->student_id], 'report_card.new_version', 'A new version of a report card is available');
    }

    /**
     * Freezes a card: a random verification code, the PDF with its QR code,
     * then the status change (in a transaction with $alsoInside).
     *
     * @param  array{id: string, name: string}  $by
     */
    private function issue(ReportCard $card, array $by, string $action, ?\Closure $alsoInside = null): void
    {
        $code = rtrim(strtr(base64_encode(random_bytes((int) config('reports.verification.code_bytes', 32))), '+/', '-_'), '=');
        $tenantId = $this->tenant->require();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $config = $this->configs->current()->body;
        $template = $this->results->template(Section::query()->findOrFail($card->section_id));
        $student = Student::query()->find($card->student_id);
        $enrolment = Enrolment::query()->where('student_id', $card->student_id)->where('year_id', $card->year_id)->first();

        if (! $card->exists) {
            $card->save();
        }
        $card->issued_at = now();
        $html = $this->renderer->html($card, $template, [
            'name' => (string) ($config['school']['name'] ?? $tenant->name),
            'logo_path' => self::logoPath($tenantId),
            'language' => (string) ($config['languages']['default'] ?? 'en'),
        ], [
            'admission_no' => $student?->admission_no,
            'roll_no' => $enrolment?->roll_no,
        ], self::verifyUrl($tenant->code, $code));
        $bytes = $this->pdf->render($html);
        $path = 'tenants/'.$tenantId.'/report-cards/'.$card->year_id.'/'.$card->student_id.'/v'.$card->version.'-'.Str::lower(Str::random(8)).'.pdf';
        Storage::disk('local')->put($path, $bytes);

        DB::connection('mongodb')->transaction(function () use ($card, $by, $action, $code, $path, $bytes, $alsoInside): void {
            $card->fill([
                'status' => ReportCard::PUBLISHED,
                'verify_hash' => hash('sha256', $code),
                'pdf' => ['path' => $path, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)],
                'published_by' => $by,
                'history' => [...$card->history, $this->entry($action, $by)],
            ])->save();
            if ($alsoInside !== null) {
                $alsoInside($card);
            }
        }, 5);
    }

    /**
     * @param  list<string>  $studentIds
     */
    private function tell(array $studentIds, string $type, string $title): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant && $studentIds !== []) {
            $this->notifier->notify($tenant, $this->families->users(array_values(array_unique($studentIds))), $type, $title, null, '/results?tab=cards', []);
        }
    }

    /**
     * @param  list<string>  $cardIds
     */
    private function queue(string $kind, User $user, Section $section, array $cardIds, ?string $note = null): ReportCardRun
    {
        $run = ReportCardRun::create([
            'kind' => $kind,
            'section_id' => (string) $section->getKey(),
            'year_id' => $section->year_id,
            'status' => 'queued',
            'requested_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            'card_ids' => $cardIds,
            'note' => $note,
        ]);
        ProcessReportCardRun::dispatch($this->tenant->require(), (string) $run->getKey());

        return ReportCardRun::query()->findOrFail($run->getKey());
    }

    /**
     * Progress for the screen: written every few cards (and at the end), not
     * after each one, and without an audit entry per tick.
     *
     * @param  list<string>|null  $ids
     */
    private function progress(ReportCardRun $run, int $done, ?array $ids = null, bool $final = false): void
    {
        if (! $final && $done % 5 !== 0) {
            return;
        }
        ReportCardRun::query()->where('_id', $run->getKey())->update(array_filter(['done' => $done, 'card_ids' => $ids], fn ($v) => $v !== null));
        $run->done = $done;
        if ($ids !== null) {
            $run->card_ids = $ids;
        }
    }

    private function assertManager(User $user, Section $section): void
    {
        if (! $this->mayManage($user, $section)) {
            throw new ApiException('permission_denied', 'Only the Principal (or a Vice Principal for their wing) makes and publishes report cards.', 403);
        }
    }

    /**
     * @param  array{id: string, name: string}|null  $by
     * @return array<string, mixed>
     */
    private function entry(string $action, User|array|null $by, ?string $note = null): array
    {
        $who = $by instanceof User ? ['id' => (string) $by->getKey(), 'name' => $by->name] : $by;

        return ['action' => $action, 'by' => $who, 'note' => $note, 'at' => now()->toIso8601String()];
    }

    public static function logoPath(string $tenantId): ?string
    {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
            $path = 'tenants/'.$tenantId.'/branding/logo.'.$ext;
            if (Storage::disk('local')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function verifyUrl(string $schoolCode, string $code): string
    {
        return rtrim((string) config('identity.frontend_url'), '/').'/verify/'.rawurlencode($schoolCode).'/'.$code;
    }

    public static function sectionProbe(Section $section): AccessSubject
    {
        return Assessments::probe((string) $section->getKey(), $section->wing, '');
    }

    public static function classProbe(ReportCard $card): AccessSubject
    {
        return new class($card) implements AccessSubject
        {
            public function __construct(private readonly ReportCard $card) {}

            public function accessFacts(): array
            {
                return ['section_id' => $this->card->section_id, 'wing' => $this->card->wing];
            }
        };
    }

    public static function subjectProbe(ReportCard $card, string $subjectId): AccessSubject
    {
        return Assessments::probe($card->section_id, $card->wing, $card->section_id.':'.$subjectId);
    }
}
