<?php

return [
    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie', 'user', 'storage/*', 'avatars/*', 'personnel-avatar/*'],

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
        '#^https://cpc-client-[a-z0-9-]+\.ondigitalocean\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];