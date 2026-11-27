<?php

namespace App\Modules\Licensing\Http\Controllers;

use App\Modules\Licensing\Domain\ActivationService;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Activating a school with its licence key (no sign-in yet).
 *
 *   POST /licences/activate/otp  { key }                         -> code sent
 *   POST /licences/activate      { key, otp, chief: {...} }      -> Chief created
 */
class ActivationController
{
    public function __construct(private readonly ActivationService $activation) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $input = $request->validate(['key' => ['required', 'string', 'max:40']]);

        return response()->json(['data' => $this->activation->requestOtp($input['key'])]);
    }

    public function activate(Request $request): JsonResponse
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'otp' => ['required', 'string', 'digits:6'],
            'chief.name' => ['required', 'string', 'min:2', 'max:120'],
            'chief.email' => ['required', 'email', 'max:150'],
            'chief.phone' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'chief.password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $result = $this->activation->activate($input['key'], $input['otp'], [
            'name' => $input['chief']['name'],
            'email' => $input['chief']['email'],
            'phone' => $input['chief']['phone'],
            'password' => $input['chief']['password'],
        ]);

        return response()->json(['data' => [
            'school' => ['code' => $result['tenant']->code, 'name' => $result['tenant']->name],
            'user' => [
                'id' => (string) $result['user']->getKey(),
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'roles' => $result['user']->roles,
            ],
            'mfa_enrolment_required' => true,
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'token_abilities' => ['mfa:enrol'],
            'token_expires_at' => $this->expiry($result['token']->accessToken->getAttribute('expires_at')),
        ]], 201);
    }

    private function expiry(mixed $expiresAt): ?string
    {
        return $expiresAt instanceof DateTimeInterface ? $expiresAt->format(DATE_ATOM) : null;
    }
}
