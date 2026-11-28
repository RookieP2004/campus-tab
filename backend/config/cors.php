<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Only the web app may call the API from a browser; set FRONTEND_URL
    // (comma-separated for several) per environment.
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('FRONTEND_URL', 'http://127.0.0.1:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

    // The apps' local web-view origins (Phase 9). Allowed without
    // credentials, bearer token only (App\Support\Http\AppOriginCors).
    'app_origins' => env('APP_ORIGINS', 'https://localhost,capacitor://localhost,http://tauri.localhost,tauri://localhost'),

];
