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
    | waivers here (done in Phase 8).
    */
    'step_up' => [
        'valid_minutes' => 10,
        'actions' => [
            'export',
            'permanent_delete',
            'role_edit',          // editing a role and changing a user's roles
            'marks_correction_approve', // the Principal approving a change to a locked mark (Phase 6)
            'support_grant',
            'mfa_reset',          // switching off someone else's MFA (lost authenticator)
            'fees_adjust_approve',    // approving a rebate, waiver, refund or other fee adjustment (Phase 8)
            'receipt_cancel_approve', // approving a receipt cancellation (Phase 8)
            'fees_carry_forward',     // the year-end wizard's one approval for every unpaid balance
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
        'min_length' => 12, // OWASP ASVS 2.1.1 (owner's decision, 28 November 2026)
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

    /*
    | Requests the screens send on their own (TanStack Query refetches, polling,
    | later the notification socket) carry this header. They are still checked,
    | but they never count as use, so they cannot keep an idle session alive.
    */
    'background_header' => 'X-Background-Request',

    /*
    | The platform console (our team). Platform admins are created only by
    | php artisan platform:create-admin. Password and TOTP at every sign-in,
    | the same sign-in-every-time rules as school users.
    */
    'platform' => [
        'idle_minutes' => 15,
        'max_hours' => 8,
        'set_link_hours' => 48,
        'step_up' => [
            'valid_minutes' => 10,
            'actions' => ['licence_revoke', 'chief_recovery'],
        ],
        // Per-school numbers, worked out every 15 minutes inside each school.
        'stats_every_minutes' => 15,
        /*
        | Alerts: a metric "jumps" when its count in the last hour reaches
        | min_per_hour AND is at least `factor` times its usual hourly rate
        | over the 7 days before. One alert per school and metric per
        | repeat_after_hours. Counts only; details stay in the school's log.
        */
        'alerts' => [
            'factor' => 5,
            'repeat_after_hours' => 6,
            'min_per_hour' => [
                'failed_sign_ins' => 20,
                'lockouts' => 5,
                'password_resets' => 10,
                'mfa_resets' => 3,
                'chief_changes' => 2,
            ],
        ],
        // Sole-Chief recovery: the code sent to the school's registered contact.
        'chief_recovery' => [
            'code_ttl_minutes' => 30,
            'max_attempts' => 5,
            'sends_per_10_minutes' => 3,
            'reason_min_length' => 20,
        ],
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
        'never' => ['support.*', 'records.purge', 'roles.edit', 'users.edit_roles', 'export.*', 'marks.correction_approve'],
    ],

];
