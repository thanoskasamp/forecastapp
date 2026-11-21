<?php

namespace Tests\Feature;

use App\Weather\OpenMeteoProvider;
use App\Weather\WeatherApiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CollectForecastsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $openMeteo;

    private MockInterface $weatherApi;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'forecast.location' => ['lat' => '40.626430', 'lon' => '22.732910', 'timezone' => 'Europe/Athens'],
            'forecast.weatherapi.key' => 'test-api-key',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-21T21:59:59Z'));
        $this->openMeteo = $this->mock(OpenMeteoProvider::class);
        $this->weatherApi = $this->mock(WeatherApiProvider::class);
        Http::preventStrayRequests();
        Log::spy();
    }

    public function test_both_providers_save_with_one_shared_window_even_across_midnight(): void
    {
        $this->openMeteo->shouldReceive('fetch')->once()
            ->with('40.626430', '22.732910', Mockery::on(fn ($date) => $date->toDateString() === '2026-11-21'))
            ->andReturnUsing(function () {
                $this->travel(2)->seconds();

                return $this->responseRows('open_meteo', '2026-11-21 21:59:59.123456+00');
            });
        $this->weatherApi->shouldReceive('fetch')->once()
            ->with('40.626430', '22.732910', Mockery::on(fn ($date) => $date->toDateString() === '2026-11-21'))
            ->andReturn($this->responseRows('weatherapi', '2026-11-21 22:00:01.123456+00'));

        $this->artisan('forecast:collect')
            ->expectsOutput('Collection complete: 2 succeeded, 0 failed; saved 2 hourly and 2 daily rows.')
            ->assertSuccessful();

        $this->assertDatabaseCount('hourly_forecasts', 2);
        $this->assertDatabaseCount('daily_forecasts', 2);
        foreach (['hourly_forecasts', 'daily_forecasts'] as $table) {
            $this->assertDatabaseHas($table, ['provider' => 'open_meteo', 'collected' => '2026-11-21 21:59:59.123456+00']);
            $this->assertDatabaseHas($table, ['provider' => 'weatherapi', 'collected' => '2026-11-21 22:00:01.123456+00']);
        }
        Log::shouldHaveReceived('info')->with('Forecast collection summary', [
            'successful' => 2, 'failed' => 0, 'hourly' => 2, 'daily' => 2,
        ])->once();
    }

    public function test_a_new_collection_preserves_existing_history(): void
    {
        $this->openMeteo->shouldReceive('fetch')->twice()->andReturn(
            $this->responseRows('open_meteo', '2026-11-21 06:00:00+00'),
            $this->responseRows('open_meteo', '2026-11-22 06:00:00+00'),
        );
        $this->weatherApi->shouldReceive('fetch')->twice()->andReturn(
            $this->responseRows('weatherapi', '2026-11-21 06:00:01+00'),
            $this->responseRows('weatherapi', '2026-11-22 06:00:01+00'),
        );

        $this->artisan('forecast:collect')->assertSuccessful();
        $this->artisan('forecast:collect')->assertSuccessful();

        $this->assertDatabaseCount('hourly_forecasts', 4);
        $this->assertDatabaseCount('daily_forecasts', 4);
    }

    public function test_repeating_the_same_response_is_rejected_without_overwriting_history(): void
    {
        $this->openMeteo->shouldReceive('fetch')->twice()
            ->andReturn($this->responseRows('open_meteo', '2026-11-21 06:00:00+00'));
        $this->weatherApi->shouldReceive('fetch')->twice()
            ->andReturn($this->responseRows('weatherapi', '2026-11-21 06:00:01+00'));

        $this->artisan('forecast:collect')->assertSuccessful();
        $this->artisan('forecast:collect')
            ->expectsOutput('Collection complete: 0 succeeded, 2 failed; saved 0 hourly and 0 daily rows.')
            ->assertFailed();

        $this->assertDatabaseCount('hourly_forecasts', 2);
        $this->assertDatabaseCount('daily_forecasts', 2);
    }

    public static function providers(): array
    {
        return [['open_meteo'], ['weatherapi']];
    }

    #[DataProvider('providers')]
    public function test_provider_failure_preserves_the_other_provider_and_redacts_the_key(string $failed): void
    {
        $successful = $failed === 'open_meteo' ? 'weatherapi' : 'open_meteo';
        $failedMock = $failed === 'open_meteo' ? $this->openMeteo : $this->weatherApi;
        $successfulMock = $successful === 'open_meteo' ? $this->openMeteo : $this->weatherApi;
        $failedMock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Provider unavailable: test-api-key'));
        $successfulMock->shouldReceive('fetch')->once()
            ->andReturn($this->responseRows($successful, '2026-11-21 06:00:00+00'));

        $this->artisan('forecast:collect')
            ->expectsOutputToContain('Provider unavailable: [redacted]')
            ->doesntExpectOutputToContain('test-api-key')
            ->expectsOutput('Collection complete: 1 succeeded, 1 failed; saved 1 hourly and 1 daily rows.')
            ->assertFailed();

        $this->assertDatabaseCount('hourly_forecasts', 1);
        $this->assertDatabaseCount('daily_forecasts', 1);
        $this->assertDatabaseHas('hourly_forecasts', ['provider' => $successful]);
        $this->assertDatabaseMissing('hourly_forecasts', ['provider' => $failed]);
        Log::shouldHaveReceived('error')->with('Forecast provider failed', [
            'provider' => $failed, 'hourly' => 0, 'daily' => 0, 'reason' => 'Provider unavailable: [redacted]',
        ])->once();
    }

    #[DataProvider('providers')]
    public function test_a_failed_daily_insert_rolls_back_its_hourly_rows_only(string $failed): void
    {
        $badRows = $this->responseRows($failed, '2026-11-21 06:00:00+00');
        $badRows['daily'][0]['provider'] = null;
        $this->openMeteo->shouldReceive('fetch')->once()->andReturn($failed === 'open_meteo'
            ? $badRows : $this->responseRows('open_meteo', '2026-11-21 06:00:00+00'));
        $this->weatherApi->shouldReceive('fetch')->once()->andReturn($failed === 'weatherapi'
            ? $badRows : $this->responseRows('weatherapi', '2026-11-21 06:00:01+00'));

        $this->artisan('forecast:collect')
            ->expectsOutputToContain('Database save failed (SQLSTATE 23502).')
            ->expectsOutput('Collection complete: 1 succeeded, 1 failed; saved 1 hourly and 1 daily rows.')
            ->assertFailed();

        $this->assertDatabaseCount('hourly_forecasts', 1);
        $this->assertDatabaseCount('daily_forecasts', 1);
        $this->assertDatabaseMissing('hourly_forecasts', ['provider' => $failed]);
        $this->assertDatabaseMissing('daily_forecasts', ['provider' => $failed]);
        Log::shouldHaveReceived('error')->with('Forecast provider failed', [
            'provider' => $failed, 'hourly' => 0, 'daily' => 0,
            'reason' => 'Database save failed (SQLSTATE 23502).',
        ])->once();
    }

    public function test_invalid_location_configuration_stops_before_provider_calls(): void
    {
        config(['forecast.location.lat' => null]);
        $this->openMeteo->shouldNotReceive('fetch');
        $this->weatherApi->shouldNotReceive('fetch');

        $this->artisan('forecast:collect')
            ->expectsOutputToContain('Set valid FORECAST_LAT, FORECAST_LON and FORECAST_LOCATION_TIMEZONE in .env.')
            ->assertFailed();

        $this->assertDatabaseCount('hourly_forecasts', 0);
    }

    private function responseRows(string $provider, string $collected): array
    {
        $hourly = [
            'collected' => $collected, 'provider' => $provider,
            'lat' => '40.626430', 'lon' => '22.732910',
            'time' => '2026-11-22 12:00:00+00', 'temp' => '12.50', 'prec' => '0.00',
        ];
        $daily = $hourly;
        $daily['time'] = '2026-11-22';

        return ['hourly' => [$hourly], 'daily' => [$daily]];
    }
}
