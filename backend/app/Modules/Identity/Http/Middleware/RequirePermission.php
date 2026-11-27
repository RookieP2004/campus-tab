<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\PermissionCatalogue;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `permission:<ability>[,<ability>…]`. The route-level check: the user
 * must hold at least one of the abilities, at any scope. Policies then check
 * the scope against the actual record. A route test fails if a school route
 * has no permission check.
 */
class RequirePermission
{
    public function __construct(private readonly AccessControl $access) {}

    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        foreach ($abilities as $ability) {
            if (! PermissionCatalogue::knowsAbility($ability)) {
                throw new LogicException("Route asks for unknown permission [{$ability}]; add it to config/permissions.php.");
            }
        }

        $user = $request->user();

        if ($user instanceof User) {
            foreach ($abilities as $ability) {
                if ($this->access->holds($user, $ability)) {
                    return $next($request);
                }
            }
        }

        throw new ApiException('permission_denied', 'You do not have permission to do this.', 403, ['needs' => $abilities]);
    }
}
