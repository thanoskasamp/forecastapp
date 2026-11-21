<?php

namespace Tests\Feature;

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
            'hourly' => ['hourly_forecasts'],
            'daily' => ['daily_forecasts'],
        ];
    }

    #[DataProvider('forecastTables')]
    public function test_exact_duplicates_are_rejected(string $table): void
    {
        $forecast = $this->forecastRow($table);
        DB::table($table)->insert($forecast);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        DB::table($table)->insert($forecast);
    }

    #[DataProvider('forecastTables')]
    public function test_new_collections_preserve_earlier_forecasts(string $table): void
    {
        $first = $this->forecastRow($table);
        $second = $first;
        $second['collected'] = '2026-11-21 06:00:00.123457+00';
        $second['temp'] = '15.00';

        DB::table($table)->insert([$first, $second]);

        $this->assertDatabaseCount($table, 2);
        $this->assertSame(2, DB::table($table)->distinct()->count('collected'));
        $this->assertSame(['12.50', '15.00'], DB::table($table)->orderBy('collected')->pluck('temp')->all());
    }

    #[DataProvider('forecastTables')]
    public function test_missing_measurements_remain_distinct_from_zero(string $table): void
    {
        $missing = $this->forecastRow($table);
        $missing['temp'] = null;
        $missing['prec'] = null;
        $zero = $missing;
        $zero['collected'] = '2026-11-21 06:00:00.123457+00';
        $zero['temp'] = '0.00';
        $zero['prec'] = '0.00';

        DB::table($table)->insert([$missing, $zero]);
        $saved = DB::table($table)->orderBy('collected')->get();

        $this->assertCount(2, $saved);
        $this->assertNull($saved[0]->temp);
        $this->assertNull($saved[0]->prec);
        $this->assertSame('0.00', $saved[1]->temp);
        $this->assertSame('0.00', $saved[1]->prec);
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
