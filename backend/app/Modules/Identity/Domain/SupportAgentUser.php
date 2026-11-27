<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\SupportGrant;
use App\Modules\Identity\Models\User;
use LogicException;

/**
 * A platform admin acting inside one school under an active support grant.
 * Never stored: it exists for one request, so Policies and the audit log see
 * a school-level actor whose permissions come only from the grant. The audit
 * log records the agent and the grant id.
 */
class SupportAgentUser extends User
{
    public PlatformAdmin $agent;

    public SupportGrant $grant;

    /** @var list<string> */
    private array $grantPermissions = [];

    public static function for(PlatformAdmin $agent, SupportGrant $grant): self
    {
        $user = new self;
        $user->forceFill([
            'tenant_id' => $grant->tenant_id,
            'name' => 'Campus TAB support: '.$agent->name,
            'roles' => [],
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->agent = $agent;
        $user->grant = $grant;
        $user->grantPermissions = SupportAccess::permissionsFor($grant);

        return $user;
    }

    public function permissions(): array
    {
        return $this->grantPermissions;
    }

    public function level(): int
    {
        return 0;
    }

    public function mfaRequired(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('A support session is not a stored account.');
    }
}
