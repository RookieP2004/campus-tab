<?php

/*
| DPDP Act 2023 features (Phase 9). The school is the data fiduciary; we are
| its processor. Every number here is a starting point for the pilot and
| needs the legal review listed in docs/report/pilot/before-going-live.md.
*/

return [

    // Purposes a parent consents to, from their own account. Education and
    // safety processing that is strictly necessary needs no consent (the
    // school exemption); these are the optional extras.
    'purposes' => [
        'pen' => 'Keep the PEN (Permanent Education Number) on the record',
        'apaar' => 'Keep the APAAR ID on the record',
        'photo_public' => 'Use photos on the school website, notice boards or social media',
        'third_party_sharing' => 'Share details with outside partners (trips, competitions, insurance)',
    ],

    // A request (access, correction, erasure) is due this many days after it
    // arrives. The inbox shows what is late.
    'request_due_days' => (int) env('PRIVACY_REQUEST_DUE_DAYS', 30),

    // Retention: how long each kind of data is kept. A daily job only
    // REPORTS what is past its time (a dry run); nothing is deleted until the
    // Chief reads that report and confirms it with a fresh MFA code.
    'retention' => [
        // Read in-app notifications.
        'notifications' => ['days' => 365, 'label' => 'Read notifications'],
        // Ended sign-ins (device sessions).
        'device_sessions' => ['days' => 180, 'label' => 'Ended sign-ins on devices'],
        // Photos of paper attendance registers (late entries).
        'register_photos' => ['days' => 730, 'label' => 'Photos of paper attendance registers'],
        // Documents attached to decided leave requests.
        'leave_documents' => ['days' => 730, 'label' => 'Documents on decided leave requests'],
        // Uploaded import files and their row-by-row check reports.
        'import_batches' => ['days' => 90, 'label' => 'Import files and their check reports'],
        // Notice read receipts.
        'notice_reads' => ['days' => 365, 'label' => 'Notice read receipts'],
    ],
    // Never in a retention job (owner's decision 2026-11-28, decision 0017):
    // the audit log and the fee ledger are legal records, kept unchanged.

    // Incidents: the Data Protection Board must hear of a personal data
    // breach without delay, with the full report within 72 hours.
    'breach_report_hours' => 72,
];
