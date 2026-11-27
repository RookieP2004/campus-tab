# API

REST over HTTP(S) at `/api/v1`, JSON in and out. Conventions (from the blueprint's "API design"):

- Plural nouns (`/students`), actions as sub-resources (`/leave-requests/{id}/verify`).
- One error shape everywhere: `{ "error": { "code": "…", "message": "…", "details": {} } }`. Codes are listed in [modules/system.md](modules/system.md), section 3.
- From Phase 1: the school always comes from the signed-in user's token, never the request; `Idempotency-Key` on creates and workflow actions; `ETag`/`If-Match` on markbook and config saves; cursor pagination; rate limits.
- From Phase 1 an OpenAPI spec is generated from the code and a TypeScript client is generated from it into `packages/api/`; a link to the spec will be added here.

## Requests in plain words

| Request | What it does | Who | Module page |
| --- | --- | --- | --- |
| `GET /api/v1/health` | Says whether the API can reach its database and queue | Anyone | [System](modules/system.md) |

Each phase adds its requests to this table.

## All routes (generated)

Only routes under `/api` are listed. Horizon's dashboard (`/horizon`, local only) and Laravel's `/up` sit outside it.

<!-- generated:routes:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Method | Path | Name | Handler | Middleware |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/audit-logs` | audit-logs.index | `AuditLogController` | api, school, permission:audit.view,audit.view_academic |
| POST | `/api/v1/auth/login` | auth.login | `AuthController` | api |
| POST | `/api/v1/auth/logout` | auth.logout | `AuthController` | api, enrol |
| POST | `/api/v1/auth/mfa/verify` | auth.mfa.verify | `AuthController` | api |
| POST | `/api/v1/auth/otp/request` | auth.otp.request | `AuthController` | api |
| POST | `/api/v1/auth/otp/verify` | auth.otp.verify | `AuthController` | api |
| POST | `/api/v1/auth/password/forgot` | auth.password.forgot | `AuthController` | api |
| POST | `/api/v1/auth/password/set` | auth.password.set | `AuthController` | api |
| POST | `/api/v1/auth/step-up` | auth.step-up | `AuthController` | api, account |
| GET | `/api/v1/config` | config.show | `SchoolConfigController` | api, school, permission:config.view |
| PUT | `/api/v1/config` | config.update | `SchoolConfigController` | api, school, permission:config.edit,config.edit_policies |
| GET | `/api/v1/config/versions` | config.versions.index | `SchoolConfigController` | api, school, permission:config.view |
| GET | `/api/v1/config/versions/{version}` | config.versions.show | `SchoolConfigController` | api, school, permission:config.view |
| POST | `/api/v1/config/versions/{version}/rollback` | config.versions.rollback | `SchoolConfigController` | api, school, permission:config.edit,config.edit_policies |
| GET | `/api/v1/health` | system.health | `HealthController` | api |
| POST | `/api/v1/licences/activate` | licences.activate | `ActivationController` | api, throttle:activation |
| POST | `/api/v1/licences/activate/otp` | licences.activate.otp | `ActivationController` | api, throttle:activation |
| GET | `/api/v1/me` | me.show | `MeController` | api, account |
| DELETE | `/api/v1/me/mfa` | me.mfa.disable | `MfaController` | api, account |
| POST | `/api/v1/me/mfa/enrol` | me.mfa.enrol | `MfaController` | api, enrol |
| POST | `/api/v1/me/mfa/enrol/confirm` | me.mfa.enrol.confirm | `MfaController` | api, enrol |
| POST | `/api/v1/me/mfa/recovery-codes` | me.mfa.recovery-codes | `MfaController` | api, account |
| PUT | `/api/v1/me/password` | me.password | `MeController` | api, account |
| PATCH | `/api/v1/me/preferences` | me.preferences | `MeController` | api, account |
| GET | `/api/v1/me/sessions` | me.sessions.index | `MeController` | api, account |
| POST | `/api/v1/me/sessions/revoke-others` | me.sessions.revoke-others | `MeController` | api, account |
| DELETE | `/api/v1/me/sessions/{id}` | me.sessions.destroy | `MeController` | api, account |
| GET | `/api/v1/permissions` | permissions.index | `RoleController` | api, school, permission:roles.view |
| POST | `/api/v1/platform/support/sessions` | platform.support.sessions | `PlatformSupportController` | api, auth:platform, throttle:activation |
| GET | `/api/v1/public/tenants/{code}/config` | public.tenants.config | `PublicConfigController` | api, throttle:public |
| GET | `/api/v1/recycle-bin` | recycle-bin.index | `RecycleBinController` | api, school, permission:records.restore |
| DELETE | `/api/v1/recycle-bin/{type}/{id}` | recycle-bin.purge | `RecycleBinController` | api, school, permission:records.purge, step-up:permanent_delete |
| POST | `/api/v1/recycle-bin/{type}/{id}/restore` | recycle-bin.restore | `RecycleBinController` | api, school, permission:records.restore |
| GET | `/api/v1/roles` | roles.index | `RoleController` | api, school, permission:roles.view |
| POST | `/api/v1/roles` | roles.store | `RoleController` | api, school, permission:roles.edit, step-up:role_edit |
| PUT | `/api/v1/roles/{name}` | roles.update | `RoleController` | api, school, permission:roles.edit, step-up:role_edit |
| GET | `/api/v1/support-grants` | support-grants.index | `SupportGrantController` | api, school, permission:support.view |
| POST | `/api/v1/support-grants` | support-grants.store | `SupportGrantController` | api, school, permission:support.grant, step-up:support_grant |
| GET | `/api/v1/support-grants/{id}/activity` | support-grants.activity | `SupportGrantController` | api, school, permission:support.view |
| POST | `/api/v1/support-grants/{id}/revoke` | support-grants.revoke | `SupportGrantController` | api, school, permission:support.grant |
| GET | `/api/v1/users` | users.index | `UserController` | api, school, permission:users.view |
| POST | `/api/v1/users` | users.store | `UserController` | api, school, permission:users.create |
| POST | `/api/v1/users/{id}/deactivate` | users.deactivate | `UserController` | api, school, permission:users.deactivate |
| POST | `/api/v1/users/{id}/mfa-reset` | users.mfa-reset | `UserController` | api, school, permission:users.reset_mfa, step-up:mfa_reset |
| POST | `/api/v1/users/{id}/password-link` | users.password-link | `UserController` | api, school, permission:users.send_password_link |
| PUT | `/api/v1/users/{id}/roles` | users.roles | `UserController` | api, school, permission:users.edit_roles, step-up:role_edit |
| POST | `/api/v1/users/{id}/unlock` | users.unlock | `UserController` | api, school, permission:users.unlock |

<!-- generated:routes:end -->
