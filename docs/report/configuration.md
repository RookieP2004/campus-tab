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
| `SMS_DRIVER` | `log` | Text messages (OTP, password links): `log` writes them to `storage/logs/laravel.log`; `array` keeps them in memory (tests). Both are allowed **only** when `APP_ENV` is `local` or `testing`; anywhere else a missing or local-only driver stops the app at start-up |
| `TENANCY_QUERY_GUARD` | `log` | `log` warns about school-data queries without `tenant_id`; `record` (tests) fails the test; `off` |
| `SIGN_IN_IP_MAX_FAILURES` | `100` | Failed sign-ins per IP per 15 minutes before `429` (bulk abuse only; many people share an IP) |
| `PARENT_OTP_IP_MAX_SENDS` | `60` | Parent OTP sends per IP per 10 minutes |
| `PARENT_OTP_IP_MAX_FAILURES` | `100` | Wrong parent OTPs per IP per 15 minutes |
| `PASSWORD_RESET_IP_MAX` | `100` | Forgot-password requests per IP per 15 minutes |
| `PUSH_DRIVER` | not set: `log` in local and testing, `none` elsewhere | Push notifications: `log` writes them to the log (device count, title and the generic text; never the device tokens); `array` keeps them in memory (tests, forced in `phpunit.xml`); `none` sends nothing; `native` (Phase 9) sends Android push through FCM and iPhone push through APNs. `log` and `array` are allowed **only** when `APP_ENV` is `local` or `testing`; anywhere else the app refuses to start with them |
| `FCM_CREDENTIALS` | not set | The Firebase service account JSON (or that JSON in base64). Without it, `native` skips Android |
| `APNS_KEY`, `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_ID`, `APNS_SANDBOX` | not set; bundle `in.campustab.app`; sandbox `false` | The APNs `.p8` key (or base64) and its ids. Without the key, `native` skips iPhones |
| `APP_ORIGINS` | `https://localhost,capacitor://localhost,http://tauri.localhost,tauri://localhost` | The apps' web-view origins, allowed by CORS **without credentials** (decision 0016) |
| `VIRUS_SCAN_DRIVER` | `clamav` (`fake` in tests only) | Upload virus scanning; `fake` refuses to start outside tests |
| `CLAMAV_HOST`, `CLAMAV_PORT`, `CLAMAV_TIMEOUT` | `clamav`, `3310`, `5` | Where clamd listens |
| `EXPORT_LARGE_ROWS` | `1000` | An export with at least this many rows alerts the Chief (a school can set `policies.exports.chief_alert_rows`) |
| `LOADTEST_FIXTURES` | `infra/loadtest/fixtures.json` (set to `/loadtest/fixtures.json` in the `api-load` container) | Where `php artisan loadtest:seed` writes the accounts and ids k6 uses (git-ignored; holds the shared load-test password) |
| `PRIVACY_REQUEST_DUE_DAYS` | `30` | Days a DPDP data request has before it shows as late |
| `ATTENDANCE_QR_MINUTES` | `5` | How often the office QR code for staff check-in changes (`config/attendance.php` `qr_minutes`); the code just before is still accepted |
| `SESSION_LIFETIME` | `30` | Outer bound for the browser cookie, in minutes; the real idle and total limits are in `config/identity.php`. The cookie always ends when the browser closes |

## Environment variables (front end)

Defined in [`frontend/.env.example`](../../frontend/.env.example). Only `VITE_*` values reach the browser, so they are never secret.

| Variable | Default | What it changes |
| --- | --- | --- |
| `VITE_API_BASE_URL` | `/api/v1` | Where the app sends API calls (same origin through the Vite proxy in dev) |
| `VITE_REVERB_APP_KEY` | `campus-tab-local-key` | Public Reverb key |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | `127.0.0.1`, `8080`, `http` | Reverb address as the browser sees it |
| `VITE_APP_SHELL` | `web` | `mobile` or `desktop` for app builds: bearer token in memory, deep links, push (decision 0016) |
| `VITE_PUBLIC_WEB_URL` | empty | The web address whose `/verify/…` and `/password/…` links open in the apps |
| `VITE_PUSH_ENABLED` | `false` | Phone push on (only in builds that include the Firebase/APNs setup) |

App builds get these from `.github/scripts/app-env.mjs`, which works them out from the API address given to the **Apps** workflow.

Set on the `web` container only: `VITE_USE_POLLING=true` (watch files by polling) and `VITE_API_PROXY_TARGET=http://api:8000` (proxy target).

## Host ports (local stack)

Defined in [`infra/.env.example`](../../infra/.env.example): `API_PORT` 8000, `WEB_PORT` 5173, `REVERB_PORT` 8080, `MONGO_PORT` 27017, `REDIS_PORT` 6379, `MAILPIT_SMTP_PORT` 1025, `MAILPIT_UI_PORT` 8025.

## Code settings (`backend/config/`)

| File | Key | What it changes |
| --- | --- | --- |
| `licensing.php` | `plans` | Plans a licence may name: `starter`, `standard`, `premium` |
| `licensing.php` | `modules` | Modules a licence can switch on: attendance, leave, assessment, reports, fees, communication |
| `licensing.php` | `activation.*` | OTP minutes (10), tries (5), sends per 10 minutes (3) |
| `tenancy.php` | `platform_collections` | Collections that are not school data (decision 0005) |
| `identity.php` | `languages`, `default_language`, `themes`, `default_theme` | Every language the app ships (any user may pick any of them), English and Match device by default |
| `identity.php` | `mfa.*` | Required from level 60; challenge 5 minutes and 5 tries; 10 recovery codes; TOTP window ±1 step; enrol-only session 30 minutes |
| `identity.php` | `step_up.*` | Valid 10 minutes; actions `export`, `permanent_delete`, `role_edit` (roles and users' roles), `marks_correction_approve` (Phase 6; `marks_unlock` removed), `support_grant`, `mfa_reset`. Later phases add fee rebates, waivers and locked-mark approvals |
| `identity.php` | `sign_in.*` | 5 failures in a row lock an account for 15 minutes; per-IP limit from `SIGN_IN_IP_MAX_FAILURES` |
| `identity.php` | `parent_otp.*` | Code lasts 5 minutes, 5 tries; 3 sends per phone per 10 minutes; 10 failures per phone per hour; per-IP limits from env |
| `identity.php` | `sessions.*` | Sign in every time: idle limit 15 minutes at level 60 and up (`idle_minutes_mfa_roles`), 30 minutes otherwise (`idle_minutes`), 8 hours in total (`max_hours`) |
| `identity.php` | `passwords.*` | At least 10 characters; set links 48 hours; reset links 30 minutes; 3 forgot-password requests per account per hour; the bundled common-password list |
| `identity.php` | `support.*` | Grants last 15 minutes to 72 hours; the areas a Chief can open and the abilities never given to support |
| `permissions.php` | `abilities`, `scopes`, `roles`, `duties` | The permission catalogue, the default matrix, the duty allow-lists ([permissions.md](permissions.md)) |
| `audit.php` | `purge_after_days`, `recyclable` | 30 days in the recycle bin before purge; models listed in the bin |
| `session.php` | `expire_on_close` | Always true: no "remember me" |

### Phase 1 part C settings

- **`identity.background_header`** (`X-Background-Request`): requests carrying it with value `1` never extend an idle session.
- **`identity.platform`**, the console:
  - `idle_minutes` 15, `max_hours` 8, `set_link_hours` 48
  - `step_up.actions` (`licence_revoke`, `chief_recovery`), `step_up.valid_minutes` 10
  - `chief_recovery.code_ttl_minutes` 30, `max_attempts` 5, `sends_per_10_minutes` 3, `reason_min_length` 20
- **`auth.guards.console`**: session guard for platform admins.
- **`scramble.*`**: the OpenAPI export. `export_path` is `packages/api/openapi.json` (env `OPENAPI_EXPORT_PATH`).
- **Rate limiter `console`**: 20 a minute per IP.

### Phase 2 settings

- **School config `sign_in.methods`:** add `student_login` to switch student sign-in on (off by default).
- **The licence's `seats.students`:** the student seat limit. Admission and import are refused past it; there is a warning from 90%.
- **Import limits:** a file is up to 10 MB with 5,000 rows (`Spreadsheet::MAX_ROWS`); progress is written every 25 rows.
- **Custom fields:** at most 50 per entity and scope (`FieldRules::MAX_PER_SCOPE`).
- **Consent notice version:** `config('people.consent_notice_version')`, default `2026-1` (no config file yet; set one when the legal text is final).
- **Demo password** (local and testing only): `DemoSchoolSeeder::DEMO_PASSWORD`.

### Console numbers and alerts

- **`identity.platform.stats_every_minutes`** (15): the job runs every 15 minutes (`routes/console.php`).
- **`identity.platform.alerts`:**
  - `factor` (5) and `repeat_after_hours` (6)
  - `min_per_hour`: failed_sign_ins 20, lockouts 5, password_resets 10, mfa_resets 3, chief_changes 2

### Phase 3 settings: notices, notifications, dashboard

- **`config/communication.php`:**

  | Key | Default | What it changes |
  | --- | --- | --- |
  | `notices.attachment_types` | pdf, png, jpg, jpeg, webp, docx, xlsx | File types a notice may carry |
  | `notices.attachment_max_kb` | 5120 | Largest attachment (5 MB) |
  | `notices.attachments_per_notice` | 5 | Most attachments on one notice |
  | `notices.link_minutes` | 5 | How long a signed download link works (also used for the attendance register photo) |
  | `notices.list_days` | 120 | Notices older than this drop out of the list |
  | `push_driver` | env `PUSH_DRIVER` | See the environment table |
  | `default_preferences` | push on, email off | Each person can change both |
  | `generic_texts` | `notice`, `attendance.absent`, `default` | What push and email say; `:school` is the school's name. Never a name, mark or other private detail |
  | `types` | notice, attendance.absent | Types a person can mute |

- **`config/dashboard.php` `providers`:** the dashboard card providers, in order: `SchoolAdminCards`, `PeopleCards`, `NoticeCards`, `AttendanceCards` ([modules/dashboard.md](modules/dashboard.md)).

### Phase 4 settings: attendance

- **`config/attendance.php` `qr_minutes`** (env `ATTENDANCE_QR_MINUTES`, 5).
- **School config `policies.attendance`:** the new keys are in the table below (`late_entry_days`, `school_starts_at`, `periods_per_day`, `staff.late_after`, `staff.geofence`). See [modules/attendance.md](modules/attendance.md).

### Phase 5 settings: leave

- **`config/leave.php`:**

  | Key | Default | What it changes |
  | --- | --- | --- |
  | `documents.types` | pdf, png, jpg, jpeg, webp | Files a leave request may carry |
  | `documents.max_kb` | 5120 | Largest document (5 MB) |
  | `documents.per_request` | 3 | Most documents on one request |
  | `documents.link_minutes` | 5 | How long a signed document link works |
  | `max_span_days` | 180 | Longest leave one request may cover |
  | `weekly_off` | [7] (Sunday) | ISO weekdays that are always off |
  | `starter_types` | CL, SL, EL, DL, LWP | What "Add the starter types" and `leave:seed-types` add |

- **`config/approvals.php` `sources`:** the approvals inbox sources (`LeaveApprovals`).
- **`config/communication.php`:** `generic_texts` and `types` gain `leave.updated` and `leave.waiting`.
- **`config/dashboard.php`:** `LeaveCards` added.
- **Scheduler:** `leave:remind` runs hourly (`routes/console.php`).

### Phase 6 settings: tests and marks

- **`config/assessment.php`:**

  | Key | Default | What it changes |
  | --- | --- | --- |
  | `codes` | AB absent, ML medical | Codes typed instead of a mark |
  | `mark_step` | 0.5 | Marks are whole or end in .5 |
  | `remark_max` | 200 | Longest remark beside a mark |
  | `reason_min` | 10 | Shortest correction reason, and the Principal's typed reason |
  | `types` · `class_test_types` | eight types · weekly, monthly | What can be scheduled; what a teacher's class test can be |
  | `presets` | cbse_6_8, cbse_9_10, generic | Starter schemes, each with "check against your board's latest circular" |

- **`config/identity.php`:** step-up action `marks_correction_approve` replaces `marks_unlock`.
- **`config/approvals.php`:** `CorrectionApprovals` added. **`config/communication.php`:** `results.published`, `marks.correction`. **`config/dashboard.php`:** `AssessmentCards`.

- **Phase 7, `config/reports.php`:** blocks, template presets (starting points only), co-scholastic areas and grades, letterhead (PNG/JPG, 1 MB), `remark_max` 400, `reissue_reason_min` 10, `link_minutes` 5, `pdf.driver` (`gotenberg`; `fake` in tests only, `REPORTS_PDF_DRIVER`), `pdf.gotenberg_url` (`GOTENBERG_URL`), `verification.code_bytes` 32 and `per_minute` 30.
- **Phase 8, `config/fees.php`:** `modes`, `clearance_modes` (cheque, dd), `adjustment_kinds`, `raise_days_before` 30, `bounce_fine_paise` (`FEES_BOUNCE_FINE_PAISE`, 0), `reason_min` 10, `gateway` (drivers razorpay and fake, `fake_environments` local and testing, `razorpay_api` (`RAZORPAY_API_URL`), `order_minutes` 30, `fake_webhook_url` (`FAKE_GATEWAY_WEBHOOK_URL`; `http://api:8000/…` in Docker)), `reports.types`.
- **School config, Phase 8:** `policies.fees.chief_alert_paise` (integer paise, default 500000): an approved waiver, rebate, fine waiver or scholarship above it alerts the Chief.

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
| `locale.featured_languages` | list of the above (en, hi) | Chief |

The language settings never limit anyone: every user may always pick any language the app ships in their own preferences. `featured_languages` only sets which ones the sign-in screen offers first. (Phase 1 part B removed `user_can_choose_language` and renamed `available_languages`.)
| `locale.timezone` | IANA name (Asia/Kolkata) | Chief |
| `locale.date_format` | DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD (DD-MM-YYYY) | Chief |
| `academic.year_start_month` | 1–12 (4) | Chief, Principal |
| `academic.terms` | 1–4 names (Term 1, Term 2) | Chief, Principal |
| `modules.attendance` … `modules.communication` | true/false (true when licensed); can't be on unless licensed | Chief |
| `sign_in.methods` | password, parent_otp (both) | Chief |
| `policies.attendance.student_mode` | daily, period (daily) | Chief, Principal |
| `policies.attendance.edit_window_hours` | 0–168 (4) | Chief, Principal |
| `policies.attendance.min_percent_alert` | 0–100 (75) | Chief, Principal |
| `policies.attendance.late_entry_days` | 0–60 (7) | Chief, Principal |
| `policies.attendance.school_starts_at` | `HH:MM` (08:00) | Chief, Principal |
| `policies.attendance.periods_per_day` | 1–12 (8) | Chief, Principal |
| `policies.attendance.staff.late_after` | `HH:MM` (08:15) | Chief, Principal |
| `policies.attendance.staff.geofence` | `{enabled}` required; `lat` −90–90, `lng` −180–180, `radius_m` 50–5000 (`{enabled: false}`) | Chief, Principal |

What the attendance keys do:

- **`edit_window_hours`** counts from **`school_starts_at`** on the day: until then the teacher changes the register freely; after it only the Clerk or the Principal, with a reason.
- **`late_entry_days`:** how many days after the date a teacher may still enter a missed register from paper (with a reason). After that only the Clerk or the Principal.
- **`periods_per_day`:** the periods a register can be taken for in period mode.
- **`staff.late_after`:** a staff check-in after this time is late (L).
- **`staff.geofence`:** with `enabled` and a point and radius (200 m when `radius_m` is missing), app check-ins need the phone's location and must be inside. Only inside or outside is stored; screens are told only whether the geofence is on.
| `policies.leave.chain` | clerk, vice_principal, principal; must include principal (clerk, principal) | Chief, Principal |
| `policies.leave.clerk_can_reject` | true/false (false) | **Chief only** (`ConfigSections::CHIEF_ONLY`) |
| `policies.leave.remind_after_hours` | 1–168 (24) | Chief, Principal |
| `policies.leave.escalate_after_hours` | 1–336 (48) | Chief, Principal |
| `policies.leave.student_principal_after_days` | 1–60 (3): longer student leave is also shown to the Principal | Chief, Principal |
| `policies.marks.auto_publish_class_tests` | true/false (true): a class test is published at submit and stays editable until locked | Chief, Principal |
| `policies.marks.class_teacher_can_enter` | true/false (false): class teachers may type marks for their class | Chief, Principal |
| `policies.fees.gateway` | none, razorpay (none) | Chief, Principal |
| `policies.fees.waiver_by` | principal, chief (principal) | Chief, Principal |
| `policies.fees.receipt_prefix` | text or null (null) | Chief, Principal |
| `school.grievance` | `{name, role, email, phone, address}` or null | Chief (School settings); shown on the public profile and Privacy page |
| `policies.exports.chief_alert_rows` | integer (not set: `EXPORT_LARGE_ROWS`, 1,000) | Chief, Principal |

Code settings added in Phase 9: [`config/privacy.php`](../../backend/config/privacy.php) (consent purposes, request due days, retention days per kind, breach hours), [`config/uploads.php`](../../backend/config/uploads.php), [`config/exports.php`](../../backend/config/exports.php), `config/communication.php` `push.*`, `config/cors.php` `app_origins`.

There is deliberately no setting that lets a clerk change locked marks: that rule is fixed in code.
