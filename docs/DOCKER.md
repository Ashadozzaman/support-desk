# Run Support Desk with Docker Compose

One command starts the whole stack. No PHP, Composer, Node or MySQL is needed on the host, only Docker
(Engine 24+ with Compose v2).

## 1. What runs

| Service | Image | What it does | Host port |
|---|---|---|---|
| `app` | `support-desk-app:local` (target `app`) | php-fpm; runs `migrate --force` on start (`RUN_MIGRATIONS=true`) | — |
| `nginx` | `support-desk-web:local` (target `web`) | serves `public/` (built assets), forwards PHP to `app:9000` | `8000` (`APP_PORT`) |
| `queue` | app image | `queue:work --queue=sla,default,notifications,broadcasts,exports --tries=3 --max-time=3600` | — |
| `imports` | app image | `queue:work --queue=imports --timeout=3600 --tries=1` (own worker, so a big CSV never delays SLA jobs) | — |
| `scheduler` | app image | `schedule:work`: `sla:scan`, `tickets:auto-assign`, dashboard warm-up, reports, prune… | — |
| `reverb` | app image | `reverb:start` WebSocket server | `8080` (`REVERB_FORWARD_PORT`) |
| `mysql` | `mysql:8.4` | `support_desk` + the empty test DB `support_desk_test` | `3308` (`FORWARD_DB_PORT`) |
| `redis` | `redis:7-alpine` | cache store | — |
| `mailpit` | `axllent/mailpit` | catches all outgoing mail; web UI | `8025` |
| `tests` | app image (profile `test`) | one-off Pest run, only when asked | — |

### Why no Supervisor

Supervisor's job (keep `queue:work` / `schedule:work` alive and restart them when they die) is done
by Docker here. Each long-running process is **its own compose service** with `restart: unless-stopped`
and a healthcheck, so:

* a crashed worker is restarted by Docker; `docker compose ps` shows each one's health;
* each one has its own logs (`docker compose logs -f queue`) and can be scaled on its own
  (`docker compose up -d --scale queue=3`);
* `stop_grace_period: 60s` lets a worker finish its current job on `docker compose stop`.

On a plain VM without Docker you would use Supervisor instead (see README "Background processing").

### Startup order (healthchecks)

`mysql` + `redis` healthy → `app` migrates, then php-fpm starts and answers `/ping` → `nginx`, `queue`,
`imports`, `scheduler`, `reverb` start. Workers therefore never boot against an un-migrated database.

| Service | Healthcheck |
|---|---|
| app | php-fpm `/ping` over FastCGI (`cgi-fcgi`) |
| nginx | `GET /up` (Laravel's health route, so it goes through PHP and the DB) |
| queue / imports / scheduler | the worker process is running (`pgrep`) |
| reverb | TCP connect to `:8080` |
| mysql / redis / mailpit | `mysqladmin ping` / `redis-cli ping` / `/livez` |

## 2. First run

```bash
cp .env.docker.example .env.docker
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env.docker

docker compose up -d --build          # first build ≈ 3–5 min
docker compose ps                     # wait until every service is "healthy"
docker compose exec app php artisan db:seed --force    # roles, SLA policies, demo users + 2,000 tickets
```

Open:

* App: <http://localhost:8000> (demo accounts below)
* Mailpit (password resets, notifications): <http://localhost:8025>
* System health (admin): <http://localhost:8000/admin/system-health>

| Role | Email | Password |
|---|---|---|
| Admin | `admin@support-desk.test` | `password` |
| Agent | `agent1@support-desk.test` (… `agent3`) | `password` |
| Customer | `customer1@support-desk.test` (… `customer5`) | `password` |

`.env.docker` is gitignored. Its host names are the service names (`DB_HOST=mysql`, `REDIS_HOST=redis`,
`MAIL_HOST=mailpit`, `REVERB_HOST=reverb`). The MySQL credentials there must match the `mysql` service
in `docker-compose.yml` (local-only values).

## 3. Everyday commands

```bash
docker compose logs -f app queue scheduler        # follow logs
docker compose exec app php artisan tinker        # any artisan command
docker compose exec app php artisan health:check  # same checks as /admin/system-health
docker compose run --rm tests                     # full Pest suite against support_desk_test
docker compose restart queue imports              # after changing job code (workers keep code in memory)
docker compose down                               # stop (data kept in volumes)
docker compose down -v                            # stop AND delete DB, Redis and uploaded files
```

**Code changes need a rebuild.** Code and built assets are baked into the image (no bind mount), so it runs
the same on every machine: `docker compose up -d --build`. For day-to-day development keep using the
host setup (`composer dev`).

## 4. How the image is built (`docker/php/Dockerfile`)

| Stage | Purpose |
|---|---|
| `base` | `php:8.3-fpm` + `pdo_mysql bcmath intl pcntl zip gd opcache sockets redis`, Composer, `php.ini`, fpm pool |
| `assets` | `composer install` + `npm ci` + `npm run build`. Needs PHP because the Wayfinder Vite plugin runs `php artisan wayfinder:generate` |
| `app` | runtime: code + `vendor/` + `public/build`, runs as `www-data`; `entrypoint.sh` checks `APP_KEY`, migrates (app only), `artisan optimize` |
| `web` | `nginx:alpine` + `docker/nginx/default.conf` + `public/` |

* **Dev packages are included** because the seeders use factories (Faker) and Pest runs in the container.
  For a production image, build with `composer install --no-dev` and `APP_ENV=production`, `APP_DEBUG=false`.
* **Reverb keys are compiled into the JS.** `VITE_REVERB_*` are build args (browser → `localhost:8080`),
  while the server side uses `REVERB_HOST=reverb` on the docker network. `VITE_REVERB_APP_KEY` (default
  `support-desk-local-key`, override with `DOCKER_REVERB_APP_KEY`) must equal `REVERB_APP_KEY` in `.env.docker`.
* **Uploads and imports** go to the private disk under `storage/`, a named volume (`storage`) shared by
  `app`, `queue`, `imports` and `scheduler`, so a CSV uploaded via the app is readable by the import worker.
* **Queue stays on the `database` driver** (the queue health checks read the `jobs` table); Redis backs the cache.
* `opcache.validate_timestamps=0` is safe because code never changes inside a running container.

Files: `docker-compose.yml`, `.env.docker.example`, `.dockerignore`, `docker/php/{Dockerfile,php.ini,www.conf,entrypoint.sh}`,
`docker/nginx/default.conf`, `docker/mysql/create-testing-database.sh`.

## 5. Troubleshooting

| Symptom | Fix |
|---|---|
| `APP_KEY is empty` and `app` keeps restarting | generate the key (step 2), then `docker compose up -d` |
| port already in use | set `APP_PORT`, `REVERB_FORWARD_PORT`, `FORWARD_DB_PORT` or `FORWARD_MAILPIT_DASHBOARD_PORT` in your shell or in a project `.env`, e.g. `APP_PORT=8001 docker compose up -d` (if you change `REVERB_FORWARD_PORT`, also rebuild) |
| live updates don't arrive | `docker compose ps reverb` is healthy; `REVERB_APP_KEY` matches the build arg; rebuild after changing it |
| 502 from nginx | `docker compose logs app nginx` |
| browser shows an old page after a rebuild | hard refresh: asset hashes changed and Inertia reloads on version mismatch (409) |
| connect a DB client | `127.0.0.1:3308`, user `support_desk` / `secret` |
