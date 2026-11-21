<?php

namespace App\Weather;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WeatherApiProvider implements ForecastProvider
{
    public function fetch(string $lat, string $lon, CarbonImmutable $startDate): array
    {
        $start = $startDate->startOfDay();
        $end = $start->addDays(3);
        $response = $this->requestForecast($lat, $lon, $start->timezoneName);
        $expectedDates = [
            $start->toDateString(),
            $start->addDay()->toDateString(),
            $start->addDays(2)->toDateString(),
        ];
        $days = [];

        foreach ($response['days'] as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null)) {
                throw new RuntimeException('WeatherAPI returned an invalid forecast date.');
            }
            if (in_array($day['date'], $expectedDates, true)) {
                $days[] = $day;
            }
        }

        $baseRow = [
            'collected' => $response['collected'],
            'provider' => 'weatherapi',
            'lat' => $lat,
            'lon' => $lon,
        ];
        $hourly = $this->hourlyRows($days, $baseRow, $start, $end);
        $daily = $this->dailyRows($days, $baseRow);
        $dates = array_column($daily, 'time');
        sort($dates);
        $expectedHours = (int) $start->diffInHours($end);

        if ($dates !== $expectedDates || count($hourly) !== $expectedHours
            || count(array_unique(array_column($hourly, 'time'))) !== $expectedHours) {
            throw new RuntimeException('WeatherAPI returned incomplete three-day forecast coverage.');
        }

        return ['hourly' => $hourly, 'daily' => $daily];
    }

    private function requestForecast(string $lat, string $lon, string $timezone): array
    {
        $key = config('forecast.weatherapi.key');
        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException('Set WEATHERAPI_KEY in .env before collecting WeatherAPI forecasts.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->withoutRedirecting()
                ->retry(2, 1000, function (Exception $error): bool {
                    return $error instanceof ConnectionException
                        || ($error instanceof RequestException && $error->response->serverError());
                }, throw: false)
                ->get('https://api.weatherapi.com/v1/forecast.json', [
                    'key' => $key,
                    'q' => $lat.','.$lon,
                    'days' => 3,
                    'aqi' => 'no',
                    'alerts' => 'no',
                ]);
        } catch (ConnectionException) {
            // Transport exceptions may contain the key in the request URL.
            throw new RuntimeException('WeatherAPI connection failed after two attempts.');
        }

        $collected = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');

        if (! $response->successful()) {
            $message = 'WeatherAPI returned HTTP '.$response->status();
            $code = $response->json('error.code');
            if (is_int($code)) {
                $message .= ' (code '.$code.')';
            }
            throw new RuntimeException($message.'.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['location']['tz_id'] ?? null) !== $timezone) {
            throw new RuntimeException('WeatherAPI returned an invalid response or unexpected location time zone.');
        }

        $days = $data['forecast']['forecastday'] ?? null;
        if (! is_array($days) || ! array_is_list($days)) {
            throw new RuntimeException('WeatherAPI returned an invalid forecast section.');
        }

        return ['days' => $days, 'collected' => $collected];
    }

    private function hourlyRows(array $days, array $baseRow, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];
        foreach ($days as $day) {
            $hours = $day['hour'] ?? null;
            if (! is_array($hours) || ! array_is_list($hours)) {
                throw new RuntimeException('WeatherAPI returned an invalid hourly section.');
            }

            foreach ($hours as $hour) {
                if (! is_array($hour) || ! is_int($hour['time_epoch'] ?? null)) {
                    throw new RuntimeException('WeatherAPI returned an invalid hourly timestamp.');
                }

                $time = CarbonImmutable::createFromTimestampUTC($hour['time_epoch']);
                if ($time->lessThan($start) || $time->greaterThanOrEqualTo($end)) {
                    continue;
                }

                $row = $baseRow;
                $row['time'] = $time->format('Y-m-d H:i:sP');
                $row['temp'] = $this->measurement($hour['temp_c'] ?? null);
                $row['prec'] = $this->measurement($hour['precip_mm'] ?? null);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function dailyRows(array $days, array $baseRow): array
    {
        $rows = [];
        foreach ($days as $day) {
            $summary = $day['day'] ?? null;
            if (! is_array($summary)) {
                throw new RuntimeException('WeatherAPI returned an invalid daily section.');
            }

            $row = $baseRow;
            $row['time'] = $day['date'];
            $row['temp'] = $this->measurement($summary['avgtemp_c'] ?? null);
            $row['prec'] = $this->measurement($summary['totalprecip_mm'] ?? null);
            $rows[] = $row;
        }

        return $rows;
    }

    private function measurement(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('WeatherAPI returned a non-numeric measurement.');
        }

        return (float) $value;
    }
}
