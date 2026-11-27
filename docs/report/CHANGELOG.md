# Change log

Every session adds an entry at the top: date, phase, what changed, why, files touched, report pages updated and how to test it.

## 2026-11-27 · Phase 1 part A · Tenancy and licensing (items 1–5)

**What changed:**

- **Platform collections (item 1):** `tenants`, `licences`, `support_grants`, `school_configs`, plus `platform_admins` (our team, separate from school users) and `personal_access_tokens` (Sanctum tokens on MongoDB). Migrations create every collection and index; every index on school data starts with `tenant_id`. The placeholder `App\Models\User` is replaced by the tenant-scoped `Identity\Models\User`.
- **Licence keys (item 2):** `CTAB-XXXX-XXXX-XXXX-XXXX`, only an HMAC stored, Ed25519-signed payload (keys from `.env`, `php artisan licence:keygen --write`). `POST /licences/activate/otp` sends a code to the school's registered email and phone (SMS driver interface; `log` driver in dev); `POST /licences/activate` creates the Chief with MFA required, writes config version 1, activates the school and returns a token that only allows MFA enrolment. Middleware `licence` (15 grace days with `X-Licence-State: grace`, then read-only with exports allowed) and `module:<name>` (`403 module_disabled`). Commands `platform:create-school` and `platform:issue-licence` (brought forward from item 10 so part A can be checked).
- **Public config (item 3):** `GET /public/tenants/{code}/config` returns public branding only, from an allow-list.
- **School config (item 4):** versioned, JSON-schema-validated, diff per save, rollback as a new version, `ETag`/`If-Match` conflicts. Edit rights by section: the Chief edits everything; the Principal edits `policies.attendance|leave|marks|fees` and `academic` only; both view. `SchoolConfigPolicy` checks every changed path. The schema has no setting that lets a clerk change locked marks. A module can't be switched on unless the licence includes it.
- **Keeping schools apart (item 5):** `BelongsToTenant` (scope that throws with no school set, creating hook, tenant-checked updates and deletes) and `TenantQueryGuard`, which fails any test that sends a school-data query without `tenant_id`. Sign-in and token lookup are the one documented exception (decision 0005); password sign-in finds the school by code first.
- The API error renderer now keeps a Policy's own error code (for example `config_section_forbidden`).

**Why:** Phase 1 items 1–5 of `docs/BUILD-PROMPTS.md`, with the owner's changes: edit rights by config section, the sign-in lookup exception, and a MongoDB-backed Sanctum token model in part A.

**Files:** `backend/app/Modules/{Tenancy,Licensing,Identity}/**`, `backend/app/Support/{Tenancy,Otp,Sms}/**`, `backend/app/Support/Http/ApiExceptionRenderer.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/bootstrap/{app,providers}.php`, `backend/config/{auth,services,tenancy,licensing}.php`, `backend/database/migrations/2026_11_27_00000{1,2}_*.php`, `backend/.env.example`, `backend/phpunit.xml`, `backend/composer.{json,lock}` (`opis/json-schema` ^2.4), `backend/tests/{TestCase.php,Concerns/CreatesSchools.php,Feature/Tenancy/*,Feature/Licensing/*,Feature/Identity/*}`, `backend/tests/Feature/Support/RefreshMongoDatabaseTest.php`; removed `backend/app/Models/User.php` and `backend/database/factories/UserFactory.php`. `docs/CONVENTIONS.md` (tenancy rules).

**Report pages updated:** `modules/tenancy.md`, `modules/licensing.md`, `modules/identity-and-access.md` (part A), `configuration.md` (new variables, code settings, every school config key), `permissions.md` (rules coded so far), `decisions/0004-licence-keys.md`, `decisions/0005-tenant-scope-and-sign-in-exception.md`, and the generated blocks in `README.md`, `api.md`, `data-dictionary.md`.

**How to test:**

1. `cd infra && docker compose up -d`, then `docker compose exec api php artisan licence:keygen --write` (once; adds the keys to `backend/.env`) and `docker compose exec api php artisan migrate`.
2. `docker compose exec api composer test` → 124 tests pass; `docker compose exec api composer lint` and `composer analyse` → clean; `docker compose exec api php artisan report:generate --check` → up to date.
3. `docker compose exec api php artisan platform:create-school TEST-BPL "Test Public School" --email=office@test-bpl.test --phone=+919800000009`, then `docker compose exec api php artisan platform:issue-licence TEST-BPL` → note the key it prints.
4. `curl -X POST http://127.0.0.1:8000/api/v1/licences/activate/otp -H "Accept: application/json" -H "Content-Type: application/json" -d "{\"key\":\"<key>\"}"` → masked destinations. The code is in Mailpit (http://127.0.0.1:8025) and in `backend/storage/logs/laravel.log` ("SMS (log driver)").
5. `POST /api/v1/licences/activate` with the key, the code and a made-up Chief (`name`, `email`, `phone`, `password` of 12+ letters and digits, `password_confirmation`) → 201 with a token and `"token_abilities":["mfa:enrol"]`. `GET /api/v1/config` with that token → `403 mfa_enrolment_required`. Asking for a code again → `409 licence_already_activated`.
6. http://127.0.0.1:8000/api/v1/public/tenants/test-bpl/config → only code, name, short_name, logo_url, accent, languages, login_methods.

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
