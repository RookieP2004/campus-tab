<?php

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Domain\Auditable;
use App\Modules\Audit\Domain\Recyclable;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Domain\Scopes\AccessScopeSource;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;
use MongoDB\Laravel\Eloquent\SoftDeletes;

/**
 * A person who signs in to one school. Tenant-scoped: every query is filtered
 * by the current school. Loading a user by id for a token or session is the
 * one documented exception (see Auth\SchoolUserProvider and
 * Models\PersonalAccessToken).
 *
 * Secrets: the password is hashed; the TOTP secret is encrypted; recovery
 * codes are stored as HMACs. None of them is ever serialised or audited.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $username a login name, for staff without email or phone
 * @property string|null $password
 * @property list<string> $roles
 * @property array{required?: bool, enabled?: bool, enrolled_at?: string|null, last_timestep?: int|null} $mfa
 * @property string|null $mfa_secret
 * @property string|null $mfa_pending_secret
 * @property list<string>|null $mfa_recovery_codes
 * @property int|null $failed_sign_ins
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_sign_in_at
 * @property string $status
 * @property string $language
 * @property string $theme
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements Recyclable
{
    use Auditable, BelongsToTenant, HasApiTokens, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Created, but no password set yet. */
    public const STATUS_INVITED = 'invited';

    protected $connection = 'mongodb';

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'username', 'password', 'roles', 'mfa', 'status', 'language', 'theme', 'notification_prefs',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'roles' => [],
        'mfa' => ['enabled' => false],
        'status' => self::STATUS_ACTIVE,
        'language' => 'en',
        'theme' => 'system',
        'failed_sign_ins' => 0,
    ];

    /**
     * Bookkeeping fields that never produce an audit entry on their own.
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['failed_sign_ins', 'locked_until', 'last_sign_in_at', 'mfa', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'remember_token', 'updated_at'];
    }

    public function auditCategory(): string
    {
        return 'admin';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_pending_secret' => 'encrypted',
            'failed_sign_ins' => 'integer',
            'locked_until' => 'datetime',
            'last_sign_in_at' => 'datetime',
        ];
    }

    /** The highest level among the user's roles (0 when none; duties have no level). */
    public function level(): int
    {
        $registry = app(RoleRegistry::class);
        $levels = array_map(fn (string $role) => $registry->level($role) ?? 0, $this->roles ?? []);

        return $levels === [] ? 0 : max($levels);
    }

    /**
     * Every permission string the user holds through their roles.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return app(RoleRegistry::class)->permissionsFor($this->roles ?? []);
    }

    public function hasRole(Role|string $role): bool
    {
        return in_array($role instanceof Role ? $role->value : $role, $this->roles ?? [], true);
    }

    /** Parent OTP sign-in is only for accounts whose every role is Parent. */
    public function isParentOnly(): bool
    {
        $roles = $this->roles ?? [];

        return $roles !== [] && array_diff($roles, [Role::Parent->value]) === [];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->deleted_at === null;
    }

    public function isInvited(): bool
    {
        return $this->status === self::STATUS_INVITED && $this->deleted_at === null;
    }

    /**
     * What a password must not be: the person's logins and name.
     *
     * @return list<string>
     */
    public function personalWords(): array
    {
        $email = (string) $this->email;

        return array_values(array_filter([
            $email,
            strstr($email, '@', true) ?: null,
            $this->phone,
            $this->username,
            $this->name,
            ...preg_split('/\s+/', trim((string) $this->name)) ?: [],
        ]));
    }

    public function mfaRequired(): bool
    {
        return ($this->mfa['required'] ?? false) || $this->level() >= (int) config('identity.mfa.required_from_level', Role::MFA_REQUIRED_FROM);
    }

    public function mfaEnabled(): bool
    {
        return (bool) ($this->mfa['enabled'] ?? false) && is_string($this->mfa_secret) && $this->mfa_secret !== '';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * What the scope resolvers compare against, worked out from the school's
     * records (Phase 2): a parent's children from guardian links, a class
     * teacher's sections, a subject teacher's subject-sections from teaching
     * assignments, a Vice Principal's wings from the staff record. See
     * Domain\Scopes\AccessScopeSource.
     *
     * @return array{children: list<string>, class_sections: list<string>, subject_sections: list<string>, wings: list<string>}
     */
    public function accessScope(): array
    {
        return app(AccessScopeSource::class)->for($this);
    }

    public function recycleType(): string
    {
        return 'user';
    }

    public function recycleLabel(): string
    {
        return $this->name;
    }
}
