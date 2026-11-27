<?php

/*
| Attendance (Phase 4). The school's own rules (edit window, late-entry days,
| late time, geofence) live in school config policies.attendance.
*/

return [
    // The office QR code changes this often (minutes); the one before is still accepted.
    'qr_minutes' => (int) env('ATTENDANCE_QR_MINUTES', 5),
];
