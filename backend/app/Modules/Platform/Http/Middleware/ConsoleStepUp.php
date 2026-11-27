<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `console.step-up:<action>`. Runs after `console`. The action must be
 * listed in config('identity.platform.step_up.actions'). A code entered at
 * POST /platform/auth/step-up counts for valid_minutes on that browser.
 */
class ConsoleStepUp
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        /** @var list<string> $actions */
        $actions = config('identity.platform.step_up.actions', []);

        if (! in_array($action, $actions, true)) {
            throw new LogicException("Unknown console step-up action [{$action}]; add it to config/identity.php platform.step_up.actions.");
        }

        $minutes = (int) config('identity.platform.step_up.valid_minutes', 10);
        $session = $request->attributes->get('platform_session');

        if (! $session instanceof PlatformSession || ! $session->steppedUpWithin($minutes)) {
            throw new ApiException('step_up_required', 'Enter a code from your authenticator app to confirm this.', 403, [
                'action' => $action,
                'valid_minutes' => $minutes,
            ]);
        }

        return $next($request);
    }
}
