# Campus TAB — Conventions

Every coding session reads this file first and follows it for the whole session. If a request conflicts with a rule here, stop and ask the owner before going ahead.

## 1. Ground rules

1. **No git writes.** Never run `git commit`, `git push`, `git tag` or anything that rewrites history, and never open pull requests. Only write files. The owner, Sujal Chandel, reviews, commits and pushes.
2. **No file or folder name may contain "claude"** (in any letter case). `.claude/`, `CLAUDE.md` and `CLAUDE.local.md` stay listed in `.gitignore`; never remove them from it.
3. **No AI or tool credit anywhere:** not in code comments, commit messages, README files, package metadata, licence files, generated files or the report.
4. **Author and copyright: Sujal Chandel.** Use this name in `composer.json`, every `package.json`, `LICENSE` and any file header that names an author.
5. **Show a plan before changing files.** Each session starts with a plan the owner approves.
6. **Report in the same session.** Every session ends by updating `docs/report/`: the module pages it touched plus a `docs/report/CHANGELOG.md` entry (date, phase, what changed, why, files, report pages updated, how to test). CI fails a code change that has no report update.
7. **No secrets and no real personal data** in code, tests, seeders, docs or the report. Keys live in `.env` files, which are never committed. Examples use made-up names (see the demo school in `docs/design/`).

## 2. Working on this machine (Windows host, Linux containers)

1. **PHP, Composer and Node run inside Docker only.** Run `composer …`, `php artisan …` and `npm …` through `docker compose` (see `docs/report/operations.md`). Never install packages on the host.
2. **`backend/vendor` and every `node_modules` folder live in named Docker volumes,** never on the D: drive. The compose file mounts them; don't change that.
3. **LF line endings everywhere.** `.gitattributes` enforces LF (CRLF only for `.ps1` and `.bat`). Every shell script and container entrypoint must use LF, or it fails inside Linux containers.
4. **Live reload from a Windows folder:** the Vite dev server binds to `0.0.0.0` and uses file polling. Keep both.

## 3. Coding rules

1. **Tenancy.** Every school model uses the `BelongsToTenant` trait. It adds the tenant filter to every query and sets `tenant_id` when a record is created. With no school set, a query throws instead of reading every school. Platform code names the school with `TenantContext::run($tenantId, fn)`. The only way around the scope is `TenantContext::bypass()`, used solely for the sign-in exception in decision 0005; a new bypass needs a new decision record. The tenant query guard fails any test that sends a school-data query without `tenant_id`.
2. **`tenant_id` comes only from the signed-in user,** never from the request body, query string or headers. Password sign-in finds the school from its code first, then the user inside that school.
3. **Every permission check goes through a Policy.** Controllers call `authorize()` (or `Gate`); no inline role checks. Hiding something in the UI is never the protection. Every school route names `permission:<ability>` from `config/permissions.php`, and sensitive ones add `step-up:<action>` (a route test enforces this).
4. **Soft deletes on every school model.** Deleted records go to the recycle bin; only the Chief Admin can purge permanently.
5. **Every write is audit-logged** with before and after values, actor, IP and device: school models use the `Auditable` trait. The audit log is append-only; never add an update or delete path (decision 0008).
6. **Fee records are append-only.** A wrong entry is cancelled by a reversal entry; never edit or delete a ledger entry.
7. **A locked mark changes only through a correction request that the Principal approves** from their own account. This is a fixed rule in code, not a setting.
8. **Never store full Aadhaar numbers.** Keep the last four digits at most. PEN and APAAR are optional and stored with a consent record.
9. **Every user-facing string goes through i18next** on the front end (and translation files on the back end). No hard-coded text in components.
10. **Every screen works in the light and dark themes.** Use the theme tokens from `packages/ui`; never hard-code colours.
11. **Sign in every time.**
    - No "remember me" and no "trust this device".
    - The browser uses a session cookie only.
    - The apps (Phase 9) keep their token **in memory only, never saved on the device**.
    - Screens warn one minute before an idle sign-out (`GET /me` → `session.idle_minutes`, `session.ends_at`) and keep unsaved form input.
12. **Nobody types or sees another person's password,** and passwords, OTPs, TOTP secrets, links and tokens are never logged or written to the audit log (decision 0006).

## 4. Code layout and style

- **Back end:** a modular monolith. Each module lives in `backend/app/Modules/<Module>/` with its own `Http/`, `Models/`, `Policies/`, `Domain/`, `routes.php` and tests under `backend/tests/Feature/<Module>/`. Shared code lives in `backend/app/Support/`.
- **API:** REST under `/api/v1`, plural nouns, actions as sub-resources, one error shape `{"error":{"code":"…","message":"…","details":{}}}`, an `Idempotency-Key` header on creates and workflow actions, and `ETag`/`If-Match` on markbook and config saves.
- **Database:** MongoDB through `mongodb/laravel-mongodb`. Every index on a school collection starts with `tenant_id`. The one exception is the unique `(provider, order_id)` index on `gateway_orders`, which serves the payment webhook's school lookup (decision 0015); a new exception needs a decision record. Use transactions for multi-document changes (the database runs as a replica set, `rs0`, even in development).
- **Tests:** Pest. Database tests use the `Tests\Concerns\RefreshMongoDatabase` trait, which drops and recreates the test database. Laravel's `RefreshDatabase` does not work with MongoDB.
- **Quality gates (CI):** Pint, PHPStan level 6, Pest, ESLint, `tsc`, Vitest, and the production build must all pass.
- **Front end:** React 19 + TypeScript, feature folders under `frontend/src/features/<feature>/`, server data through TanStack Query, UI state through Zustand, forms through React Hook Form + Zod.
