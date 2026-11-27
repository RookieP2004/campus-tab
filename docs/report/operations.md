# Operations

How to run Campus TAB locally, which settings it needs, and what to do when something breaks. Deployment, backups and monitoring are added when hosting is chosen (the blueprint leaves the provider open).

## What you need

Git, Docker Desktop (Linux containers) and Node.js 22+. PHP, Composer and the npm packages run only inside containers.

## The local stack

All commands run from `infra/`.

```bash
cp .env.example .env        # first time only: host ports
docker compose up -d        # start everything in the background
docker compose ps           # check status
docker compose logs -f api  # follow one service's log
docker compose down         # stop (data kept)
docker compose down -v      # stop and delete all volumes: database, vendor, node_modules
```

| Service | What it is | Address from Windows |
| --- | --- | --- |
| `mongo` | MongoDB 8, single-node replica set `rs0` (initiated by its healthcheck on first start) | `127.0.0.1:27017` (add `directConnection=true` in Compass) |
| `redis` | Redis 7: cache, sessions, queues | `127.0.0.1:6379` |
| `mailpit` | Catches all outgoing email | http://127.0.0.1:8025 |
| `api` | Laravel API (`php artisan serve`); installs Composer packages on first start | http://127.0.0.1:8000 |
| `horizon` | Queue workers; starts after `api` is healthy | dashboard at http://127.0.0.1:8000/horizon (local only) |
| `reverb` | WebSocket server | `127.0.0.1:8080` |
| `web` | Vite dev server; installs npm packages on first start | http://127.0.0.1:5173 |

**Always use `127.0.0.1`, not `localhost`.** Sign-in cookies (Phase 1) belong to one host name, and on this machine `localhost` also resolves to `::1`, where another Windows program (a `DllHost.exe` process) listens on port 8000.

### Where things live

| Thing | Where | Why |
| --- | --- | --- |
| Source code | bind-mounted from the D: drive | You edit on Windows, containers see changes |
| `backend/vendor` | Docker volume `campus-tab_backend_vendor` | Kept off the Windows drive (speed, line endings) |
| every `node_modules` | volumes `campus-tab_node_modules_*` | Same reason |
| Composer download cache | volume `campus-tab_composer_cache` | Faster reinstalls |
| Database, Redis data | volumes `campus-tab_mongo_*`, `campus-tab_redis_data` | Survive restarts |
| `backend/.env` | created from `.env.example` on first start | Never committed |

The empty `backend/vendor` folder on D: is only the mount point Docker creates; the files are in the volume.

## Everyday commands

| Task | Command (from `infra/`) |
| --- | --- |
| Back-end tests | `docker compose exec api composer test` |
| Pint + PHPStan | `docker compose exec api composer lint` |
| Auto-fix code style | `docker compose exec api composer format` |
| Any artisan command | `docker compose exec api php artisan <command>` |
| Regenerate report tables | `docker compose exec api php artisan report:generate` |
| Add a PHP package | `docker compose exec api composer require vendor/package` |
| Front-end tests | `docker compose exec web npm test -w @campus-tab/frontend` |
| ESLint / tsc | `docker compose exec web npm run lint -w @campus-tab/frontend` · `… run typecheck …` |
| Production build | `docker compose exec web npm run build -w @campus-tab/frontend` |
| Add an npm package | `docker compose exec web npm install <pkg> -w @campus-tab/frontend` |
| Report check (before a PR) | `node .github/scripts/check-report.mjs origin/main` (from the repo root) |

When `composer.lock` or `package-lock.json` changes (for example after a `git pull`), the next start of `api` or `web` reinstalls automatically.

## Line endings

`.gitattributes` keeps every file LF (CRLF only for `.ps1`/`.bat`). Shell scripts with CRLF fail inside Linux containers with `/bin/sh^M: bad interpreter`. If that happens: `git add --renormalize .` and re-checkout the file.

## CI

[`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) runs on every push to `main` and every pull request:

| Job | Steps |
| --- | --- |
| Backend | Starts MongoDB 8 with `docker run … --replSet rs0`, runs `rs.initiate` and waits until it is primary (service containers cannot pass `--replSet`); Redis as a service; PHP 8.4 with the same extensions as the container (mongodb 2.5.9, redis, pcntl, intl, bcmath, sockets, zip); Pint, PHPStan level 6, Pest, `report:generate --check` |
| Frontend | Node 22, `npm ci`, ESLint, `tsc`, Vitest, build |
| Application Report | Pull requests only: [`.github/scripts/check-report.mjs`](../../.github/scripts/check-report.mjs) fails if code changed without a `CHANGELOG.md` entry, a changed module without its `modules/<module>.md`, or front-end code without `frontend.md` or a module page |

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `failed to connect to the docker API … dockerDesktopLinuxEngine` | Docker Desktop is not running; start it |
| `http://localhost:8000` times out | Use `http://127.0.0.1:8000` (see above) |
| `api` stays "starting" on first run | It is installing Composer packages; `docker compose logs -f api` |
| Health shows `"db":"fail"` | `docker compose ps mongo`; if the replica set was never initiated, `docker compose restart mongo` lets the healthcheck initiate it |
| Health shows `"queue":"fail"` | `docker compose ps redis` |
| Tests refuse to run: "Refusing to drop database" | The test database name must end in `_test`; check `phpunit.xml` |
| The page does not reload after a change | The `web` service needs `VITE_USE_POLLING=true` (set in compose); restart it |
| Port already in use | Change it in `infra/.env` |
| Start completely fresh | `docker compose down -v`, then `docker compose up -d` |
