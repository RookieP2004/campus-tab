<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `step-up:<action>`. Sensitive actions ask for a fresh MFA code.
 * The action must be listed in config('identity.step_up.actions'); a typo
 * fails loudly instead of silently skipping the check. A code entered at
 * POST /auth/step-up counts for valid_minutes on that device.
 * Support agents can never do step-up actions.
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        /** @var list<string> $actions */
        $actions = config('identity.step_up.actions', []);

        if (! in_array($action, $actions, true)) {
            throw new LogicException("Unknown step-up action [{$action}]; add it to config/identity.php step_up.actions.");
        }

        $user = $request->user();

        if ($user instanceof SupportAgentUser) {
            throw new ApiException('support_not_allowed', 'Support access cannot do this. Ask the Chief Admin.', 403);
        }

        if (! $user instanceof User || ! $user->mfaEnabled()) {
            throw new ApiException('mfa_enrolment_required', 'Set up two-step sign-in to do this.', 403);
        }

        $minutes = (int) config('identity.step_up.valid_minutes', 10);
        $session = $request->attributes->get('device_session');

        if (! $session instanceof DeviceSession || ! $session->steppedUpWithin($minutes)) {
            throw new ApiException('step_up_required', 'Enter a code from your authenticator app to confirm this.', 403, [
                'action' => $action,
                'valid_minutes' => $minutes,
            ]);
        }

        return $next($request);
    }
}
