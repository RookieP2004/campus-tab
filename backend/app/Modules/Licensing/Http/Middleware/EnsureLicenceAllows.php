<?php

namespace App\Modules\Licensing\Http\Middleware;

use App\Modules\Licensing\Domain\LicenceState;
use App\Modules\Licensing\Models\Licence;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `licence`. Runs after `tenant` on every school route.
 *
 *  active    - passes
 *  grace     - passes, with X-Licence-State: grace so the app shows a banner
 *  read_only - reads pass; writes get 403 licence_read_only, except routes
 *              marked as exports with ->defaults('licence_export', true)
 *              and account-safety routes (unlock, password link, support
 *              access) marked ->defaults('licence_always', true)
 *  inactive  - 403 licence_inactive
 *
 * Every response carries X-Licence-State.
 */
class EnsureLicenceAllows
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $licence = Licence::currentFor($this->context->require());
        $state = $licence?->state() ?? LicenceState::Inactive;
        $request->attributes->set('licence', $licence);
        $request->attributes->set('licence_state', $state);

        if ($state === LicenceState::Inactive) {
            throw new ApiException('licence_inactive', 'This school has no active licence. Contact Campus TAB support.', 403);
        }

        $defaults = $request->route()->defaults ?? [];
        $exempt = (bool) ($defaults['licence_export'] ?? false) || (bool) ($defaults['licence_always'] ?? false);

        if ($state === LicenceState::ReadOnly && ! $request->isMethodSafe() && ! $exempt) {
            throw new ApiException('licence_read_only', 'The licence has expired, so the school is read-only. You can still view and export everything.', 403, [
                'valid_to' => $licence?->valid_to,
            ]);
        }

        $response = $next($request);
        $response->headers->set('X-Licence-State', $state->value);

        return $response;
    }
}
