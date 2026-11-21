<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ForecastScheduleTest extends TestCase
{
    public static function scheduleTimes(): array
    {
        return [
            'winter' => ['06:00', 'Europe/Athens', '2026-11-21T04:00:00Z', true],
            'summer' => ['06:00', 'Europe/Athens', '2026-07-21T03:00:00Z', true],
            'other minute' => ['06:00', 'Europe/Athens', '2026-11-21T04:01:00Z', false],
            'configured time and zone' => ['07:15', 'UTC', '2026-11-21T07:15:00Z', true],
        ];
    }

    #[DataProvider('scheduleTimes')]
    public function test_collection_runs_at_the_configured_local_time(string $time, string $timezone, string $now, bool $due): void
    {
        config(['forecast.schedule.time' => $time, 'forecast.schedule.timezone' => $timezone]);
        Schedule::swap(new Scheduler('UTC'));
        require base_path('routes/console.php');
        $this->travelTo(CarbonImmutable::parse($now));

        $events = Schedule::events();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('forecast:collect', $events[0]->command);
        $this->assertSame($due, $events[0]->isDue($this->app));
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
