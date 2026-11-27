# API

REST over HTTP(S) at `/api/v1`, JSON in and out. Conventions (from the blueprint's "API design"):

- Plural nouns (`/students`), actions as sub-resources (`/leave-requests/{id}/verify`).
- One error shape everywhere: `{ "error": { "code": "…", "message": "…", "details": {} } }`. Codes are listed in [modules/system.md](modules/system.md), section 3.
- From Phase 1: the school always comes from the signed-in user's token, never the request; `Idempotency-Key` on creates and workflow actions; `ETag`/`If-Match` on markbook and config saves; cursor pagination; rate limits.
- The OpenAPI spec is generated from the code ([`packages/api/openapi.json`](../../packages/api/openapi.json), `php artisan scramble:export`) and the typed TypeScript client from it ([`packages/api/src/`](../../packages/api/src/)). CI fails when either is out of date. In local development the docs UI is at http://127.0.0.1:8000/docs/api.

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
| GET | `/api/v1/academic-years` | academic-years.index | `StructureController` | api, school, permission:academic.view |
| POST | `/api/v1/academic-years` | academic-years.store | `StructureController` | api, school, permission:academic.manage |
| POST | `/api/v1/academic-years/{id}/make-current` | academic-years.make-current | `StructureController` | api, school, permission:academic.manage |
| GET | `/api/v1/audit-logs` | audit-logs.index | `AuditLogController` | api, school, permission:audit.view,audit.view_academic |
| POST | `/api/v1/auth/login` | auth.login | `AuthController` | api |
| POST | `/api/v1/auth/logout` | auth.logout | `AuthController` | api, enrol |
| POST | `/api/v1/auth/mfa/verify` | auth.mfa.verify | `AuthController` | api |
| POST | `/api/v1/auth/otp/request` | auth.otp.request | `AuthController` | api |
| POST | `/api/v1/auth/otp/verify` | auth.otp.verify | `AuthController` | api |
| POST | `/api/v1/auth/password/forgot` | auth.password.forgot | `AuthController` | api |
| POST | `/api/v1/auth/password/set` | auth.password.set | `AuthController` | api |
| POST | `/api/v1/auth/step-up` | auth.step-up | `AuthController` | api, account |
| GET | `/api/v1/classes` | classes.index | `StructureController` | api, school, permission:academic.view |
| POST | `/api/v1/classes` | classes.store | `StructureController` | api, school, permission:academic.manage |
| DELETE | `/api/v1/classes/{id}` | classes.destroy | `StructureController` | api, school, permission:academic.manage |
| PATCH | `/api/v1/classes/{id}` | classes.update | `StructureController` | api, school, permission:academic.manage |
| GET | `/api/v1/config` | config.show | `SchoolConfigController` | api, school, permission:config.view |
| PUT | `/api/v1/config` | config.update | `SchoolConfigController` | api, school, permission:config.edit,config.edit_policies |
| POST | `/api/v1/config/logo` | config.logo | `SchoolLogoController` | api, school, permission:config.edit |
| GET | `/api/v1/config/versions` | config.versions.index | `SchoolConfigController` | api, school, permission:config.view |
| GET | `/api/v1/config/versions/{version}` | config.versions.show | `SchoolConfigController` | api, school, permission:config.view |
| POST | `/api/v1/config/versions/{version}/rollback` | config.versions.rollback | `SchoolConfigController` | api, school, permission:config.edit,config.edit_policies |
| GET | `/api/v1/custom-fields` | custom-fields.index | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical,students.view,staff.view |
| POST | `/api/v1/custom-fields` | custom-fields.store | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| PATCH | `/api/v1/custom-fields/{id}` | custom-fields.update | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| POST | `/api/v1/custom-fields/{id}/archive` | custom-fields.archive | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| POST | `/api/v1/custom-fields/{id}/promote` | custom-fields.promote | `CustomFieldController` | api, school, permission:custom_fields.manage |
| POST | `/api/v1/custom-fields/{id}/restore` | custom-fields.restore | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| PATCH | `/api/v1/guardians/{id}` | guardians.update | `StudentController` | api, school, permission:guardians.manage |
| DELETE | `/api/v1/guardians/{id}/parent-login` | guardians.parent-login.destroy | `StudentController` | api, school, permission:guardians.manage |
| POST | `/api/v1/guardians/{id}/parent-login` | guardians.parent-login.store | `StudentController` | api, school, permission:guardians.manage |
| GET | `/api/v1/health` | system.health | `HealthController` | api |
| POST | `/api/v1/imports/{entity}` | imports.store | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{entity}/template` | imports.template | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{id}` | imports.show | `ImportController` | api, school, permission:imports.students,imports.staff |
| POST | `/api/v1/imports/{id}/commit` | imports.commit | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{id}/report` | imports.report | `ImportController` | api, school, permission:imports.students,imports.staff |
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
| GET | `/api/v1/platform/alerts` | platform.alerts.index | `ConsoleAlertController` | api, console |
| POST | `/api/v1/platform/alerts/{id}/acknowledge` | platform.alerts.acknowledge | `ConsoleAlertController` | api, console |
| GET | `/api/v1/platform/audit-logs` | platform.audit-logs.index | `ConsoleAuditController` | api, console |
| POST | `/api/v1/platform/auth/login` | platform.auth.login | `ConsoleAuthController` | api, throttle:console |
| POST | `/api/v1/platform/auth/logout` | platform.auth.logout | `ConsoleAuthController` | api, console |
| POST | `/api/v1/platform/auth/mfa/verify` | platform.auth.mfa.verify | `ConsoleAuthController` | api, throttle:console |
| POST | `/api/v1/platform/auth/password/set` | platform.auth.password.set | `ConsoleAuthController` | api, throttle:console |
| POST | `/api/v1/platform/auth/step-up` | platform.auth.step-up | `ConsoleAuthController` | api, console, throttle:console |
| GET | `/api/v1/platform/me` | platform.me | `ConsoleAuthController` | api, console |
| POST | `/api/v1/platform/me/mfa/enrol` | platform.me.mfa.enrol | `ConsoleAuthController` | api, console |
| POST | `/api/v1/platform/me/mfa/enrol/confirm` | platform.me.mfa.enrol.confirm | `ConsoleAuthController` | api, console |
| GET | `/api/v1/platform/me/sessions` | platform.me.sessions.index | `ConsoleSessionController` | api, console |
| DELETE | `/api/v1/platform/me/sessions/{id}` | platform.me.sessions.destroy | `ConsoleSessionController` | api, console |
| GET | `/api/v1/platform/options` | platform.options | `ConsoleSchoolController` | api, console |
| GET | `/api/v1/platform/schools` | platform.schools.index | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools` | platform.schools.store | `ConsoleSchoolController` | api, console |
| GET | `/api/v1/platform/schools/{code}` | platform.schools.show | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools/{code}/chief-recovery` | platform.schools.chief-recovery.start | `ConsoleSchoolController` | api, console, console.step-up:chief_recovery |
| POST | `/api/v1/platform/schools/{code}/chief-recovery/complete` | platform.schools.chief-recovery.complete | `ConsoleSchoolController` | api, console, console.step-up:chief_recovery, throttle:console |
| PUT | `/api/v1/platform/schools/{code}/client` | platform.schools.client.update | `ConsoleSchoolController` | api, console |
| PATCH | `/api/v1/platform/schools/{code}/licence` | platform.schools.licence.update | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools/{code}/licence/renew` | platform.schools.licence.renew | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools/{code}/licence/revoke` | platform.schools.licence.revoke | `ConsoleSchoolController` | api, console, console.step-up:licence_revoke |
| POST | `/api/v1/platform/schools/{code}/licences` | platform.schools.licences.store | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools/{code}/payments` | platform.schools.payments.store | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/schools/{code}/stats/refresh` | platform.schools.stats.refresh | `ConsoleSchoolController` | api, console |
| POST | `/api/v1/platform/support/sessions` | platform.support.sessions | `PlatformSupportController` | api, auth:platform, throttle:activation |
| GET | `/api/v1/public/tenants/{code}/config` | public.tenants.config | `PublicConfigController` | api, throttle:public |
| GET | `/api/v1/public/tenants/{code}/logo` | public.tenants.logo | `SchoolLogoController` | api, throttle:public |
| GET | `/api/v1/recycle-bin` | recycle-bin.index | `RecycleBinController` | api, school, permission:records.restore |
| DELETE | `/api/v1/recycle-bin/{type}/{id}` | recycle-bin.purge | `RecycleBinController` | api, school, permission:records.purge, step-up:permanent_delete |
| POST | `/api/v1/recycle-bin/{type}/{id}/restore` | recycle-bin.restore | `RecycleBinController` | api, school, permission:records.restore |
| GET | `/api/v1/roles` | roles.index | `RoleController` | api, school, permission:roles.view |
| POST | `/api/v1/roles` | roles.store | `RoleController` | api, school, permission:roles.edit, step-up:role_edit |
| PUT | `/api/v1/roles/{name}` | roles.update | `RoleController` | api, school, permission:roles.edit, step-up:role_edit |
| POST | `/api/v1/sections` | sections.store | `StructureController` | api, school, permission:academic.manage |
| DELETE | `/api/v1/sections/{id}` | sections.destroy | `StructureController` | api, school, permission:academic.manage |
| PATCH | `/api/v1/sections/{id}` | sections.update | `StructureController` | api, school, permission:academic.manage |
| POST | `/api/v1/sections/{id}/student-setup-codes` | sections.student-setup-codes | `StudentController` | api, school, permission:students.login_codes |
| GET | `/api/v1/staff` | staff.index | `StaffController` | api, school, permission:staff.view |
| POST | `/api/v1/staff` | staff.store | `StaffController` | api, school, permission:staff.create,staff.draft |
| GET | `/api/v1/staff/{id}` | staff.show | `StaffController` | api, school, permission:staff.view |
| PATCH | `/api/v1/staff/{id}` | staff.update | `StaffController` | api, school, permission:staff.edit |
| POST | `/api/v1/staff/{id}/approve` | staff.approve | `StaffController` | api, school, permission:staff.approve |
| POST | `/api/v1/staff/{id}/deactivate` | staff.deactivate | `StaffController` | api, school, permission:staff.deactivate |
| POST | `/api/v1/staff/{id}/reject` | staff.reject | `StaffController` | api, school, permission:staff.approve |
| GET | `/api/v1/students` | students.index | `StudentController` | api, school, permission:students.view |
| POST | `/api/v1/students` | students.store | `StudentController` | api, school, permission:students.admit |
| GET | `/api/v1/students/seats` | students.seats | `StudentController` | api, school, permission:students.view |
| DELETE | `/api/v1/students/{id}` | students.destroy | `StudentController` | api, school, permission:students.admit |
| GET | `/api/v1/students/{id}` | students.show | `StudentController` | api, school, permission:students.view |
| PATCH | `/api/v1/students/{id}` | students.update | `StudentController` | api, school, permission:students.edit |
| POST | `/api/v1/students/{id}/guardians` | students.guardians.store | `StudentController` | api, school, permission:guardians.manage |
| GET | `/api/v1/subjects` | subjects.index | `StructureController` | api, school, permission:academic.view |
| POST | `/api/v1/subjects` | subjects.store | `StructureController` | api, school, permission:academic.manage |
| DELETE | `/api/v1/subjects/{id}` | subjects.destroy | `StructureController` | api, school, permission:academic.manage |
| PATCH | `/api/v1/subjects/{id}` | subjects.update | `StructureController` | api, school, permission:academic.manage |
| GET | `/api/v1/support-grants` | support-grants.index | `SupportGrantController` | api, school, permission:support.view |
| POST | `/api/v1/support-grants` | support-grants.store | `SupportGrantController` | api, school, permission:support.grant, step-up:support_grant |
| GET | `/api/v1/support-grants/{id}/activity` | support-grants.activity | `SupportGrantController` | api, school, permission:support.view |
| POST | `/api/v1/support-grants/{id}/revoke` | support-grants.revoke | `SupportGrantController` | api, school, permission:support.grant |
| GET | `/api/v1/teaching-assignments` | teaching-assignments.index | `StructureController` | api, school, permission:academic.view |
| POST | `/api/v1/teaching-assignments` | teaching-assignments.store | `StructureController` | api, school, permission:academic.manage |
| DELETE | `/api/v1/teaching-assignments/{id}` | teaching-assignments.destroy | `StructureController` | api, school, permission:academic.manage |
| GET | `/api/v1/users` | users.index | `UserController` | api, school, permission:users.view |
| POST | `/api/v1/users` | users.store | `UserController` | api, school, permission:users.create |
| POST | `/api/v1/users/{id}/deactivate` | users.deactivate | `UserController` | api, school, permission:users.deactivate |
| POST | `/api/v1/users/{id}/mfa-reset` | users.mfa-reset | `UserController` | api, school, permission:users.reset_mfa, step-up:mfa_reset |
| POST | `/api/v1/users/{id}/password-link` | users.password-link | `UserController` | api, school, permission:users.send_password_link |
| PUT | `/api/v1/users/{id}/roles` | users.roles | `UserController` | api, school, permission:users.edit_roles, step-up:role_edit |
| POST | `/api/v1/users/{id}/unlock` | users.unlock | `UserController` | api, school, permission:users.unlock |

<!-- generated:routes:end -->
