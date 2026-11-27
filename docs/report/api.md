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

| `GET /api/v1/leave-requests` and its moves | Apply for leave, check, decide, cancel; student leave | Staff, Clerk, Principal, Chief, parents, class teachers | [Leave](modules/leave.md) |
| `GET /api/v1/papers/{id}` · `PUT …/marks` and the lifecycle; `POST /api/v1/corrections` | The markbook with conflicts, lock and publish, corrections the Principal approves | Teachers, Clerk, Principal | [Assessment](modules/assessment.md) |
| `GET /api/v1/results/students/{id}` | Published marks only | Parents, students, staff | [Assessment](modules/assessment.md) |
| `GET /api/v1/approvals/inbox` | Everything waiting on me | Approvers | [Approvals](modules/approvals.md) |

Each phase adds its requests to this table.

**`Idempotency-Key` (Phase 5):** creates and workflow moves marked `idempotent` run once per key. A retry with the same key and body gets the first answer back with `Idempotent-Replayed: true`; the same key with another body is refused (`422 idempotency_key_reused`); two at once get `409 idempotency_in_progress`. Keys belong to one person and one route and are kept 24 hours ([`Support/Http/Idempotency.php`](../../backend/app/Support/Http/Idempotency.php)).

## All routes (generated)

Only routes under `/api` are listed. Horizon's dashboard (`/horizon`, local only) and Laravel's `/up` sit outside it.

<!-- generated:routes:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Method | Path | Name | Handler | Middleware |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/academic-years` | academic-years.index | `StructureController` | api, school, permission:academic.view |
| POST | `/api/v1/academic-years` | academic-years.store | `StructureController` | api, school, permission:academic.manage |
| POST | `/api/v1/academic-years/{id}/make-current` | academic-years.make-current | `StructureController` | api, school, permission:academic.manage |
| GET | `/api/v1/approvals/inbox` | approvals.inbox | `ApprovalInboxController` | api, school, permission:leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve,marks.correction_approve,staff.approve |
| GET | `/api/v1/assessment-schemes` | assessment-schemes.index | `SchemeController` | api, school, module:assessment, permission:assessment.scheme,assessment.setup,exams.schedule,tests.create |
| POST | `/api/v1/assessment-schemes` | assessment-schemes.store | `SchemeController` | api, school, module:assessment, permission:assessment.scheme,assessment.setup, idempotent |
| GET | `/api/v1/assessment-schemes/presets` | assessment-schemes.presets | `SchemeController` | api, school, module:assessment, permission:assessment.scheme,assessment.setup |
| PUT | `/api/v1/assessment-schemes/{id}` | assessment-schemes.update | `SchemeController` | api, school, module:assessment, permission:assessment.scheme,assessment.setup |
| GET | `/api/v1/assessments` | assessments.index | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| POST | `/api/v1/assessments` | assessments.store | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:exams.schedule,tests.create, idempotent |
| GET | `/api/v1/assessments/{id}` | assessments.show | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| GET | `/api/v1/assessments/{id}/completion` | assessments.completion | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| POST | `/api/v1/assessments/{id}/lock` | assessments.lock | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:marks.lock, idempotent |
| POST | `/api/v1/assessments/{id}/open` | assessments.open | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:exams.schedule,tests.create, idempotent |
| POST | `/api/v1/assessments/{id}/publish` | assessments.publish | `AssessmentController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:results.publish, idempotent |
| GET | `/api/v1/attendance/below-threshold` | attendance.below-threshold | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| POST | `/api/v1/attendance/days/{id}/photo` | attendance.days.photo | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| POST | `/api/v1/attendance/days/{id}/photo/link` | attendance.days.photo-link | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/attendance/register` | attendance.register | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/attendance/sections/{id}/days/{date}` | attendance.days.show | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| PUT | `/api/v1/attendance/sections/{id}/days/{date}` | attendance.days.update | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| POST | `/api/v1/attendance/sections/{id}/days/{date}/import` | attendance.days.import | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/attendance/sections/{id}/template` | attendance.template | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/attendance/settings` | attendance.settings | `AttendanceController` | api, school, permission:attendance.view,attendance.mark,staff_attendance.mark,staff_attendance.manage |
| GET | `/api/v1/attendance/staff` | staff-attendance.index | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter |
| POST | `/api/v1/attendance/staff/check-in` | staff-attendance.check-in | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter, permission:staff_attendance.mark |
| POST | `/api/v1/attendance/staff/check-out` | staff-attendance.check-out | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter, permission:staff_attendance.mark |
| POST | `/api/v1/attendance/staff/import` | staff-attendance.import | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter, permission:staff_attendance.manage |
| GET | `/api/v1/attendance/staff/me` | staff-attendance.mine | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter |
| GET | `/api/v1/attendance/staff/qr` | staff-attendance.qr | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter, permission:staff_attendance.manage |
| POST | `/api/v1/attendance/staff/{staffId}/manual` | staff-attendance.manual | `StaffAttendanceController` | api, school, permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter, permission:staff_attendance.enter |
| GET | `/api/v1/attendance/students/{id}` | attendance.student | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/attendance/summary` | attendance.summary | `AttendanceController` | api, school, permission:attendance.view,attendance.mark |
| GET | `/api/v1/audit-logs` | audit-logs.index | `AuditLogController` | api, school, permission:audit.view,audit.view_academic |
| POST | `/api/v1/auth/login` | auth.login | `AuthController` | api |
| POST | `/api/v1/auth/logout` | auth.logout | `AuthController` | api, enrol |
| POST | `/api/v1/auth/mfa/verify` | auth.mfa.verify | `AuthController` | api |
| POST | `/api/v1/auth/otp/request` | auth.otp.request | `AuthController` | api |
| POST | `/api/v1/auth/otp/verify` | auth.otp.verify | `AuthController` | api |
| POST | `/api/v1/auth/password/forgot` | auth.password.forgot | `AuthController` | api |
| POST | `/api/v1/auth/password/set` | auth.password.set | `AuthController` | api |
| POST | `/api/v1/auth/step-up` | auth.step-up | `AuthController` | api, account |
| GET, POST | `/api/v1/broadcasting/auth` | — | `BroadcastController` | api, account |
| GET | `/api/v1/calendar` | calendar.index | `CalendarController` | api, school, permission:calendar.view |
| POST | `/api/v1/calendar` | calendar.store | `CalendarController` | api, school, permission:calendar.view, permission:calendar.manage |
| DELETE | `/api/v1/calendar/{id}` | calendar.destroy | `CalendarController` | api, school, permission:calendar.view, permission:calendar.manage |
| PATCH | `/api/v1/calendar/{id}` | calendar.update | `CalendarController` | api, school, permission:calendar.view, permission:calendar.manage |
| POST | `/api/v1/calendar/{id}/approve` | calendar.approve | `CalendarController` | api, school, permission:calendar.view, permission:calendar.approve |
| POST | `/api/v1/calendar/{id}/reject` | calendar.reject | `CalendarController` | api, school, permission:calendar.view, permission:calendar.approve |
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
| GET | `/api/v1/corrections` | corrections.index | `CorrectionController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| POST | `/api/v1/corrections` | corrections.store | `CorrectionController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:marks.correction_request, idempotent |
| GET | `/api/v1/corrections/{id}` | corrections.show | `CorrectionController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| POST | `/api/v1/corrections/{id}/approve` | corrections.approve | `CorrectionController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:marks.correction_approve, step-up:marks_correction_approve, idempotent |
| POST | `/api/v1/corrections/{id}/reject` | corrections.reject | `CorrectionController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:marks.correction_approve, idempotent |
| GET | `/api/v1/custom-fields` | custom-fields.index | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical,students.view,staff.view |
| POST | `/api/v1/custom-fields` | custom-fields.store | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| PATCH | `/api/v1/custom-fields/{id}` | custom-fields.update | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| POST | `/api/v1/custom-fields/{id}/archive` | custom-fields.archive | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| POST | `/api/v1/custom-fields/{id}/promote` | custom-fields.promote | `CustomFieldController` | api, school, permission:custom_fields.manage |
| POST | `/api/v1/custom-fields/{id}/restore` | custom-fields.restore | `CustomFieldController` | api, school, permission:custom_fields.manage,custom_fields.clerical |
| GET | `/api/v1/files/attendance/{tenant}/{day}` | files.attendance-photo | `AttendanceController` | api, signed:relative, throttle:public |
| GET | `/api/v1/files/leave/{tenant}/{request}/{doc}` | files.leave-document | `LeaveRequestController` | api, signed:relative, throttle:public |
| GET | `/api/v1/files/notices/{tenant}/{notice}/{attachment}` | files.notice-attachment | `NoticeController` | api, signed:relative, throttle:public |
| PATCH | `/api/v1/guardians/{id}` | guardians.update | `StudentController` | api, school, permission:guardians.manage |
| DELETE | `/api/v1/guardians/{id}/parent-login` | guardians.parent-login.destroy | `StudentController` | api, school, permission:guardians.manage |
| POST | `/api/v1/guardians/{id}/parent-login` | guardians.parent-login.store | `StudentController` | api, school, permission:guardians.manage |
| GET | `/api/v1/health` | system.health | `HealthController` | api |
| POST | `/api/v1/imports/{entity}` | imports.store | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{entity}/template` | imports.template | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{id}` | imports.show | `ImportController` | api, school, permission:imports.students,imports.staff |
| POST | `/api/v1/imports/{id}/commit` | imports.commit | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/imports/{id}/report` | imports.report | `ImportController` | api, school, permission:imports.students,imports.staff |
| GET | `/api/v1/leave-requests` | leave-requests.index | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve |
| POST | `/api/v1/leave-requests` | leave-requests.store | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, permission:leave.apply,student_leave.apply, idempotent |
| GET | `/api/v1/leave-requests/preview` | leave-requests.preview | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, permission:leave.apply |
| GET | `/api/v1/leave-requests/{id}` | leave-requests.show | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve |
| PATCH | `/api/v1/leave-requests/{id}` | leave-requests.update | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, permission:leave.apply,student_leave.apply |
| POST | `/api/v1/leave-requests/{id}/approve` | leave-requests.approve | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/cancel` | leave-requests.cancel | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.decide,leave.decide_principal,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/cancel-request` | leave-requests.cancel-request | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.apply,student_leave.apply |
| POST | `/api/v1/leave-requests/{id}/documents` | leave-requests.documents.store | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.apply,student_leave.apply |
| POST | `/api/v1/leave-requests/{id}/documents/{doc}/link` | leave-requests.documents.link | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/keep` | leave-requests.keep | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.decide,leave.decide_principal,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/reject` | leave-requests.reject | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/return` | leave-requests.return | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve |
| POST | `/api/v1/leave-requests/{id}/submit` | leave-requests.submit | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.apply,student_leave.apply |
| POST | `/api/v1/leave-requests/{id}/verify` | leave-requests.verify | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.verify,leave.decide,leave.fast_track |
| POST | `/api/v1/leave-requests/{id}/withdraw` | leave-requests.withdraw | `LeaveRequestController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve, idempotent, permission:leave.apply,student_leave.apply |
| GET | `/api/v1/leave-types` | leave-types.index | `LeaveSetupController` | api, school, module:leave, permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve,leave.manage_types |
| POST | `/api/v1/leave-types` | leave-types.store | `LeaveSetupController` | api, school, module:leave, permission:leave.manage_types, idempotent |
| POST | `/api/v1/leave-types/starter` | leave-types.starter | `LeaveSetupController` | api, school, module:leave, permission:leave.manage_types, idempotent |
| PATCH | `/api/v1/leave-types/{id}` | leave-types.update | `LeaveSetupController` | api, school, module:leave, permission:leave.manage_types |
| GET | `/api/v1/leave/balances/me` | leave.balances.me | `LeaveSetupController` | api, school, module:leave, permission:leave.apply |
| GET | `/api/v1/leave/calendar` | leave.calendar | `LeaveSetupController` | api, school, module:leave, permission:leave.view |
| GET | `/api/v1/leave/cover` | leave.cover | `LeaveSetupController` | api, school, module:leave, permission:leave.verify,leave.decide,leave.decide_principal |
| GET | `/api/v1/leave/delegations` | leave.delegations.index | `LeaveSetupController` | api, school, module:leave, permission:leave.delegate |
| POST | `/api/v1/leave/delegations` | leave.delegations.store | `LeaveSetupController` | api, school, module:leave, permission:leave.delegate, idempotent |
| POST | `/api/v1/leave/delegations/{id}/end` | leave.delegations.end | `LeaveSetupController` | api, school, module:leave, permission:leave.delegate |
| POST | `/api/v1/licences/activate` | licences.activate | `ActivationController` | api, throttle:activation |
| POST | `/api/v1/licences/activate/otp` | licences.activate.otp | `ActivationController` | api, throttle:activation |
| GET | `/api/v1/me` | me.show | `MeController` | api, account |
| GET | `/api/v1/me/dashboard` | me.dashboard | `DashboardController` | api, account |
| DELETE | `/api/v1/me/mfa` | me.mfa.disable | `MfaController` | api, account |
| POST | `/api/v1/me/mfa/enrol` | me.mfa.enrol | `MfaController` | api, enrol |
| POST | `/api/v1/me/mfa/enrol/confirm` | me.mfa.enrol.confirm | `MfaController` | api, enrol |
| POST | `/api/v1/me/mfa/recovery-codes` | me.mfa.recovery-codes | `MfaController` | api, account |
| GET | `/api/v1/me/notification-preferences` | me.notification-preferences.show | `NotificationController` | api, account |
| PUT | `/api/v1/me/notification-preferences` | me.notification-preferences.update | `NotificationController` | api, account |
| GET | `/api/v1/me/notifications` | me.notifications.index | `NotificationController` | api, account |
| POST | `/api/v1/me/notifications/read-all` | me.notifications.read-all | `NotificationController` | api, account |
| POST | `/api/v1/me/notifications/{id}/read` | me.notifications.read | `NotificationController` | api, account |
| PUT | `/api/v1/me/password` | me.password | `MeController` | api, account |
| PATCH | `/api/v1/me/preferences` | me.preferences | `MeController` | api, account |
| DELETE | `/api/v1/me/push-devices` | me.push-devices.destroy | `NotificationController` | api, account |
| POST | `/api/v1/me/push-devices` | me.push-devices.store | `NotificationController` | api, account |
| GET | `/api/v1/me/search` | me.search | `DashboardController` | api, account |
| GET | `/api/v1/me/sessions` | me.sessions.index | `MeController` | api, account |
| POST | `/api/v1/me/sessions/revoke-others` | me.sessions.revoke-others | `MeController` | api, account |
| DELETE | `/api/v1/me/sessions/{id}` | me.sessions.destroy | `MeController` | api, account |
| GET | `/api/v1/notices` | notices.index | `NoticeController` | api, school, permission:notices.view |
| POST | `/api/v1/notices` | notices.store | `NoticeController` | api, school, permission:notices.send |
| GET | `/api/v1/notices/{id}` | notices.show | `NoticeController` | api, school, permission:notices.view |
| POST | `/api/v1/notices/{id}/attachments/{attachment}/link` | notices.attachments.link | `NoticeController` | api, school, permission:notices.view |
| GET | `/api/v1/notices/{id}/receipts` | notices.receipts | `NoticeController` | api, school, permission:notices.view |
| GET | `/api/v1/papers/{id}` | papers.show | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create |
| POST | `/api/v1/papers/{id}/lock` | papers.lock | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:marks.lock |
| PUT | `/api/v1/papers/{id}/marks` | papers.marks | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, permission:marks.enter |
| POST | `/api/v1/papers/{id}/open` | papers.open | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:exams.schedule,tests.create |
| POST | `/api/v1/papers/{id}/publish` | papers.publish | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:results.publish |
| POST | `/api/v1/papers/{id}/send-back` | papers.send-back | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:marks.verify,marks.lock |
| POST | `/api/v1/papers/{id}/submit` | papers.submit | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:marks.enter |
| POST | `/api/v1/papers/{id}/verify` | papers.verify | `PaperController` | api, school, module:assessment, permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create, idempotent, permission:marks.verify |
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
| GET | `/api/v1/results/mine` | results.mine | `AssessmentController` | api, school, module:assessment, permission:results.view |
| GET | `/api/v1/results/students/{id}` | results.student | `AssessmentController` | api, school, module:assessment, permission:results.view |
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
