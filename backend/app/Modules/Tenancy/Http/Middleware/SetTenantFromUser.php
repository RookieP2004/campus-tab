<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tenant`. Runs after auth:sanctum on every school route and sets the
 * school from the signed-in user's tenant_id: never from the body or URL.
 * A platform account, or a user of a suspended school, is refused.
 */
class SetTenantFromUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! is_string($user->tenant_id) || $user->tenant_id === '') {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            throw new ApiException('tenant_suspended', 'This school is not active. Contact the school office.', 403);
        }

        $this->context->set($tenant->id);
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
