<?php

/*
| Exports (Phase 9): every Excel or PDF export carries a watermark (who,
| when, which school) and is audited; one with at least this many rows
| alerts the Chief. A school can set its own number in
| policies.exports.chief_alert_rows.
*/

return [
    'large_rows' => (int) env('EXPORT_LARGE_ROWS', 1000),
];
