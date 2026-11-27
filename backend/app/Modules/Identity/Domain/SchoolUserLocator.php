<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Password sign-in and forgot-password: find the school from its code
 * first, then look up the user inside that school only. The same email,
 * phone or login name in another school is never matched.
 *
 * The login is an email (has "@"), a phone (digits, optional +, spaces and
 * dashes) or a login name. Only active accounts are found: an invited
 * account has no password yet.
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

        [$field, $value] = self::field($login);

        $user = $this->context->run($tenant->id, fn () => User::query()
            ->where($field, $value)
            ->where('status', User::STATUS_ACTIVE)
            ->first());

        return $user instanceof User ? [$tenant, $user] : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function field(string $login): array
    {
        $login = trim($login);

        if (str_contains($login, '@')) {
            return ['email', mb_strtolower($login)];
        }

        if (preg_match('/^\+?[\d\s\-()]{10,20}$/', $login) === 1) {
            return ['phone', preg_replace('/[\s\-()]/', '', $login) ?? $login];
        }

        return ['username', mb_strtolower($login)];
    }
}
