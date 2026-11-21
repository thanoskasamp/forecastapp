<?php

return [
    'default' => env('QUEUE_CONNECTION'),
    'connections' => [
        'sync' => ['driver' => 'sync'],
    ],
    'failed' => ['driver' => 'null'],
];
