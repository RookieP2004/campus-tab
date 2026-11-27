<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Domain\Role;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;
use MongoDB\Laravel\Eloquent\SoftDeletes;

/**
 * A person who signs in to one school. Tenant-scoped: every query is filtered
 * by the current school. Loading a user by id for a token or session is the
 * one documented exception (see Auth\SchoolUserProvider and
 * Models\PersonalAccessToken).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $password
 * @property list<string> $roles
 * @property array{required?: bool, enabled?: bool, secret?: string|null, enrolled_at?: string|null} $mfa
 * @property string $status
 * @property string $language
 * @property string $theme
 */
class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, Notifiable, SoftDeletes;

    protected $connection = 'mongodb';

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'email', 'phone', 'password', 'roles', 'mfa', 'status', 'language', 'theme'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'roles' => [],
        'mfa' => ['required' => false, 'enabled' => false],
        'status' => 'active',
        'language' => 'en',
        'theme' => 'system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** The highest level among the user's roles (0 when none). */
    public function level(): int
    {
        $levels = array_map(fn (string $role) => Role::tryFrom($role)?->level() ?? 0, $this->roles ?? []);

        return $levels === [] ? 0 : max($levels);
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }

    public function mfaRequired(): bool
    {
        return ($this->mfa['required'] ?? false) || $this->level() >= Role::MFA_REQUIRED_FROM;
    }

    public function mfaEnabled(): bool
    {
        return (bool) ($this->mfa['enabled'] ?? false);
    }
}
