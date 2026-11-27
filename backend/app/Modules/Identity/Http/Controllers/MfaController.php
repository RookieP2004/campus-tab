<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\Mfa;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Setting up and managing two-step sign-in (TOTP).
 *
 *   POST   /me/mfa/enrol           -> secret and otpauth:// link for the app
 *   POST   /me/mfa/enrol/confirm   { code } -> recovery codes (shown once)
 *   POST   /me/mfa/recovery-codes  { code } -> new recovery codes
 *   DELETE /me/mfa                 { code } -> switch off (only when not required)
 *
 * Enrolment works with an enrol-only session. Confirming it upgrades that
 * session: a browser session is marked full; an enrol-only token is deleted
 * and a full token is returned in its place.
 */
class MfaController
{
    public function __construct(private readonly Mfa $mfa) {}

    public function start(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->mfa->startEnrolment($this->user($request), $tenant)]);
    }

    public function confirm(Request $request, SessionIssuer $sessions): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $this->user($request);
        $codes = $this->mfa->confirmEnrolment($user, $input['code']);

        $data = ['recovery_codes' => $codes];
        $session = $request->attributes->get('device_session');

        if ($session instanceof DeviceSession && $session->limited) {
            if ($session->kind === DeviceSession::KIND_TOKEN) {
                $sessions->revoke($session, 'upgraded_after_mfa_enrolment');
                $data['session'] = $sessions->issueToken($user, $session->name, 'mfa_enrolment', false, $request);
            } else {
                $session->forceFill(['limited' => false])->save();
                $data['session'] = ['mode' => 'browser', 'device_session_id' => (string) $session->getKey(), 'mfa_enrolment_required' => false];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $this->user($request);

        if ($this->mfa->verify($user, $input['code'], allowRecovery: false) === null) {
            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422);
        }

        return response()->json(['data' => ['recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->user($request);

        if ($user->mfaRequired()) {
            throw new ApiException('mfa_required_for_role', 'Your role needs two-step sign-in, so it cannot be switched off.', 403);
        }

        if ($this->mfa->verify($user, $input['code']) === null) {
            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422);
        }

        $this->mfa->disable($user);

        return response()->json(['data' => ['mfa' => ['enabled' => false]]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
