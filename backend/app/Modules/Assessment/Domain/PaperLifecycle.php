<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A paper's lifecycle, each move permission-checked:
 *
 *   draft ──open──▶ open ──submit──▶ submitted ──verify──▶ verified ──lock──▶ locked ──publish──▶ (published)
 *                    ▲                   │  │                  │
 *                    └──── send back ────┘  └──── send back ───┘
 *
 *  - submit needs every student filled (a mark, AB or ML).
 *  - A class test with policies.marks.auto_publish_class_tests is published
 *    at submit; its marks stay editable until it is locked (owner's
 *    decision). Each change is audited.
 *  - lock and publish run in MongoDB transactions (owner's rule), for one
 *    paper or every eligible paper of an assessment at once.
 *  - Publishing tells students and parents only that results are out, never
 *    the marks (owner's rule).
 */
class PaperLifecycle
{
    public function __construct(
        private readonly PaperAccess $access,
        private readonly Markbook $markbook,
        private readonly Notifier $notifier,
        private readonly SchoolConfigService $configs,
        private readonly TenantContext $tenant,
    ) {}

    public function open(User $user, Paper $paper): Paper
    {
        $this->assertStatus($paper, [Paper::DRAFT], 'open');
        $this->assertAllowed($this->access->mayOpen($user, $paper));

        return $this->move($paper, Paper::OPEN, $user, 'opened');
    }

    public function submit(User $user, Paper $paper): Paper
    {
        $this->assertStatus($paper, [Paper::OPEN], 'submit');
        $this->assertAllowed($this->access->mayEnter($user, $paper));

        $marks = $paper->marksByStudent();
        $missing = array_values(array_filter($this->markbook->roll($paper), fn (array $s) => ! Markbook::filled($marks[$s['id']] ?? [])));

        if ($missing !== []) {
            throw new ApiException('marks_incomplete', count($missing).' student(s) have no mark yet. Enter a mark, AB or ML for every student.', 422, [
                'missing' => array_column($missing, 'id'),
            ]);
        }

        $auto = $paper->class_test && (bool) ($this->configs->current()->body['policies']['marks']['auto_publish_class_tests'] ?? false);
        $saved = $this->move($paper, Paper::SUBMITTED, $user, $auto ? 'submitted_published' : 'submitted', $auto ? ['published_at' => now()] : []);

        if ($auto) {
            $this->tellResultsOut(collect([$saved]));
        }

        return $saved;
    }

    /** The class teacher (or the office) sends a paper back to the teacher, with a note. */
    public function sendBack(User $user, Paper $paper, string $note): Paper
    {
        $this->assertStatus($paper, [Paper::SUBMITTED, Paper::VERIFIED], 'send back');
        $this->assertAllowed($this->access->mayVerify($user, $paper) || $this->access->mayLock($user, $paper));

        if (mb_strlen(trim($note)) < 3) {
            throw new ApiException('note_required', 'Say what needs checking.', 422);
        }

        return $this->move($paper, Paper::OPEN, $user, 'sent_back', ['returned_note' => trim($note)], trim($note));
    }

    public function verify(User $user, Paper $paper): Paper
    {
        $this->assertStatus($paper, [Paper::SUBMITTED], 'verify');
        $this->assertAllowed($this->access->mayVerify($user, $paper));

        return $this->move($paper, Paper::VERIFIED, $user, 'verified', ['returned_note' => null]);
    }

    /**
     * Lock verified papers (one, or all of an assessment the person may lock)
     * in one transaction: either every paper locks or none does.
     *
     * @param  list<string>  $paperIds
     * @return Collection<int, Paper>
     */
    public function lock(User $user, array $paperIds): Collection
    {
        return $this->transaction(function () use ($user, $paperIds): Collection {
            $papers = Paper::query()->whereIn('_id', $paperIds)->get();

            foreach ($papers as $paper) {
                $this->assertStatus($paper, [Paper::VERIFIED], 'lock');
                $this->assertAllowed($this->access->mayLock($user, $paper));
                $paper->fill([
                    'status' => Paper::LOCKED,
                    'locked' => ['by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'at' => now()->toIso8601String(), 'version' => $paper->version],
                    'version' => $paper->version + 1,
                    'history' => [...$paper->history, $this->entry('locked', $user)],
                ])->save();
            }

            return $papers;
        });
    }

    /**
     * Publish locked papers in one transaction, then tell students and
     * parents that results are out.
     *
     * @param  list<string>  $paperIds
     * @return Collection<int, Paper>
     */
    public function publish(User $user, array $paperIds): Collection
    {
        $papers = $this->transaction(function () use ($user, $paperIds): Collection {
            $papers = Paper::query()->whereIn('_id', $paperIds)->get();

            foreach ($papers as $paper) {
                $this->assertStatus($paper, [Paper::LOCKED], 'publish');
                $this->assertAllowed($this->access->mayPublish($user, $paper));
                if ($paper->published_at !== null) {
                    continue;
                }
                $paper->fill(['published_at' => now(), 'history' => [...$paper->history, $this->entry('published', $user)]])->save();
            }

            return $papers;
        });

        $this->tellResultsOut($papers);

        return $papers;
    }

    /**
     * Papers of an assessment this person may move with $check.
     *
     * @param  Closure(Paper): bool  $check
     * @return list<string>
     */
    public function eligible(Assessment $assessment, string $status, Closure $check, ?string $sectionId = null): array
    {
        $query = Paper::query()->where('assessment_id', (string) $assessment->getKey())->where('status', $status);
        if ($sectionId !== null) {
            $query->where('section_id', $sectionId);
        }

        return $query->get()->filter($check)->map(fn (Paper $p) => (string) $p->getKey())->values()->all();
    }

    /**
     * Owner's rule: only "results are out", never the marks.
     *
     * @param  Collection<int, Paper>  $papers
     */
    public function tellResultsOut(Collection $papers): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if (! $tenant instanceof Tenant || $papers->isEmpty()) {
            return;
        }

        $sections = $papers->pluck('section_id')->unique()->values()->all();
        $year = $papers->first()->year_id;
        $studentIds = Enrolment::query()->where('year_id', $year)->whereIn('section_id', $sections)->pluck('student_id')->map(fn ($id) => (string) $id)->all();
        $students = Student::query()->whereIn('_id', $studentIds)->where('status', 'active')->get();

        $userIds = $students->pluck('user_id')->filter()->map(fn ($id) => (string) $id)->all();
        $guardianIds = $students->flatMap(fn (Student $s) => $s->guardian_ids ?? [])->unique()->values()->all();
        $userIds = [...$userIds, ...Guardian::query()->whereIn('_id', $guardianIds)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (string) $id)->all()];

        $users = User::query()->whereIn('_id', array_values(array_unique($userIds)))->get();
        $this->notifier->notify($tenant, $users, 'results.published', 'Results are out', null, '/results', []);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(Paper $paper, string $to, User $user, string $action, array $extra = [], ?string $note = null): Paper
    {
        $paper->fill(array_merge($extra, [
            'status' => $to,
            'version' => $paper->version + 1,
            'history' => [...$paper->history, $this->entry($action, $user, $note)],
        ]))->save();

        return $paper;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(Paper $paper, array $allowed, string $move): void
    {
        if ($paper->isLocked() && $move !== 'publish') {
            throw new ApiException('marks_locked', 'This paper is locked. A mark can change now only through a correction the Principal approves.', 409);
        }
        if (! in_array($paper->status, $allowed, true)) {
            throw new ApiException('paper_transition_invalid', "A {$paper->status} paper cannot be {$move}ed now.", 409, ['status' => $paper->status, 'move' => $move]);
        }
    }

    private function assertAllowed(bool $allowed): void
    {
        if (! $allowed) {
            throw new ApiException('permission_denied', 'You do not have permission to do this.', 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $action, User $user, ?string $note = null): array
    {
        return ['action' => $action, 'by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'note' => $note, 'at' => now()->toIso8601String()];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        return DB::connection('mongodb')->transaction($callback, 5);
    }
}
