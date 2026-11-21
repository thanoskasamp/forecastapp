<?php

namespace Tests\Feature;

use App\Weather\WeatherApiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WeatherApiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['forecast.weatherapi.key' => 'test-weatherapi-key']);
        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-21T06:00:00Z'));
    }

    public function test_it_maps_fields_and_captures_one_response_receipt_time(): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $body = $this->responseBody($start);
        unset($body['forecast']['forecastday'][0]['hour'][0]['temp_c']);
        $body['forecast']['forecastday'][0]['day']['totalprecip_mm'] = null;
        Http::fake(function (Request $request, array $options) use ($body) {
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->travel(2)->seconds();

            return Http::response($body);
        });

        $result = (new WeatherApiProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount(72, $result['hourly']);
        $this->assertCount(3, $result['daily']);
        $this->assertSame('2026-11-20 22:00:00+00:00', $result['hourly'][0]['time']);
        $this->assertSame(['2026-11-21', '2026-11-22', '2026-11-23'], array_column($result['daily'], 'time'));
        $this->assertNull($result['hourly'][0]['temp']);
        $this->assertSame(0.0, $result['hourly'][0]['prec']);
        $this->assertSame(18.7, $result['daily'][0]['temp']);
        $this->assertNull($result['daily'][0]['prec']);
        $this->assertSame('weatherapi', $result['daily'][0]['provider']);
        $this->assertSame('40.626430', $result['hourly'][0]['lat']);
        $this->assertSame('22.732910', $result['daily'][0]['lon']);
        $collectionTimes = array_column(array_merge($result['hourly'], $result['daily']), 'collected');
        $this->assertSame(['2026-11-21 06:00:02.000000+00:00'], array_values(array_unique($collectionTimes)));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.weatherapi.com/v1/forecast.json?')
            && $request['key'] === 'test-weatherapi-key'
            && $request['q'] === '40.626430,22.732910'
            && $request['days'] === 3
            && $request['aqi'] === 'no'
            && $request['alerts'] === 'no');
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_missing_key_is_reported_before_any_request(): void
    {
        config(['forecast.weatherapi.key' => null]);
        Http::fake();

        try {
            (new WeatherApiProvider)->fetch('40.626430', '22.732910', CarbonImmutable::parse('2026-11-21', 'Europe/Athens'));
            $this->fail('Expected missing key rejection.');
        } catch (RuntimeException $error) {
            $this->assertSame('Set WEATHERAPI_KEY in .env before collecting WeatherAPI forecasts.', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function daylightSavingWindows(): array
    {
        return [['2027-03-27', 71], ['2027-10-30', 73]];
    }

    #[DataProvider('daylightSavingWindows')]
    public function test_epoch_hours_preserve_the_three_local_dates(string $date, int $expectedHours): void
    {
        $start = CarbonImmutable::parse($date, 'Europe/Athens');
        Http::fake(['*' => Http::response($this->responseBody($start))]);

        $result = (new WeatherApiProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount($expectedHours, $result['hourly']);
        $this->assertCount($expectedHours, array_unique(array_column($result['hourly'], 'time')));
        $this->assertSame($date, $result['daily'][0]['time']);
        $this->assertSame($start->addDays(2)->toDateString(), $result['daily'][2]['time']);
    }

    public static function invalidResponses(): array
    {
        return [['invalid_json'], ['missing_hours'], ['bad_timestamp'], ['bad_value'], ['shifted_dates'], ['wrong_timezone']];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_or_incomplete_responses_are_rejected_without_retry(string $problem): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $body = $this->responseBody($start);
        switch ($problem) {
            case 'invalid_json':
                $body = 'not JSON';
                break;
            case 'missing_hours':
                unset($body['forecast']['forecastday'][0]['hour']);
                break;
            case 'bad_timestamp':
                $body['forecast']['forecastday'][0]['hour'][0]['time_epoch'] = 'invalid';
                break;
            case 'bad_value':
                $body['forecast']['forecastday'][0]['day']['avgtemp_c'] = 'invalid';
                break;
            case 'shifted_dates':
                $body = $this->responseBody($start->addDay());
                break;
            case 'wrong_timezone':
                $body['location']['tz_id'] = 'America/New_York';
                break;
        }
        Http::fake(['*' => Http::response($body)]);

        try {
            (new WeatherApiProvider)->fetch('40.626430', '22.732910', $start);
            $this->fail('Expected invalid response rejection.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('WeatherAPI returned ', $error->getMessage());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public static function retryableFailures(): array
    {
        return [['server'], ['connection']];
    }

    #[DataProvider('retryableFailures')]
    public function test_a_transient_failure_gets_one_retry(string $failure): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $sequence = Http::sequence();
        $sequence->pushResponse($failure === 'server' ? Http::response([], 503) : Http::failedConnection());
        $sequence->push($this->responseBody($start));
        Http::fake(['*' => $sequence]);

        $result = (new WeatherApiProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount(3, $result['daily']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds == 1000);
    }

    #[DataProvider('retryableFailures')]
    public function test_two_failures_stop_with_a_safe_error(string $failure): void
    {
        $sequence = Http::sequence();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $sequence->pushResponse($failure === 'server'
                ? Http::response([], 503)
                : Http::failedConnection('URL contained test-weatherapi-key'));
        }
        Http::fake(['*' => $sequence]);

        try {
            (new WeatherApiProvider)->fetch('40.626430', '22.732910', CarbonImmutable::parse('2026-11-21', 'Europe/Athens'));
            $this->fail('Expected provider failure.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('WeatherAPI ', $error->getMessage());
            $this->assertStringNotContainsString('test-weatherapi-key', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public static function clientErrors(): array
    {
        return [[400, 1006], [401, 2006], [403, 2009], [429, 2012]];
    }

    #[DataProvider('clientErrors')]
    public function test_client_errors_are_not_retried_and_do_not_expose_the_key(int $status, int $code): void
    {
        Http::fake(['*' => Http::response(['error' => [
            'code' => $code,
            'message' => 'Request with test-weatherapi-key was rejected',
        ]], $status)]);

        try {
            (new WeatherApiProvider)->fetch('40.626430', '22.732910', CarbonImmutable::parse('2026-11-21', 'Europe/Athens'));
            $this->fail('Expected client error.');
        } catch (RuntimeException $error) {
            $this->assertSame('WeatherAPI returned HTTP '.$status.' (code '.$code.').', $error->getMessage());
            $this->assertStringNotContainsString('test-weatherapi-key', $error->getMessage());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    private function responseBody(CarbonImmutable $start): array
    {
        $days = [];
        for ($index = 0; $index < 3; $index++) {
            $day = $start->addDays($index);
            $hours = [];
            for ($time = $day->utc(); $time->lessThan($day->addDay()); $time = $time->addHour()) {
                $hours[] = ['time_epoch' => $time->timestamp, 'temp_c' => 12.5, 'precip_mm' => 0];
            }
            $days[] = [
                'date' => $day->toDateString(),
                'day' => ['avgtemp_c' => 18.7, 'totalprecip_mm' => 1.2],
                'hour' => $hours,
            ];
        }

        return [
            'location' => ['lat' => 40.63, 'lon' => 22.73, 'tz_id' => 'Europe/Athens'],
            'forecast' => ['forecastday' => $days],
        ];
    }
}
