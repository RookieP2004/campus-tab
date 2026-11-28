<?php

namespace App\Modules\Privacy\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Privacy\Models\PrivacyNotice;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;

/**
 * Consent per purpose, given by the parent from their own signed-in account
 * (owner's rule, Phase 9): that is the verified parental consent for a
 * child's data. Each answer is a new record with the notice version the
 * parent saw. Withdrawing consent for PEN or APAAR also removes that number
 * from the child's record at once.
 */
class Consents
{
    /**
     * The parent's children with every purpose and the current answer.
     *
     * @return array{notice: array{version: int, title: string}|null, purposes: array<string, string>, children: list<array{id: string, name: string, consents: array<string, array{given: bool, at: string, version: string}|null>}>}
     */
    public function forParent(User $parent): array
    {
        $children = [];
        foreach ($this->children($parent) as $student) {
            $answers = [];
            foreach (Consent::PURPOSES as $purpose) {
                $latest = Consent::query()->where('student_id', (string) $student->getKey())->where('purpose', $purpose)
                    ->where('method', Consent::PARENT_ACCOUNT)->orderBy('created_at', 'desc')->first();
                $answers[$purpose] = $latest instanceof Consent ? ['given' => $latest->given, 'at' => $latest->at, 'version' => $latest->notice_version] : null;
            }
            $children[] = ['id' => (string) $student->getKey(), 'name' => $student->name, 'consents' => $answers];
        }
        $notice = PrivacyNotice::current();

        return [
            'notice' => $notice instanceof PrivacyNotice ? ['version' => (int) $notice->version, 'title' => $notice->title] : null,
            'purposes' => (array) config('privacy.purposes'),
            'children' => $children,
        ];
    }

    public function answer(User $parent, string $studentId, string $purpose, bool $given, int $noticeVersion): Consent
    {
        if (! in_array($purpose, Consent::PURPOSES, true)) {
            throw new ApiException('purpose_unknown', 'That is not a consent purpose.', 422);
        }
        $student = $this->children($parent)->first(fn (Student $s) => (string) $s->getKey() === $studentId)
            ?? throw new ApiException('not_your_child', 'You can answer only for your own children.', 403);
        $notice = PrivacyNotice::current() ?? throw new ApiException('no_privacy_notice', 'The school has not published its privacy notice yet. Ask the school office.', 409);
        if ((int) $notice->version !== $noticeVersion) {
            throw new ApiException('notice_changed', 'The privacy notice changed. Read the new version, then answer again.', 409, ['version' => (int) $notice->version]);
        }
        $guardian = Guardian::query()->where('user_id', (string) $parent->getKey())->first();

        $consent = Consent::create([
            'student_id' => (string) $student->getKey(),
            'guardian_id' => $guardian instanceof Guardian ? (string) $guardian->getKey() : null,
            'user_id' => (string) $parent->getKey(),
            'purpose' => $purpose,
            'notice_version' => (string) $notice->version,
            'given' => $given,
            'at' => now()->toIso8601String(),
            'method' => Consent::PARENT_ACCOUNT,
            'recorded_by' => ['id' => (string) $parent->getKey(), 'name' => $parent->name],
        ]);

        // Withdrawn: stop keeping the number now.
        if (! $given && in_array($purpose, ['pen', 'apaar'], true) && ! empty($student->ids[$purpose])) {
            $student->fill(['ids' => array_merge($student->ids ?? [], [$purpose => null])])->save();
        }

        return $consent;
    }

    /** @return Collection<int, Student> */
    private function children(User $parent): Collection
    {
        $ids = Guardian::query()->where('user_id', (string) $parent->getKey())->get()->flatMap(fn (Guardian $g) => $g->student_ids)->unique()->values()->all();

        return $ids === [] ? collect() : Student::query()->whereIn('_id', $ids)->where('status', 'active')->orderBy('name')->get();
    }
}
