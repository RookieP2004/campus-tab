# Permissions

> Built in **Phase 1 part B**. Source of truth: [`backend/config/permissions.php`](../../backend/config/permissions.php) (catalogue, default roles, duty allow-lists). The intended matrix is "Permission matrix" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md); this page is the matrix **as coded**.

## How a check works

1. **The form.** A permission is `module.action:scope`, for example `marks.enter:subject`. Scopes, widest first: `school` > `wing` > `class` > `subject` > `own`. `child` (a parent's children) is covered only by itself or by `school`.
2. **The route** names the ability: `permission:users.view`. The user must hold it at some scope, or the answer is `403 permission_denied`.
3. **The Policy** checks the record (`AccessControl::allows($user, $ability, $record)`) through the scope resolvers, and the level rule where people are involved.
4. **Sensitive routes** also name `step-up:<action>`.
5. **The front end** reads `GET /me` → `permissions` to hide what the user can't do. Hiding is never the protection; [`RoutePermissionTest`](../../backend/tests/Feature/Identity/RoutePermissionTest.php) fails if any route lacks a server-side check.

## Rules fixed in code (not settings)

- **No escalation.** You act only on people and roles below your level, and grant only permissions you hold, at the same or a narrower scope. A Chief may act on other Chiefs. The Chief role's permissions can't be edited.
- **The last Chief** can't be deactivated or lose the role. Adding or removing a Chief is a security event in the audit log; Phase 3 notifications will also alert the other Chiefs.
- **Only known strings.** Any permission string not in the catalogue is refused, so no setting can create a new power. There is no ability to change a locked mark: that happens only through a correction the Principal approves from their own account (Phase 6).
- **Duties** (no level; the Principal gives them) hold only their allow-list:
  - **Fee Collector:** `fees.*`.
  - **Attendance Officer:** `staff_attendance.enter`, `staff_attendance.view`. **Phase 4 rule:** nobody can enter or change their own attendance through this duty.
- **"Delete staff" means deactivate:** the account goes to the recycle bin, and only the Chief can purge, after 30 days.
- **Support agents** hold only their grant's permissions. They can never touch support grants, purge, edit roles or a user's roles, export, unlock marks, approve corrections, or do any step-up action.
- **Fee records are append-only**; reversals instead of edits (Phase 8).

## Step-up actions (`config/identity.php`)

| Action | Routes now | Valid for |
| --- | --- | --- |
| `role_edit` | `POST /roles`, `PUT /roles/{name}`, `PUT /users/{id}/roles` | 10 minutes on that device |
| `permanent_delete` | `DELETE /recycle-bin/{type}/{id}` | 10 minutes |
| `support_grant` | `POST /support-grants` | 10 minutes |
| `mfa_reset` | `POST /users/{id}/mfa-reset` | 10 minutes |
| `export` | (Phase 2 and later exports) | 10 minutes |
| `marks_unlock` | (Phase 6) | 10 minutes |

Later phases add fee rebates, waivers and locked-mark approvals to this list.

## Default roles as coded

Levels: Chief 90, Principal 80, Vice Principal 70, Clerk 60, Class Teacher 50, Subject Teacher 40, Staff 30, Parent 20, Student 10. Duties have no level. MFA is required from level 60.

| Role | Permissions (Phase 1 ones in bold; the rest are used by later phases) |
| --- | --- |
| Chief | **config.view, config.edit, config.edit_policies, roles.view, roles.edit, users.view, users.create, users.edit_roles, users.deactivate, users.unlock, users.send_password_link, users.reset_mfa, records.restore, records.purge, audit.view, support.view, support.grant** (all `:school`); export.bulk; students.view/edit; attendance.view/mark/edit_after_window; staff_attendance.manage; leave.apply:own; leave.decide_principal; student_leave.approve; assessment.scheme; exams.schedule; tests.create; marks.enter/verify/lock/unlock/correction_request; results.publish/view; remarks.edit; custom_fields.manage; notices.send/view; fees.gateway_view/gateway_edit/setup/collect/adjust/receipt_cancel/dues_view/reports (all `:school` unless shown) |
| Principal | **config.view, config.edit_policies, roles.view, users.view, users.create, users.edit_roles, users.deactivate, users.unlock, users.send_password_link, users.reset_mfa, records.restore, audit.view_academic** (`:school`), **audit.view:own**; export.academic; students, attendance, staff attendance as the Chief; leave.apply:own, leave.fast_track, leave.decide, student_leave.approve; assessment.scheme; exams.schedule; tests.create; marks.enter/verify/lock/unlock; **marks.correction_approve**; results.publish/view; remarks.edit; custom_fields.manage; notices.send/view; fees.gateway_view/setup/collect/adjust_approve/receipt_cancel_approve/dues_view/reports |
| Vice Principal | **users.view:school, audit.view:own**; students.view/edit, attendance.view/mark/edit_after_window, leave.fast_track/decide, student_leave.approve, tests.create, marks.enter/verify/lock, results.publish, remarks.edit, custom_fields.manage, notices.send (all `:wing`); leave.apply:own; notices.view:school |
| Clerk | **users.view:school, users.draft:school, audit.view:own**; export.lists; students.view/edit; attendance.view/correct/edit_after_window; staff_attendance.manage; leave.apply:own; leave.verify; assessment.setup; exams.schedule; tests.create; marks.enter/lock/correction_request; custom_fields.clerical; notices.send/view; fees.setup/collect/adjust_propose/receipt_cancel_propose/dues_view/reports |
| Class Teacher | students.view/edit, attendance.view/mark/request_edit, student_leave.approve, tests.create, marks.enter/verify/correction_request, remarks.edit, custom_fields.manage, notices.send, export.class_list, fees.dues_view (all `:class`); staff_attendance.mark:own, leave.apply:own, notices.view:own |
| Subject Teacher | students.view, attendance.view/mark/request_edit, tests.create, marks.enter/correction_request, remarks.edit, custom_fields.manage, notices.send (all `:subject`); staff_attendance.mark:own, leave.apply:own, notices.view:own |
| Staff | staff_attendance.mark:own, leave.apply:own, notices.view:own |
| Parent | students.view, students.request_edit, attendance.view, student_leave.apply, results.view, export.own_data, fees.dues_view, fees.pay_online (all `:child`); notices.view:own |
| Student | students.view, attendance.view, results.view, notices.view, export.own_data (all `:own`) |
| Fee Collector (duty) | fees.collect:school, fees.receipt_cancel_request:school, fees.dues_view:school, fees.reports:own |
| Attendance Officer (duty) | staff_attendance.enter:school, staff_attendance.view:school |

The Chief can edit any role except Chief (within the rules above) and add custom roles below their own level. Existing schools get new system roles with `php artisan identity:sync-roles`.

## Where each Phase 1 rule lives

| Rule | Where |
| --- | --- |
| School config view and edit by section | [`SchoolConfigPolicy`](../../backend/app/Modules/Tenancy/Policies/SchoolConfigPolicy.php): `config.edit` any path; `config.edit_policies` only `policies.attendance|leave|marks|fees` and `academic` |
| Staff accounts | [`UserPolicy`](../../backend/app/Modules/Identity/Policies/UserPolicy.php), [`UserAdmin`](../../backend/app/Modules/Identity/Domain/UserAdmin.php), [`Authority`](../../backend/app/Modules/Identity/Domain/Authority.php) |
| Roles and the matrix | [`RolePolicy`](../../backend/app/Modules/Identity/Policies/RolePolicy.php), [`RoleAdmin`](../../backend/app/Modules/Identity/Domain/RoleAdmin.php) |
| Support grants | [`SupportGrantPolicy`](../../backend/app/Modules/Identity/Policies/SupportGrantPolicy.php), [`SupportAccess`](../../backend/app/Modules/Identity/Domain/SupportAccess.php) |
| Audit log and recycle bin | gates in [`AuditServiceProvider`](../../backend/app/Modules/Audit/AuditServiceProvider.php), [`AuditLogController`](../../backend/app/Modules/Audit/Http/Controllers/AuditLogController.php), [`RecycleBin`](../../backend/app/Modules/Audit/Domain/RecycleBin.php) |
| MFA required, enrol-only sessions | middleware `mfa.enrolled` |
| School data only for its own users | middleware `tenant`, `BelongsToTenant` |
| Read-only licence | middleware `licence` (account safety routes exempt) |
| Module not licensed | middleware `module:<name>` |
