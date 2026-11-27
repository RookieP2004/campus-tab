<?php

namespace App\Modules\Identity\Models;

use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;

/**
 * Our own staff (level 100). Kept in their own collection with no tenant_id,
 * so a platform account is never a school user. It holds no school
 * permissions unless a Chief grants support access (Phase 1 part B).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property array{enabled?: bool, secret?: string|null} $mfa
 * @property string $status
 */
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'mongodb';

    protected $table = 'platform_admins';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'mfa', 'status'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
