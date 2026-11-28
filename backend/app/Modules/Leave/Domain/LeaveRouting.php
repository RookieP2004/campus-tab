<?php

namespace App\Modules\Leave\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveDelegation;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\People\Models\Staff;
use Illuminate\Support\Collection;

/**
 * Who a request goes to, who may act at each stage and who may see it.
 *
 * Owner's rules:
 *  - Nobody decides their own leave, at any stage.
 *  - Own-leave routing: a Clerk's or a Vice Principal's leave goes straight
 *    to the Principal; the Principal's goes to the Chief Admin; the Chief
 *    Admin's goes to the Principal (the Principal is the school's leave
 *    authority). Everyone else follows the school's chain
 *    (policies.leave.chain: clerk, optional vice_principal, principal).
 *  - A Vice Principal acting as the Principal's dated delegate never decides
 *    for anyone at or above their own level.
 *  - Student leave: the class teacher decides (or anyone holding
 *    student_leave.approve over the section); long ones are also shown to
 *    the Principal.
 *
 * Stages:
 *   clerk           verify and forward, or return (reject only with clerk_can_reject)
 *   vice_principal  forward or return, for the applicant's wing
 *   principal       the final decision (leave.decide:school or an active delegate)
 *   chief           the final decision on the Principal's own leave (leave.decide_principal)
 *   class_teacher   the decision on student leave
 */
class LeaveRouting
{
    public const DECIDING = ['principal', 'chief', 'class_teacher'];

    public function __construct(
        private readonly AccessControl $access,
        private readonly RoleRegistry $roles,
        private readonly LeaveSettings $settings,
    ) {}

    /**
     * The stages a staff member's leave goes through.
     *
     * @return list<string>
     */
    public function chainFor(User $applicant, ?string $wing): array
    {
        if ($applicant->hasRole(Role::Chief)) {
            return ['principal'];
        }

        $level = $applicant->level();

        if ($level >= Role::Principal->level()) {
            return ['chief'];
        }

        if ($level >= Role::Clerk->level()) {
            return ['principal'];
        }

        $chain = [];
        foreach ($this->settings->policy()['chain'] as $stage) {
            if ($stage === 'vice_principal' && ($wing === null || $this->wingDeputies($wing)->isEmpty())) {
                continue;
            }
            $chain[] = $stage;
        }

        return in_array('principal', $chain, true) ? $chain : [...$chain, 'principal'];
    }

    /** The applicant's wing: their staff record's, or the wing of a section they class-teach. */
    public function wingOf(Staff $staff): ?string
    {
        if (($staff->wings ?? []) !== []) {
            return (string) $staff->wings[0];
        }

        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear) {
            return null;
        }

        $section = Section::query()->where('year_id', (string) $year->getKey())->where('class_teacher_id', (string) $staff->getKey())->whereNotNull('wing')->first();

        return $section?->wing;
    }

    /** May this person act on the request at its current stage? */
    public function mayAct(User $user, LeaveRequest $request): bool
    {
        return $request->stage !== null && $this->couldAct($user, $request, $request->stage);
    }

    /**
     * May this person act at this stage of this request? Never the applicant,
     * nor the parent who applied.
     */
    public function couldAct(User $user, LeaveRequest $request, string $stage): bool
    {
        $id = (string) $user->getKey();

        if ($id === $request->applicantUserId() || $id === ($request->applied_by['id'] ?? null) || ! $user->isActive()) {
            return false;
        }

        $applicantLevel = (int) ($request->applicant['level'] ?? 0);

        return match ($stage) {
            'clerk' => $this->access->allows($user, 'leave.verify'),
            'vice_principal' => $user->level() > $applicantLevel && $this->access->allows($user, 'leave.decide', $request),
            'principal' => ($this->access->allows($user, 'leave.decide') && $user->level() > $applicantLevel)
                || ($this->access->allows($user, 'leave.decide') && $request->applicant['level'] >= Role::Chief->level())
                || $this->isDelegateFor($user, $request),
            'chief' => $this->access->allows($user, 'leave.decide_principal'),
            'class_teacher' => $this->access->allows($user, 'student_leave.approve', $request),
            default => false,
        };
    }

    /** The Principal acting at the Clerk's stage for a same-day emergency. */
    public function mayFastTrack(User $user, LeaveRequest $request): bool
    {
        return $request->stage === 'clerk'
            && $this->access->allows($user, 'leave.fast_track', $request)
            && $this->couldAct($user, $request, 'principal');
    }

    /** An active dated delegation to this Vice Principal, for someone below them. */
    public function isDelegateFor(User $user, LeaveRequest $request): bool
    {
        if ((int) ($request->applicant['level'] ?? 0) >= $user->level()) {
            return false;
        }

        $today = $this->settings->today();

        return LeaveDelegation::query()->where('delegate_id', (string) $user->getKey())->whereNull('ended_at')
            ->where('from', '<=', $today)->where('to', '>=', $today)->exists();
    }

    /**
     * May this person see the request's private details (reason, remarks,
     * documents)? Only the applicant (or the parent who applied, and the
     * child's other linked guardians) and the people in this request's chain:
     * anyone who could act at one of its stages, or who already did.
     */
    public function canSeeDetails(User $user, LeaveRequest $request): bool
    {
        $id = (string) $user->getKey();

        if ($id === $request->applicantUserId() || $id === ($request->applied_by['id'] ?? null)) {
            return true;
        }

        if ($request->kind === 'student' && $this->access->allows($user, 'students.view', $request) && in_array($request->applicant['student_id'] ?? null, $user->accessScope()['children'], true)) {
            return true;
        }

        foreach ($request->steps as $step) {
            if (($step['by']['id'] ?? null) === $id) {
                return true;
            }
        }

        foreach ($request->chain as $stage) {
            if ($this->couldAct($user, $request, $stage)) {
                return true;
            }
        }

        return $request->kind === 'staff' && in_array('clerk', $request->chain, true) && $this->access->allows($user, 'leave.fast_track', $request)
            && $this->couldAct($user, $request, 'principal');
    }

    /**
     * Everyone who may act at the request's current stage (for the inbox and
     * notifications).
     *
     * @return Collection<int, User>
     */
    public function actorsNow(LeaveRequest $request): Collection
    {
        return $request->stage === null ? collect() : $this->actorsAt($request, $request->stage);
    }

    /**
     * @return Collection<int, User>
     */
    public function actorsAt(LeaveRequest $request, string $stage): Collection
    {
        $ability = match ($stage) {
            'clerk' => 'leave.verify',
            'vice_principal', 'principal' => 'leave.decide',
            'chief' => 'leave.decide_principal',
            default => 'student_leave.approve',
        };

        $users = $this->usersHolding($ability);

        if ($stage === 'principal') {
            $delegates = LeaveDelegation::query()->whereNull('ended_at')->where('from', '<=', $this->settings->today())->where('to', '>=', $this->settings->today())->pluck('delegate_id')->all();
            $users = $users->merge(User::query()->whereIn('_id', $delegates)->get());
        }

        return $users->unique(fn (User $u) => (string) $u->getKey())
            ->filter(fn (User $u) => $this->couldAct($u, $request, $stage))->values();
    }

    /**
     * Who to tell when a request has waited too long at a stage: the level
     * above (clerk or vice principal stage: the Principal; principal stage:
     * the Chief Admin; class teacher: the Principal). Escalation only tells
     * them; it never changes who decides.
     *
     * @return Collection<int, User>
     */
    public function escalationFor(LeaveRequest $request): Collection
    {
        $ability = match ($request->stage) {
            'principal' => 'leave.decide_principal',
            'chief' => null,
            'class_teacher' => 'leave.decide',
            default => 'leave.decide',
        };

        if ($ability === null) {
            return collect();
        }

        $id = $request->applicantUserId();

        return $this->usersHolding($ability)->filter(fn (User $u) => (string) $u->getKey() !== $id && $this->access->holdsAt($u, $ability, 'school'))->values();
    }

    /**
     * Active users whose roles hold an ability.
     *
     * @return Collection<int, User>
     */
    public function usersHolding(string $ability): Collection
    {
        $roleNames = [];
        foreach ($this->roles->all() as $name => $role) {
            foreach ($role->permissions ?? [] as $permission) {
                if (str_starts_with($permission, $ability.':')) {
                    $roleNames[] = $name;
                    break;
                }
            }
        }

        if ($roleNames === []) {
            return collect();
        }

        return User::query()->whereIn('roles', $roleNames)->where('status', User::STATUS_ACTIVE)->get()->values();
    }

    /**
     * Vice Principals whose wings include this one.
     *
     * @return Collection<int, Staff>
     */
    private function wingDeputies(string $wing): Collection
    {
        $users = User::query()->where('roles', Role::VicePrincipal->value)->where('status', User::STATUS_ACTIVE)->get()->map(fn (User $u) => (string) $u->getKey())->all();

        return Staff::query()->whereIn('user_id', $users)->where('status', Staff::STATUS_ACTIVE)->where('wings', $wing)->get();
    }
}
