<?php

namespace App\Modules\Fees\Domain;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;

/**
 * Who sees which fee details ("Who can do what" in the blueprint):
 *
 *  - the office (fees.dues_view at school scope: Chief, Principal, Clerk,
 *    Fee Collector): every student's dues, ledger and receipts;
 *  - a parent (child scope) or a student the school allows (own scope):
 *    their own dues, ledger and receipts;
 *  - a class teacher (class scope): only "paid" or "pending" for each
 *    student of their own section, never an amount (owner's rule 7).
 */
class FeeAccess
{
    public function __construct(private readonly AccessControl $access) {}

    public function office(User $user): bool
    {
        return $this->access->holdsAt($user, 'fees.dues_view', 'school');
    }

    public function mayViewStudent(User $user, Student $student): bool
    {
        return $this->office($user)
            || ($this->access->holdsAt($user, 'fees.dues_view', 'child') && $this->access->allows($user, 'fees.dues_view', $student) && $this->isChild($user, $student))
            || ($this->access->holdsAt($user, 'fees.dues_view', 'own') && $student->user_id === (string) $user->getKey());
    }

    public function mayPayFor(User $user, Student $student): bool
    {
        return $this->access->holdsAt($user, 'fees.pay_online', 'child') && $this->isChild($user, $student);
    }

    /** "Paid or pending" for a section: its class teacher, or the office. */
    public function mayViewSectionStatus(User $user, Section $section): bool
    {
        if ($this->office($user)) {
            return true;
        }
        $probe = Assessments::probe((string) $section->getKey(), $section->wing, '');

        return $this->access->holdsAt($user, 'fees.dues_view', 'class') && app(ClassScope::class)->covers($user, $probe);
    }

    private function isChild(User $user, Student $student): bool
    {
        return in_array((string) $student->getKey(), $user->accessScope()['children'] ?? [], true);
    }
}
