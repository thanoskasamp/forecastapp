<?php

return [
    'location' => [
        'lat' => env('FORECAST_LAT'),
        'lon' => env('FORECAST_LON'),
        'timezone' => env('FORECAST_LOCATION_TIMEZONE'),
    ],

    'schedule' => [
        'time' => env('FORECAST_COLLECTION_TIME'),
        'timezone' => env('FORECAST_SCHEDULE_TIMEZONE'),
    ],

    'weatherapi' => [
        'key' => env('WEATHERAPI_KEY'),
    ],
];
