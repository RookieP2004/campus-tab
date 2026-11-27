<?php

namespace App\Modules\People\Policies;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use Illuminate\Auth\Access\Response;

/**
 * Students, guardians, staff and imports. Registered for Student, Guardian,
 * Staff and ImportBatch. A check on a record uses the scope resolvers
 * (school, wing, class, subject, child, own) against that record.
 */
class PeoplePolicy
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly StudentRecords $students,
    ) {}

    public function viewAnyStudents(User $actor): Response
    {
        return $this->holds($actor, 'students.view');
    }

    public function view(User $actor, Student|Staff $record): Response
    {
        if ($record instanceof Staff) {
            return $this->can($actor, 'staff.view');
        }

        return $this->onRecord($actor, 'students.view', $this->students->withEnrolment($record));
    }

    public function admit(User $actor): Response
    {
        return $this->can($actor, 'students.admit');
    }

    public function update(User $actor, Student|Staff $record): Response
    {
        if ($record instanceof Staff) {
            return $this->can($actor, 'staff.edit');
        }

        return $this->onRecord($actor, 'students.edit', $this->students->withEnrolment($record));
    }

    public function delete(User $actor, Student $student): Response
    {
        return $this->can($actor, 'students.admit');
    }

    public function manageGuardians(User $actor): Response
    {
        return $this->can($actor, 'guardians.manage');
    }

    public function loginCodes(User $actor, Section $section): Response
    {
        return $this->onRecord($actor, 'students.login_codes', new class($section) implements AccessSubject
        {
            public function __construct(private readonly Section $section) {}

            public function accessFacts(): array
            {
                return ['section_id' => (string) $this->section->getKey(), 'wing' => $this->section->wing];
            }
        });
    }

    public function viewAnyStaff(User $actor): Response
    {
        return $this->can($actor, 'staff.view');
    }

    public function createStaff(User $actor): Response
    {
        return $this->access->allows($actor, 'staff.create') || $this->access->allows($actor, 'staff.draft')
            ? Response::allow()
            : $this->deny();
    }

    public function approve(User $actor, Staff $staff): Response
    {
        return $this->can($actor, 'staff.approve');
    }

    public function deactivate(User $actor, Staff $staff): Response
    {
        return $this->can($actor, 'staff.deactivate');
    }

    public function import(User $actor, string $entity): Response
    {
        return in_array($entity, ['students', 'staff'], true) ? $this->can($actor, 'imports.'.$entity) : $this->deny();
    }

    private function onRecord(User $actor, string $ability, AccessSubject $subject): Response
    {
        return $this->access->allows($actor, $ability, $subject) ? Response::allow() : $this->deny();
    }

    private function holds(User $actor, string $ability): Response
    {
        return $this->access->holds($actor, $ability) ? Response::allow() : $this->deny();
    }

    private function can(User $actor, string $ability): Response
    {
        return $this->access->allows($actor, $ability) ? Response::allow() : $this->deny();
    }

    private function deny(): Response
    {
        return Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
