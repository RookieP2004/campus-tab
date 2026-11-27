<?php

namespace App\Modules\Communication\Models;

use App\Support\Tenancy\BelongsToTenant;
use MongoDB\Laravel\Eloquent\Model;

/**
 * A device that receives push notifications (an FCM registration token from
 * the apps, Phase 9). Only an HMAC of the token is used to find it again;
 * the token itself is kept encrypted, because push needs it.
 *
 * @property string $tenant_id
 * @property string $user_id
 * @property string $token encrypted
 * @property string $token_hash
 * @property string $platform android | ios | web | desktop
 */
class PushDevice extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'push_devices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'user_id', 'token', 'token_hash', 'platform'];

    /** @var list<string> */
    protected $hidden = ['token', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
