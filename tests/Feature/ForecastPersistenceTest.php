<?php

namespace Tests\Feature;

use App\Models\DailyForecast;
use App\Models\HourlyForecast;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForecastPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public static function forecastTables(): array
    {
        return [
            'hourly' => [HourlyForecast::class, 'hourly_forecasts'],
            'daily' => [DailyForecast::class, 'daily_forecasts'],
        ];
    }

    #[DataProvider('forecastTables')]
    public function test_exact_duplicates_are_rejected(string $model, string $table): void
    {
        $forecast = $this->forecastRow($table);
        $model::create($forecast);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        $model::create($forecast);
    }

    #[DataProvider('forecastTables')]
    public function test_new_collections_preserve_earlier_forecasts(string $model, string $table): void
    {
        $first = $this->forecastRow($table);
        $second = $first;
        $second['collected'] = '2026-11-21 06:00:00.123457+00';
        $second['temp'] = '15.00';

        $model::create($first);
        $model::create($second);

        $this->assertDatabaseCount($table, 2);
        $this->assertSame(2, DB::table($table)->distinct()->count('collected'));
        $this->assertSame(['12.50', '15.00'], DB::table($table)->orderBy('collected')->pluck('temp')->all());
    }

    #[DataProvider('forecastTables')]
    public function test_missing_measurements_remain_distinct_from_zero(string $model, string $table): void
    {
        $missing = $this->forecastRow($table);
        $missing['temp'] = null;
        $missing['prec'] = null;
        $zero = $missing;
        $zero['collected'] = '2026-11-21 06:00:00.123457+00';
        $zero['temp'] = '0.00';
        $zero['prec'] = '0.00';

        $model::create($missing);
        $model::create($zero);
        $saved = $model::orderBy('collected')->get();

        $this->assertCount(2, $saved);
        $this->assertNull($saved[0]->temp);
        $this->assertNull($saved[0]->prec);
        $this->assertSame('0.00', $saved[1]->temp);
        $this->assertSame('0.00', $saved[1]->prec);
    }

    #[DataProvider('forecastTables')]
    public function test_models_preserve_dates_and_decimal_output(string $model, string $table): void
    {
        $saved = $model::create($this->forecastRow($table))->fresh()->toArray();

        $this->assertSame('2026-11-21T06:00:00.123456Z', $saved['collected']);
        $this->assertSame(
            $table === 'hourly_forecasts' ? '2026-11-22T12:00:00.000000Z' : '2026-11-22',
            $saved['time'],
        );
        $this->assertSame('40.626430', $saved['lat']);
        $this->assertSame('22.732910', $saved['lon']);
        $this->assertSame('12.50', $saved['temp']);
        $this->assertSame('0.80', $saved['prec']);
    }

    private function forecastRow(string $table): array
    {
        return [
            'collected' => '2026-11-21 06:00:00.123456+00',
            'provider' => 'open_meteo',
            'lat' => '40.626430',
            'lon' => '22.732910',
            'time' => $table === 'hourly_forecasts' ? '2026-11-22 12:00:00+00' : '2026-11-22',
            'temp' => '12.50',
            'prec' => '0.80',
        ];
    }
}
