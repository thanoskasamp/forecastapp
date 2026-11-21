# AgroApps Assignment

A web application developed for the AgroApps technical assignment.

## Project status

The PHP/Composer Docker toolbox and backend Laravel skeleton are prepared.
Dependency installation, Laravel CLI startup, and the root JSON HTTP response
have been verified using Docker directly and through the app-only Compose
service. PostgreSQL, collection, and the forecast API will be added in later
steps.

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

Expected output starts with `Laravel Framework 13.`. Database connectivity will
be configured and verified in a subsequent step.

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
with Ctrl+C. This development server verifies HTTP startup; database/provider
behavior is implemented in later steps.

## Compose basics

`compose.yaml` currently defines just one service, `app`:

- `build: .` builds the root Dockerfile and `image` names the result.
- `ports` maps localhost port 8000 to port 8000 in the container.
- `volumes: .:/app` shares the project directory rather than copying source into
  the image. It matches the Dockerfile's `/app` working directory.
- `command` runs the same Laravel development server already tested manually.

Useful commands, with the app running:

```sh
docker compose ps
docker compose exec app php artisan route:list
docker compose logs app
docker compose down
```

Use `run --rm app ...` for a temporary one-off container, including initial setup
before the server starts. Use `exec app ...` to run a command inside the existing
running app container. `down` removes this project's containers/network, not the
bind-mounted source or installed packages. PostgreSQL and scheduler services
will be introduced in separate steps.

## Current structure

- `composer.json`: declares Laravel and configures PHP class loading.
- `artisan`: Laravel's command-line entry point.
- `public/index.php`: the HTTP request entry point.
- `bootstrap/app.php`: configures application startup, routes, and errors.
- `routes/web.php`: a small JSON startup response; the forecast API comes later.
- `config/`: standard Laravel configuration. Cache/sessions use files and queues
  use synchronous execution, so this skeleton needs no cache/queue tables.
- `storage/`: writable runtime directories; their generated contents are ignored.

Frontend assets, example user code, and default user/cache/jobs migrations are
omitted from this backend skeleton. The agreed forecast tables are not created
yet. No external weather requests are made by this setup.
