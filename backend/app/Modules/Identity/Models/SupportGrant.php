<?php

namespace App\Modules\Identity\Models;

use App\Modules\Audit\Domain\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;

/**
 * Time-boxed access a Chief gives one of our platform admins. Read-only
 * unless the Chief says otherwise, with a fixed end time that cannot be
 * extended; the Chief can revoke it early. Every action under it is in the
 * audit log with the agent and this grant's id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property array{id: string|null, name: string|null} $granted_by
 * @property string $agent_id
 * @property string $agent_email
 * @property string $agent_name
 * @property list<string> $scope areas from config('identity.support.areas')
 * @property bool $read_only
 * @property string $reason
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property array{id: string|null, name: string|null}|null $revoked_by
 * @property Carbon|null $deleted_at
 */
class SupportGrant extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $connection = 'mongodb';

    protected $table = 'support_grants';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'granted_by', 'agent_id', 'agent_email', 'agent_name', 'scope', 'read_only', 'reason',
        'starts_at', 'expires_at', 'revoked_at', 'revoked_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture() && $this->deleted_at === null;
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    public function auditCategory(): string
    {
        return 'security';
    }
}
