<?php

namespace Tests\Feature;

use App\Models\DailyForecast;
use App\Models\HourlyForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForecastApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public static function invalidResolutions(): array
    {
        return ['missing' => [''], 'invalid' => ['?resolution=weekly']];
    }

    #[DataProvider('invalidResolutions')]
    public function test_resolution_errors_are_json_even_without_an_accept_header(string $query): void
    {
        $this->get('/api/forecasts'.$query)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['resolution']);
        Http::assertNothingSent();
    }

    public static function resolutions(): array
    {
        return [
            'hourly' => ['hourly', HourlyForecast::class],
            'daily' => ['daily', DailyForecast::class],
        ];
    }

    #[DataProvider('resolutions')]
    public function test_it_returns_only_the_selected_resolution_with_history(string $resolution, string $model): void
    {
        $first = $model::create($this->forecastRow($resolution));
        $newer = $this->forecastRow($resolution);
        $newer['provider'] = 'weatherapi';
        $newer['collected'] = '2026-11-22 06:00:00.123456+00';
        $newer['temp'] = null;
        $newer['prec'] = '0.00';
        $latest = $model::create($newer);
        $otherModel = $resolution === 'hourly' ? DailyForecast::class : HourlyForecast::class;
        $otherResolution = $resolution === 'hourly' ? 'daily' : 'hourly';
        $otherModel::create($this->forecastRow($otherResolution));

        $this->getJson('/api/forecasts?resolution='.$resolution)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $latest->id)
            ->assertJsonPath('1.id', $first->id)
            ->assertJsonPath('0.provider', 'weatherapi')
            ->assertJsonPath('1.provider', 'open_meteo')
            ->assertJsonPath('0.collected', '2026-11-22T06:00:00.123456Z')
            ->assertJsonPath('1.collected', '2026-11-21T06:00:00.123456Z')
            ->assertJsonPath('0.time', $resolution === 'hourly' ? '2026-11-22T12:00:00.000000Z' : '2026-11-22')
            ->assertJsonPath('0.lat', '40.626430')
            ->assertJsonPath('0.lon', '22.732910')
            ->assertJsonPath('0.temp', null)
            ->assertJsonPath('0.prec', '0.00');
        Http::assertNothingSent();
    }

    #[DataProvider('resolutions')]
    public function test_all_rows_are_returned_without_pagination(string $resolution, string $model): void
    {
        $ids = [];
        for ($index = 0; $index < 16; $index++) {
            $row = $this->forecastRow($resolution);
            $row['collected'] = CarbonImmutable::parse('2026-11-21T06:00:00Z')
                ->addMinutes($index)->format('Y-m-d H:i:s.uP');
            $ids[] = $model::create($row)->id;
        }

        $this->getJson('/api/forecasts?resolution='.$resolution)
            ->assertOk()
            ->assertJsonCount(16)
            ->assertJsonPath('0.id', $ids[15])
            ->assertJsonPath('15.id', $ids[0]);
        $this->assertDatabaseCount((new $model)->getTable(), 16);
        Http::assertNothingSent();
    }

    #[DataProvider('resolutions')]
    public function test_no_stored_forecasts_returns_an_empty_array(string $resolution, string $model): void
    {
        config(['forecast.weatherapi.key' => null]);

        $this->getJson('/api/forecasts?resolution='.$resolution)
            ->assertOk()
            ->assertExactJson([]);
        Http::assertNothingSent();
    }

    private function forecastRow(string $resolution): array
    {
        return [
            'collected' => '2026-11-21 06:00:00.123456+00',
            'provider' => 'open_meteo',
            'lat' => '40.626430',
            'lon' => '22.732910',
            'time' => $resolution === 'hourly' ? '2026-11-22 12:00:00+00' : '2026-11-22',
            'temp' => '12.50',
            'prec' => '0.00',
        ];
    }
}
