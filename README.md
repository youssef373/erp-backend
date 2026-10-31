# Mini ERP — Docker development environment

The stack is two sibling folders under `htdocs/`, run together by the
`docker-compose.yml` in this folder:

```
htdocs/
├── erp-laravel/          Laravel 13 REST API (this folder)
│   ├── Dockerfile        PHP 8.4-FPM + Composer (backend, queue, reverb, scheduler)
│   ├── docker-compose.yml
│   └── docker/nginx/default.conf
└── react-erb/            React + Vite SPA
    └── Dockerfile        Node 22 running the Vite dev server
```

| Service     | What it does                              | URL / port                    |
|-------------|-------------------------------------------|-------------------------------|
| `nginx`     | Serves the Laravel API                    | http://localhost:8000         |
| `backend`   | PHP-FPM running the Laravel code          | internal (9000)               |
| `frontend`  | Vite dev server with hot reload           | http://localhost:5173         |
| `reverb`    | WebSocket server (`reverb:start`)         | ws://localhost:8080           |
| `queue`     | `php artisan queue:work redis`            | —                             |
| `scheduler` | `php artisan schedule:work`               | —                             |
| `mysql`     | MySQL 8.4, data in the `mysql_data` volume | `localhost:3308` (from the host) |
| `redis`     | Redis 7: cache, sessions, queue           | internal (6379)               |

Run all commands below from `erp-laravel/`.

## First-time setup

Requires Docker Desktop.

```bash
cp .env.example .env
cp ../react-erb/.env.example ../react-erb/.env

docker compose up -d --build                     # first run installs vendor/ and node_modules/
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
```

The first start takes a few minutes while `composer install` and
`npm install` run inside the containers. Follow progress with
`docker compose logs -f backend frontend`. `queue`, `reverb`, `scheduler` and
`nginx` start once `backend` reports healthy, and every PHP service waits for
`mysql` and `redis` healthchecks.

Then open:

- http://localhost:8000/api returns `{"status":"ok", ...}`
- http://localhost:5173 shows the React app, with an "API: ok · Reverb: connected" line

## Migrations

```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan migrate --seed       # also seeds test@example.com / password
docker compose exec backend php artisan migrate:fresh --seed # drop everything and rebuild
```

## Shell in the backend container

```bash
docker compose exec backend sh
```

Inside it you can run `php artisan …` and `composer …` as usual. One-off
commands work without opening a shell:

```bash
docker compose exec backend php artisan tinker
docker compose exec backend composer require vendor/package
docker compose exec frontend npm install some-package
```

## Stopping

```bash
docker compose stop        # stop containers, keep them
docker compose down        # stop and remove containers (DB data is kept)
docker compose down -v     # also delete volumes: MySQL data, vendor/, node_modules/
```

## How the development setup works

- **Live code.** `erp-laravel/` and `react-erb/` are bind-mounted, so edits
  on the host apply immediately. Vite hot-reloads in the browser; it polls
  for changes (`VITE_USE_POLLING`) because file events from Windows mounts
  don't reach Linux containers.
- **Long-running workers keep old code in memory.** After changing jobs,
  events or scheduled tasks, run
  `docker compose restart queue reverb scheduler`.
- **Dependencies live in volumes.** `vendor/` and `node_modules/` are named
  volumes (`backend_vendor`, `frontend_node_modules`) mounted over the source
  folders, so Linux-built packages never land on Windows. The empty
  `vendor/` and `node_modules/` folders on the host are just mount points.
  Your editor won't see the packages, so autocompletion for them won't work
  unless you also install them locally. Containers run `composer install` and
  `npm install` on every start, so changes to `composer.json` or
  `package.json` are picked up by `docker compose restart backend frontend`.
- **Config is shared with Compose.** `docker-compose.yml` reads `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD` and `DB_ROOT_PASSWORD` from `.env`, so the
  MySQL container and Laravel always agree on credentials. If you change them
  after the first run, run `docker compose down -v` (MySQL only reads them
  when it creates its data volume).

## Authentication (Sanctum SPA mode)

The React app authenticates with session cookies, not tokens:

1. `GET  http://localhost:8000/sanctum/csrf-cookie` sets the `XSRF-TOKEN` cookie.
2. `POST http://localhost:8000/api/login` with `{ email, password }`.
3. Later requests (e.g. `GET /api/user`) are authenticated by the session cookie.
4. `POST /api/logout`.

`react-erb/src/lib/api.js` holds an axios instance configured for this
(`withCredentials`, `withXSRFToken`) plus `login()` / `logout()` helpers.

Relevant backend settings: `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN=localhost`
and `FRONTEND_URL` in `.env`; `statefulApi()` in `bootstrap/app.php`; and
`config/cors.php` (SPA origin only, `supports_credentials => true`).

## Real-time (Reverb + Echo)

`react-erb/src/lib/echo.js` exports a configured Laravel Echo client.
Private channels are authorized at `POST /api/broadcasting/auth` using the
Sanctum session (see `withBroadcasting()` in `bootstrap/app.php`).

```js
import echo from './lib/echo'

echo.private(`App.Models.User.${user.id}`)
    .listen('OrderShipped', (e) => console.log(e))
```

Laravel publishes to Reverb over the Docker network (`REVERB_HOST=reverb`);
browsers connect to `localhost:8080` (`VITE_REVERB_*` in `react-erb/.env`).
`REVERB_APP_KEY` must match `VITE_REVERB_APP_KEY`.

## Troubleshooting

- **Port already in use.** The host's MySQL port defaults to 3308. Override
  it with `FORWARD_DB_PORT=3309` in `.env`. Ports 8000, 5173 and 8080 must be free.
- **Logs.** `docker compose logs -f <service>`; Laravel's log is
  `storage/logs/laravel.log`.
- **Failed jobs.** `docker compose exec backend php artisan queue:failed`.
