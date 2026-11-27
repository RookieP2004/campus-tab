<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `mfa.enrolled`. A user whose role needs MFA (Clerk and above, and
 * every newly activated Chief) can do nothing else until MFA is enrolled.
 * The enrolment endpoints arrive in Phase 1 part B.
 */
class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mfaRequired() && ! $user->mfaEnabled()) {
            throw new ApiException('mfa_enrolment_required', 'Set up two-step sign-in before you continue.', 403);
        }

        return $next($request);
    }
}
