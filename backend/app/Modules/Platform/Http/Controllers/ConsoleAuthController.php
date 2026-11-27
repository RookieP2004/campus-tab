<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Domain\Mfa;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Domain\ConsoleSessions;
use App\Modules\Platform\Domain\ConsoleSignIn;
use App\Modules\Platform\Domain\PlatformAdmins;
use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signing in to the platform console (web app only, session cookie).
 *
 *   POST /platform/auth/login         { email, password }  -> MFA challenge, or an enrol-only session
 *   POST /platform/auth/mfa/verify    { challenge, code }  -> signed in
 *   POST /platform/auth/password/set  { token, password, password_confirmation }
 *   POST /platform/auth/logout
 *   POST /platform/auth/step-up       { code }
 *   GET  /platform/me                 the admin, MFA state, idle limit
 *   POST /platform/me/mfa/enrol       -> secret and otpauth:// link
 *   POST /platform/me/mfa/enrol/confirm { code } -> recovery codes, shown once
 */
class ConsoleAuthController
{
    public function login(Request $request, ConsoleSignIn $signIn): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $signIn->attempt($request, $input['email'], $input['password'])]);
    }

    public function verifyMfa(Request $request, ConsoleSignIn $signIn): JsonResponse
    {
        $input = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $signIn->verifyChallenge($request, $input['challenge'], $input['code'])]);
    }

    public function setPassword(Request $request, PlatformAdmins $admins): JsonResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'password' => PasswordPolicy::rules(),
        ]);

        $admins->setPassword($input['token'], $input['password']);

        return response()->json(['data' => ['message' => 'Your password is set. Sign in to the console with it now.']]);
    }

    public function logout(Request $request, ConsoleSessions $sessions): JsonResponse
    {
        $sessions->end($request, self::admin($request));

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function stepUp(Request $request, ConsoleSignIn $signIn): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $session = $signIn->stepUp($request, self::admin($request), $input['code']);

        return response()->json(['data' => [
            'stepped_up_until' => $session->step_up_at?->copy()->addMinutes((int) config('identity.platform.step_up.valid_minutes', 10))->toIso8601String(),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = self::admin($request);
        $session = $request->attributes->get('platform_session');

        return response()->json(['data' => [
            'id' => (string) $admin->getKey(),
            'name' => $admin->name,
            'email' => $admin->email,
            'mfa' => ['enabled' => $admin->mfaEnabled(), 'required' => true],
            'session' => [
                'idle_minutes' => (int) config('identity.platform.idle_minutes', 15),
                'ends_at' => $session instanceof PlatformSession
                    ? $session->created_at?->copy()->addHours((int) config('identity.platform.max_hours', 8))->toIso8601String()
                    : null,
                'mfa_enrolment_only' => $session instanceof PlatformSession && $session->limited,
            ],
        ]]);
    }

    public function startEnrolment(Request $request, Mfa $mfa): JsonResponse
    {
        return response()->json(['data' => $mfa->startEnrolment(self::admin($request), 'Platform')]);
    }

    public function confirmEnrolment(Request $request, Mfa $mfa): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $mfa->confirmEnrolment(self::admin($request), $input['code']);

        $session = $request->attributes->get('platform_session');

        if ($session instanceof PlatformSession && $session->limited) {
            $session->forceFill(['limited' => false])->save();
        }

        return response()->json(['data' => ['recovery_codes' => $codes, 'mfa_enrolment_required' => false]]);
    }

    public static function admin(Request $request): PlatformAdmin
    {
        $admin = $request->user();

        return $admin instanceof PlatformAdmin
            ? $admin
            : throw new ApiException('unauthenticated', 'Sign in to continue.', 401);
    }
}
