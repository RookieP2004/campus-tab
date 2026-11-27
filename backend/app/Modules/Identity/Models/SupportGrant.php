<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Time-boxed access a Chief gives our support team. Phase 1 part A creates
 * the collection; part B (item 9) adds creating, using and revoking grants.
 *
 * @property string $tenant_id
 * @property string $granted_by
 * @property string $agent
 * @property list<string> $scope
 * @property bool $read_only
 * @property string|null $reason
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
class SupportGrant extends Model
{
    use BelongsToTenant;

    protected $connection = 'mongodb';

    protected $table = 'support_grants';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'granted_by', 'agent', 'scope', 'read_only', 'reason', 'expires_at', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
