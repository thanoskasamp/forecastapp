<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL'),
        'trace' => env('LOG_DEPRECATIONS_TRACE'),
    ],
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'processors' => [PsrLogMessageProcessor::class],
        ],
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],
];
