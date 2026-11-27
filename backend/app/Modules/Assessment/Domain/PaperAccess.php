<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Domain\Scopes\SubjectScope;
use App\Modules\Identity\Domain\Scopes\WingScope;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Who may do what to one paper, from the permission matrix ("Tests and
 * marks" rows) and the paper's section, wing and subject:
 *
 *   view       anyone who may enter, verify, lock, publish or correct it, or schedule exams
 *   enter      marks.enter: school (the Clerk, before lock), wing, subject (its
 *              teacher), class only when policies.marks.class_teacher_can_enter
 *   verify     marks.verify (the class teacher of the section, wing, school)
 *   lock       marks.lock (Principal, Clerk, a Vice Principal's wing)
 *   publish    results.publish
 *   correct    marks.correction_request (raise a correction on a locked mark)
 */
class PaperAccess
{
    public const VIEW_ABILITIES = ['marks.enter', 'marks.verify', 'marks.lock', 'results.publish', 'marks.correction_request', 'marks.correction_approve', 'exams.schedule'];

    public function __construct(
        private readonly AccessControl $access,
        private readonly SchoolConfigService $configs,
    ) {}

    public function mayView(User $user, Paper $paper): bool
    {
        foreach (self::VIEW_ABILITIES as $ability) {
            if ($this->access->allows($user, $ability, $paper)) {
                return true;
            }
        }

        return false;
    }

    /** May this person type marks on this paper (any state; the state is checked by the lifecycle)? */
    public function mayEnter(User $user, Paper $paper): bool
    {
        $classTeachersEnter = (bool) ($this->configs->current()->body['policies']['marks']['class_teacher_can_enter'] ?? false);

        foreach ($this->access->scopesFor($user, 'marks.enter') as $scope) {
            $ok = match ($scope) {
                'school' => true,
                'wing' => app(WingScope::class)->covers($user, $paper),
                'subject' => app(SubjectScope::class)->covers($user, $paper),
                'class' => $classTeachersEnter && app(ClassScope::class)->covers($user, $paper),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    public function mayVerify(User $user, Paper $paper): bool
    {
        return $this->access->allows($user, 'marks.verify', $paper);
    }

    public function mayLock(User $user, Paper $paper): bool
    {
        return $this->access->allows($user, 'marks.lock', $paper);
    }

    public function mayPublish(User $user, Paper $paper): bool
    {
        return $this->access->allows($user, 'results.publish', $paper);
    }

    public function mayRequestCorrection(User $user, Paper $paper): bool
    {
        return $this->access->allows($user, 'marks.correction_request', $paper);
    }

    /** Opening papers for entry: exams by whoever schedules exams; class tests by whoever may create them there. */
    public function mayOpen(User $user, Paper $paper): bool
    {
        return $paper->class_test
            ? $this->access->allows($user, 'tests.create', $paper)
            : $this->access->allows($user, 'exams.schedule', $paper);
    }
}
