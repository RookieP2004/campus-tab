<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\ParentOtpSignIn;
use App\Modules\Identity\Domain\PasswordLinks;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Domain\SignInService;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Signing in and out.
 *
 *   POST /auth/login            { school_code, login, password, device_name? }
 *   POST /auth/mfa/verify       { challenge, code, device_name? }
 *   POST /auth/otp/request      { school_code, phone }              (parents)
 *   POST /auth/otp/verify       { school_code, phone, code, device_name? }
 *   POST /auth/password/forgot  { school_code, login }
 *   POST /auth/password/set     { school_code, token, password, password_confirmation }
 *   POST /auth/logout
 *   POST /auth/step-up          { code }
 *
 * A request from the web app gets the session cookie; any other client
 * gets a bearer token named after device_name.
 */
class AuthController
{
    public function login(Request $request, SignInService $signIn): JsonResponse
    {
        $input = $request->validate([
            'school_code' => ['required', 'string', 'max:30'],
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $signIn->attempt($request, $input['school_code'], $input['login'], $input['password'])]);
    }

    public function verifyMfa(Request $request, SignInService $signIn): JsonResponse
    {
        $input = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $signIn->verifyChallenge($request, $input['challenge'], $input['code'])]);
    }

    public function requestOtp(Request $request, ParentOtpSignIn $otp): JsonResponse
    {
        $input = $request->validate([
            'school_code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $otp->request($request, $input['school_code'], $input['phone'])], 202);
    }

    public function verifyOtp(Request $request, ParentOtpSignIn $otp): JsonResponse
    {
        $input = $request->validate([
            'school_code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $otp->verify($request, $input['school_code'], $input['phone'], $input['code'])]);
    }

    public function forgotPassword(Request $request, PasswordLinks $links): JsonResponse
    {
        $input = $request->validate([
            'school_code' => ['required', 'string', 'max:30'],
            'login' => ['required', 'string', 'max:150'],
        ]);

        $links->forgot($request, $input['school_code'], $input['login']);

        return response()->json(['data' => [
            'message' => 'If an account matches, a link to reset the password has been sent to its email or phone.',
        ]], 202);
    }

    public function setPassword(Request $request, PasswordLinks $links): JsonResponse
    {
        $input = $request->validate([
            'school_code' => ['required', 'string', 'max:30'],
            'token' => ['required', 'string', 'max:200'],
            'password' => PasswordPolicy::rules(),
            // The language picked on the set-password page becomes the person's own.
            'language' => ['sometimes', 'string', Rule::in(config('identity.languages'))],
        ]);

        $user = $links->consume($input['school_code'], $input['token'], $input['password']);

        if (isset($input['language'])) {
            app(TenantContext::class)->run($user->tenant_id, fn () => $user->forceFill(['language' => $input['language']])->save());
        }

        return response()->json(['data' => ['message' => 'Your password is set. Sign in with it now.']]);
    }

    public function logout(Request $request, SessionIssuer $sessions): JsonResponse
    {
        $sessions->endCurrent($request);

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function stepUp(Request $request, SignInService $signIn): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:20']]);

        /** @var User $user */
        $user = $request->user();
        $session = $signIn->stepUp($request, $user, $input['code']);

        return response()->json(['data' => [
            'stepped_up_until' => $session->step_up_at?->copy()->addMinutes((int) config('identity.step_up.valid_minutes', 10))->toIso8601String(),
        ]]);
    }
}
