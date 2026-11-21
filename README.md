# AgroApps Assignment

A web application developed for the AgroApps technical assignment.

## Project status

The PHP/Composer Docker toolbox and backend Laravel skeleton are prepared.
Dependency installation, Laravel CLI startup, and the root JSON HTTP response
have been verified using Docker directly and through Compose. PostgreSQL 17.12
startup and Laravel database connectivity have also been verified. Both
forecast migrations have run, and PostgreSQL column types and constraints have
been inspected. Both providers and the models are implemented. Live checks of
Open-Meteo and WeatherAPI each returned 72 hourly and three daily rows for the
configured location. The collection command's live save is verified: 144 hourly
and six daily rows across both providers. Scheduling and the forecast API come later. The current
suite passes (52 tests, 325 assertions).

## Setup and running

Run commands from this repository's root. Docker Desktop must be running with
Linux containers and Docker Compose available. Host PHP, Composer, and Node.js
are not needed. The Docker commands below work in PowerShell or Command Prompt.

Create a local environment file only if one does not already exist. In
PowerShell:

```powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

In Command Prompt:

```cmd
if not exist .env copy .env.example .env
```

Before starting Compose services or running one-off app containers, set your own
`DB_PASSWORD` in `.env` as described under PostgreSQL configuration below.
All project configuration reads environment variables without fallback values.
For an existing `.env`, add missing non-secret entries from `.env.example` while
preserving your existing credentials and `APP_KEY`. The example contains the
initial values for this project. Unused integrations such as mail, Redis and
cloud storage can remain unconfigured; their settings are not required here.

Build the PHP 8.5 / Composer 2.11.2 image, then install the dependencies declared
in `composer.json` (skip installation if they are already installed):

```sh
docker compose build app
docker compose run --rm app composer install --no-interaction
```

The `.:/app` bind mount shares the folder containing `compose.yaml` with `/app`
inside the container. Source edits are immediately available there. Installed
dependencies and writable runtime files also remain in this shared folder;
their generated contents are ignored by Git.
Composer creates `vendor/` (installed PHP packages, ignored by Git) and
`composer.lock` (exact package versions, to be tracked). Subsequent installations
use the lockfile. This setup does not automatically migrate or reset a database.

Verify that Laravel's command-line tool starts:

```sh
docker compose run --rm app php artisan --version
```

Expected output starts with `Laravel Framework 13.`.

## PostgreSQL configuration

For an existing `.env`, add/update these database settings while keeping the
existing `APP_KEY` and other settings:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=forecastapp
DB_USERNAME=forecastapp
DB_PASSWORD=
```

Set `DB_PASSWORD` to your own password in the ignored `.env` before first startup.
The example leaves it blank; configuration has no fallback database credentials
or API keys. If PostgreSQL is already initialized, changing `.env` alone does not
change its stored role password; update the role password to match as well.
`db` is the Compose service hostname: from the app container,
`localhost` refers to the app container itself.

The Dockerfile installs `pdo_pgsql`, PHP's PostgreSQL driver, using the `libpq`
client development library. Rebuild the app image after this Dockerfile change.
PostgreSQL 17 runs separately in the `db` service, with no host port published.
The named `postgres_data` volume retains database files across normal container
replacement and `docker compose down` / `up` cycles. App source still uses the
existing `.:/app` bind mount.

Compose waits for `pg_isready` to report the database healthy before starting
`app`. This also applies when a one-off `run app ...` command starts its
dependency. The app reads its DB settings through Laravel's `.env` loading;
Compose supplies the matching initialization settings to PostgreSQL.
The Compose file references environment variables only for these settings; it
contains no hardcoded database name, username, or password fallback. Set them
in the ignored `.env` before starting the services.

After startup, verify that Laravel can connect without creating tables:

```sh
docker compose exec app php artisan db:show
```

The expected database is `forecastapp` on PostgreSQL. This step does not run
migrations; the forecast tables are added separately. The PostgreSQL connection
uses UTC for timestamp handling.

Before testing HTTP routes, generate Laravel's application key once if `APP_KEY`
is empty in `.env`:

```sh
docker compose run --rm app php artisan key:generate
```

This stores Laravel's encryption/signing key in the ignored `.env`. Retain an
existing key on later starts. Laravel reads `.env` from the bind-mounted project;
no separate Compose `env_file` is required for this app-only step.

If the earlier manual `docker run` server is still running, stop it with Ctrl+C
to free port 8000. Start the development server through Compose:

```sh
docker compose up --build
```

Keep the terminal running and visit [http://localhost:8000](http://localhost:8000).
The root route returns `{"name":"ForecastApp","status":"ok"}` with the name
coming from `APP_NAME`. `/up` is Laravel's startup health route. Stop the server
with Ctrl+C. The root response verifies HTTP startup; use `db:show` separately to
verify database connectivity. Collection and the forecast API come later.

## Forecast configuration

`config/forecast.php` reads all its values from `.env`, with no fallback values.
For collection and scheduling, add the five `FORECAST_*` entries below. The
values shown are the approved initial settings, also included in `.env.example`:

| Variable | Initial value | Purpose |
|---|---|---|
| `FORECAST_LAT` | `40.626430` | Collection latitude in Chalastra, Thessaloniki |
| `FORECAST_LON` | `22.732910` | Collection longitude |
| `FORECAST_LOCATION_TIMEZONE` | `Europe/Athens` | Local calendar dates for the three-day forecast window |
| `FORECAST_COLLECTION_TIME` | `06:00` | Daily collection time, using 24-hour `HH:MM` format |
| `FORECAST_SCHEDULE_TIMEZONE` | `Europe/Athens` | Time zone used for the collection schedule |
| `WEATHERAPI_KEY` | Unset | Required credential for WeatherAPI collection |

For an existing `.env`, add all five forecast settings and your own WeatherAPI
key before using that provider. Keep existing database credentials and `APP_KEY`.
The key belongs only in the ignored `.env`. Application code will read these
settings through `config('forecast.location.lat')`, for example, rather than
calling `env()` directly. Coordinates remain strings to retain decimal precision.

The location and schedule time zones have separate purposes; Laravel and
PostgreSQL remain on UTC for timestamps. The forecast horizon stays today,
tomorrow, and the day after in the location time zone. Settings alone do not call
providers or start a collection schedule. The collector and scheduler come later.

## Open-Meteo provider

`app/Weather/ForecastProvider.php` defines the small common provider interface.
Its `fetch` method accepts coordinates and one start date in the location time
zone, returning `hourly` and `daily` arrays of rows in the database field shape.
The collector will resolve this date once for both providers and save the rows;
the adapter itself only requests and normalizes data.

In `OpenMeteoProvider`, `fetch()` coordinates three steps: `requestForecast()`
performs the HTTP request and captures the receipt time; `hourlyRows()` maps
hour-specific fields to UTC rows; `dailyRows()` maps provider daily summaries to
date-only rows. Both mapping methods use the same collection/provider/coordinate
fields. The mappings are explicit, with no resolution-dependent field switching.

`OpenMeteoProvider` requests hourly `temperature_2m` / `precipitation` and daily
`temperature_2m_mean` / `precipitation_sum`, explicitly selecting °C and mm.
Hourly precipitation covers the preceding hour; timestamps retain the provider's
labels. Daily values remain provider-supplied summaries. Configured coordinates
are retained even when the provider's grid-cell coordinates differ.
[Open-Meteo API documentation](https://open-meteo.com/en/docs).

Unix timestamps and explicit hourly bounds preserve the three local dates,
including 71/73-hour windows around daylight-saving changes. Open-Meteo uses a
fixed request-time UTC offset for daily boundaries; its daily summaries therefore
remain provider-defined 24-hour aggregates across DST. The adapter preserves the
provider's daily labels, filters to the selected dates, and rejects incomplete
coverage. It does not recompute daily values from hourly rows.

All rows from a response share its received UTC `collected` timestamp. NULL
measurements stay unavailable; zero stays zero. Malformed responses and unexpected
units are rejected. Each request has a 10-second timeout and at most two attempts:
retry network failures/timeouts or HTTP 5xx once after one second; do not retry
HTTP 4xx/429. Redirects are disabled. Errors contain a provider/status description
rather than raw response bodies or URLs.

The free endpoint does not need a key. Its non-commercial limits include 10,000
calls/day, 5,000/hour and 600/minute, and attribution is required. Data source:
[Open-Meteo](https://open-meteo.com/), under [its terms](https://open-meteo.com/en/terms).

## WeatherAPI provider

`WeatherApiProvider` implements the same interface and straightforward flow:
`requestForecast()`, `hourlyRows()`, then `dailyRows()`. It reads
`config('forecast.weatherapi.key')`, populated from `.env` without a fallback,
and reports a missing key before sending a request. Authentication sends the key
as the provider's required HTTPS request parameter; errors omit raw URLs,
response messages and chained transport exceptions.
[WeatherAPI authentication](https://www.weatherapi.com/docs/authentication/).

The request uses coordinates, `days=3`, and disables unused alerts/air quality.
Hourly `temp_c` / `precip_mm` become `temp` / `prec`; `time_epoch` becomes UTC
`time`. Daily `avgtemp_c` / `totalprecip_mm` supply the agreed mean temperature
and precipitation total, with the provider's `date` retained. Configured
coordinates are stored rather than the matched location's rounded coordinates.
[Forecast fields](https://www.weatherapi.com/docs/forecast-api/).

The response location's time zone must match the configured location zone. The
adapter selects the shared three local dates and rejects incomplete coverage,
including a date-window shift across midnight. Epoch hours preserve DST when
supplied correctly; missing or duplicate hours are rejected rather than invented.
DST behavior is tested with synthetic responses, not live transition-day data.
The same received-UTC timestamp, NULL/zero and bounded retry policies apply.

The free plan currently includes three-day hourly/daily forecasts and 100,000
calls/month. Live verification confirmed the configured key can access all three
days. [WeatherAPI pricing](https://www.weatherapi.com/pricing).
Failures report HTTP status and, when present, a numeric provider error code;
the collector will log them later. [Error codes](https://www.weatherapi.com/docs/error-codes/).
WeatherAPI documents hourly precipitation as an amount for that forecast hour;
Open-Meteo explicitly describes a preceding-hour sum. Provider labels are
preserved, so direct interval equivalence should not be assumed.

## Forecast migrations

With the services running, apply the migrations and check their status:

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

The two migration files create `hourly_forecasts` and `daily_forecasts`. Laravel
also creates its standard `migrations` table to track which files have run.
Both forecast tables contain only `id`, `collected`, `provider`, `lat`, `lon`,
`time`, `temp`, and `prec`:

- `id` is an automatically generated integer primary key.
- `collected` is a response-received UTC timestamp with microsecond precision.
- `provider` is a required string of up to 50 characters (`open_meteo` or `weatherapi`).
- Coordinates use `DECIMAL(9,6)` and are stored directly in every row.
- Hourly `time` is a UTC timestamp; daily `time` is a location-local calendar date.
- `temp` and `prec` use nullable `DECIMAL(8,2)`: NULL means unavailable, zero means
  a reported zero. Daily values are provider-supplied mean temperature in °C and
  total precipitation in mm; hourly values remain hour-specific.
- A unique constraint on `(collected, provider, lat, lon, time)` prevents
  duplicates within one response while allowing later collections to be retained.

Timestamps use PostgreSQL `timestamp with time zone`; the Laravel connection uses
UTC. Capturing response receipt times belongs to the later collection code.
Migrations define storage and constraints; they do not fetch or insert forecasts.

`App\Models\HourlyForecast` and `App\Models\DailyForecast` provide Eloquent access
to the matching tables. Their fillable fields are the seven forecast fields;
IDs are generated by PostgreSQL. Automatic `created_at`/`updated_at` handling is
disabled. Date storage preserves microseconds, daily dates serialize as
`YYYY-MM-DD`, and collected/hourly timestamps serialize in UTC. Decimal casts
return fixed-precision strings (six places for coordinates, two for measurements)
while preserving NULL measurements. The application time zone stays UTC;
collection code must normalize hourly and collection timestamps to UTC.

Migration rollback and re-application were verified in a disposable PostgreSQL
database, which was removed afterward. Repeatable persistence tests are described
below.

## Collecting forecasts

After migrations, collect and save forecasts with:

```sh
docker compose exec app php artisan forecast:collect
```

`app/Console/Commands/CollectForecasts.php` holds the orchestration in one class.
Laravel supplies the two provider objects to its `handle()` method. The command
validates configured coordinates/time zone and resolves today's local date once
for both providers. Each response's hourly and daily rows are inserted in one
database transaction. Any save failure rolls back that response; the other
provider is still attempted and its successful data is preserved.

New collections append history. An exact repeated response is rejected by the
database unique constraint instead of overwriting existing rows. Provider receipt
timestamps are preserved; the command does not replace them with a start time.
The command adds no HTTP retries or database-save retries of its own.

Console output and application logs report each provider, committed row counts,
safe failure reasons and a final summary. Failed responses count as zero saved
rows. Exit status is `0` when both succeed, `1` if either fails or required
location configuration is invalid. Database errors report SQLSTATE without SQL
bindings; API keys are redacted from provider failure reasons. Scheduling is
added separately; collection currently runs only when this command is invoked.

## Running tests

PHPUnit and Mockery are development dependencies installed by `composer install`.
With the Compose services running, create the dedicated test database once:

```sh
docker compose exec db createdb -U forecastapp forecastapp_test
```

Use your configured `DB_USERNAME` instead of `forecastapp` if you changed it.
Skip this command if the test database already exists. Keep the normal database
settings in `.env`; `phpunit.xml` selects `forecastapp_test` for tests and reuses
the connection credentials. No separate environment file is needed.

Run the tests:

```sh
docker compose exec app vendor/bin/phpunit
```

`tests/Feature/ForecastPersistenceTest.php` checks duplicate rejection, preservation
of earlier collections, NULL versus zero measurements, and date/decimal output
through both models (eight test cases). Laravel's `RefreshDatabase` prepares the
test tables and rolls back each test's inserts. The test database is reserved for disposable test data;
its tables can be recreated. A check in `tests/TestCase.php` requires the testing
environment and the actual PostgreSQL database `forecastapp_test` before database
refresh can run. Tests do not call weather providers.

`tests/Feature/OpenMeteoProviderTest.php` adds 18 cases with fake HTTP responses:
field mapping, receipt timestamps, missing/malformed data, DST boundaries, timeout
settings and the approved retry limits. No live key or network call is needed.

`tests/Feature/WeatherApiProviderTest.php` adds 18 fake-response cases for field
mapping, receipt time, missing configuration, date coverage, DST, malformed data,
retry limits and secret-safe failures. Tests use a fake key, not the real one.

`tests/Feature/CollectForecastsTest.php` tests collection with mocked providers
and real PostgreSQL transactions: preserving receipt timestamps and history,
one shared date window across midnight, duplicate rejection, provider isolation,
rollback of a response's hourly rows when daily insertion fails, committed counts,
exit statuses, missing location settings and API-key redaction.

## Compose basics

`compose.yaml` defines the app and database services. For `app`:

- `build: .` builds the root Dockerfile and `image` names the result.
- `ports` maps localhost port 8000 to port 8000 in the container.
- `volumes: .:/app` shares the project directory rather than copying source into
  the image. It matches the Dockerfile's `/app` working directory.
- `command` runs the same Laravel development server already tested manually.

For `db`, `image` selects PostgreSQL 17, `environment` uses the `.env` database
settings, `volumes` retains database files, and `healthcheck` tests readiness.
`app.depends_on` waits for that healthy state before starting the app.

Useful commands, with the app running:

```sh
docker compose ps
docker compose exec app php artisan route:list
docker compose logs app
docker compose down
```

Use `run --rm app ...` for a temporary one-off container, including initial setup
before the server starts. Use `exec app ...` to run a command inside the existing
running app container. `down` removes this project's containers/network while
keeping the source, installed packages, and named database volume. The scheduler
service will be introduced in a later step.

## Current structure

- `composer.json`: declares Laravel and configures PHP class loading.
- `artisan`: Laravel's command-line entry point.
- `public/index.php`: the HTTP request entry point.
- `bootstrap/app.php`: configures application startup, routes, and errors.
- `routes/web.php`: a small JSON startup response; the forecast API comes later.
- `config/`: compact application configuration. Cache/sessions use files and queues
  use synchronous execution, so this skeleton needs no cache/queue tables.
- `database/migrations/`: table definitions applied by `php artisan migrate`.
- `app/Models/`: the hourly and daily forecast models used for database access.
- `app/Weather/`: the common interface and Open-Meteo/WeatherAPI adapters.
- `storage/`: writable runtime directories; their generated contents are ignored.

Frontend assets, example user code, and default user/cache/jobs migrations are
omitted from this backend skeleton. The agreed forecast tables are created by
the migrations above. No external weather requests are made by this setup.
