# ForecastApp — AgroApps backend assignment

A small PHP 8.5 / Laravel 13 application that collects Open-Meteo and WeatherAPI
forecasts for one configured location, preserves their history in PostgreSQL 17,
collects daily, and exposes stored forecasts through a read API.

## Installation and setup

Prerequisite: Docker with Compose and Linux containers (Docker Desktop on
Windows). Host PHP, Composer and Node.js are not required. Run commands from
this repository's root.

Create `.env` only if it does not exist. In Windows Command Prompt:

```cmd
if not exist .env copy .env.example .env
```

In PowerShell:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Set your own `DB_PASSWORD` and `WEATHERAPI_KEY` in `.env`. Keep the other entries
from `.env.example`: project configuration reads them without fallback values.
The default database name/user is `forecastapp`, with host `db` and port `5432`.
Real credentials belong only in the ignored `.env`.

For a fresh installation:

```sh
docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```

Generate `APP_KEY` only when it is empty; retain an existing key. The temporary
`run app` commands start the database dependency as needed. Migrations create
the forecast tables and Laravel's migration-tracking table before scheduling starts.

Services:

- `app`: Laravel development HTTP server at http://localhost:8000.
- `db`: PostgreSQL, accessible inside Compose without a published host port.
- `scheduler`: the same PHP image running `php artisan schedule:work`.

The `.:/app` bind mount shares source, installed dependencies and runtime files
with the containers. PostgreSQL files use the persistent `postgres_data` named
volume. `docker compose down` stops/removes containers and the network while
retaining that volume. Changing `.env` does not change an already-initialized
PostgreSQL role password; update the role separately if changing its password.

### Forecast settings

The example uses Chalastra, Thessaloniki. Change these entries in `.env`:

| Variable | Initial value | Purpose |
|---|---|---|
| `FORECAST_LAT` | `40.626430` | Latitude |
| `FORECAST_LON` | `22.732910` | Longitude |
| `FORECAST_LOCATION_TIMEZONE` | `Europe/Athens` | Local forecast dates |
| `FORECAST_COLLECTION_TIME` | `06:00` | Daily collection time, `HH:MM` |
| `FORECAST_SCHEDULE_TIMEZONE` | `Europe/Athens` | Scheduled time zone |
| `WEATHERAPI_KEY` | Blank | Your WeatherAPI credential |

Forecasts cover today, tomorrow and the day after in the location time zone.
The command resolves that window once for both providers. Application/database
timestamps remain UTC; the location and schedule time zones have separate roles.

### Collection and daily execution

For an immediate collection:

```sh
docker compose exec app php artisan forecast:collect
```

The command calls each provider and saves all its hourly/daily rows in one
transaction. A failure rolls back that response and preserves the other provider's
successful data. It logs provider outcomes, committed counts, safe errors and a
summary. Exit status is `0` when both succeed, `1` otherwise. API reads do not
trigger collection.

`routes/console.php` schedules this same command daily at the configured time.
Keep Docker and the containers running at that time; missed runs while stopped
are not replayed. No collection is triggered immediately when the scheduler starts.
Laravel's standard cache lock prevents overlapping scheduled executions. Run only
one scheduler service.

Inspect the schedule and runner:

```sh
docker compose exec app php artisan schedule:list --timezone=Europe/Athens
docker compose logs scheduler
```

Scheduled command output is appended to `storage/logs/forecast-collection.log`
in the bind-mounted project. After changing schedule settings, run
`docker compose restart scheduler`. The configured 06:00 time avoids Athens'
ambiguous/skipped daylight-saving transition hours.

### Read API

```sh
curl "http://localhost:8000/api/forecasts?resolution=hourly"
curl "http://localhost:8000/api/forecasts?resolution=daily"
```

`resolution` is required and accepts only `hourly` or `daily`. Missing/invalid
values return HTTP `422` JSON with an `errors.resolution` field.

The response is a plain JSON array of **all stored rows** for that resolution,
including `collected` and older collections. Empty storage returns `[]`.
Ordering is collection time descending, then forecast time and ID ascending.
There is no pagination or optional filtering; response size grows with history.

Each row contains `id`, `collected`, `provider`, `lat`, `lon`, `time`, `temp`
and `prec`. Timestamps serialize as UTC ISO strings; daily `time` is a local
`YYYY-MM-DD` date. Decimals serialize as fixed-precision strings. Unavailable
measurements are JSON null; actual zero remains `"0.00"`.

### Tests and checks

Create the dedicated test database once (use your configured database username
if you changed it; skip creation if it already exists):

```sh
docker compose exec db createdb -U forecastapp forecastapp_test
docker compose exec app vendor/bin/phpunit
```

PHPUnit and Mockery are development dependencies installed by Composer. Tests
use `forecastapp_test`, with a guard before Laravel refreshes its tables. They
cover provider normalization/retries, history and uniqueness, transaction rollback,
provider failure isolation, API behavior, and configured winter/summer scheduling.
External HTTP and clocks are faked; tests do not require live keys or modify the
development forecast database.

Other useful checks:

```sh
docker compose config --quiet
docker compose exec app php artisan migrate:status
docker compose ps
```

## Implementation choices and trade-offs

- Laravel supplies the HTTP client, validation, ORM, transactions, command runner
  and scheduler. PHPUnit/Mockery keep behavioral tests repeatable.
- Two straightforward provider classes implement a small shared interface. Each
  owns its request and explicit hourly/daily mappings. One Artisan command
  coordinates them; one controller reads stored rows.
- `hourly_forecasts` and `daily_forecasts` have generated integer IDs, required
  `collected`, provider VARCHAR(50), coordinates DECIMAL(9,6), resolution-specific
  `time`, and nullable `temp`/`prec` DECIMAL(8,2). Coordinates are stored directly;
  there are no separate provider/location/run tables.
- Unique `(collected, provider, lat, lon, time)` prevents repeating the same
  response while retaining new collections. `collected` is captured separately
  when each response arrives, with microsecond precision. Old history is never
  overwritten. Both resolutions from a response share this timestamp.
- Daily temperature is the provider-supplied mean in °C; precipitation is its
  daily total in mm. Hourly values remain hour-specific. Missing values remain
  NULL, and invalid/incomplete responses are rejected.
- Requests have a 10-second timeout per attempt and at most one retry after one
  second, only for network failures/timeouts or HTTP 5xx. HTTP 4xx/429 and failed
  database saves are not retried. Raw provider errors/URLs are not logged, and
  the WeatherAPI key is redacted from collection failures.
- The development server and source bind mount keep this local assignment easy
  to run. They are not a production HTTP deployment configuration.

## Incomplete requirements and verification limits

The core collection, storage, read access and daily trigger are implemented.
Manual live collection and stored reads have been verified for both providers.
Scheduler timing is verified with a fake clock, `schedule:list` and runner startup;
the next real 06:00 execution has not been observed by waiting overnight.
A final clean-machine installation has not been repeated. Stopped containers do
not catch up missed collections. These operational limits are intentionally kept
simple for this assignment.

## Next steps with more time

- Support multiple configured locations and additional provider/variable mappings.
- Add optional history filters/export and retention/indexing as stored data grows.
- Add production HTTP serving and monitoring appropriate to the deployment.

## Additional notes

Open-Meteo maps hourly `temperature_2m` / `precipitation` and daily
`temperature_2m_mean` / `precipitation_sum`. WeatherAPI maps hourly `temp_c` /
`precip_mm` and daily `avgtemp_c` / `totalprecip_mm`.
[Open-Meteo documentation](https://open-meteo.com/en/docs),
[WeatherAPI documentation](https://www.weatherapi.com/docs/forecast-api/).

Provider time labels are preserved. Open-Meteo describes hourly precipitation as
a preceding-hour sum; WeatherAPI documents an hourly amount without the same
explicit boundary definition. Open-Meteo daily summaries use its fixed request-time
UTC offset and 24-hour aggregation, including across DST. Unix hours and explicit
bounds preserve the selected local-date window; daily summaries are not recomputed.
Synthetic tests cover 71/73-hour local windows; actual live transition-day behavior
has not been verified. Incomplete hour coverage is rejected rather than invented.

Data attribution: [Open-Meteo](https://open-meteo.com/). Its free API is for
non-commercial use, requires attribution, and currently limits requests to 10,000
per day, 5,000 per hour and 600 per minute; see [terms](https://open-meteo.com/en/terms).
WeatherAPI's current free plan includes three forecast days and 100,000 calls/month;
see [pricing](https://www.weatherapi.com/pricing). Keep real keys out of Git and logs.
