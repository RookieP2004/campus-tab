<?php

namespace App\Modules\Platform\Models;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * One signed-in console browser of a platform admin (platform level, no
 * tenant). Holds only a SHA-256 hash of the session id, never the cookie.
 * Same rules as a school user's device session: ends after 15 minutes
 * without use or 8 hours in total, can be signed out remotely, and a
 * revoked session stays as a record. "limited" while it may only enrol MFA.
 *
 * @property string $id
 * @property string $admin_id
 * @property string $session_hash
 * @property string|null $user_agent
 * @property string|null $ip
 * @property string $method
 * @property bool $limited
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $step_up_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property Carbon|null $created_at
 */
class PlatformSession extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'platform_sessions';

    /** @var list<string> */
    protected $fillable = [
        'admin_id', 'session_hash', 'user_agent', 'ip', 'method', 'limited',
        'last_seen_at', 'step_up_at', 'revoked_at', 'revoked_reason',
    ];

    /** @var list<string> */
    protected $hidden = ['session_hash'];

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
