<?php

/*
| Report cards and year-end (Phase 7). See docs/report/modules/reports.md
| and docs/report/configuration.md.
|
| Template presets are STARTING POINTS ONLY, like the scheme presets: the
| Principal arranges the blocks, uploads the letterhead and matches the
| board's latest format.
*/

return [

    // Every block a template can hold, in a sensible default order.
    'blocks' => ['header', 'student', 'scholastic', 'co_scholastic', 'attendance', 'descriptive', 'remarks', 'result', 'signatures'],

    // Co-scholastic areas a class teacher grades (A to E or the school's own words).
    'co_scholastic' => [
        'areas' => ['work_education' => 'Work education', 'art_education' => 'Art education', 'health_physical' => 'Health and physical education', 'discipline' => 'Discipline'],
        'grades' => ['A', 'B', 'C', 'D', 'E'],
    ],

    'template_presets' => [
        'cbse_1_8' => [
            'label' => 'CBSE-style, Classes I to VIII',
            'blocks' => ['header', 'student', 'scholastic', 'co_scholastic', 'attendance', 'remarks', 'result', 'signatures'],
        ],
        'cbse_9_10' => [
            'label' => 'CBSE-style, Classes IX and X',
            'blocks' => ['header', 'student', 'scholastic', 'co_scholastic', 'attendance', 'remarks', 'result', 'signatures'],
        ],
        'state_board' => [
            'label' => 'Generic state board',
            'blocks' => ['header', 'student', 'scholastic', 'attendance', 'remarks', 'result', 'signatures'],
        ],
        'hpc' => [
            'label' => 'Holistic Progress Card style (descriptive)',
            'blocks' => ['header', 'student', 'descriptive', 'co_scholastic', 'attendance', 'remarks', 'signatures'],
        ],
    ],
    'preset_note' => 'A starting point only. Check against your board\'s latest format and change anything that differs.',

    // Letterhead: an image across the top of the card.
    'letterhead' => ['types' => ['png', 'jpg', 'jpeg'], 'max_kb' => 1024],

    'remark_max' => 400,
    'reissue_reason_min' => 10,

    // A PDF download link works for this long once issued (after the permission check).
    'link_minutes' => 5,

    // PDF rendering: gotenberg (Chromium, with Noto fonts) or fake (testing only).
    'pdf' => [
        'driver' => env('REPORTS_PDF_DRIVER', 'gotenberg'),
        'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'timeout_seconds' => 60,
    ],

    // The public verification page (QR code). The code is 32 random bytes
    // (base64url, 43 characters): it cannot be guessed or listed. Only its
    // SHA-256 is stored. The page is rate-limited per address.
    'verification' => [
        'code_bytes' => 32,
        'per_minute' => 30,
    ],

];
