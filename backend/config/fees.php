<?php

/*
| Fees (Phase 8). See docs/report/modules/fees.md and decision 0015.
|
| Money is whole paise (integers) everywhere: in the database, in every
| calculation and in the API (fields end in _paise). Rupee strings typed on
| screen are parsed exactly (Money::parse), never through floating point.
*/

return [

    'modes' => ['cash', 'cheque', 'dd', 'bank_transfer', 'upi_counter', 'online'],

    // Counter modes whose money is not certain yet: they wait for clearance.
    'clearance_modes' => ['cheque', 'dd'],

    'adjustment_kinds' => ['rebate', 'waiver', 'fine_waiver', 'refund', 'scholarship', 'carry_forward'],

    // An instalment's dues are raised this many days before it falls due
    // (the year-start run raises everything already inside the window).
    'raise_days_before' => 30,

    // A bounced cheque reverses its payment; the school may add this fine (paise, 0 = none).
    'bounce_fine_paise' => (int) env('FEES_BOUNCE_FINE_PAISE', 0),

    'reason_min' => 10,

    // Online payments. Razorpay (test mode) is the real driver; "fake" is a
    // local stand-in that simulates checkout and sends a correctly signed
    // webhook, allowed only when APP_ENV is local or testing.
    'gateway' => [
        'drivers' => ['razorpay', 'fake'],
        'fake_environments' => ['local', 'testing'],
        'currency' => 'INR',
        'razorpay_api' => env('RAZORPAY_API_URL', 'https://api.razorpay.com/v1'),
        'timeout_seconds' => 15,
        // An unpaid order is not reused after this long; a new one is made.
        'order_minutes' => 30,
        // Where the fake gateway's queue job sends its webhook (the API as the worker reaches it).
        'fake_webhook_url' => env('FAKE_GATEWAY_WEBHOOK_URL', rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/v1/webhooks/payments/fake'),
    ],

    // Excel exports of fee reports (step-up "export", audited).
    'reports' => ['types' => ['daily', 'dues', 'heads', 'adjustments', 'cheques'], 'max_rows' => 20000],

];
