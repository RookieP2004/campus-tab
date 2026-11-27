<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Domain\SupportGrants;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tenant`. Runs after auth on every school route and sets the school
 * from the signed-in account: never from the body or URL.
 *
 *  - A school user: their own tenant_id.
 *  - A platform admin: only with a support-session token, only for the
 *    school and grant that token names, and only while that grant is still
 *    active (checked on every request, so a revoke takes effect at once).
 *    The request then runs as a SupportAgentUser holding the grant's
 *    permissions only; a read-only grant refuses every write.
 *  - Anything else, or a user of a suspended school, is refused.
 */
class SetTenantFromUser
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SupportGrants $grants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof PlatformAdmin) {
            return $this->asSupportAgent($request, $next, $user);
        }

        if (! $user instanceof User || ! is_string($user->tenant_id) || $user->tenant_id === '') {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        $tenant = $this->activeTenant($user->tenant_id);

        return $this->within($request, $next, $tenant);
    }

    private function asSupportAgent(Request $request, Closure $next, PlatformAdmin $agent): Response
    {
        $token = $agent->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken ? (array) $token->abilities : [];

        if (! in_array(SupportGrants::TOKEN_ABILITY, $abilities, true)) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        $tenantId = self::named($abilities, 'support-tenant:');
        $grantId = self::named($abilities, 'support-grant:');

        if ($tenantId === null || $grantId === null) {
            throw SupportGrants::noGrant();
        }

        $tenant = $this->activeTenant($tenantId);

        $this->context->set($tenant->id);

        try {
            $grant = $this->grants->grantForToken($agent, $grantId);

            if ($grant === null) {
                throw new ApiException('support_grant_inactive', 'This support access has ended or was revoked.', 403);
            }

            if ($grant->read_only && ! $request->isMethodSafe()) {
                throw new ApiException('support_read_only', 'This support access is read-only.', 403);
            }

            $supportUser = SupportAgentUser::for($agent, $grant);
            Auth::setUser($supportUser);
            $request->setUserResolver(fn () => $supportUser);
            $request->attributes->set('support_grant', $grant);
        } catch (\Throwable $e) {
            $this->context->clear();

            throw $e;
        }

        return $this->within($request, $next, $tenant);
    }

    private function within(Request $request, Closure $next, Tenant $tenant): Response
    {
        $this->context->set($tenant->id);
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function activeTenant(string $tenantId): Tenant
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            throw new ApiException('tenant_suspended', 'This school is not active. Contact the school office.', 403);
        }

        return $tenant;
    }

    /**
     * @param  list<mixed>  $abilities
     */
    private static function named(array $abilities, string $prefix): ?string
    {
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, $prefix)) {
                return substr($ability, strlen($prefix));
            }
        }

        return null;
    }
}
