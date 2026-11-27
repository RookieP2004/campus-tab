# Campus TAB

Campus TAB is one multi-tenant school platform. Each school activates it with a licence key and gets attendance, staff and student leave, tests and marks, report cards, fees, notices and a parent app, all under the Campus TAB brand with the school's own logo and name.

- **Back end:** Laravel 13 API (PHP 8.4) on MongoDB 8, with Redis, Horizon and Reverb. Code in `backend/`.
- **Front end:** one React 19 + TypeScript codebase for the web app and, later, the Android, iOS, Windows and Mac apps. Code in `frontend/`.
- **Shared packages:** design tokens and logo in `packages/ui/`, the generated API client in `packages/api/`.

## Run it locally

You need **Git**, **Docker Desktop** and **Node.js 22+**. PHP, Composer and the npm packages run inside the containers, so you don't install them yourself.

```bash
cd infra
cp .env.example .env              # first time only; edit ports if you need to
docker compose up                 # first run takes a few minutes: images build, then composer installs
```

When it is up:

| What | Where |
| --- | --- |
| API health check | http://127.0.0.1:8000/api/v1/health |
| Web app (Vite dev server) | http://127.0.0.1:5173 |
| Mailpit (test email inbox) | http://127.0.0.1:8025 |

Useful commands (run from `infra/`):

```bash
docker compose exec api php artisan about          # Laravel status
docker compose exec api composer test              # back-end tests (Pest)
docker compose exec api composer lint              # Pint + PHPStan
docker compose down                                # stop everything (data is kept)
docker compose down -v                             # stop and wipe database, vendor and node_modules volumes
```

## Where the docs are

| File | What it is |
| --- | --- |
| [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) | The full product and system plan |
| [`docs/BUILD-PROMPTS.md`](docs/BUILD-PROMPTS.md) | The build phases and their "done when" tests |
| [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) | Rules every change follows |
| [`docs/report/`](docs/report/) | The Application Report: how every part of the app works, plus the change log |
| [`docs/design/`](docs/design/) | Screen designs in light and dark |

## Licence

Copyright (c) 2026 Sujal Chandel. All rights reserved. See [`LICENSE`](LICENSE).
