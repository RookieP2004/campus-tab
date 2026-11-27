<?php

namespace App\Modules\Communication\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Communication\Models\Notice;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Support\Collection;

/**
 * Who a notice is for, and who may send to whom. All on the server.
 *
 *   school   everyone in the school
 *   role     accounts holding one of the named roles
 *   class    as "section", for every section of the named classes
 *   section  the section's students and their parents, its class teacher
 *            and the subject teachers who teach there
 *
 * Sending (notices.send):
 *   school scope   any audience
 *   wing scope     classes and sections in the sender's wings
 *   class scope    their own class sections
 *   subject scope  sections they teach
 * Role audiences need school scope.
 */
class NoticeAudience
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @param  array{type: string, refs: list<string>}  $audience
     * @return string the scope the sender used
     */
    public function assertMaySend(User $sender, array $audience): string
    {
        $scopes = $this->access->scopesFor($sender, 'notices.send');

        if (in_array('school', $scopes, true)) {
            return 'school';
        }

        if (in_array($audience['type'], ['school', 'role'], true) || $audience['refs'] === []) {
            throw self::forbidden();
        }

        $sections = $this->sectionsOf($audience);
        $mine = $sender->accessScope();

        $allowed = [
            'wing' => fn (): array => Section::query()->whereIn('wing', $mine['wings'])->get()->map(fn (Section $s) => (string) $s->getKey())->all(),
            'class' => fn (): array => $mine['class_sections'],
            'subject' => fn (): array => array_map(fn (string $k) => strtok($k, ':') ?: '', $mine['subject_sections']),
        ];

        foreach (['wing', 'class', 'subject'] as $scope) {
            if (in_array($scope, $scopes, true) && array_diff($sections, $allowed[$scope]()) === []) {
                // Class and subject teachers send to sections only, not whole classes.
                if ($scope !== 'wing' && $audience['type'] !== 'section') {
                    continue;
                }

                return $scope;
            }
        }

        throw self::forbidden();
    }

    /**
     * Every active account the notice reaches.
     *
     * @return Collection<int, User>
     */
    public function recipients(Notice $notice): Collection
    {
        $audience = $notice->audience;

        $query = User::query()->where('status', User::STATUS_ACTIVE);

        return match ($audience['type']) {
            'school' => $query->get(),
            'role' => $query->whereIn('roles', $audience['refs'])->get(),
            default => $query->whereIn('_id', $this->sectionUserIds($this->sectionsOf($audience)))->get(),
        };
    }

    /** May this person read the notice? Senders and school-wide readers always can. */
    public function reaches(User $user, Notice $notice): bool
    {
        if (($notice->sent_by['id'] ?? null) === (string) $user->getKey() || $this->access->holdsAt($user, 'notices.view', 'school')) {
            return true;
        }

        $audience = $notice->audience;

        return match ($audience['type']) {
            'school' => true,
            'role' => array_intersect($user->roles, $audience['refs']) !== [],
            default => array_intersect($this->sectionsOf($audience), $this->sectionsOfUser($user)) !== [],
        };
    }

    /**
     * The sections this person belongs to for notices: the ones they teach
     * or class-teach, their children's, or their own.
     *
     * @return list<string>
     */
    public function sectionsOfUser(User $user): array
    {
        $mine = $user->accessScope();
        $sections = array_merge($mine['class_sections'], array_map(fn (string $k) => strtok($k, ':') ?: '', $mine['subject_sections']));
        $year = AcademicYear::current();

        if ($year instanceof AcademicYear) {
            $students = array_merge($mine['children'], Student::query()->where('user_id', (string) $user->getKey())->get()->map(fn (Student $s) => (string) $s->getKey())->all());

            if ($students !== []) {
                $sections = array_merge($sections, Enrolment::query()->where('year_id', (string) $year->getKey())->whereIn('student_id', $students)->pluck('section_id')->all());
            }
        }

        return array_values(array_unique(array_map('strval', $sections)));
    }

    /**
     * @param  array{type: string, refs: list<string>}  $audience
     * @return list<string>
     */
    public function sectionsOf(array $audience): array
    {
        if ($audience['type'] === 'section') {
            return array_values($audience['refs']);
        }

        if ($audience['type'] === 'class') {
            return Section::query()->whereIn('class_id', $audience['refs'])->get()->map(fn (Section $s) => (string) $s->getKey())->all();
        }

        return [];
    }

    /**
     * @param  list<string>  $sections
     * @return list<string>
     */
    private function sectionUserIds(array $sections): array
    {
        if ($sections === []) {
            return [];
        }

        $year = AcademicYear::current();
        $studentIds = $year instanceof AcademicYear
            ? Enrolment::query()->where('year_id', (string) $year->getKey())->whereIn('section_id', $sections)->pluck('student_id')->map(fn ($id) => (string) $id)->all()
            : [];

        $ids = Student::query()->whereIn('_id', $studentIds)->whereNotNull('user_id')->pluck('user_id')->all();
        $ids = array_merge($ids, Guardian::query()->whereIn('student_ids', $studentIds)->whereNotNull('user_id')->pluck('user_id')->all());

        $staffIds = array_merge(
            Section::query()->whereIn('_id', $sections)->whereNotNull('class_teacher_id')->pluck('class_teacher_id')->all(),
            TeachingAssignment::query()->whereIn('section_id', $sections)->pluck('teacher_id')->all(),
        );
        $ids = array_merge($ids, Staff::query()->whereIn('_id', array_values(array_unique($staffIds)))->whereNotNull('user_id')->pluck('user_id')->all());

        return array_values(array_unique(array_map('strval', $ids)));
    }

    private static function forbidden(): ApiException
    {
        return new ApiException('notice_audience_forbidden', 'You can send notices only to your own classes or sections.', 403);
    }
}
