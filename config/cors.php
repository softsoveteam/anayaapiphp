<?php

$frontend = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'index.php/api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(array_merge($frontend, [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://anaya.softsove.life',
        'https://www.anaya.softsove.life',
    ])))),

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?softsove\.life$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
