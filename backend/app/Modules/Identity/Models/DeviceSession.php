<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * One signed-in device: an app token or a browser session.
 *
 * Holds only the token's record id or a SHA-256 hash of the session id,
 * never the token or cookie value. A revoked session stays as a record (it
 * is part of the account's security history), so this model has no soft
 * deletes; revoked_at is its "deleted".
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $kind token|browser
 * @property string|null $token_id
 * @property string|null $session_hash
 * @property string $name
 * @property string|null $user_agent
 * @property string|null $ip
 * @property string $method password|otp|activation|mfa_enrolment|test
 * @property bool $limited true while it may only enrol MFA
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $step_up_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $created_at
 */
class DeviceSession extends Model
{
    use BelongsToTenant;

    public const KIND_TOKEN = 'token';

    public const KIND_BROWSER = 'browser';

    protected $connection = 'mongodb';

    protected $table = 'device_sessions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'user_id', 'kind', 'token_id', 'session_hash', 'name', 'user_agent', 'ip', 'method',
        'limited', 'last_seen_at', 'step_up_at', 'revoked_at', 'revoked_reason',
    ];

    /** @var list<string> */
    protected $hidden = ['session_hash', 'token_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limited' => 'boolean',
            'last_seen_at' => 'datetime',
            'step_up_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashSessionId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function steppedUpWithin(int $minutes): bool
    {
        return $this->step_up_at !== null && $this->step_up_at->greaterThan(now()->subMinutes($minutes));
    }
}
