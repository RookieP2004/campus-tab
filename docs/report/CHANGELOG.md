# Change log

Every session adds an entry at the top: date, phase, what changed, why, files touched, report pages updated and how to test it.

## 2026-11-27 · Phase 1 part B · Identity, roles, audit and support access (items 6–9)

**What changed:**

- **Sign-in (item 6).**
  - **Password sign-in** finds the school by code, then the user by email, phone or login name.
    - Every failure gets one answer, `401 sign_in_failed` ("Those details didn't work, or there were too many tries…").
    - While an account is locked its password is not checked at all.
    - 5 failures in a row, wrong MFA codes included, lock it for 15 minutes. `GET /users` shows the lock, and the Principal or Chief can unlock.
    - Per-IP limits are roomy config values for bulk abuse only.
  - **TOTP MFA** is mandatory from level 60 and asked at every sign-in. The secret is encrypted, the 10 recovery codes are stored as HMACs, and a code can't be replayed. An enrol-only session never becomes a full one.
  - **Step-up MFA:** one middleware and a named list in `config/identity.php` (`export`, `permanent_delete`, `role_edit`, `marks_unlock`, `support_grant`, `mfa_reset`), valid 10 minutes on that device. `role_edit` also covers changing a user's roles.
  - **Parent OTP** is for Parent-only accounts without MFA. Only a hash is stored, the code lasts 5 minutes, and sends and failures are limited per phone and per IP, with the same replies for known and unknown numbers. The `log` and `array` SMS drivers work only in local and testing; anywhere else a missing driver stops the app at start-up.
  - **Sign in every time:** browser session cookie only; idle limit 15 minutes at level 60 and up, 30 below; 8 hours in total. Device sessions hold only the token id or a hash of the session id, and can be listed and signed out remotely.
  - **Per-user language** (any language the app ships) and theme. The school setting `user_can_choose_language` is gone; `available_languages` is now `featured_languages`.
- **Passwords.**
  - **New accounts** start `invited` with no password. The person gets a one-time set link (48 h) by email or SMS, or the Principal gets a one-time setup code shown once. Resending cancels the old link.
  - **Forgot password** gives the same reply for everyone. Its link lasts 30 minutes, with limits per account and per IP. The Principal or Chief can send a reset link to anyone below them.
  - **Changing your own** needs the current password.
  - **Any set, change or reset** ends every session and token, keeps MFA, and tells the user.
  - **Rules:** 10+ characters, not the login or name, not on the bundled common list.
  - **Lost authenticator:** recovery codes first; otherwise an MFA reset with step-up by someone above (another Chief for a Chief).
- **Roles and permissions (item 7).**
  - **The form:** `module.action:scope` from a catalogue in `config/permissions.php`.
  - **Seeded roles:** nine levels plus two duties, Fee Collector and the new Attendance Officer (staff-attendance entry only; Phase 4 must stop anyone entering their own attendance through it). Command `identity:sync-roles` adds them to existing schools.
  - **Checks:** six scope resolvers, no escalation, Chief over Chief, the last-Chief guard, and Chief changes recorded as security events.
  - **Every route** names its permission; a route test enforces it. `SchoolConfigPolicy` now uses `config.view`, `config.edit` and `config.edit_policies`.
- **Audit (item 8).**
  - **The log** is append-only, with no update or delete path and no soft deletes, and never holds passwords, codes, links or tokens. Support actions record the agent and the grant id. The Chief sees all, the Principal academic entries and their own, a Clerk their own.
  - **Recycle bin** with restore; the Chief's permanent purge needs step-up, 30 days and the record's typed name.
- **Support grants (item 9).** Created by the Chief with step-up: read-only by default, ending 15 minutes to 72 hours ahead with no extension, revocable. Agents act only inside an active grant, checked on every request. The school sees each grant and its activity.
- **Bugs the tenant query guard and the new tests caught on the way:**
  - `Model::refresh()` skips the tenant scope.
  - A right password used to reset the count of wrong MFA codes.
  - The driver rewrites `actor.id` to `actor._id` in queries, so entries now also carry `actor_id`.

**Why:** Phase 1 items 6–9 of `docs/BUILD-PROMPTS.md`, with the owner's changes for part B.

**Files:**
- **Back end:**
  - `backend/app/Modules/Identity/**`, `backend/app/Modules/Audit/**` (new);
  - `backend/app/Modules/Tenancy/{Http/Middleware/SetTenantFromUser.php,Policies/SchoolConfigPolicy.php,Models/SchoolConfig.php,Domain/{TenantProvisioner,PublicConfig,DefaultConfig}.php,Schemas/school-config.schema.json,routes.php}`;
  - `backend/app/Modules/Licensing/{Domain/ActivationService.php,Http/Controllers/ActivationController.php,Http/Middleware/EnsureLicenceAllows.php}`;
  - `backend/app/Support/Otp/OtpService.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/bootstrap/{app,providers}.php`.
- **Config and data:**
  - `backend/config/{identity,permissions,audit,licensing,services,session}.php`;
  - `backend/database/migrations/2026_11_27_000003_create_identity_and_audit_collections.php`;
  - `backend/resources/security/common-passwords.txt`;
  - `backend/.env.example`, `backend/composer.{json,lock}` (`pragmarx/google2fa` ^8.0).
- **Tests:**
  - `backend/tests/Concerns/{CreatesSchools,TestData}.php`;
  - `backend/tests/Feature/Identity/*`, `backend/tests/Feature/Audit/*`, `backend/tests/Feature/Tenancy/PublicConfigTest.php`.
- **Docs:** `docs/CONVENTIONS.md`.

**Report pages updated:** `modules/identity-and-access.md` and `modules/audit.md` (in full), `modules/tenancy.md`, `modules/licensing.md`, `permissions.md` (the matrix as coded), `configuration.md`, decisions `0006`–`0009` (0008 holds the open question for the legal review), and the generated blocks.

**How to test:**

1. `cd infra && docker compose exec api php artisan migrate`, then `docker compose exec api php artisan identity:sync-roles` (adds the roles to schools made in part A).
2. `docker compose exec api composer test`, `composer lint`, `composer analyse`, then `docker compose exec api php artisan report:generate --check`.
3. Create and activate a school as in part A (step 3–5 below). The activation response now carries `device_session_id` and an enrol-only token.
4. With that token: `POST /api/v1/me/mfa/enrol` → `secret`; add it to an authenticator app; `POST /api/v1/me/mfa/enrol/confirm {"code":"<6 digits>"}` → 10 recovery codes and a full token. `GET /api/v1/me` → roles, permissions, `session.idle_minutes: 15`.
5. Sign out, then `POST /api/v1/auth/login {"school_code":"TEST-BPL","login":"<chief email>","password":"…"}` → `mfa_required` and a `challenge`; `POST /api/v1/auth/mfa/verify {"challenge":"…","code":"…"}` → token.
6. Try a wrong password 5 times, then the right one → the same `sign_in_failed` body every time.
7. As the Chief: `POST /api/v1/users {"name":"Made Up Teacher","email":"teacher@test-bpl.test","roles":["class_teacher"]}` → `status: invited`; the set link is in Mailpit (http://127.0.0.1:8025). `POST /api/v1/auth/password/set` with the link's `token` → then the teacher can sign in.
8. `PUT /api/v1/roles/staff` with the Chief's token → `403 step_up_required`; `POST /api/v1/auth/step-up {"code":"…"}` → then it works.
9. `GET /api/v1/audit-logs` as the Chief → every step above, without any password, code or token.

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
