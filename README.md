# AgroApps Assignment

A web application developed for the AgroApps technical assignment.

## Project status

The PHP/Composer Docker toolbox and backend Laravel skeleton are prepared.
Dependency installation, Laravel CLI startup, and the root JSON HTTP response
have been verified using Docker directly and through Compose. PostgreSQL 17.12
startup and Laravel database connectivity have also been verified. Both
forecast migrations have run, and PostgreSQL column types and constraints have
been inspected. Collection and the forecast API come later.
The two forecast models are implemented. The persistence test suite passes
against PostgreSQL (eight tests, 32 assertions).

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
DB_PASSWORD=local-development-only
```

The password is a local-development example. Real local credentials stay in the
ignored `.env`. `db` is the Compose service hostname: from the app container,
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
verify database connectivity. Provider behavior is implemented in later steps.

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
- `config/`: standard Laravel configuration. Cache/sessions use files and queues
  use synchronous execution, so this skeleton needs no cache/queue tables.
- `database/migrations/`: table definitions applied by `php artisan migrate`.
- `app/Models/`: the hourly and daily forecast models used for database access.
- `storage/`: writable runtime directories; their generated contents are ignored.

Frontend assets, example user code, and default user/cache/jobs migrations are
omitted from this backend skeleton. The agreed forecast tables are created by
the migrations above. No external weather requests are made by this setup.
