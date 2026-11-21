<?php

namespace App\Weather;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenMeteoProvider implements ForecastProvider
{
    public function fetch(string $lat, string $lon, CarbonImmutable $startDate): array
    {
        $start = $startDate->startOfDay();
        $end = $start->addDays(3);
        $response = $this->requestForecast($lat, $lon, $start, $end);
        $data = $response['data'];
        $baseRow = [
            'collected' => $response['collected'],
            'provider' => 'open_meteo',
            'lat' => $lat,
            'lon' => $lon,
        ];

        $hourly = $this->hourlyRows($data['hourly'] ?? null, $baseRow, $start, $end);
        $daily = $this->dailyRows($data['daily'] ?? null, $baseRow, $start, $end, $response['offset']);

        $expectedHours = (int) $start->diffInHours($end);
        if (count($hourly) !== $expectedHours
            || count(array_unique(array_column($hourly, 'time'))) !== $expectedHours
            || count($daily) !== 3
            || count(array_unique(array_column($daily, 'time'))) !== 3) {
            throw new RuntimeException('Open-Meteo returned incomplete three-day forecast coverage.');
        }

        return ['hourly' => $hourly, 'daily' => $daily];
    }

    private function requestForecast(string $lat, string $lon, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $requestOffset = CarbonImmutable::now($start->timezone)->offset;

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->withoutRedirecting()
                ->retry(2, 1000, function (Exception $error): bool {
                    return $error instanceof ConnectionException
                        || ($error instanceof RequestException && $error->response->serverError());
                }, throw: false)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'hourly' => 'temperature_2m,precipitation',
                    'daily' => 'temperature_2m_mean,precipitation_sum',
                    'temperature_unit' => 'celsius',
                    'precipitation_unit' => 'mm',
                    'timezone' => $start->timezoneName,
                    'timeformat' => 'unixtime',
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->subDay()->toDateString(),
                    // Open-Meteo uses a fixed request-time offset, even across DST.
                    'start_hour' => $start->utc()->addSeconds($requestOffset)->format('Y-m-d\TH:i'),
                    'end_hour' => $end->utc()->subHour()->addSeconds($requestOffset)->format('Y-m-d\TH:i'),
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Open-Meteo connection failed after two attempts.');
        }

        $collected = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');

        if (! $response->successful()) {
            throw new RuntimeException('Open-Meteo returned HTTP '.$response->status().'.');
        }

        $data = $response->json();

        if (! is_array($data) || ($data['utc_offset_seconds'] ?? null) !== $requestOffset) {
            throw new RuntimeException('Open-Meteo returned an invalid response or unexpected UTC offset.');
        }

        if (($data['hourly_units']['temperature_2m'] ?? null) !== '°C'
            || ($data['hourly_units']['precipitation'] ?? null) !== 'mm'
            || ($data['daily_units']['temperature_2m_mean'] ?? null) !== '°C'
            || ($data['daily_units']['precipitation_sum'] ?? null) !== 'mm') {
            throw new RuntimeException('Open-Meteo returned unexpected measurement units.');
        }

        return ['data' => $data, 'collected' => $collected, 'offset' => $requestOffset];
    }

    private function hourlyRows(mixed $section, array $baseRow, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $this->validateSection($section, 'temperature_2m', 'precipitation');
        $rows = [];

        foreach ($section['time'] as $index => $timestamp) {
            if (! is_int($timestamp)) {
                throw new RuntimeException('Open-Meteo returned an invalid forecast timestamp.');
            }

            $hour = CarbonImmutable::createFromTimestampUTC($timestamp);
            if ($hour->lessThan($start) || $hour->greaterThanOrEqualTo($end)) {
                continue;
            }

            $row = $baseRow;
            $row['time'] = $hour->format('Y-m-d H:i:sP');
            $row['temp'] = $this->measurement($section['temperature_2m'][$index] ?? null);
            $row['prec'] = $this->measurement($section['precipitation'][$index] ?? null);
            $rows[] = $row;
        }

        return $rows;
    }

    private function dailyRows(
        mixed $section,
        array $baseRow,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $offset,
    ): array {
        $this->validateSection($section, 'temperature_2m_mean', 'precipitation_sum');
        $firstDate = $start->toDateString();
        $endDate = $end->toDateString();
        $rows = [];

        foreach ($section['time'] as $index => $timestamp) {
            if (! is_int($timestamp)) {
                throw new RuntimeException('Open-Meteo returned an invalid forecast timestamp.');
            }

            // Preserve the provider's daily label, using its fixed offset.
            $date = CarbonImmutable::createFromTimestampUTC($timestamp)->addSeconds($offset)->toDateString();
            if ($date < $firstDate || $date >= $endDate) {
                continue;
            }

            $row = $baseRow;
            $row['time'] = $date;
            $row['temp'] = $this->measurement($section['temperature_2m_mean'][$index] ?? null);
            $row['prec'] = $this->measurement($section['precipitation_sum'][$index] ?? null);
            $rows[] = $row;
        }

        return $rows;
    }

    private function validateSection(mixed $section, string $tempField, string $precField): void
    {
        if (! is_array($section) || ! isset($section['time'])
            || ! is_array($section['time']) || ! array_is_list($section['time'])) {
            throw new RuntimeException('Open-Meteo returned an invalid forecast section.');
        }

        foreach ([$tempField, $precField] as $field) {
            if (isset($section[$field]) && (! is_array($section[$field])
                || ! array_is_list($section[$field])
                || count($section[$field]) !== count($section['time']))) {
                throw new RuntimeException('Open-Meteo returned mismatched measurement arrays.');
            }
        }
    }

    private function measurement(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new RuntimeException('Open-Meteo returned a non-numeric measurement.');
        }

        return (float) $value;
    }
}
