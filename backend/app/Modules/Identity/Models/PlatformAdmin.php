<?php

namespace App\Modules\Identity\Models;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;

/**
 * Our own staff (level 100). Kept in their own collection with no tenant_id,
 * so a platform account is never a school user. It holds no school
 * permissions unless a Chief grants support access.
 *
 * Created only by `php artisan platform:create-admin` (never by sign-up): the
 * account starts "invited" with no password and a one-time set-password
 * link. Signing in to the console needs the password and a TOTP code every
 * time; the first sign-in only allows MFA enrolment.
 *
 * Secrets: the password is hashed, the TOTP secret encrypted, recovery codes
 * and the set-password link stored as HMACs. None is ever serialised or
 * written to an audit log.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property array{enabled?: bool, enrolled_at?: string|null, last_timestep?: int|null} $mfa
 * @property string|null $mfa_secret
 * @property string|null $mfa_pending_secret
 * @property list<string>|null $mfa_recovery_codes
 * @property int|null $failed_sign_ins
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_sign_in_at
 * @property string|null $password_link_hash
 * @property Carbon|null $password_link_expires_at
 * @property string $status invited | active | inactive
 */
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $connection = 'mongodb';

    protected $table = 'platform_admins';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'mfa', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'password_link_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'mfa' => ['enabled' => false],
        'failed_sign_ins' => 0,
    ];

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
            'password_link_expires_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function mfaEnabled(): bool
    {
        return (bool) ($this->mfa['enabled'] ?? false) && is_string($this->mfa_secret) && $this->mfa_secret !== '';
    }

    /** Platform accounts always need MFA. */
    public function mfaRequired(): bool
    {
        return true;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasPassword(): bool
    {
        return is_string($this->password) && $this->password !== '';
    }

    /**
     * What a password must not be.
     *
     * @return list<string>
     */
    public function personalWords(): array
    {
        $email = (string) $this->email;

        return array_values(array_filter([
            $email,
            strstr($email, '@', true) ?: null,
            $this->name,
            ...preg_split('/\s+/', trim((string) $this->name)) ?: [],
        ]));
    }
}
