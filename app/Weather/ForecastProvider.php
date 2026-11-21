<?php

namespace App\Weather;

use Carbon\CarbonImmutable;

interface ForecastProvider
{
    /**
     * Return hourly and daily rows for three local dates starting at startDate.
     * The collector resolves startDate once in the configured location time zone.
     *
     * @return array{hourly: array, daily: array}
     */
    public function fetch(string $lat, string $lon, CarbonImmutable $startDate): array;
}
