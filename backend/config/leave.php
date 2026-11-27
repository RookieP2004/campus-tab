<?php

/*
| Leave (Phase 5). School choices (the chain, clerk_can_reject, reminder and
| escalation hours) live in the school config under policies.leave; leave
| types and quotas are records the Principal keeps. These are the numbers a
| school office would not change. See docs/report/configuration.md.
*/

return [

    // Supporting documents (for example a medical certificate). Stored
    // encrypted under tenants/<tenant_id>/leave/<request_id>/, served only
    // through short-lived signed links to the applicant and the chain.
    'documents' => [
        'types' => ['pdf', 'png', 'jpg', 'jpeg', 'webp'],
        'max_kb' => 5120,
        'per_request' => 3,
        'link_minutes' => 5,
    ],

    // The longest leave one request may cover, in calendar days.
    'max_span_days' => 180,

    // Days of the week that are always off (ISO: 7 = Sunday). Holidays come
    // from the approved holiday calendar.
    'weekly_off' => [7],

    // Starter leave types for a new school or the demo seeder. The Principal
    // changes names, quotas and rules (php artisan leave:seed-types).
    'starter_types' => [
        ['code' => 'CL', 'name' => 'Casual leave', 'quota' => 12, 'sandwich' => false, 'half_day' => true, 'document_after_days' => null],
        ['code' => 'SL', 'name' => 'Sick leave', 'quota' => 10, 'sandwich' => false, 'half_day' => true, 'document_after_days' => 2],
        ['code' => 'EL', 'name' => 'Earned leave', 'quota' => 15, 'sandwich' => true, 'half_day' => false, 'document_after_days' => null],
        ['code' => 'DL', 'name' => 'Duty leave', 'quota' => null, 'sandwich' => false, 'half_day' => true, 'document_after_days' => null],
        ['code' => 'LWP', 'name' => 'Leave without pay', 'quota' => null, 'sandwich' => true, 'half_day' => true, 'document_after_days' => null],
    ],

];
