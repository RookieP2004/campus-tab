<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Licensing\Domain\ActivationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Activating a school with its licence key (no sign-in yet).
 *
 *   POST /licences/activate/otp  { key }                         -> code sent
 *   POST /licences/activate      { key, otp, chief: {...} }      -> Chief created
 *
 * The Chief's first session (a token, or the cookie from the web app) only
 * allows MFA enrolment.
 */
class ActivationController
{
    public function __construct(private readonly ActivationService $activation) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['key' => ['required', 'string', 'max:40']]);

        return response()->json(['data' => $this->activation->requestOtp($input['key'])]);
    }

    public function activate(Request $request, SessionIssuer $sessions, TenantContext $context): JsonResponse
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'otp' => ['required', 'string', 'digits:6'],
            'chief.name' => ['required', 'string', 'min:2', 'max:120'],
            'chief.email' => ['required', 'email', 'max:150'],
            'chief.phone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'chief.password' => PasswordPolicy::rules(),
            'chief.language' => ['sometimes', 'string', Rule::in(config('identity.languages'))],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $email = mb_strtolower($input['chief']['email']);
        PasswordPolicy::assertNotPersonal($input['chief']['password'], array_values(array_filter([
            $email, strstr($email, '@', true) ?: null, $input['chief']['phone'], $input['chief']['name'],
            ...preg_split('/\s+/', trim($input['chief']['name'])) ?: [],
        ])), 'chief.password');

        $result = $this->activation->activate($input['key'], $input['otp'], [
            'name' => $input['chief']['name'],
            'email' => $input['chief']['email'],
            'phone' => $input['chief']['phone'],
            'password' => $input['chief']['password'],
        ]);

        $user = $result['user'];

        // The language chosen while activating becomes the Chief's own.
        if (isset($input['chief']['language'])) {
            $context->run($result['tenant']->id, fn () => $user->forceFill(['language' => $input['chief']['language']])->save());
        }
        $session = $context->run($result['tenant']->id, fn () => $sessions->issue($request, $user, 'activation', limited: true));

        return response()->json(['data' => array_merge([
            'school' => ['code' => $result['tenant']->code, 'name' => $result['tenant']->name],
            'user' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles,
            ],
        ], $session)], 201);
    }
}
