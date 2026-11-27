# Configuration

Two kinds of settings:

1. **Environment variables** — how the server is wired (database, Redis, mail). Set per environment in `.env` files that are never committed. Names only here, never values of secrets.
2. **The school config** — every school-level choice (branding, modules, attendance mode, leave chain, fee rules), stored as a versioned JSON document per school. Built in **Phase 1**; each key will be listed below with its default and effect. The intended shape is "The school config (JSON)" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md).

## Environment variables (back end)

Defined in [`backend/.env.example`](../../backend/.env.example). In Docker, [`infra/docker-compose.yml`](../../infra/docker-compose.yml) sets the connection variables directly, which override `backend/.env`.

| Variable | Default (dev) | What it changes |
| --- | --- | --- |
| `APP_NAME` | `Campus TAB` | Name in emails and logs |
| `APP_ENV` | `local` | `local`, `testing`, `staging`, `production` |
| `APP_KEY` | generated on first start | Encryption key; secret |
| `APP_DEBUG` | `true` | Shows exception details in 500 errors; must be `false` outside development |
| `APP_URL` | `http://127.0.0.1:8000` | The API's own address |
| `FRONTEND_URL` | `http://127.0.0.1:5173` | The web app's address; the only origin CORS allows (comma-separate several) |
| `SANCTUM_STATEFUL_DOMAINS` | `127.0.0.1:5173` | Hosts whose requests get sign-in cookies (Phase 1) |
| `DB_CONNECTION` | `mongodb` | Always `mongodb` |
| `MONGODB_URI` | `mongodb://mongo:27017/?replicaSet=rs0` | MongoDB address; must name the replica set |
| `MONGODB_DATABASE` | `campus_tab` | Database name (tests force `campus_tab_test`) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `redis`, `6379`, none | Redis for cache, sessions and queues |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `redis` | Where cache, sessions and jobs live |
| `BROADCAST_CONNECTION` | `reverb` | Live updates through Reverb |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | dev placeholders | Reverb credentials; secret outside development |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `reverb`, `8080`, `http` | How the API reaches Reverb |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `mailpit`, `1025` | Outgoing email (Mailpit catches it in dev) |
| `MAIL_FROM_ADDRESS` | `no-reply@campustab.local` | Sender address |
| `AWS_*` | empty, `ap-south-1` | Object storage for files (from Phase 2) |
| `REPORT_PATH` | `/docs/report` in Docker, else `../docs/report` | Where `report:generate` writes |
| `LICENCE_KEY_PEPPER` | none; `php artisan licence:keygen --write` | Secret for hashing licence keys. Changing it invalidates every issued key |
| `LICENCE_SIGNING_SECRET_KEY`, `LICENCE_SIGNING_PUBLIC_KEY` | none; `php artisan licence:keygen --write` | Ed25519 pair (base64) that signs and checks licence payloads; secret |
| `LICENCE_GRACE_DAYS` | `15` | Days after the licence ends with a banner, before read-only |
| `SMS_DRIVER` | `log` | Text messages (OTP): `log` writes them to `storage/logs/laravel.log`; `array` keeps them in memory (tests) |
| `TENANCY_QUERY_GUARD` | `log` | `log` warns about school-data queries without `tenant_id`; `record` (tests) fails the test; `off` |

## Environment variables (front end)

Defined in [`frontend/.env.example`](../../frontend/.env.example). Only `VITE_*` values reach the browser, so they are never secret.

| Variable | Default | What it changes |
| --- | --- | --- |
| `VITE_API_BASE_URL` | `/api/v1` | Where the app sends API calls (same origin through the Vite proxy in dev) |
| `VITE_REVERB_APP_KEY` | `campus-tab-local-key` | Public Reverb key |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | `127.0.0.1`, `8080`, `http` | Reverb address as the browser sees it |

Set on the `web` container only: `VITE_USE_POLLING=true` (watch files by polling) and `VITE_API_PROXY_TARGET=http://api:8000` (proxy target).

## Host ports (local stack)

Defined in [`infra/.env.example`](../../infra/.env.example): `API_PORT` 8000, `WEB_PORT` 5173, `REVERB_PORT` 8080, `MONGO_PORT` 27017, `REDIS_PORT` 6379, `MAILPIT_SMTP_PORT` 1025, `MAILPIT_UI_PORT` 8025.

## Code settings (`backend/config/`)

| File | Key | What it changes |
| --- | --- | --- |
| `licensing.php` | `plans` | Plans a licence may name: `starter`, `standard`, `premium` |
| `licensing.php` | `modules` | Modules a licence can switch on: attendance, leave, assessment, reports, fees, communication |
| `licensing.php` | `activation.*` | OTP minutes (10), tries (5), sends per 10 minutes (3), enrolment-token minutes (30) |
| `tenancy.php` | `platform_collections` | Collections that are not school data (decision 0005) |

## School config keys

Each school's versioned config, validated by [`school-config.schema.json`](../../backend/app/Modules/Tenancy/Schemas/school-config.schema.json). Every object forbids unknown keys. "Edits" says who may change the key (Tenancy page, section 9).

| Key | Values (default) | Edits |
| --- | --- | --- |
| `school.name`, `school.short_name` | text (from the school record) | Chief |
| `school.logo_url` | storage path or null (null) | Chief |
| `school.board` | CBSE, ICSE, STATE, IB, CAMBRIDGE, OTHER (CBSE) | Chief |
| `school.affiliation_no` | text or null (null) | Chief |
| `branding.accent` | `#RRGGBB` (`#0F766E`) | Chief |
| `locale.default_language` | en, hi, bn, mr, gu, pa, ta, te, kn, ml, or, ur (en) | Chief |
| `locale.available_languages` | list of the above (en, hi) | Chief |
| `locale.user_can_choose_language` | true/false (true) | Chief |
| `locale.timezone` | IANA name (Asia/Kolkata) | Chief |
| `locale.date_format` | DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD (DD-MM-YYYY) | Chief |
| `academic.year_start_month` | 1–12 (4) | Chief, Principal |
| `academic.terms` | 1–4 names (Term 1, Term 2) | Chief, Principal |
| `modules.attendance` … `modules.communication` | true/false (true when licensed); can't be on unless licensed | Chief |
| `sign_in.methods` | password, parent_otp (both) | Chief |
| `policies.attendance.student_mode` | daily, period (daily) | Chief, Principal |
| `policies.attendance.edit_window_hours` | 0–168 (4) | Chief, Principal |
| `policies.attendance.min_percent_alert` | 0–100 (75) | Chief, Principal |
| `policies.leave.chain` | clerk, principal (both) | Chief, Principal |
| `policies.leave.clerk_can_reject` | true/false (false) | Chief, Principal |
| `policies.leave.remind_after_hours` | 1–168 (24) | Chief, Principal |
| `policies.leave.escalate_after_hours` | 1–336 (48) | Chief, Principal |
| `policies.marks.auto_publish_class_tests` | true/false (true) | Chief, Principal |
| `policies.fees.gateway` | none, razorpay (none) | Chief, Principal |
| `policies.fees.waiver_by` | principal, chief (principal) | Chief, Principal |
| `policies.fees.receipt_prefix` | text or null (null) | Chief, Principal |

There is deliberately no setting that lets a clerk change locked marks: that rule is fixed in code.
