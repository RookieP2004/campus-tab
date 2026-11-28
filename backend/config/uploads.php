<?php

/*
| Virus scanning of every upload (Phase 9 security pass). clamav in every
| real environment (the `clamav` container locally); fake only in tests,
| where it finds the EICAR test string. A scanner that cannot be reached
| refuses the upload.
*/

return [
    'scanner' => env('VIRUS_SCAN_DRIVER', env('APP_ENV') === 'testing' ? 'fake' : 'clamav'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (float) env('CLAMAV_TIMEOUT', 5),
    ],
];
