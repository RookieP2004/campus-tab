<?php

/*
| Identity and access: sign-in, MFA, sessions, passwords and support access.
| Every number a school office might ask about lives here, so it can be
| changed without touching code. See docs/report/configuration.md.
*/

return [

    // Where links in emails and texts point (the first FRONTEND_URL).
    'frontend_url' => rtrim(explode(',', (string) env('FRONTEND_URL', 'http://127.0.0.1:5173'))[0], '/'),

    // Every language the app ships. Every user may pick any of them for their
    // own screens; English is the default for new accounts.
    'languages' => ['en', 'hi', 'bn', 'mr', 'gu', 'pa', 'ta', 'te', 'kn', 'ml', 'or', 'ur'],
    'default_language' => 'en',
    'themes' => ['light', 'dark', 'system'],
    'default_theme' => 'system',

    'mfa' => [
        // Clerk (60) and above must use MFA; it is optional below.
        'required_from_level' => 60,
        'issuer' => 'Campus TAB',
        // Password accepted, waiting for the TOTP code.
        'challenge_minutes' => 5,
        'challenge_max_attempts' => 5,
        'recovery_codes' => 10,
        // TOTP steps of 30 s accepted either side of now (clock drift).
        'window' => 1,
        // A token that only allows MFA enrolment (first sign-in, activation).
        'enrolment_token_minutes' => 30,
    ],

    /*
    | Step-up MFA: these actions ask for a fresh TOTP code. Routes name an
    | action with the middleware step-up:<action>; a name not in this list is
    | a programming error and fails loudly. Later phases add fee rebates,
    | waivers and locked-mark approvals here.
    */
    'step_up' => [
        'valid_minutes' => 10,
        'actions' => [
            'export',
            'permanent_delete',
            'role_edit',          // editing a role and changing a user's roles
            'marks_unlock',
            'support_grant',
            'mfa_reset',          // switching off someone else's MFA (lost authenticator)
        ],
    ],

    'sign_in' => [
        // Per account: this many failures in a row lock it for lockout_minutes.
        'account_max_failures' => 5,
        'lockout_minutes' => 15,
        // Per IP, for bulk abuse only: a school office, a parents' meeting on
        // school Wi-Fi or a mobile network (CGNAT) puts many people behind
        // one address.
        'ip_max_failures' => (int) env('SIGN_IN_IP_MAX_FAILURES', 100),
        'ip_decay_minutes' => 15,
    ],

    'parent_otp' => [
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'sends_per_phone_per_10_minutes' => 3,
        'failures_per_phone_per_hour' => 10,
        'sends_per_ip_per_10_minutes' => (int) env('PARENT_OTP_IP_MAX_SENDS', 60),
        'failures_per_ip_per_15_minutes' => (int) env('PARENT_OTP_IP_MAX_FAILURES', 100),
    ],

    /*
    | Sign in every time: no "remember me", no "trust this device". The
    | browser gets a session cookie only (closing the browser signs out); the
    | apps (Phase 9) keep their token in memory only. Every session and token
    | also ends after a time without use, and after max_hours in total,
    | whichever comes first.
    */
    'sessions' => [
        'idle_minutes_mfa_roles' => 15,   // level 60 and up
        'idle_minutes' => 30,             // everyone else
        'max_hours' => 8,
    ],

    'passwords' => [
        'min_length' => 10,
        // New accounts: a one-time set-password link (or setup code).
        'set_link_hours' => 48,
        // Reset links: forgot password, or sent by the Principal or Chief.
        'reset_link_minutes' => 30,
        // Forgot-password requests per account per hour (silently capped).
        'reset_requests_per_hour' => 3,
        'reset_requests_per_ip_per_15_minutes' => (int) env('PASSWORD_RESET_IP_MAX', 100),
        // Bundled list; no outside API is called.
        'common_list' => resource_path('security/common-passwords.txt'),
    ],

    'support' => [
        'min_minutes' => 15,
        'max_hours' => 72,
        /*
        | What a Chief can open to support. Each area lists the permission
        | modules it covers. Read-only grants get the view actions only.
        */
        'areas' => [
            'settings' => ['config'],
            'users' => ['users', 'roles'],
            'audit' => ['audit'],
            'students' => ['students'],
            'attendance' => ['attendance', 'staff_attendance'],
            'leave' => ['leave', 'student_leave'],
            'assessment' => ['assessment', 'exams', 'tests', 'marks', 'results', 'remarks'],
            'fees' => ['fees'],
            'communication' => ['notices'],
        ],
        // Never available to support, whatever the grant says.
        'never' => ['support.*', 'records.purge', 'roles.edit', 'users.edit_roles', 'export.*', 'marks.unlock', 'marks.correction_approve'],
    ],

];
