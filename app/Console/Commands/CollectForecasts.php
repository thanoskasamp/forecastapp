<?php

namespace App\Console\Commands;

use App\Models\DailyForecast;
use App\Models\HourlyForecast;
use App\Weather\OpenMeteoProvider;
use App\Weather\WeatherApiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class CollectForecasts extends Command
{
    protected $signature = 'forecast:collect';

    protected $description = 'Collect and store three days of forecasts from both providers';

    public function handle(OpenMeteoProvider $openMeteo, WeatherApiProvider $weatherApi): int
    {
        $location = config('forecast.location');
        $validator = Validator::make($location, [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'timezone' => ['required', 'timezone'],
        ]);
        if ($validator->fails()) {
            $message = 'Set valid FORECAST_LAT, FORECAST_LON and FORECAST_LOCATION_TIMEZONE in .env.';
            Log::error($message);
            $this->error($message);

            return self::FAILURE;
        }

        $startDate = CarbonImmutable::today($location['timezone']);
        $successful = 0;
        $failed = 0;
        $hourlyTotal = 0;
        $dailyTotal = 0;

        foreach (['open_meteo' => $openMeteo, 'weatherapi' => $weatherApi] as $name => $provider) {
            try {
                $rows = $provider->fetch((string) $location['lat'], (string) $location['lon'], $startDate);

                DB::transaction(function () use ($rows): void {
                    HourlyForecast::insert($rows['hourly']);
                    DailyForecast::insert($rows['daily']);
                });
            } catch (Throwable $error) {
                $failed++;
                $reason = $this->failureReason($error);
                Log::error('Forecast provider failed', [
                    'provider' => $name, 'hourly' => 0, 'daily' => 0, 'reason' => $reason,
                ]);
                $this->error("{$name}: failed; saved 0 rows. {$reason}");
                continue;
            }

            $hourly = count($rows['hourly']);
            $daily = count($rows['daily']);
            $successful++;
            $hourlyTotal += $hourly;
            $dailyTotal += $daily;
            Log::info('Forecast provider saved', ['provider' => $name, 'hourly' => $hourly, 'daily' => $daily]);
            $this->info("{$name}: saved {$hourly} hourly and {$daily} daily rows.");
        }

        Log::info('Forecast collection summary', [
            'successful' => $successful, 'failed' => $failed,
            'hourly' => $hourlyTotal, 'daily' => $dailyTotal,
        ]);
        $this->info("Collection complete: {$successful} succeeded, {$failed} failed; saved {$hourlyTotal} hourly and {$dailyTotal} daily rows.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function failureReason(Throwable $error): string
    {
        if ($error instanceof QueryException) {
            return 'Database save failed (SQLSTATE '.$error->getCode().').';
        }
        if (! $error instanceof RuntimeException) {
            return 'Unexpected provider failure.';
        }

        $reason = $error->getMessage();
        $key = config('forecast.weatherapi.key');
        if (is_string($key) && $key !== '') {
            $reason = str_replace([$key, rawurlencode($key)], '[redacted]', $reason);
        }

        return $reason;
    }
}
