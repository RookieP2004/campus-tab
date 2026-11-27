<?php

return [

    /*
    | Licence keys are stored only as an HMAC-SHA256 under this pepper. Change
    | it and every issued key stops working. Generate with:
    |   php artisan licence:keygen
    */
    'key_pepper' => env('LICENCE_KEY_PEPPER'),

    /*
    | Ed25519 key pair (base64) that signs each licence payload. The secret key
    | stays on the server that issues licences; the public key verifies them.
    | Generate with:  php artisan licence:keygen
    */
    'signing' => [
        'secret_key' => env('LICENCE_SIGNING_SECRET_KEY'),
        'public_key' => env('LICENCE_SIGNING_PUBLIC_KEY'),
    ],

    // Days after valid_to with a warning banner, before the school turns read-only.
    'grace_days' => (int) env('LICENCE_GRACE_DAYS', 15),

    'plans' => ['starter', 'standard', 'premium'],

    // Modules a licence can switch on. Core modules (tenancy, identity,
    // academic structure, people, customisation, audit) are always on.
    'modules' => ['attendance', 'leave', 'assessment', 'reports', 'fees', 'communication'],

    'activation' => [
        'otp_ttl_minutes' => 10,
        'otp_max_attempts' => 5,
        'otp_sends_per_10_minutes' => 3,
        // The first session only allows MFA enrolment; its length is
        // identity.mfa.enrolment_token_minutes.
    ],

];
