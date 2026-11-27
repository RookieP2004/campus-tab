<?php

namespace App\Modules\People\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\UserAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;

/**
 * Staff records and their accounts.
 *
 *  - The Chief or Principal (staff.create) creates a staff member at once;
 *    with roles, the account is made too (invited, with a set-password link
 *    or setup code; no escalation, see UserAdmin).
 *  - A Clerk (staff.draft only) makes a draft for the Principal. No account
 *    exists until the Principal approves it (staff.approve): the account is
 *    then made with the roles the draft asked for, checked against the
 *    approver's own level. The Principal can also reject the draft.
 */
class StaffRecords
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly UserAdmin $users,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{staff: Staff, password_setup: array<string, mixed>|null}
     */
    public function create(User $actor, Tenant $tenant, array $input): array
    {
        $direct = $this->access->allows($actor, 'staff.create');

        if (! $direct && ! $this->access->allows($actor, 'staff.draft')) {
            throw new ApiException('permission_denied', 'You do not have permission to do this.', 403);
        }

        $code = trim((string) ($input['employee_code'] ?? '')) ?: $this->nextCode();

        if (Staff::query()->withTrashed()->where('employee_code', $code)->exists()) {
            throw new ApiException('employee_code_taken', 'This employee code is already used.', 422);
        }

        $staff = Staff::create([
            'employee_code' => $code,
            'name' => $input['name'],
            'designation' => $input['designation'] ?? null,
            'type' => $input['type'] ?? 'teaching',
            'email' => isset($input['email']) && $input['email'] !== '' ? mb_strtolower($input['email']) : null,
            'phone' => StudentRecords::phone($input['phone'] ?? null),
            'joined_on' => $input['joined_on'] ?? null,
            'wings' => array_values($input['wings'] ?? []),
            'status' => $direct ? Staff::STATUS_ACTIVE : Staff::STATUS_DRAFT,
            'requested_roles' => $direct ? [] : array_values($input['roles'] ?? []),
            'drafted_by' => $direct ? null : ['id' => (string) $actor->getKey(), 'name' => $actor->name],
            'import_batch_id' => $input['import_batch_id'] ?? null,
        ]);

        $setup = null;

        if ($direct && ($input['roles'] ?? []) !== []) {
            $setup = $this->makeAccount($actor, $tenant, $staff, array_values($input['roles']));
        }

        return ['staff' => $staff, 'password_setup' => $setup];
    }

    /**
     * @return array<string, mixed>|null the password setup of the new account
     */
    public function approve(User $actor, Tenant $tenant, Staff $staff): ?array
    {
        if ($staff->status !== Staff::STATUS_DRAFT) {
            throw new ApiException('staff_not_draft', 'Only a draft can be approved.', 409);
        }

        $roles = $staff->requested_roles;
        $setup = $roles === [] ? null : $this->makeAccount($actor, $tenant, $staff, $roles);

        $staff->forceFill(['status' => Staff::STATUS_ACTIVE, 'requested_roles' => []])->save();
        $this->audit->record('staff.draft_approved', 'admin', 'staff', (string) $staff->getKey(), meta: [
            'drafted_by' => $staff->drafted_by, 'roles' => $roles,
        ]);

        return $setup;
    }

    public function reject(User $actor, Staff $staff, string $reason): void
    {
        if ($staff->status !== Staff::STATUS_DRAFT) {
            throw new ApiException('staff_not_draft', 'Only a draft can be rejected.', 409);
        }

        $this->audit->record('staff.draft_rejected', 'admin', 'staff', (string) $staff->getKey(), meta: ['reason' => $reason, 'drafted_by' => $staff->drafted_by]);
        $staff->delete();
    }

    public function deactivate(User $actor, Staff $staff): void
    {
        if (is_string($staff->user_id)) {
            $user = User::query()->find($staff->user_id);

            if ($user instanceof User && $user->status !== User::STATUS_INACTIVE) {
                $this->users->deactivate($actor, $user);
            }
        }

        $staff->forceFill(['status' => Staff::STATUS_INACTIVE])->save();
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>|null
     */
    private function makeAccount(User $actor, Tenant $tenant, Staff $staff, array $roles): ?array
    {
        if (! is_string($staff->email) && ! is_string($staff->phone)) {
            // A login name for staff with no email or phone; they get a setup code.
            $username = 'staff.'.mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $staff->employee_code) ?? '');
        }

        $result = $this->users->create($actor, $tenant, [
            'name' => $staff->name,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'username' => $username ?? null,
            'roles' => $roles,
        ]);

        $staff->forceFill(['user_id' => (string) $result['user']->getKey()])->save();

        return $result['password_setup'];
    }

    private function nextCode(): string
    {
        $n = Staff::query()->withTrashed()->count() + 1;

        do {
            $code = 'EMP-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (Staff::query()->withTrashed()->where('employee_code', $code)->exists());

        return $code;
    }
}
