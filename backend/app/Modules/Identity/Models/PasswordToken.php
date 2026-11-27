<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A one-time set-password or reset link. Only a SHA-256 hash of the link's
 * secret is stored. Used or expired links stay as records (no soft deletes);
 * a user's links are removed only when the user is purged.
 *
 * @property string $tenant_id
 * @property string $user_id
 * @property string $purpose set|reset
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property array{id: string|null, name: string|null}|null $created_by
 */
class PasswordToken extends Model
{
    use BelongsToTenant;

    public const PURPOSE_SET = 'set';

    public const PURPOSE_RESET = 'reset';

    protected $connection = 'mongodb';

    protected $table = 'password_tokens';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'user_id', 'purpose', 'token_hash', 'expires_at', 'used_at', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
