<?php

namespace App\Modules\Communication\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
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
 * @property string|null $device_session_id the sign-in that registered it (ending that sign-in on purpose stops its push)
 * @property Carbon|null $last_seen_at the last sign-in on this device; forgotten after 30 days
 */
class PushDevice extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'push_devices';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'user_id', 'token', 'token_hash', 'platform', 'device_session_id', 'last_seen_at'];

    /** @var list<string> */
    protected $hidden = ['token', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_seen_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
