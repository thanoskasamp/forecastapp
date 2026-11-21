<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('forecast:collect')
    ->dailyAt(config('forecast.schedule.time'))
    ->timezone(config('forecast.schedule.timezone'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/forecast-collection.log'));
