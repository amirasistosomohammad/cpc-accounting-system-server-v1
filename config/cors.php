<?php

return [
    'paths' => [
        'api/*',
        'login',
        'logout',
        'admin/login',
        'personnel/login',
        'sanctum/csrf-cookie',
        'user',
        'storage/*',
        'avatars/*',
        'personnel-avatar/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
        'https://cpc-client-vj8bx.ondigitalocean.app',
        'https://cpc-client-vj8hx.ondigitalocean.app',
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

    'max_age' => 86400,

    'supports_credentials' => true,
];
