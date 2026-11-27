<?php

namespace App\Modules\People\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\People\Models\Guardian;
use App\Support\Http\ApiException;

/**
 * Turning a guardian's parent login on or off.
 *
 * On: a Parent account (phone required; it signs in with an SMS code, so it
 * has no password) linked to the guardian record. Because siblings share one
 * guardian record, the one login covers all of that guardian's children.
 * If someone in the school already has an account with that phone (a
 * teacher who is also a parent), the Parent role is added to it instead.
 *
 * Off: the link goes; a Parent-only account is deactivated, otherwise only
 * the Parent role is removed (and only when no other guardian record uses it).
 */
class ParentLogins
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function enable(User $actor, Guardian $guardian): User
    {
        if (! is_string($guardian->phone) || $guardian->phone === '') {
            throw new ApiException('phone_required', 'A parent login needs the guardian\'s mobile number: parents sign in with a code sent by SMS.', 422);
        }

        if (is_string($guardian->user_id) && User::query()->whereKey($guardian->user_id)->exists()) {
            throw new ApiException('parent_login_exists', 'This guardian already has a parent login.', 409);
        }

        $user = User::query()->where('phone', $guardian->phone)->first();

        if ($user instanceof User) {
            if ($user->status === User::STATUS_INACTIVE) {
                if (Seats::usesStaffSeat($user->roles)) {
                    app(Seats::class)->assertRoom('staff');
                }

                $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
            }

            if (! $user->hasRole(Role::Parent)) {
                $user->forceFill(['roles' => array_values(array_unique([...$user->roles, Role::Parent->value]))])->save();
            }
        } else {
            $user = AuditRecorder::muted(fn () => User::create([
                'name' => $guardian->name,
                'phone' => $guardian->phone,
                'email' => null,
                'password' => null,
                'roles' => [Role::Parent->value],
                'status' => User::STATUS_ACTIVE,
            ]));

            $this->audit->record('user.created', 'admin', 'users', (string) $user->getKey(), after: [
                'name' => $user->name, 'phone' => $user->phone, 'roles' => $user->roles, 'status' => $user->status, 'how' => 'parent_login',
            ]);
        }

        $guardian->forceFill(['user_id' => (string) $user->getKey()])->save();
        $this->audit->record('parent_login.enabled', 'admin', 'guardians', (string) $guardian->getKey(), meta: [
            'user_id' => (string) $user->getKey(),
            'children' => count($guardian->student_ids),
        ]);

        return $user;
    }

    public function disable(User $actor, Guardian $guardian): void
    {
        $userId = $guardian->user_id;

        if (! is_string($userId)) {
            return;
        }

        $guardian->forceFill(['user_id' => null])->save();
        $user = User::query()->find($userId);

        if ($user instanceof User && ! Guardian::query()->where('user_id', $userId)->exists()) {
            if ($user->isParentOnly()) {
                $user->forceFill(['status' => User::STATUS_INACTIVE])->save();
            } else {
                $user->forceFill(['roles' => array_values(array_diff($user->roles, [Role::Parent->value]))])->save();
            }
        }

        $this->audit->record('parent_login.disabled', 'admin', 'guardians', (string) $guardian->getKey(), meta: ['user_id' => $userId]);
    }
}
