<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Staff accounts: create, change roles, deactivate ("delete staff"),
 * unlock and send a password link. Every method enforces the guard rules
 * in code, whatever the UI shows:
 *
 *  - level: you act only on people below your level (a Chief may act on
 *    Chiefs) and give or take only roles below your level;
 *  - last Chief: the last active Chief cannot be deactivated or lose the
 *    role (checked in a transaction that also writes the school record, so
 *    two demotions at once cannot both pass);
 *  - adding or removing a Chief is a security event in the audit log.
 *    Phase 3 notifications will also alert the other Chiefs.
 */
class UserAdmin
{
    public function __construct(
        private readonly Authority $authority,
        private readonly RoleRegistry $roles,
        private readonly PasswordLinks $links,
        private readonly SessionIssuer $sessions,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * A new account starts with no password. Staff start "invited" and get a
     * set-password link (or, with no email or phone, a setup code returned
     * once to the creator). Parents sign in by SMS code, so they start active.
     *
     * @param  array{name: string, email?: string|null, phone?: string|null, username?: string|null, roles: list<string>}  $input
     * @return array{user: User, password_setup: array{channel: string, to: string|null, code: string|null, expires_at: string}|null}
     */
    public function create(User $actor, Tenant $tenant, array $input): array
    {
        $roles = $this->checkAssignable($actor, $input['roles'], []);

        // Staff seats: every account except parents and students uses one.
        if (Seats::usesStaffSeat($roles)) {
            app(Seats::class)->assertRoom('staff');
        }
        $parentOnly = array_diff($roles, [Role::Parent->value]) === [];

        if ($parentOnly && empty($input['phone'])) {
            throw new ApiException('phone_required', 'A parent account needs a phone number: parents sign in with a code sent by SMS.', 422);
        }

        $user = AuditRecorder::muted(fn () => User::create([
            'name' => $input['name'],
            'email' => isset($input['email']) ? mb_strtolower($input['email']) : null,
            'phone' => $input['phone'] ?? null,
            'username' => isset($input['username']) ? mb_strtolower($input['username']) : null,
            'password' => null,
            'roles' => $roles,
            'status' => $parentOnly ? User::STATUS_ACTIVE : User::STATUS_INVITED,
        ]));

        $this->audit->record('user.created', 'admin', 'users', (string) $user->getKey(), after: [
            'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'username' => $user->username,
            'roles' => $roles, 'status' => $user->status,
        ]);
        $this->chiefEvents($user, [], $roles);

        $setup = $parentOnly ? null : $this->links->send($tenant, $user, $actor);

        return ['user' => $user, 'password_setup' => $setup];
    }

    /**
     * @param  list<string>  $roles
     */
    public function changeRoles(User $actor, User $target, array $roles): User
    {
        $this->mustOutrank($actor, $target);
        $before = $target->roles ?? [];
        $roles = $this->checkAssignable($actor, $roles, $before);

        // A parent or student account becoming staff takes a staff seat.
        if (! Seats::usesStaffSeat($before) && Seats::usesStaffSeat($roles) && $target->status !== User::STATUS_INACTIVE) {
            app(Seats::class)->assertRoom('staff');
        }

        return $this->guarded(function () use ($target, $before, $roles): User {
            if (in_array(Role::Chief->value, $before, true) && ! in_array(Role::Chief->value, $roles, true)) {
                $this->assertNotLastChief($target);
            }

            AuditRecorder::muted(fn () => $target->forceFill(['roles' => $roles])->save());
            $this->audit->record('user.roles_changed', 'admin', 'users', (string) $target->getKey(), ['roles' => $before], ['roles' => $roles]);
            $this->chiefEvents($target, $before, $roles);

            return $target;
        });
    }

    /** "Delete staff": deactivate, move to the recycle bin, end every device. History stays. */
    public function deactivate(User $actor, User $target): void
    {
        $this->mustOutrank($actor, $target);

        $this->guarded(function () use ($target): void {
            if ($target->hasRole(Role::Chief)) {
                $this->assertNotLastChief($target);
            }

            AuditRecorder::muted(function () use ($target): void {
                $target->forceFill(['status' => User::STATUS_INACTIVE])->save();
                $target->delete();
            });

            $ended = $this->sessions->revokeAllFor($target, 'deactivated');
            $this->audit->record('user.deactivated', 'admin', 'users', (string) $target->getKey(), meta: ['sessions_ended' => $ended]);

            if ($target->hasRole(Role::Chief)) {
                $this->audit->record('chief.removed', 'security', 'users', (string) $target->getKey(), meta: ['how' => 'deactivated']);
            }
        });
    }

    public function unlock(User $actor, User $target): void
    {
        $this->mustOutrank($actor, $target);

        $target->forceFill(['failed_sign_ins' => 0, 'locked_until' => null])->save();
        $this->audit->record('user.unlocked', 'security', 'users', (string) $target->getKey());
    }

    /**
     * An invited account gets its set-password link (or setup code) again; an
     * active one gets a reset link. Either way the older link stops working.
     * The Principal or Chief never sets the password themselves.
     *
     * @return array{channel: string, to: string|null, code: string|null, expires_at: string}|null
     */
    public function sendPasswordLink(User $actor, Tenant $tenant, User $target): ?array
    {
        $this->mustOutrank($actor, $target);

        if ($target->isParentOnly()) {
            throw new ApiException('password_not_used', 'Parents sign in with a code sent to their phone; they have no password.', 422);
        }

        return $this->links->send($tenant, $target, $actor);
    }

    /**
     * Lost authenticator, when the user has no recovery code left: switches
     * MFA off and ends every session. A user at level 60 or above must enrol
     * again at their next sign-in. Only someone above them may do it (a Chief
     * for a Chief); a sole Chief recovers through the platform console.
     */
    public function resetMfa(User $actor, User $target): void
    {
        $this->mustOutrank($actor, $target);

        if ((string) $actor->getKey() === (string) $target->getKey()) {
            throw new ApiException('mfa_reset_self', 'Use a recovery code for your own account, or ask another Chief Admin.', 403);
        }

        $target->forceFill([
            'mfa' => ['enabled' => false],
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $ended = $this->sessions->revokeAllFor($target, 'mfa_reset');
        $this->audit->record('mfa.reset', 'security', 'users', (string) $target->getKey(), meta: ['sessions_ended' => $ended]);
    }

    public function mustOutrank(User $actor, User $target): void
    {
        if (! $this->authority->outranks($actor, $target)) {
            throw new ApiException('level_too_low', 'You can only manage people below your own level.', 403);
        }
    }

    /**
     * Every role added or removed must exist and be one the actor may give.
     *
     * @param  list<string>  $wanted
     * @param  list<string>  $current
     * @return list<string>
     */
    private function checkAssignable(User $actor, array $wanted, array $current): array
    {
        $wanted = array_values(array_unique($wanted));

        foreach (array_merge(array_diff($wanted, $current), array_diff($current, $wanted)) as $name) {
            $role = $this->roles->find($name);

            if (! $role instanceof SchoolRole) {
                throw new ApiException('role_not_found', "There is no role called {$name}.", 422, ['role' => $name]);
            }

            if ($name === Role::Student->value) {
                throw new ApiException('role_not_assignable', 'Student accounts are created from student records (Phase 2).', 422, ['role' => $name]);
            }

            if (! $this->authority->canAssign($actor, $role)) {
                throw new ApiException('escalation_blocked', "You cannot give or take the role {$role->label}: it is not below your level.", 403, ['role' => $name]);
            }
        }

        if ($wanted === []) {
            throw new ApiException('roles_required', 'Give the account at least one role.', 422);
        }

        return $wanted;
    }

    private function assertNotLastChief(User $target): void
    {
        $others = User::query()
            ->where('roles', Role::Chief->value)
            ->where('status', User::STATUS_ACTIVE)
            ->where('_id', '!=', $target->getKey())
            ->count();

        if ($others === 0) {
            throw new ApiException('last_chief', 'This is the only Chief Admin. Make someone else Chief Admin first.', 409);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guarded(Closure $callback): mixed
    {
        return DB::connection('mongodb')->transaction(function () use ($callback) {
            // Writing the school record makes two Chief changes at once conflict.
            Tenant::query()->where('_id', $this->context->require())->increment('chief_guard');

            return $callback();
        }, 3);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function chiefEvents(User $user, array $before, array $after): void
    {
        $chief = Role::Chief->value;
        $was = in_array($chief, $before, true);
        $is = in_array($chief, $after, true);

        if ($was !== $is) {
            $this->audit->record($is ? 'chief.added' : 'chief.removed', 'security', 'users', (string) $user->getKey());
        }
    }
}
