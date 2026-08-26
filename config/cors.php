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

    'allowed_origins' => [
        'https://adhyayanguru.shop',
        'https://www.adhyayanguru.shop',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)*adhyayanguru\.shop$#i',
        '#^http://192\.168\.\d+\.\d+(:\d+)?$#',
        '#^http://10\.\d+\.\d+\.\d+(:\d+)?$#',
        '#^http://172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+(:\d+)?$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
