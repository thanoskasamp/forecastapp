<?php

namespace Tests\Feature;

use App\Weather\OpenMeteoProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OpenMeteoProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-21T06:00:00Z'));
    }

    public function test_it_normalizes_hourly_and_daily_values_and_request_parameters(): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $body = $this->responseBody($start);
        $body['hourly']['temperature_2m'][0] = null;
        $body['hourly']['precipitation'][0] = 0;
        $body['daily']['temperature_2m_mean'][0] = 18.7;
        $body['daily']['precipitation_sum'][0] = null;
        Http::fake(function (Request $request, array $options) use ($body) {
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($body);
        });

        $result = (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount(72, $result['hourly']);
        $this->assertCount(3, $result['daily']);
        $this->assertSame('2026-11-20 22:00:00+00:00', $result['hourly'][0]['time']);
        $this->assertSame('2026-11-21', $result['daily'][0]['time']);
        $this->assertNull($result['hourly'][0]['temp']);
        $this->assertSame(0.0, $result['hourly'][0]['prec']);
        $this->assertSame(18.7, $result['daily'][0]['temp']);
        $this->assertNull($result['daily'][0]['prec']);
        $this->assertSame('40.626430', $result['hourly'][0]['lat']);
        $this->assertSame('22.732910', $result['daily'][0]['lon']);
        $this->assertSame('open_meteo', $result['daily'][0]['provider']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast?')
            && $request['latitude'] === '40.626430'
            && $request['longitude'] === '22.732910'
            && $request['hourly'] === 'temperature_2m,precipitation'
            && $request['daily'] === 'temperature_2m_mean,precipitation_sum'
            && $request['temperature_unit'] === 'celsius'
            && $request['precipitation_unit'] === 'mm'
            && $request['timezone'] === 'Europe/Athens'
            && $request['timeformat'] === 'unixtime'
            && $request['start_date'] === '2026-11-21'
            && $request['end_date'] === '2026-11-23'
            && $request['start_hour'] === '2026-11-21T00:00'
            && $request['end_hour'] === '2026-11-23T23:00');
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_collection_time_is_captured_after_the_response_is_received(): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        Http::fake(function () use ($start) {
            $this->travel(5)->seconds();

            return Http::response($this->responseBody($start));
        });

        $result = (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);

        foreach (array_merge($result['hourly'], $result['daily']) as $row) {
            $this->assertSame('2026-11-21 06:00:05.000000+00:00', $row['collected']);
        }
    }

    public static function daylightSavingWindows(): array
    {
        return [
            'spring' => ['2027-03-27', 71, '2027-03-29T22:00'],
            'autumn' => ['2027-10-30', 73, '2027-11-02T00:00'],
        ];
    }

    #[DataProvider('daylightSavingWindows')]
    public function test_three_local_dates_handle_daylight_saving_changes(string $date, int $hours, string $lastRequestedHour): void
    {
        $start = CarbonImmutable::parse($date, 'Europe/Athens');
        $this->travelTo($start->addHours(6));
        Http::fake(['api.open-meteo.com/*' => Http::response($this->responseBody($start))]);

        $result = (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount($hours, $result['hourly']);
        $this->assertCount($hours, array_unique(array_column($result['hourly'], 'time')));
        $this->assertSame([
            $date, $start->addDay()->toDateString(), $start->addDays(2)->toDateString(),
        ], array_column($result['daily'], 'time'));
        $this->assertSame($start->utc()->format('Y-m-d H:i:sP'), $result['hourly'][0]['time']);
        Http::assertSent(fn (Request $request) => $request['end_hour'] === $lastRequestedHour);
    }

    public function test_an_unavailable_measurement_array_stays_null(): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $body = $this->responseBody($start);
        unset($body['hourly']['temperature_2m']);
        Http::fake(['*' => Http::response($body)]);

        $result = (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertSame([null], array_values(array_unique(array_column($result['hourly'], 'temp'))));
    }

    public static function malformedResponses(): array
    {
        return [['invalid_json'], ['missing_daily'], ['short_array'], ['bad_value'], ['bad_units'], ['missing_hour']];
    }

    #[DataProvider('malformedResponses')]
    public function test_invalid_responses_are_rejected_without_retry(string $problem): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $body = $this->responseBody($start);
        switch ($problem) {
            case 'invalid_json':
                $body = 'not JSON';
                break;
            case 'missing_daily':
                unset($body['daily']);
                break;
            case 'short_array':
                array_pop($body['hourly']['precipitation']);
                break;
            case 'bad_value':
                $body['hourly']['temperature_2m'][0] = 'invalid';
                break;
            case 'bad_units':
                $body['daily_units']['precipitation_sum'] = 'inch';
                break;
            case 'missing_hour':
                foreach ($body['hourly'] as &$values) {
                    array_pop($values);
                }
                unset($values);
                break;
        }
        Http::fake(['*' => Http::response($body)]);

        try {
            (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);
            $this->fail('Expected malformed response rejection.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('Open-Meteo returned ', $error->getMessage());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public static function retryableFailures(): array
    {
        return [['server'], ['connection']];
    }

    #[DataProvider('retryableFailures')]
    public function test_transient_failures_retry_once(string $failure): void
    {
        $start = CarbonImmutable::parse('2026-11-21', 'Europe/Athens');
        $sequence = Http::sequence();
        $sequence->pushResponse($failure === 'server' ? Http::response([], 503) : Http::failedConnection());
        $sequence->push($this->responseBody($start));
        Http::fake(['*' => $sequence]);

        $result = (new OpenMeteoProvider)->fetch('40.626430', '22.732910', $start);

        $this->assertCount(3, $result['daily']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds == 1000);
    }

    #[DataProvider('retryableFailures')]
    public function test_retryable_failures_stop_after_two_attempts(string $failure): void
    {
        $sequence = Http::sequence();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $sequence->pushResponse($failure === 'server' ? Http::response([], 503) : Http::failedConnection());
        }
        Http::fake(['*' => $sequence]);

        try {
            (new OpenMeteoProvider)->fetch('40.626430', '22.732910', CarbonImmutable::parse('2026-11-21', 'Europe/Athens'));
            $this->fail('Expected provider failure.');
        } catch (RuntimeException $error) {
            $this->assertStringStartsWith('Open-Meteo ', $error->getMessage());
        }
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public static function clientErrors(): array
    {
        return [[400], [401], [429]];
    }

    #[DataProvider('clientErrors')]
    public function test_client_errors_are_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response([], $status)]);

        try {
            (new OpenMeteoProvider)->fetch('40.626430', '22.732910', CarbonImmutable::parse('2026-11-21', 'Europe/Athens'));
            $this->fail('Expected HTTP error.');
        } catch (RuntimeException $error) {
            $this->assertSame('Open-Meteo returned HTTP '.$status.'.', $error->getMessage());
        }
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    private function responseBody(CarbonImmutable $start): array
    {
        $hours = [];
        for ($hour = $start->utc(); $hour->lessThan($start->addDays(3)); $hour = $hour->addHour()) {
            $hours[] = $hour->timestamp;
        }
        $days = [];
        $offset = CarbonImmutable::now($start->timezone)->offset;
        for ($day = 0; $day < 3; $day++) {
            $days[] = CarbonImmutable::parse($start->addDays($day)->toDateString(), 'UTC')->subSeconds($offset)->timestamp;
        }

        return [
            'latitude' => 40.625,
            'longitude' => 22.75,
            'utc_offset_seconds' => $offset,
            'hourly_units' => ['time' => 'unixtime', 'temperature_2m' => '°C', 'precipitation' => 'mm'],
            'daily_units' => ['time' => 'unixtime', 'temperature_2m_mean' => '°C', 'precipitation_sum' => 'mm'],
            'hourly' => ['time' => $hours, 'temperature_2m' => array_fill(0, count($hours), 12.5), 'precipitation' => array_fill(0, count($hours), 0)],
            'daily' => ['time' => $days, 'temperature_2m_mean' => [18.7, 19.2, 20.1], 'precipitation_sum' => [1.2, 0, 4.5]],
        ];
    }
}
