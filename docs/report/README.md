# Campus TAB — Application Report

This folder explains the whole application in plain words, then in code, so you can find, understand and change any part without reading the whole codebase. It is updated in the same session as every code change; CI fails a code change that has no report update.

## How to use this report

1. **Looking for a feature?** Use the "Where is…?" index below: it maps each feature to its screen, request, back-end code and data.
2. **New to the code?** Read [01-overview.md](01-overview.md), then follow one request end to end in [02-request-journey.md](02-request-journey.md).
3. **Working on a module?** Open its page in [modules/](#modules). Every page has the same 13 sections, from "what it does" to "change history".
4. **What changed and why?** [CHANGELOG.md](CHANGELOG.md), newest first.
5. **Running it?** [operations.md](operations.md).

Parts marked "Written by `php artisan report:generate`" are produced from the code and must not be edited by hand.

## Pages

| Page | What you find there |
| --- | --- |
| [01-overview.md](01-overview.md) | What Campus TAB is, who uses it, the architecture, how the parts link |
| [02-request-journey.md](02-request-journey.md) | One request followed from screen to database and back, with the file at each step |
| [modules/](modules/) | One page per module |
| [frontend.md](frontend.md) | App structure, routing, state, design tokens, languages, themes; how to add a screen |
| [data-dictionary.md](data-dictionary.md) | Every collection and field |
| [api.md](api.md) | Every request, in plain words and as a generated list |
| [permissions.md](permissions.md) | The permission matrix as coded (from Phase 1) |
| [configuration.md](configuration.md) | Environment variables now; every school-config key from Phase 1 |
| [links-and-events.md](links-and-events.md) | Which module calls which, events and listeners |
| [integrations.md](integrations.md) | Email, SMS, push, payments, file storage: what we send and where keys live (names only) |
| [decisions/](decisions/) | One short record per design decision |
| [how-to/](how-to/) | Step-by-step recipes |
| [operations.md](operations.md) | Running locally, environment, troubleshooting |
| [glossary.md](glossary.md) | School and code terms in plain words |
| [CHANGELOG.md](CHANGELOG.md) | Every session: what changed, why, files, how to test |

## "Where is…?" index

| Feature | Screen | Request | Back-end code | Data |
| --- | --- | --- | --- | --- |
| Is the server up? | Sidebar footer: [`ServerStatus.tsx`](../../frontend/src/features/shell/ServerStatus.tsx) | `GET /api/v1/health` | [`System/Http/Controllers/HealthController.php`](../../backend/app/Modules/System/Http/Controllers/HealthController.php) → [`System/Domain/HealthCheck.php`](../../backend/app/Modules/System/Domain/HealthCheck.php) | none (pings MongoDB and Redis) |
| Error messages from the API | every screen: [`lib/api.ts`](../../frontend/src/lib/api.ts) | every request | [`Support/Http/ApiExceptionRenderer.php`](../../backend/app/Support/Http/ApiExceptionRenderer.php) | none |
| App shell and brand stack | [`features/shell/AppShell.tsx`](../../frontend/src/features/shell/AppShell.tsx), [`components/brand/BrandLockup.tsx`](../../frontend/src/components/brand/BrandLockup.tsx) | — | — | — |
| Light, dark, Match device | Top bar: [`features/shell/ThemePicker.tsx`](../../frontend/src/features/shell/ThemePicker.tsx) | — (browser storage until Phase 1) | — | `localStorage` key `campus-tab:preferences` |
| Language choice | Top bar: [`features/shell/LanguagePicker.tsx`](../../frontend/src/features/shell/LanguagePicker.tsx) | — (browser storage until Phase 1) | — | `localStorage` key `campus-tab:preferences` |
| Colours and theme tokens | all screens | — | — | [`packages/ui/src/tokens.css`](../../packages/ui/src/tokens.css) |
| Test database reset | — | — | [`tests/Concerns/RefreshMongoDatabase.php`](../../backend/tests/Concerns/RefreshMongoDatabase.php) | drops `campus_tab_test` |
| Report generator | — | `php artisan report:generate` | [`System/Console/ReportGenerateCommand.php`](../../backend/app/Modules/System/Console/ReportGenerateCommand.php) | `docs/report/*.md` |
| Report check in CI | — | — | [`.github/scripts/check-report.mjs`](../../.github/scripts/check-report.mjs) | — |
| Sensitive custom fields (encrypted, masked) | Student profile, Show button: [`StudentProfilePage.tsx`](../../frontend/src/features/people/StudentProfilePage.tsx) | `GET /students/{id}` → `sensitive_values` | [`Support/Privacy/SensitiveValue.php`](../../backend/app/Support/Privacy/SensitiveValue.php), [`People/Domain/StudentRecords.php`](../../backend/app/Modules/People/Domain/StudentRecords.php) | `students.custom`, `enrolments.custom` |
| Notices and attachments | `/notices`: [`features/notices/`](../../frontend/src/features/notices/) | `GET/POST /notices`, `POST /notices/{id}/attachments/{aid}/link` | [`Communication/Domain/Notices.php`](../../backend/app/Modules/Communication/Domain/Notices.php), [`NoticeAudience.php`](../../backend/app/Modules/Communication/Domain/NoticeAudience.php) | `notices`, `notice_reads` |
| Notifications, bell, preferences | `/notifications`, `/account/notifications`: [`features/notifications/`](../../frontend/src/features/notifications/) | `GET /me/notifications`, `PUT /me/notification-preferences` | [`Communication/Domain/Notifier.php`](../../backend/app/Modules/Communication/Domain/Notifier.php) | `notifications`, `push_devices` |
| My dashboard and search | `/`: [`features/dashboard/`](../../frontend/src/features/dashboard/) | `GET /me/dashboard`, `GET /me/search` | [`Dashboard/Domain/Cards/`](../../backend/app/Modules/Dashboard/Domain/Cards/) | none (reads) |
| Student register, late entry, upload | not built yet | `GET/PUT /attendance/sections/{id}/days/{date}` | [`Attendance/Domain/AttendanceRegister.php`](../../backend/app/Modules/Attendance/Domain/AttendanceRegister.php) | `attendance_days` |
| Staff check-in, QR, manual entry | not built yet | `POST /attendance/staff/check-in`, `POST /attendance/staff/{id}/manual` | [`Attendance/Domain/StaffAttendanceService.php`](../../backend/app/Modules/Attendance/Domain/StaffAttendanceService.php) | `staff_attendance` |
| Holiday calendar | not built yet | `GET/POST /calendar`, `POST /calendar/{id}/approve` | [`Attendance/Http/Controllers/CalendarController.php`](../../backend/app/Modules/Attendance/Http/Controllers/CalendarController.php) | `calendar_events` |

Each phase adds its rows here.

## Modules

The thirteen business modules from the blueprint each have a page in [modules/](modules/); a page says "planned" until its phase builds it. The table below lists the modules that exist in code today.

<!-- generated:modules:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Module | Code | API routes | Report page |
| --- | --- | --- | --- |
| AcademicStructure | [`backend/app/Modules/AcademicStructure/`](../../backend/app/Modules/AcademicStructure/) | 17 | [modules/academic-structure.md](modules/academic-structure.md) |
| Approvals | [`backend/app/Modules/Approvals/`](../../backend/app/Modules/Approvals/) | 1 | [modules/approvals.md](modules/approvals.md) |
| Assessment | [`backend/app/Modules/Assessment/`](../../backend/app/Modules/Assessment/) | 26 | [modules/assessment.md](modules/assessment.md) |
| Attendance | [`backend/app/Modules/Attendance/`](../../backend/app/Modules/Attendance/) | 25 | [modules/attendance.md](modules/attendance.md) |
| Audit | [`backend/app/Modules/Audit/`](../../backend/app/Modules/Audit/) | 4 | [modules/audit.md](modules/audit.md) |
| Communication | [`backend/app/Modules/Communication/`](../../backend/app/Modules/Communication/) | 13 | [modules/communication.md](modules/communication.md) |
| Customisation | [`backend/app/Modules/Customisation/`](../../backend/app/Modules/Customisation/) | 6 | [modules/customisation.md](modules/customisation.md) |
| Dashboard | [`backend/app/Modules/Dashboard/`](../../backend/app/Modules/Dashboard/) | 2 | [modules/dashboard.md](modules/dashboard.md) |
| Identity | [`backend/app/Modules/Identity/`](../../backend/app/Modules/Identity/) | 33 | [modules/identity-and-access.md](modules/identity-and-access.md) |
| Leave | [`backend/app/Modules/Leave/`](../../backend/app/Modules/Leave/) | 27 | [modules/leave.md](modules/leave.md) |
| Licensing | [`backend/app/Modules/Licensing/`](../../backend/app/Modules/Licensing/) | 2 | [modules/licensing.md](modules/licensing.md) |
| People | [`backend/app/Modules/People/`](../../backend/app/Modules/People/) | 23 | [modules/people.md](modules/people.md) |
| Platform | [`backend/app/Modules/Platform/`](../../backend/app/Modules/Platform/) | 26 | [modules/platform.md](modules/platform.md) |
| System | [`backend/app/Modules/System/`](../../backend/app/Modules/System/) | 1 | [modules/system.md](modules/system.md) |
| Tenancy | [`backend/app/Modules/Tenancy/`](../../backend/app/Modules/Tenancy/) | 7 | [modules/tenancy.md](modules/tenancy.md) |

<!-- generated:modules:end -->
