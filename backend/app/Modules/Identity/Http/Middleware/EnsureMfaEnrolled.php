<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `mfa.enrolled`. Runs after `device`. Refuses with
 * 403 mfa_enrolment_required when:
 *
 *  - the user's role needs MFA (Clerk and above) and it is not set up, or
 *  - this device's session may only enrol MFA (the token from activation or
 *    from a first sign-in), even after MFA is set up elsewhere: an enrol-only
 *    token never becomes a full one. Confirming enrolment replaces it.
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof SupportAgentUser || ! $user instanceof User) {
            return $next($request);
        }

        $session = $request->attributes->get('device_session');
        $token = $user->currentAccessToken();
        $limitedToken = $token instanceof PersonalAccessToken && ! in_array('*', (array) $token->abilities, true);

        if (($user->mfaRequired() && ! $user->mfaEnabled()) || $limitedToken || ($session instanceof DeviceSession && $session->limited)) {
            throw new ApiException('mfa_enrolment_required', 'Set up two-step sign-in before you continue.', 403);
        }

        return $next($request);
    }
}
