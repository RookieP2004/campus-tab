<?php

/*
| Communication: notices and notifications. See docs/report/configuration.md.
*/

return [

    'notices' => [
        // Attachments: types and size. Stored privately under
        // tenants/<tenant_id>/notices/, served only by signed links.
        'attachment_types' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'],
        'attachment_max_kb' => 5120,
        'attachments_per_notice' => 5,
        // How long a download link works once issued.
        'link_minutes' => 5,
        // Notices older than this drop out of the list.
        'list_days' => 120,
    ],

    // Push driver: log and array only in local and testing; none until the
    // apps and an FCM project exist (Phase 9).
    'push_driver' => env('PUSH_DRIVER'),

    // New accounts: push on, email off. Each person can change both and mute types.
    'default_preferences' => ['push' => true, 'email' => false],

    /*
    | What push and email say. Never a name, mark or other private detail:
    | lock screens are public. :school is the school's name.
    */
    'generic_texts' => [
        'notice' => 'New notice from :school. Open Campus TAB to read it.',
        'attendance.absent' => 'Attendance update from :school. Open Campus TAB to see it.',
        'default' => 'There is something new for you from :school. Open Campus TAB to see it.',
    ],

    // Types a person can mute in their preferences.
    'types' => ['notice', 'attendance.absent'],

];
