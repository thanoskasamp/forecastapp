# ForecastApp — Backend assignment

PHP 8.5 / Laravel 13 backend that collects three-day hourly and daily forecasts
from Open-Meteo and WeatherAPI, stores history in PostgreSQL 17, and exposes a read API.

## Installation and setup

Install Docker with Compose (Docker Desktop using Linux containers on Windows).
Host PHP, Composer and Node.js are not needed. Clone/download this repository,
start Docker, and open a terminal in the project root.

1. Copy `.env.example` to `.env` **only if `.env` does not already exist**:

   Windows Command Prompt or PowerShell:

   ```text
   copy .env.example .env
   ```

   Linux/macOS: `cp .env.example .env`.

2. Edit `.env` before running Docker commands:

   - Choose your own strong `DB_PASSWORD`; do not leave it blank.
   - Create an account at [WeatherAPI](https://www.weatherapi.com/), copy your API
     key from its dashboard, and paste it into `WEATHERAPI_KEY`.
   - Open-Meteo's free endpoint does not need an API key.
   - For a new `.env`, leave `APP_KEY` blank; the command below generates it.
   - Keep the other example settings, including `DB_HOST=db`, `DB_PORT=5432`,
     `DB_DATABASE=forecastapp` and `DB_USERNAME=forecastapp`, unless changing them
     intentionally. Keep all entries: project configuration has no env fallbacks.

   `.env` is ignored by Git. Never commit real passwords or API keys.

3. On a fresh installation, run these commands in order:

   ```sh
   docker compose build app
   docker compose run --rm app composer install --no-interaction
   docker compose run --rm app php artisan key:generate
   docker compose run --rm app php artisan migrate
   docker compose up -d
   ```

   `key:generate` writes Laravel's `APP_KEY` into `.env`; retain an existing key
   when restarting an installed project. Migrations create the database tables.

4. Open http://localhost:8000; it should return `{"name":"ForecastApp","status":"ok"}`.
   Collect the first forecasts using the command below; API storage starts empty.

Compose runs `app`, `db` and `scheduler`. Source uses the `.:/app` bind mount;
PostgreSQL uses a persistent named volume. `docker compose down` preserves data.
Changing `DB_PASSWORD` after database initialization also requires updating the
existing PostgreSQL role password; editing `.env` alone does not change it.

## Collect forecasts

```sh
docker compose exec app php artisan forecast:collect
```

This collects today, tomorrow and the day after in the location's time zone.
Initial `.env` settings use Chalastra: `FORECAST_LAT=40.626430`,
`FORECAST_LON=22.732910`, `FORECAST_LOCATION_TIMEZONE=Europe/Athens`.

The scheduler runs the same command daily at `FORECAST_COLLECTION_TIME=06:00`
in `FORECAST_SCHEDULE_TIMEZONE=Europe/Athens`. Keep Docker and the containers
running; startup does not collect immediately and missed runs are not replayed.
After changing settings, run `docker compose restart scheduler`. Scheduled output
is in `storage/logs/forecast-collection.log`; a lock prevents scheduled overlap.

## Read API

```sh
curl "http://localhost:8000/api/forecasts?resolution=hourly"
curl "http://localhost:8000/api/forecasts?resolution=daily"
```

Reads stored data only. Required `resolution` accepts `hourly` or `daily`;
missing/invalid values return JSON HTTP 422. Returns all history as a plain array
(newest collection first), without pagination or filters; empty storage returns `[]`.
Rows contain `id`, `collected`, `provider`, `lat`, `lon`, `time`, `temp`, `prec`.
Collection/hourly timestamps are UTC; daily time is a local `YYYY-MM-DD` date.
Decimals serialize as strings; unavailable measurements are null, actual zero is `"0.00"`.

## Tests

Create the test database once (skip if it exists; replace `forecastapp` if you
changed `DB_USERNAME`), then run the suite:

```sh
docker compose exec db createdb -U forecastapp forecastapp_test
docker compose exec app vendor/bin/phpunit
```

64 tests passed. PHPUnit/Mockery use fake HTTP/clocks and a guarded, separate
`forecastapp_test` database. They cover normalization, retries, history, uniqueness,
transaction rollback, provider failure isolation, API behavior and schedule timing.

## Implementation choices

- Laravel provides HTTP requests, validation, Eloquent, transactions and scheduling.
  Two provider classes share a small interface; one command saves rows and one controller reads them.
- Two tables, `hourly_forecasts` and `daily_forecasts`, store coordinates directly.
  Unique `(collected, provider, lat, lon, time)` prevents duplicate response rows.
  Actual UTC receipt time is captured separately per response; new collections preserve history.
- Daily temperature is the provider's mean in °C, precipitation its total in mm;
  hourly values remain hour-specific. Missing measurements stay null rather than zero.
- One transaction saves each response's hourly/daily rows. Failure rolls it back,
  continues with the other provider and logs safe reasons/counts. Exit is 0 if both succeed, otherwise 1.
- Requests time out after 10 seconds, with one retry after one second only for
  network failures/timeouts or HTTP 5xx. No HTTP 4xx/429 or database retries; keys are excluded from logs.
- The development server/bind mount suit this local assignment; production serving is deferred.

## Incomplete requirements and verification limits

Core features are implemented; live collection and reads worked for both providers.
Scheduler timing/startup are verified, but the first real 06:00 run and a final
clean-machine installation have not been observed. API responses grow with history.

## Next steps with more time

Multiple locations/providers/variables, optional history filters and retention,
and production HTTP serving/monitoring.

## Additional notes

[Open-Meteo](https://open-meteo.com/) daily fields are `temperature_2m_mean` /
`precipitation_sum`; WeatherAPI uses `avgtemp_c` / `totalprecip_mm`.
Open-Meteo hourly precipitation is a preceding-hour sum; WeatherAPI's interval definition
is less explicit. Open-Meteo daily summaries use fixed-offset 24-hour periods
across DST; supplied daily values are preserved. DST tests are synthetic, not live.
See [Open-Meteo docs](https://open-meteo.com/en/docs) and
[WeatherAPI docs](https://www.weatherapi.com/docs/forecast-api/).
Respect free-tier limits and [Open-Meteo attribution/non-commercial terms](https://open-meteo.com/en/terms);
WeatherAPI free access includes three days ([plans](https://www.weatherapi.com/pricing)).
