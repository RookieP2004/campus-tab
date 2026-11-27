# Identity and Access

> Status: **Phase 1 part A: foundations built** (user models, MongoDB-backed tokens, the sign-in lookup rules, the MFA gate). Sign-in endpoints, MFA, RBAC and support grants are built in part B.

## 1. What it does (plain words, no code)

Who people are and what they may do. School users belong to exactly one school. Our own platform team has separate accounts that are never school users. Sign-in (password plus MFA, OTP for parents), device sessions, roles and permissions in the form `module.action:scope`, and the guard rules (nobody can grant more than they hold; the last Chief cannot be removed) arrive in part B.

Built so far:

- School users (tenant-scoped, soft-deleted) and platform admins (their own collection).
- API tokens stored in MongoDB.
- The sign-in lookup rules: a token or session finds its user before the school is known (the one documented exception, decision [0005](../decisions/0005-tenant-scope-and-sign-in-exception.md)); password sign-in finds the school by code first.
- The MFA gate: Clerk and above, and every newly activated Chief, can do nothing until MFA is enrolled.

## 2. Who uses it and from which screens

_Sign-in screens arrive in Phase 1 part C._

## 3. Requests it handles

_No routes of its own yet (part B adds `/auth/*`, `/me`, MFA and device sessions)._ Middleware it provides:

| Alias | Effect |
| --- | --- |
| `mfa.enrolled` | `403 mfa_enrolment_required` when the user's role needs MFA and it is not enrolled |

## 4. Code map

`backend/app/Modules/Identity/`:

| File | What it does |
| --- | --- |
| [`Models/User.php`](../../../backend/app/Modules/Identity/Models/User.php) | School user: tenant-scoped, soft deletes, API tokens; `level()`, `hasRole()`, `mfaRequired()`, `mfaEnabled()`; defaults language `en`, theme `system` |
| [`Models/PlatformAdmin.php`](../../../backend/app/Modules/Identity/Models/PlatformAdmin.php) | Our platform team (level 100), collection `platform_admins`, no `tenant_id` |
| [`Models/PersonalAccessToken.php`](../../../backend/app/Modules/Identity/Models/PersonalAccessToken.php) | Sanctum's token on MongoDB; loads its owner inside the documented bypass |
| [`Models/SupportGrant.php`](../../../backend/app/Modules/Identity/Models/SupportGrant.php) | Time-boxed support access (collection only; behaviour in part B) |
| [`Domain/Role.php`](../../../backend/app/Modules/Identity/Domain/Role.php) | The ten roles and their levels; MFA required from level 60 |
| [`Domain/SchoolUserLocator.php`](../../../backend/app/Modules/Identity/Domain/SchoolUserLocator.php) | Password sign-in lookup: school by code, then the user inside it |
| [`Auth/SchoolUserProvider.php`](../../../backend/app/Modules/Identity/Auth/SchoolUserProvider.php) | User provider `school-users`: loads a session user by id inside the bypass; credentials lookups stay scoped |
| [`Http/Middleware/EnsureMfaEnrolled.php`](../../../backend/app/Modules/Identity/Http/Middleware/EnsureMfaEnrolled.php) | Middleware `mfa.enrolled` |
| [`IdentityServiceProvider.php`](../../../backend/app/Modules/Identity/IdentityServiceProvider.php) | Registers the token model, the morph map (`user`, `platform_admin`) and the provider |

Guards in `config/auth.php`: `web` (session, school users), `sanctum` (tokens and the SPA cookie, school users only), `platform` (tokens, platform admins only).

## 5. How the code works, step by step

**Token request:** `Authorization: Bearer <id>|<secret>` → Sanctum finds the token in `personal_access_tokens` → its owner is loaded in a bypass → the `sanctum` guard accepts only school users → `tenant` sets the school from `user.tenant_id` → `mfa.enrolled` → `licence` → the route.

**Password sign-in (part B builds the endpoint):** `SchoolUserLocator::find(code, login)` → active school by code, else nothing → inside that school, the active user with that email (lower-cased) or phone.

## 6. Data it reads and writes

| Collection | Fields | Indexes |
| --- | --- | --- |
| `users` (school) | `tenant_id`, `name`, `email`, `phone`, `password` (hashed), `roles[]`, `mfa {required, enabled, secret, enrolled_at}`, `status`, `language`, `theme`, timestamps, `deleted_at` | `tenant_id + email` unique (partial), `tenant_id + phone` unique (partial), `tenant_id + roles` |
| `platform_admins` (platform) | `name`, `email`, `password`, `mfa`, `status` | `email` unique |
| `personal_access_tokens` (platform) | `tokenable_type`, `tokenable_id`, `name`, `token` (SHA-256), `abilities`, `last_used_at`, `expires_at` | `token` unique; `tokenable_type + tokenable_id`; `expires_at` |
| `support_grants` (school) | `tenant_id`, `granted_by`, `agent`, `scope`, `read_only`, `reason`, `expires_at`, `revoked_at` | `tenant_id + expires_at`; `tenant_id + agent` |

## 7. Links to other modules

Tenancy sets the school from the user; Licensing creates the first Chief at activation; every school route uses `auth:sanctum`, `tenant` and `mfa.enrolled`.

## 8. Settings that change its behaviour

`config/auth.php` guards and providers; `Role::MFA_REQUIRED_FROM` (60).

## 9. Permissions and the rules behind them

Part A uses role levels only (see "Role hierarchy and authority" in the blueprint). The permission matrix, scopes and guard rules arrive in part B and will be listed in [permissions.md](../permissions.md).

## 10. How to change it

Part B fills this in. For now: never load a user outside a school except through the documented bypass; add any new exception as a decision record first.

## 11. Tests that cover it

- [`tests/Feature/Identity/SignInLookupTest.php`](../../../backend/tests/Feature/Identity/SignInLookupTest.php): token path (user loaded before the school is known, school set from the user, context cleared after), token collection treated as platform data, session path through `school-users`, password path (school by code, then user inside it; same email in two schools; phone; another school's user not found; unknown and pending schools; credentials lookup without a school throws).
- MFA gate: covered in [`ActivationTest.php`](../../../backend/tests/Feature/Licensing/ActivationTest.php).

## 12. Decisions and limits

[0005](../decisions/0005-tenant-scope-and-sign-in-exception.md). Roles are still strings on the user; the `roles` collection and editable matrix arrive in part B.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 0 | Page created from the template | Phase 0 starts the Application Report |
| 2026-11-27 | 1A | User and platform-admin models, MongoDB tokens, sign-in lookup exception, MFA gate | Phase 1 items 1, 2 and 5, and the owner's request to make sign-in lookup a documented exception |
