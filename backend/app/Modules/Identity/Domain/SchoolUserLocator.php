<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Password sign-in: find the school from its code first, then look up the
 * user inside that school only. The same email or phone in another school
 * is never matched. Phase 1 part B builds POST /auth/login on this.
 */
class SchoolUserLocator
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{0: Tenant, 1: User}|null
     */
    public function find(string $schoolCode, string $login): ?array
    {
        $tenant = Tenant::findActiveByCode($schoolCode);

        if ($tenant === null) {
            return null;
        }

        $login = trim($login);
        $field = str_contains($login, '@') ? 'email' : 'phone';
        $value = $field === 'email' ? mb_strtolower($login) : $login;

        $user = $this->context->run($tenant->id, fn () => User::query()
            ->where($field, $value)
            ->where('status', 'active')
            ->first());

        return $user instanceof User ? [$tenant, $user] : null;
    }
}
