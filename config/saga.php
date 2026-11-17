<?php

return [
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'mws'),
        'password' => env('RABBITMQ_PASSWORD', 'mws-local-change-me'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
];
