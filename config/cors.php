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

    'paths' => [
        'api/*',
        'login',
        'logout',
        'admin/login',
        'personnel/login',
        'sanctum/csrf-cookie',
        'user',
        'storage/*',
        'personnel-avatar/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
        'https://cpc-client-vj8bx.ondigitalocean.app',
        'https://cpc-client-vj8bx.ondigitalocean.app/',
        'https://cpc-client-vj8hx.ondigitalocean.app',
        'https://cpc-client-vj8hx.ondigitalocean.app/',
    ],

    'allowed_origins_patterns' => [
        '#^https://cpc-client-.*\.ondigitalocean\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Requested-With',
        'Content-Type',
        'X-Account-Id',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 86400, // 24 hours

    'supports_credentials' => true,

];
