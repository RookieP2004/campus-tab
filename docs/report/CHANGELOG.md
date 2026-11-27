# Change log

Every session adds an entry at the top: date, phase, what changed, why, files touched, report pages updated and how to test it.

## 2026-11-27 · Phase 0 · Foundations

**What changed:** the Campus TAB monorepo was set up from "System architecture > Repository layout":

- Root: npm workspaces (`frontend`, `packages/*`), `.editorconfig`, proprietary `LICENSE` (Sujal Chandel), `README.md`, `docs/CONVENTIONS.md`, placeholders for `apps/mobile`, `apps/desktop` and `packages/api`.
- `infra/docker-compose.yml`: MongoDB 8 as a single-node replica set (`rs0`, initiated by its healthcheck), Redis, Mailpit, the API, Horizon, Reverb and the Vite dev server. `backend/vendor` and every `node_modules` live in named volumes; Composer and npm run inside the containers only.
- `backend/`: Laravel 13 on PHP 8.4, API-only, MongoDB-only (no SQL), Redis for cache, sessions and queues; `mongodb/laravel-mongodb` 5.12, Sanctum, Horizon, Reverb; Pest, Pint, PHPStan level 6 (Larastan). New `System` module: `GET /api/v1/health`, the one API error shape (`ApiExceptionRenderer`, `ApiException`) and `php artisan report:generate [--check]`. Test helper `RefreshMongoDatabase`. CORS allows only `FRONTEND_URL`, with credentials.
- `frontend/`: React 19 + TypeScript + Vite 8, Tailwind CSS v4, shadcn/ui setup, React Router, TanStack Query, Zustand, React Hook Form + Zod, i18next (English default, Hindi shipped, registry for every regional language including right-to-left Urdu). Empty app shell with the brand stack, theme picker (Light, Dark, Match device) and language picker.
- `packages/ui/`: the "Theme tokens" table as CSS variables for light and dark, mapped to Tailwind utilities; brand constants; the existing logo files.
- GitHub Actions: Pint, PHPStan, Pest, `report:generate --check` (PHP 8.4, MongoDB started with `--replSet` in a step), ESLint, tsc, Vitest, build, and the Application Report check for pull requests.
- `docs/report/`: started with every page in "Application Report"; module pages from the template.
- Every local URL uses `127.0.0.1` (not `localhost`).
- Removed from the Laravel skeleton: its frontend build files, SQL migrations, welcome page, its tool instruction files and the `laravel/pao` dev package; two editor-tool entries removed from `backend/.gitignore`.

**Why:** Phase 0 of `docs/BUILD-PROMPTS.md`; the owner's requests for Windows-friendly Docker volumes, polling, LF endings, PHP 8.4 in CI and 127.0.0.1 everywhere.

**Files:** `package.json`, `package-lock.json`, `.editorconfig`, `LICENSE`, `README.md`, `docs/CONVENTIONS.md`, `apps/*/README.md`, `packages/api/*`, `packages/ui/{package.json,README.md,src/*}`, `infra/{docker-compose.yml,.env.example,docker/api/*,docker/web/entrypoint.sh}`, `backend/**` (new Laravel app; own code in `app/Modules/System/`, `app/Support/Http/`, `bootstrap/app.php`, `routes/api.php`, `config/{database,queue,cache,session,cors,sanctum,report}.php`, `tests/`), `frontend/**`, `.github/workflows/ci.yml`, `.github/scripts/check-report.mjs`, `docs/report/**`.

**Report pages updated:** all created: `README.md`, `01-overview.md`, `02-request-journey.md`, `modules/*.md` (System written in full; the thirteen business modules as templates), `frontend.md`, `data-dictionary.md`, `api.md`, `permissions.md`, `configuration.md`, `links-and-events.md`, `integrations.md`, `decisions/0001`–`0003`, `how-to/`, `operations.md`, `glossary.md`.

**How to test:**

1. `cd infra && docker compose up -d`, then `docker compose ps`: every service running, `mongo`, `redis` and `api` healthy.
2. Open http://127.0.0.1:8000/api/v1/health → `{"status":"ok","db":"ok","queue":"ok"}`. `docker compose stop redis` → HTTP 503 with `"queue":"fail"`; `docker compose start redis`.
3. Open http://127.0.0.1:5173 → the shell with the Campus TAB logo and name at the top of the sidebar, "Server: Connected" in the footer. Switch Light, Dark and Match device; switch to हिन्दी and back.
4. `docker compose exec api composer test` (10 tests) and `docker compose exec api composer lint`; `docker compose exec web npm test -w @campus-tab/frontend` (12 tests), plus `lint`, `typecheck` and `build`.
5. `docker compose exec api php artisan report:generate --check` → every page up to date.
6. Push the branch and open a pull request: the CI jobs Backend, Frontend and Application Report pass.
