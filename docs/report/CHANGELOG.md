# Change log

Every session adds an entry at the top: date, phase, what changed, why, files touched, report pages updated and how to test it.

## 2026-11-28 · Browser review of Phases 7 and 8

**What changed:** every new Phase 7 and 8 screen and the "Updated" tag were checked in Chrome, light and dark, at 360 and 1280 px, with axe-core (WCAG 2.1 A/AA plus best practice). Each "done when" was walked through with the demo accounts, including a fake-gateway payment as Suresh Patel. Fixes:

- **Behaviour bugs:**
  - The year-end promotion skipped a missing numbered class: 3-A was set to move to Class 6 because the school has no Class 4. A numbered class now moves to the next number; a class the school skipped is created as a draft on apply and listed in the preview (`roll_forward.new_classes`).
  - A class teacher could not set co-scholastic grades: the areas came from `/report-templates/presets`, which needs `report_templates.manage`. The card now carries `co_scholastic_options` for whoever may set them.
  - The **Approvals** menu entry showed for a fee collector, whose page then said "not allowed": menu permissions matched by plain prefix (`fees.receipt_cancel` matched `fees.receipt_cancel_request`). They now match the ability exactly, in any scope.
  - Step-up: the "enter a code" request also showed as a red error, because of an out-of-date check. Fixed in fee approvals and in the report export.
- **Clearer screens:**
  - A replaced card links to the current version (`replaced_by_id`), and the re-issue message no longer disappears.
  - A receipt with a cancellation waiting says so and stops offering "Ask to cancel" (`cancellation_pending`).
  - Confirming a closing with no cash taken needs no count.
  - The verify page has its own not-found text.
  - The fee history names the fee head on each line and no longer shows internal codes such as `upi_counter` or a repeated receipt number.
  - A run says "Starting…" instead of "0 of 0 done".
  - Better wording: "1 receipt", the ₹ amount on the Collect button, and the messages after asking to cancel and after closing the day.
- **Accessibility and layout:**
  - Wide fee tables are keyboard-reachable regions (axe `scrollable-region-focusable`).
  - Doubled icons in alerts are gone.
  - At 360 px, labels no longer get crushed next to chips and amounts (subject rows, template blocks, instalments, receipts, approvals); the receipt date is no longer indented.
  - Fee setup shows a spinner rather than "No plans yet" while plans load.

**Why:** the browser review the previous session could not do (Chrome was not connected).

**Files:**

- **Back end:** `backend/app/Modules/Reports/Domain/{Promotion,CardAccess}.php`, `backend/app/Modules/Fees/Http/Controllers/StudentFeesController.php`, `backend/tests/Feature/Reports/ReportCardsTest.php`.
- **Front end:** `frontend/src/features/reports/{ReportCardPages,TemplatesPage,PromotionPage,VerifyPage,FamilyCards,api}.tsx/ts`, `frontend/src/features/fees/{api.ts,useStepUp.ts,CounterPage,ClosingsPage,ReceiptPage,StudentFeesPage,MyFeesPage,ReportsPage,SetupPage,AdjustmentsPage,OnlinePage,FeeApprovalPanel}.tsx`, `frontend/src/features/fees/fees.test.tsx`, `frontend/src/features/shell/{navItems.ts,navItems.test.ts}`, `frontend/src/locales/{en,hi}/common.json`; `packages/api/{openapi.json,src/schema.ts}`.

**Report pages updated:** `modules/{reports,fees,approvals}.md`, `frontend.md`.

**How to test:** run `composer test` and the front-end checks as before. In the browser, the steps in `how-to/demo-school.md`; for the promotion, preview 2026-27 → 2027-28 as Dr. Meera Iyer: 3-A now moves to a new draft Class 4.

## 2026-11-28 · Fixes, Phase 7 (report cards and year-end) and Phase 8 (fees)

**What changed:**

- **Fix 1:** `docs/BLUEPRINT.md` "Patterns we adopt" now says "Plan for poor internet … Campus TAB stays online-only, with a paper fallback that the teacher enters later."
- **Fix 2:** every language, English included, loads on demand. Main bundle **585.61 kB → 526.82 kB** (gzip 184.48 → 164.76 kB).
- **Fix 3:** a changed mark on an auto-published class test (before lock) is stamped `updated_at`; parents and students see an **Updated** tag on it.
- **Fix 4 (flaky parallel tests), two causes found:**
  - Overlapping runs shared databases: workers of a run that timed out or was stopped kept using `campus_tab_<n>_test` while the next run emptied the same collections. The parallel token is now `<n>_<run>` (run = the pest process's pid), so each run has its own databases, cache prefix and fake disks; databases are dropped when a process ends and dead runs' are cleaned up. Tests use the in-memory cache, so Redis was not the cause; `REDIS_PREFIX` is still set per run.
  - The full-Aadhaar guard sometimes refused a request because a random 24-hex record id held a 12-digit run passing the Verhoeff check (both tests named send ids). Record ids and ULIDs are now skipped; real numbers are still caught.
  - `composer test` no longer has Composer's 300-second limit (the suite now takes about five minutes).
- **Phase 7:** grading engine with exact fractions and rounding once at the end (unit tests per preset); templates with presets and letterhead; background generation from **locked papers only**; Gotenberg PDFs with a QR code under the school's folder, served by 5-minute signed links after a permission check; public verification with identity facts only and an unguessable code; remarks, review, publish; Principal-only re-issue with a reason, old version kept, family told only that a new version exists; promotion wizard with full preview, digest, one transaction, audit, and a **read-only closed year** (`ClosedYears` on every school model).
- **Phase 8:** the Fees module (see `modules/fees.md`): whole paise everywhere; dues raised from plans once; append-only ledger; counter payments with receipt numbers from an atomic counter inside the payment transaction (cancelled receipts keep their numbers); cheques; day-end closing; adjustments and receipt cancellations never approved by their proposer, with step-up (`fees_adjust_approve`, `receipt_cancel_approve`); server-set online amounts; signed, amount-checked, idempotent webhook with the school found from our order record (**decision 0015**); local fake gateway (local/testing only) sending a signed webhook from the queue worker; Razorpay test-mode keys entered by the Chief and stored encrypted; nightly reconciliation; reports with an audited step-up Excel export; notifications without amounts or names; class teachers see paid or pending only.
- **Also:** new ability `fees.closing_confirm`; `policies.fees.chief_alert_paise`; receipt numbers keep the year exactly once even when the prefix holds it; fee approvals in the approvals inbox; demo seeders `DemoReportsSeeder`, `DemoFeesSeeder`; `gotenberg` service; the report checker maps `tests/Feature/Support` to `system.md`.
- **Found and fixed while building:** nested ids in queries (`collector.id`) never matched, so the collector's id is stored at the top level; Eloquent `refresh()` skipped the tenant scope (replaced with scoped reads); the promotion now creates a missing next-class section (9-B → 10-B) as a draft instead of failing.

**Why:** the session's three fixes plus the flaky-test fix, then Phase 7 and Phase 8 of `docs/BUILD-PROMPTS.md` with the owner's rules.

**Files:**

- **Back end:** `backend/app/Modules/Reports/**`, `backend/app/Modules/Fees/**` (new); `backend/app/Support/Models/{ClosedYears,GuardsClosedYear,SchoolModel}.php`; `backend/app/Support/Privacy/Aadhaar.php`; `backend/app/Modules/Assessment/{Domain/Markbook,Domain/Corrections,Domain/Assessments,Models/Paper,Http/Controllers/SchemeController}.php`; `backend/app/Modules/People/Domain/Families.php`; `backend/app/Modules/Tenancy/{Domain/DefaultConfig.php,Schemas/school-config.schema.json}`; `backend/config/{reports,fees,permissions,identity,communication,approvals}.php`; `backend/bootstrap/providers.php`; `backend/routes/console.php`; `backend/composer.json`; migrations `2026_12_01_000001`, `2026_12_02_000001`; seeders `DemoReportsSeeder`, `DemoFeesSeeder`, `DemoSchoolSeeder`; `backend/lang/{en,hi}/reports.php`.
- **Tests:** `tests/Feature/Reports/ReportCardsTest.php`, `tests/Feature/Fees/FeesTest.php`, `tests/Unit/{Reports,Fees,Support}/*`, `tests/Feature/Support/ParallelIsolationTest.php`, `tests/TestCase.php`, `tests/Concerns/RefreshMongoDatabase.php`, `tests/Feature/Identity/{StepUpTest,RoutePermissionTest}.php`, `tests/Feature/Assessment/MarksTest.php`.
- **Front end:** `frontend/src/features/{reports,fees}/**` (new), `features/assessment/{TestsPages,api}.ts(x)`, `features/approvals/ApprovalsPage.tsx`, `features/shell/navItems.ts`, `app/routes.tsx`, `i18n/index.ts`, `test/setup.ts`, `locales/{en,hi}/common.json`; `packages/api/{openapi.json,src/schema.ts}`.
- **Infra and CI:** `infra/docker-compose.yml` (`gotenberg`, `GOTENBERG_URL`, `FAKE_GATEWAY_WEBHOOK_URL`), `.github/scripts/check-report.mjs`.

**Report pages updated:** `modules/{reports,fees,assessment,academic-structure,leave,people,tenancy,identity-and-access,approvals,communication,system}.md`, `decisions/0015-payment-webhook-tenant-exception.md` (new), `decisions/0005…`, `permissions.md`, `configuration.md`, `operations.md`, `frontend.md`, `how-to/demo-school.md`, generated `api.md`, `data-dictionary.md`, `README.md`.

**How to test:** `docker compose exec api composer test` and `composer lint`; `docker compose exec web npm test -w @campus-tab/frontend`, `run lint`, `run typecheck`, `run build`. In the browser: see "Done when" checks in `how-to/demo-school.md` (Report cards, Fees).


## 2026-11-27 · Phase 6 · Tests and marks (items 1–6)

**What changed:**

- **Schemes (item 1):**
  - the school's own scheme per group of classes: terms, components with weightage (must total 100%), grade scale, rules
  - optional presets (CBSE-style VI–VIII, IX–X, a simple one) as **starting points only**, labelled "Check against your board's latest circular…" when chosen and on the scheme (owner's rule 5)
  - the Principal changes everything
- **Assessments and papers (item 2):**
  - school exams by the Principal or the Clerk
  - class tests by teachers, only for their own subject-sections (class teachers also for their class)
  - one paper per section × subject, marks embedded
- **Lifecycle (item 3):** draft → open → submitted → verified → locked → published, each move permission-checked.
  - **Locking and publishing run in MongoDB transactions** (owner's rule 6), for one paper or all eligible papers of an assessment.
  - Auto-published class tests stay **editable until locked** (owner's decision this session).
- **Corrections (item 4):**
  - Clerk, teachers, the Chief and the Principal may request
  - only the Principal approves, with **step-up MFA (`marks_correction_approve`, new) and a typed reason** (owner's rule 2)
  - a correction the Principal raised needs a code entered **after** raising it, is flagged **self-approved** and is shown to the Chief (owner's rule 3)
  - applied in a transaction, and only if the mark is unchanged
  - old and new mark, both reasons, who and when are kept on the request and in the audit log
- **No unlock** (owner's decision this session): `marks.unlock` and `marks_unlock` are removed, and `identity:sync-roles --with-new-permissions` now drops retired abilities from stored roles.
- **Markbook (item 5):**
  - keyboard navigation, paste from Excel, AB and ML, max-marks checks, autosave
  - **`If-Match`** with **412 `paper_conflict`** instead of an overwrite
  - a `marks.changed` audit entry with old and new for every mark
  - card view on phones
  - **online only** (owner's rule 1); `docs/BLUEPRINT.md` and Phase 6 item 5 of `docs/BUILD-PROMPTS.md` now say so
- **Completion grid (item 6):** for the class teacher (their class) and the Principal (every section).
- **Families (owner's rule 4):** see published marks only (`/results`, the profile's Results tab); the notification says "Results are out" and nothing else.
- **Also:**
  - corrections in the approvals inbox
  - dashboard cards: marks to enter, papers to verify, marks this year, self-approved corrections for the Chief
  - `policies.marks.class_teacher_can_enter` (false) for the matrix's "Class (if enabled)"
  - the blueprint's step-up lines now name approving a locked-mark correction instead of unlocking
  - `DemoMarksSeeder`
- **Found in the browser and fixed:**
  - a published paper was counted twice in the tests list
  - the Principal got the teachers' "Marks to enter" card
  - an empty inbox tab hid the remaining leave
  - a parent's second child showed as "Child 2"

**Why:** Phase 6 of `docs/BUILD-PROMPTS.md` with the owner's six rules.

**Files:**

- **Back end:**
  - `backend/app/Modules/Assessment/**` (new)
  - `backend/app/Modules/Dashboard/Domain/Cards/AssessmentCards.php`
  - `backend/app/Modules/Identity/{Domain/SystemRoles.php,Console/SyncRolesCommand.php}`
  - `backend/app/Modules/Tenancy/{Domain/DefaultConfig.php,Schemas/school-config.schema.json}`
  - `backend/config/{assessment,identity,permissions,approvals,communication,dashboard}.php`, `backend/bootstrap/providers.php`
  - `backend/database/migrations/2026_11_30_000002_create_assessment_collections.php`
  - `backend/database/seeders/{DemoMarksSeeder,DemoSchoolSeeder}.php`
- **Tests:** `backend/tests/Feature/Assessment/MarksTest.php`, `backend/tests/Feature/Identity/StepUpTest.php`, `backend/tests/Feature/Tenancy/SchoolConfigTest.php` (the marks settings allowed are now exactly `auto_publish_class_tests` and `class_teacher_can_enter`)
- **Front end and packages:**
  - `frontend/src/features/assessment/**` (new)
  - `frontend/src/features/{approvals/ApprovalsPage.tsx,people/StudentProfilePage.tsx,dashboard/cards.tsx,notifications/socket.ts,shell/navItems.ts}`
  - `frontend/src/app/routes.tsx`, `frontend/src/locales/{en,hi}/common.json`
  - `packages/api/{openapi.json,src/schema.ts}`
- **Docs:** `docs/BLUEPRINT.md`, `docs/BUILD-PROMPTS.md`

**Report pages updated:**

- `modules/assessment.md` (in full), `decisions/0014-marks-lifecycle-and-corrections.md` (new)
- `modules/{approvals,dashboard,identity-and-access,tenancy,communication,attendance}.md`
- `permissions.md`, `configuration.md`, `frontend.md`, `api.md`, `links-and-events.md`, `how-to/demo-school.md`
- the generated blocks

**How to test:**

1. `cd infra && docker compose exec api php artisan migrate`, then `php artisan identity:sync-roles --with-new-permissions` (adds the Principal's correction request, removes `marks.unlock`), then `php artisan db:seed --class=DemoMarksSeeder` for a demo school made earlier.
2. `docker compose exec api php artisan test tests/Feature/Assessment`, then the full checks.
3. In the browser: follow the Phase 6 steps in the session summary.

## 2026-11-27 · Phase 5 · Leave (items 1–7)

**What changed:**

- **Branch:** built on `session-3-leave-marks`, reset (at the owner's request) to the Phase 3–4 work on `session-2-shell-attendance`, because leave needs staff attendance, the holiday calendar and notifications.
- **Leave types and the ledger (item 1):**
  - the Principal keeps types: quota (or none), the **sandwich rule per type**, half days, the document rule, offered or not
  - starter types on request
  - `leave_ledger` is append-only (reserve, release, deduct, restore); balances are worked out from the quota and the ledger
- **The state machine (item 2):**
  - all eight states, with every legal move in `LeaveWorkflow::MOVES` and everything else refused (409)
  - the chain comes from `policies.leave.chain` (clerk, optional vice_principal for the applicant's wing, principal)
  - fast-track; `clerk_can_reject`, now the Chief's switch only
  - dated delegation to a Vice Principal
  - approve with other dates
  - withdraw, ask to cancel, cancel, keep
- **Owner's rule 1, nobody decides their own leave:**
  - a Clerk or a Vice Principal → the Principal; the Principal → the Chief Admin
  - **the Chief Admin → the Principal** (owner's decision this session: the Principal is the school's leave authority)
  - a delegate never decides for anyone at or above their level
- **Owner's rule 2, privacy:**
  - reasons and remarks are sealed at rest; documents are encrypted on disk with sealed names and served only by 5-minute signed links
  - a request outside your chain is 404; the audit log stores `[private]`
  - the calendar shows only who is away; notifications say only that a request changed
- **Owner's rule 3:** the sandwich rule counts days off **inside** the leave only; days off at the ends never count.
- **Transactions (item 3, owner's rule 4):**
  - each move re-reads the request in a MongoDB transaction (5 attempts) and bumps a per-balance lock document first, so concurrent approvals collide and retry instead of spending the same days twice
  - approval writes the request, the ledger and attendance together
  - cancelling restores the untaken days and their attendance together
- **Reminders (item 4):** `leave:remind` runs hourly. It reminds after `remind_after_hours` (24) and tells the level above after `escalate_after_hours` (48), once per stage.
- **Screens (item 5):** apply with a live balance, the request timeline (live over the notification socket), the Clerk's and the Principal's queues in the shared **approvals inbox** (`GET /approvals/inbox`, new Approvals module) with "Away this week", the leave calendar, leave settings. See [frontend.md](frontend.md#phase-5-leave-screens).
- **On approval (item 6, owner's rule 5):** staff attendance gets **LV with source `leave`**, a system entry rather than a manual one. The needs-cover list comes from teaching assignments and class registers.
- **Student leave (item 7):** the parent applies and the class teacher decides; the register shows LV (registers already saved too); long leave is shown to the Principal.
- **Also:**
  - **`Idempotency-Key`** middleware (`idempotent`) on creates and workflow moves
  - dashboard cards: leave waiting, staff away today, my leave balance
  - the empty staff-drafts card drops to the bottom
  - new abilities `leave.view`, `leave.manage_types`, `leave.delegate`
  - `leave:seed-types`; demo leave in `DemoSchoolSeeder`
- **Found in the browser and fixed:** a raw `{{stage}}` in the inbox line; a balance that read "10 of 12" instead of "12 of 12 → 10 after"; date fields cut off at 360 px; the scrollable cover table not reachable by keyboard.

**Why:** Phase 5 of `docs/BUILD-PROMPTS.md` with the owner's five rules.

**Files:**

- **Back end:**
  - `backend/app/Modules/{Leave,Approvals}/**` (new), `backend/app/Support/Http/Idempotency.php` (new)
  - `backend/app/Modules/Dashboard/Domain/Cards/{LeaveCards,SchoolAdminCards}.php`
  - `backend/app/Modules/Attendance/{Domain/AttendanceRegister.php,Models/StaffAttendance.php}`
  - `backend/app/Modules/Tenancy/{Domain/DefaultConfig.php,Domain/ConfigSections.php,Schemas/school-config.schema.json}`
  - `backend/config/{leave,approvals,permissions,communication,dashboard}.php`
  - `backend/bootstrap/{app,providers}.php`, `backend/routes/console.php`
  - `backend/database/migrations/2026_11_30_000001_create_leave_collections.php`, `backend/database/seeders/DemoSchoolSeeder.php`
- **Tests:** `backend/tests/Feature/Leave/{LeaveWorkflowTest,StudentLeaveTest}.php`, `backend/tests/Feature/Identity/RoutePermissionTest.php`
- **Front end and packages:**
  - `frontend/src/features/{leave,approvals}/**` (new)
  - `frontend/src/features/{notifications/socket.ts,shell/navItems.ts}`, `frontend/src/app/routes.tsx`, `frontend/src/locales/{en,hi}/common.json`
  - `packages/api/{openapi.json,src/schema.ts}`

**Report pages updated:**

- `modules/leave.md` (in full), `modules/approvals.md` (new), `decisions/0013-leave-routing-privacy-and-ledger.md` (new)
- `modules/{attendance,dashboard,tenancy,identity-and-access,communication}.md`
- `permissions.md`, `configuration.md`, `frontend.md`, `api.md`, `links-and-events.md`, `how-to/demo-school.md`
- the generated blocks

**How to test:**

1. `cd infra && docker compose exec api php artisan migrate`, then `php artisan identity:sync-roles --with-new-permissions`, then `php artisan leave:seed-types --school=SUNRISE-BPL` (or a fresh `db:seed --class=DemoSchoolSeeder`).
2. `docker compose exec api php artisan test tests/Feature/Leave`, then `composer test`, `composer lint` and `php artisan report:generate --check`. Front end: `npm test -w @campus-tab/frontend`, `lint`, `typecheck`.
3. In the browser: follow the Phase 5 steps in the session summary.

## 2026-11-27 · Session 2 · Browser review fixes

**What changed** (found by walking every Phase 2–4 screen at 360 px and 1280 px in light and dark, and by doing each "done when" step in Chrome):

- **Phone header:** the school name now shortens with "…" and no longer runs under the search button; the year picker shows just the year ("2026-27") so it is no longer cut off.
- **Taking attendance:** after a save that closes the day for the teacher (first marking after the edit window), the "Saved · N parents told" line stays on screen and the "window closed" warning waits until the next visit. The late-entry banner shows the date as a date ("Tuesday, 24 November 2026"), and the title no longer reads " attendance" while loading.
- **Staff attendance:** "Enter manually" appears only on the rows the person may actually enter (never their own, only people below their level). The server tells the screen with a new `can_enter` flag on each row; it still refuses anything else.
- **Role names:** the Attendance Officer duty shows as "Attendance Officer" / "उपस्थिति अधिकारी", not `attendance_officer`.
- **Dashboard:**
  - Recent activity shows readable actions and records ("Set up two-step sign-in · Accounts"), not audit codes.
  - Dates in card lists are formatted.
  - "Holidays waiting for approval" opens the calendar on the holiday's month.
  - A class teacher's register card says "View attendance" once the edit window has closed.
- **Dark theme:** the soft primary background is darker (`#172554`), so blue text on it (active nav item, avatar initials) passes 4.5:1. Lighthouse accessibility is 100 on the dashboard and the attendance taker, phone and desktop, light and dark.
- **School settings:** the logo upload button wraps under the file picker on phones.
- **Phone width:** every sideways-scrolling area (tables, tab strips) is now `relative`, so the screen-reader-only labels inside a table can no longer stretch the whole page. The Daily summary, Custom fields, student list and monthly register now fit 360 px.
- **Teacher marking after the edit window for the first time:** the taker no longer says "You can change this until 12:00 PM today" at 8 PM; it says the window has passed, the day can still be marked, and later changes go through the Clerk or the Principal.

**Why:** the owner's review before commit.

**Files:**

- **Front end:** `features/shell/{BrandStack,SchoolCard}.tsx`, `features/attendance/{TakerPage,StaffPages,api}.tsx`, `features/attendance/attendance.test.tsx`, `features/dashboard/cards.tsx`, `features/settings/SchoolSettingsPage.tsx`, scroll wrappers in `features/{attendance,console,dashboard,people,setup}`, `locales/{en,hi}/common.json`, `packages/ui/src/tokens.css`, `packages/api/{openapi.json,src/schema.ts}`
- **Back end:** `Attendance/Domain/StaffAttendanceService.php` (`canEnterFor`), `Attendance/Http/Controllers/StaffAttendanceController.php`, `Dashboard/Domain/Cards/{AttendanceCards,SchoolAdminCards}.php`, tests in `tests/Feature/Attendance/StaffAttendanceTest.php` and `tests/Feature/Dashboard/DashboardTest.php`

**Report pages updated:** this entry, `modules/attendance.md`, `modules/system.md` (the report generator's page alias and the 404 renderer from earlier in Session 2, which the report check found missing), `languages.md`.

**How to test:** sign in as Ramesh (`clerk@sunrise.test`) at `/attendance/staff`: his own row and the Principal's have no "Enter manually" button. At 360 px the header shows "Sunrise P…" and "2026-27" without overlap.

## 2026-11-27 · Session 2 · Phase 4 screens (attendance) and follow-ups

**What changed:**

- **Attendance screens** (`frontend/src/features/attendance/`):
  - taking attendance, phone-first, following `TeacherAttendance.dc.html`: all present by default, tap to toggle, one save; late entry with reason, photo and Excel upload; online-only message pointing to the paper register
  - daily summary, printable monthly register, my check-in, the office staff view with manual entry and biometric import, the office QR display, the holiday calendar, and an Attendance tab on the student profile (also the parent's view)
- **Dashboard and navigation:** renderers for the attendance cards; new nav items gated on permissions; "Take attendance" in quick create; Attendance is the class teacher's main phone tab.
- **Parents are told once a day**, also in period mode, however many periods a child misses.
- **The report generator** links the Identity module to `identity-and-access.md`, the same alias the CI check uses.
- **Main bundle:** 564.83 kB (gzip 178.15 kB), up from 551.35 kB after the Phase 3 shell; see [frontend.md](frontend.md#bundle-split-session-2-fix).

**Why:** Phase 4 of `docs/BUILD-PROMPTS.md` (owner's version) and items found while writing the report.

**Files:**

- **Front end:**
  - `frontend/src/features/attendance/**` (new)
  - shell, dashboard cards, student profile, `app/routes.tsx`
  - `locales/{en,hi}/common.json`, `styles/index.css`
- **Back end:**
  - `backend/app/Modules/Attendance/Domain/AttendanceRegister.php`
  - `backend/app/Modules/System/Console/ReportGenerateCommand.php`
  - `backend/tests/Feature/Attendance/AttendanceRegisterTest.php`

**Report pages updated:** `frontend.md`, `modules/attendance.md`, `languages.md`, the generated README block.

**How to test:**

1. `docker compose run --rm web npm test -w frontend`
2. `docker compose exec api php artisan test tests/Feature/Attendance`
3. Then follow the browser steps in the session summary.

## 2026-11-27 · Session 2 · Phase 4 back end (attendance)

**What changed:**

- **The student register** (`AttendanceRegister`):
  - everyone present by default; codes P, A, L, HD typed by teachers; H from approved holidays (LV from leave in Phase 5)
  - daily mode (class teacher, or class, wing or school scope) or period mode (subject teachers too, periods 1 to `periods_per_day`)
  - online only; one save for the whole class
- **The edit window:** the teacher changes a day freely until `school_starts_at` + `edit_window_hours`; after that only the Clerk or the Principal (`attendance.edit_after_window`), with a reason.
- **The paper-register late entry:** fills only a day never marked, with a reason and an optional photo (stored privately, served by a short-lived signed link), shown as a late entry and audited; the teacher within `late_entry_days` (7), after that only the Clerk or the Principal.
- **Upload:** an Excel template of the class, then an Excel or CSV upload with a dry run and a commit through the same rules.
- **Absence notifications** to parents once per student for each day's register, naming the date for a late entry; push and email with the generic text only.
- **Staff attendance** (`StaffAttendanceService`):
  - the app button, with an optional geofence that stores only `inside_area` (true or false), never the location; screens are told only that the geofence is on
  - the office QR code: an HMAC per few-minute window (`ATTENDANCE_QR_MINUTES`, 5), the previous one accepted
  - a biometric device CSV import with a dry run
  - manual entry, with the time and a reason, only by someone at a higher level (`Authority::outranks`, the user-management check) and never for yourself
  - late after `staff.late_after` (08:15)
- **The holiday calendar:** the Clerk keeps it, the Principal approves or rejects; only approved holidays close the register.
- **Reports:** daily summary, the printable monthly register, each student's % for the year, students below the threshold, and a per-student endpoint for parents and students.
- **Dashboard cards** (`AttendanceCards`): the class teacher's register today at the top, staff check-in, attendance today, holidays to approve, below threshold.
- **Permissions:** `calendar.view`, `calendar.manage`, `calendar.approve`; `staff_attendance.enter` for the Chief, Principal and Vice Principal (and the Attendance Officer duty); `staff_attendance.view` and `staff_attendance.mark:own` for more roles.
- **School config:** `policies.attendance.late_entry_days`, `school_starts_at`, `periods_per_day`, `staff.late_after`, `staff.geofence`.
- **Demo school:** the Clerk is also the Attendance Officer; one approved and one pending holiday.
- **Docs:** `docs/BUILD-PROMPTS.md` Phase 4 and the attendance sections of `docs/BLUEPRINT.md` now follow the owner's version, with the Attendance Officer duty listed next to Fee Collector.
- **Screens:** built in the entry above.

**Why:** Phase 4 of `docs/BUILD-PROMPTS.md`, owner's version.

**Files:**

- **Back end:**
  - `backend/app/Modules/Attendance/**` (new)
  - `backend/app/Modules/Dashboard/Domain/Cards/AttendanceCards.php`, `backend/config/dashboard.php`
  - `backend/app/Modules/Tenancy/{Domain/DefaultConfig.php,Schemas/school-config.schema.json}`
  - `backend/config/{permissions,attendance}.php`, `backend/bootstrap/providers.php`
  - `backend/database/migrations/2026_11_29_000002_create_attendance_collections.php`
  - `backend/database/seeders/DemoSchoolSeeder.php`
- **Tests:** `backend/tests/Feature/Attendance/{AttendanceRegisterTest,StaffAttendanceTest}.php`, `backend/tests/Feature/Identity/RoutePermissionTest.php`
- **Docs:** `docs/BUILD-PROMPTS.md`, `docs/BLUEPRINT.md`

**Report pages updated:**

- `modules/attendance.md` (in full), `modules/dashboard.md`, `modules/tenancy.md`, `modules/people.md`, `modules/identity-and-access.md`
- `permissions.md`, `configuration.md`, `how-to/demo-school.md`
- the generated blocks

**How to test:**

1. `cd infra && docker compose exec api php artisan migrate`, then `php artisan identity:sync-roles --with-new-permissions` for schools made earlier, and `php artisan db:seed --class=DemoSchoolSeeder` for the demo school.
2. `docker compose exec api composer test` (or `php artisan test tests/Feature/Attendance`), `composer lint`, `php artisan report:generate --check`.
3. With a signed-in class teacher token: `GET /api/v1/attendance/sections/{id}/days/{today}`, then `PUT` the same path with `{"statuses": {"<student_id>": "A"}}`; the parent's inbox (`GET /api/v1/me/notifications`) shows the absence.

## 2026-11-27 · Session 2 · Fixes and Phase 3

**What changed:**

- **Sensitive custom fields are encrypted at rest** (`Support/Privacy/SensitiveValue`: `seal`, `open`, `MASK`, with `Crypt::encryptString`). They stay masked in lists and profiles; `GET /students/{id}` returns `sensitive_values`, decrypted, only for people who may fill the field, and the profile shows them after **Show**. `php artisan customisation:seal-sensitive` seals values stored before (safe to run again).
- **Notices (Phase 3):**
  - audiences school, role, class and section, checked on the server for sending and reading
  - read receipts for the sender and the office
  - attachments limited by type, size (5 MB) and count (5), stored under `tenants/<tenant>/notices/<id>/`, served only through 5-minute relative signed links after a permission check
- **Notifications:**
  - in the app (with the bell live over Reverb on the private channel `school.{tenant}.user.{user}`, carrying only id, type and unread count), by email, and by push
  - push and email carry generic text only, because lock screens and inboxes are not private
  - per-person preferences: push on, email off, types can be muted
  - `PUSH_DRIVER`: `log` or `array` only in local and testing, `none` by default elsewhere
- **My Dashboard, by role:** cards built on the server from permissions and scopes (`CardProvider`, `DashboardContext` with `?as=` for the role switcher); providers `SchoolAdminCards`, `PeopleCards`, `NoticeCards`; the "New module available" banner for the Chief (the console never writes school settings); `GET /me/dashboard` and `GET /me/search`.
- **Fixes:**
  - `GET /me` returns `tenant_id` (for the socket channel)
  - set-password and licence activation accept an optional `language`
  - a Policy's `denyAsNotFound` now answers `404 not_found` instead of 403
- **Bundle size:** the main bundle went from **851.22 kB (gzip 250.64 kB)** to **511.64 kB (gzip 163.56 kB)** by splitting every route into its own chunk and lazy-loading the sign-in forms. [frontend.md](frontend.md) carries the detail.

**Why:** Phase 3 of `docs/BUILD-PROMPTS.md`, the blueprint's "fields marked sensitive are encrypted and masked", and fixes found while building it.

**Files:**

- **Back end:**
  - `backend/app/Modules/{Communication,Dashboard}/**` (new), `backend/app/Modules/Customisation/Console/SealSensitiveValuesCommand.php` (new)
  - `backend/app/Support/{Privacy/SensitiveValue.php,Push/*}` (new), `backend/app/Support/Http/ApiExceptionRenderer.php`
  - `backend/app/Modules/People/{Domain/StudentRecords.php,Http/Controllers/StudentController.php}`
  - `backend/app/Modules/Identity/{Http/Controllers/AuthController.php,Http/Controllers/MeController.php,Models/User.php}`
  - `backend/app/Modules/Licensing/Http/Controllers/ActivationController.php`
  - `backend/config/{communication,dashboard}.php`, `backend/bootstrap/providers.php`, `backend/phpunit.xml` (`PUSH_DRIVER=array`)
  - `backend/database/migrations/2026_11_29_000001_create_communication_collections.php`
- **Tests:** `backend/tests/Feature/{Communication/NoticesTest,Dashboard/DashboardTest,Customisation/SensitiveFieldsTest}.php`, `backend/tests/Feature/Identity/RoutePermissionTest.php`
- **Front end and packages:**
  - `frontend/src/features/{dashboard,notices,notifications}/**` (new), `frontend/src/features/{shell,auth,people}/*`, `frontend/src/app/routes.tsx`, `frontend/src/locales/*`
  - `packages/api/{openapi.json,src/schema.ts}`, `packages/ui/*`

**Report pages updated:**

- `modules/communication.md` (in full), `modules/dashboard.md` (new), `modules/customisation.md`, `modules/identity-and-access.md`, `modules/licensing.md`, `modules/people.md`
- `configuration.md`, `permissions.md`, `decisions/0012-notices-and-notifications-privacy.md` (new)
- `frontend.md` (the screens and the bundle split)
- the generated blocks

**How to test:**

1. `cd infra && docker compose exec api php artisan migrate`, then `docker compose exec api php artisan customisation:seal-sensitive` once for data made earlier.
2. `docker compose exec api composer test`, `composer lint`, `php artisan report:generate --check`; `docker compose exec web npm test -w @campus-tab/frontend`, plus `lint`, `typecheck` and `build` (check the main chunk size in the build output).
3. In the browser, sign in as the demo class teacher, send a notice with a PDF to 7-B, then sign in as a 7-B parent: the bell shows it live, the attachment opens, and the teacher sees the read receipt. Check `storage/logs/laravel.log`: the push line carries only the generic text.

## 2026-11-27 · Phase 2 · School setup, people and customisation (items 1–7)

**What changed:**

- **Academic Structure (item 1):**
  - years, one of them current
  - classes and sections, each section with a wing and a class teacher
  - subjects, and teaching assignments (one teacher per subject-section)
- **People (item 2):**
  - students, guardians, enrolments, staff and consents
  - siblings share a guardian with the same phone
  - PEN and APAAR are kept only with a consent record
- **Excel import (item 3):**
  - an .xlsx template that includes the school-wide custom fields
  - a queued dry run (`RunImport` on Horizon) with progress and a downloadable report: ok, error or duplicate per row
  - duplicates checked on name + date of birth + guardian phone
  - a queued commit, where every record carries `meta.import_batch_id` in the audit log (`AuditRecorder::withMeta`) and one `import.committed` entry closes it
- **Custom fields (item 4):**
  - owner level and scope: school, wing, section, subject-section
  - values in `custom`; section columns sit on the year's enrolment
  - archive instead of delete; promote to school-wide (Principal or Chief)
  - level and scope checked on every change
  - at most 50 per scope; duplicate labels blocked
- **Screens (items 5 and 6):** setup wizard, student list (TanStack Table with custom columns and a column chooser), student profile with tabs, admit, staff list and profile, custom-field manager, import, student setup codes. See [frontend.md](frontend.md).
- **Demo school (item 7):** `DemoSchoolSeeder`, Sunrise Public School (`SUNRISE-BPL`), with the made-up people of `docs/design/`.
- **Owner's rules:**
  1. **Scope resolvers** come from records:
     - **child:** guardians linked to the account
     - **class:** sections' class teacher
     - **subject:** teaching assignments
     - **wing:** the staff record's wings

     Only the current year counts. The stored `users.access` field is gone. `GET /me` returns `scope`. Each resolver has its own test.
  2. **Parent login** on a guardian makes one Parent account (phone required, SMS-code sign-in) for all that guardian's children. A teacher who is also a parent gets the Parent role added instead.
  3. **The Clerk's draft for the Principal:** no account until the Principal approves; then it is made with the drafted roles, checked against the approver's level. Drafts can also be rejected.
  4. **Student sign-in** is the setting `sign_in.methods` containing `student_login`, off by default. Class teachers print setup codes (48 hours); students sign in with a login name made from the admission number. With the setting off, student password sign-in gets the same answer as a wrong password.
  5. **Aadhaar:**
     - `no-aadhaar` middleware on every school request refuses any field holding a full Aadhaar number (12 digits passing the Verhoeff check), alone or in text; import cells are checked too
     - custom fields for Aadhaar or bank numbers are refused (English and Hindi)
     - only `ids.aadhaar_last4` may be kept
  6. **Import** is queued with progress; the report can be downloaded; the commit is audited under one batch id.
  7. **Seats** (see the part C addendum below for staff seats): past the licence is refused with a clear message, and the dry run shows it first.
  8. **The demo seeder's one known dev password** (`sunrise-demo-2026`); it refuses to run outside `APP_ENV` local or testing. The sign-ins are in [how-to/demo-school.md](how-to/demo-school.md).
- **Also:**
  - school logo upload (`POST /config/logo`; served by `GET /public/tenants/{code}/logo`; SVG refused)
  - `identity:sync-roles --with-new-permissions` gives existing schools the Phase 2 permissions
  - new abilities: `academic.*`, `students.admit`, `students.login_codes`, `guardians.manage`, `staff.*`, `imports.*`
  - `packages/api`: `unwrap()` now builds errors from the body openapi-fetch already read. Before, every non-401 error showed as a network error.

**Why:** Phase 2 of `docs/BUILD-PROMPTS.md`, with the owner's rules 1–8.

**Files:**

- **Back end:**
  - `backend/app/Modules/{AcademicStructure,People,Customisation}/**` (new)
  - `backend/app/Support/{Models/SchoolModel.php,Privacy/*}`
  - `backend/app/Modules/Identity/{Domain/Scopes/{AccessScopeSource,SubjectScope}.php,Domain/{SignInService,PasswordLinks,SystemRoles}.php,Console/SyncRolesCommand.php,Models/{User,SchoolRole}.php,Http/Controllers/MeController.php}`
  - `backend/app/Modules/Tenancy/{Http/Controllers/SchoolLogoController.php,routes.php,Schemas/school-config.schema.json}`
  - `backend/app/Modules/Audit/Domain/AuditRecorder.php`
  - `backend/bootstrap/{app,providers}.php`
  - `backend/config/{permissions,audit}.php`
  - `backend/database/migrations/2026_11_28_000002_create_people_and_structure_collections.php`
  - `backend/database/seeders/DemoSchoolSeeder.php`
- **Tests:**
  - `backend/tests/Concerns/BuildsSchoolRecords.php`
  - `backend/tests/Feature/{People,Customisation}/*`
  - `backend/tests/Feature/Tenancy/SchoolLogoTest.php`
  - `backend/tests/Feature/Identity/{SyncRolesTest,RolesAndPermissionsTest,RoutePermissionTest}.php`
- **Front end and packages:**
  - `frontend/src/features/{setup,people,customisation,academic}/**`
  - `frontend/src/{lib/requests.ts,features/auth/permissions.ts,app/routes.tsx,features/shell/navItems.ts,locales/*}`
  - `packages/api/{openapi.json,src/*}`

**Report pages updated:**

- `modules/academic-structure.md`, `modules/people.md`, `modules/customisation.md` (in full)
- `modules/identity-and-access.md`, `modules/tenancy.md`, `modules/audit.md`
- `permissions.md` (Phase 2 abilities), `configuration.md`, `operations.md`, `frontend.md`
- `decisions/0011-people-privacy-and-imports.md`, `how-to/demo-school.md`, `how-to/README.md`
- the generated blocks

**How to test:**

1. **Containers and data:**
   - `cd infra && docker compose up -d` (it now includes `scheduler`)
   - `docker compose restart horizon`
   - `docker compose exec api php artisan migrate`
   - `docker compose exec api php artisan identity:sync-roles --with-new-permissions` (for schools made earlier)
   - `docker compose exec api php artisan db:seed --class=DemoSchoolSeeder`
2. **Checks:** `docker compose exec api composer test`, `composer lint`, `php artisan report:generate --check`. Then `docker compose exec web npm test -w @campus-tab/frontend`, plus `lint`, `typecheck`, `build` and `npm run check -w @campus-tab/api`.
3. **In the browser:** follow the Phase 2 steps in the session summary, then sign in as each demo role from [how-to/demo-school.md](how-to/demo-school.md).

## 2026-11-27 · Phase 1 part C, addendum · Client records, numbers, alerts, renewals and two seat limits

**What changed:**

- **Two seat limits on a licence:**
  - students: active, not left, not in the recycle bin
  - staff: every account that is not inactive, except accounts that are only Parent and/or Student; parents never count
- **Where they are checked:** creating users, staff, approved drafts, both imports, turning a parent into staff, restoring from the recycle bin, and re-activating. The refusal is `422 seat_limit_reached` with `details.kind`, and the dry-run report shows the check first. `Seats` moved from People to `Licensing\Domain\Seats`.
- **Client record per school:** contact, package, price, billing period, paid-until, notes, plus an append-only payment history (corrections are negative entries with a note). Every change is in the platform audit log.
- **School list and page:**
  - plan, licence state and end date, modules
  - students and staff against seats
  - active users in 7 and 30 days, last activity, storage
  - licence history (issued, renewed, changed, revoked), support grants (our agent, scope, times, status), the client record and payments
- **Security overview, counts only:**
  - failed sign-ins, lockouts, password resets, MFA resets, Chief added or removed
  - counted for the last hour, 24 h, 7 days and 30 days
  - **alerts:** a metric reaching its minimum and 5 times its usual rate in the last hour raises one, emailed to every platform admin with counts only, and shown at `/console/alerts`
- **Where the numbers come from:** the job `CollectSchoolStats` (every 15 minutes, new `scheduler` container; `platform:collect-stats` by hand) works them out inside each school with `TenantContext::run` and stores only numbers in `school_stats`.
  - The console no longer reads any school collection: the chief and user counts it used to read are gone.
  - Reading school data still needs a support grant; a test checks that a console session opens no school endpoint.
- **Renew** (`POST /platform/schools/{code}/licence/renew`) and changes to seats, plan or modules take effect at once and go in both audit logs.
- **Modules:** a removed module stops at once; an added module is switched on by the Chief, because the console never writes school settings.

**Why:** the owner's additions to part C and to the Phase 2 seat rule.

**Files:**

- **Back end:**
  - `backend/app/Modules/Licensing/Domain/Seats.php`
  - `backend/app/Modules/Platform/{Domain/{SchoolStatsCollector,SecurityAlerts,SchoolConsole}.php,Jobs/CollectSchoolStats.php,Console/CollectStatsCommand.php,Models/{SchoolClient,SchoolPayment,SchoolStats,PlatformAlert}.php,Mail/SecurityAlertMail.php,Http/Controllers/{ConsoleSchoolController,ConsoleAlertController}.php,routes.php}`
  - `backend/app/Modules/{Identity/Domain/UserAdmin.php,Audit/Domain/RecycleBin.php,People/Domain/*}`
  - `backend/routes/console.php`, `backend/config/{identity,tenancy}.php`
  - `backend/database/migrations/2026_11_28_000003_create_platform_client_and_stats_collections.php`
- **Tests:** `backend/tests/Feature/{Platform/ConsoleClientsAndStatsTest,People/SeatsTest}.php`
- **Infra:** `infra/docker-compose.yml` (`scheduler`)
- **Front end:** `frontend/src/features/console/{ConsoleSchoolExtras.tsx,ConsoleSchoolsPages.tsx,ConsoleLayout.tsx,api.ts,console.test.tsx}`, locales

**Report pages updated:** `modules/platform.md`, `modules/licensing.md`, `modules/people.md`, `modules/identity-and-access.md`, `modules/audit.md`, `configuration.md`, `operations.md`, `frontend.md`, `decisions/0010` (addendum).

**How to test:**

1. `docker compose up -d scheduler`, then `docker compose exec api php artisan platform:collect-stats`.
2. Open a school in the console: the usage, security, client, payments, history and grants cards are there.
3. Renew it and check the school's audit log (as its Chief) for `platform.licence.renewed`.

## 2026-11-27 · Phase 1 part C · Platform console, sign-in screens, API client (items 10–12)

**What changed:**

- **Parallel tests.** `composer test` runs `pest --parallel` (paratest).
  - Each process has its own database `campus_tab_<n>_test`, named before the app boots. Purging the connection after boot would drop the tenant query guard's listener; that was caught and fixed on the way.
  - `RefreshMongoDatabase` migrates once per process, then empties the collections between tests.
  - The full suite went from about 7.5 minutes to about 1 minute.
- **Platform console (item 10).** New `Platform` module:
  - **Admins** come only from `php artisan platform:create-admin` (invited, no password, a one-time link for 48 h; `--resend`).
  - **Sign-in** needs the password and a TOTP code every time; the first sign-in is enrol-only. Lockout and IP limits match school sign-in.
  - **Sessions:** session cookie only (guard `console`), 15 minutes idle, 8 hours in total, remote sign-out, step-up.
  - **Schools and licences:** list and create schools; issue a key (shown once); change plan, seats, modules and end date (payload signed again); revoke (step-up plus the school code typed again).
  - **`platform:revoke-licence`** does the same from the command line. `platform:create-school` and `platform:issue-licence` are now audited too.
  - **Audit:** every action goes in the new append-only `platform_audit_logs`. Every action on a school also goes in that school's `audit_logs`, as `platform.<action>` with actor type `platform_admin`.
  - **Sole-Chief recovery**, only with exactly one active Chief:
    - step-up, then a code to the school's registered contact, then the code plus a written reason (20+ characters)
    - it resets only the Chief's MFA (still required) and ends the Chief's sessions; the password is never touched
    - it is a security event in the school's log
- **Idle time counts only real use.** Requests with `X-Background-Request: 1` are checked but never extend a school or console session. Tests cover both.
- **Screens (item 11):**
  - school code with public branding
  - sign-in with password, then an MFA or recovery code
  - parent SMS-code sign-in
  - MFA enrolment: a QR code drawn in the browser, the key for typing in, recovery codes once with download and print
  - licence activation
  - set, reset and forgot password
  - device sessions with remote sign-out
  - the console
  - an idle warning one minute ahead, and sign-in over the page when the session ends (the page stays mounted, so typed input is kept)
  - light and dark themes, phone and desktop widths, every string in English and Hindi
- **No token in the browser.** The web app uses the session cookie only; a test checks that nothing but display preferences and the public school code is stored.
- **API client (item 12).** Scramble exports the OpenAPI spec to `packages/api/openapi.json`, and `openapi-typescript` and `openapi-fetch` build the typed client in `packages/api`. CI fails when either is out of date. The api container now mounts `packages/api`.
- **Part B test:** an invited account or a parent account (no password, or an empty one stored) never signs in by password, whatever is typed.
- **CI:** `.github/scripts/check-report.mjs` now maps the `Identity` module to `identity-and-access.md` (it looked for `identity.md`).

**Why:** Phase 1 items 10–12 of `docs/BUILD-PROMPTS.md`, with the owner's rules for part C.

**Files:**

- **Back end:**
  - `backend/app/Modules/Platform/**` (new)
  - `backend/app/Modules/Audit/{Domain/PlatformAuditRecorder.php,Models/PlatformAuditLog.php,Models/AuditLogBuilder.php,Domain/AuditRecorder.php}`
  - `backend/app/Modules/Identity/{Domain/Mfa.php,Domain/SessionIssuer.php,Http/Middleware/TrackDeviceSession.php,Models/PlatformAdmin.php}`
  - `backend/app/Modules/Licensing/{Domain/LicenceIssuer.php,Domain/ActivationService.php,Console/IssueLicenceCommand.php}`
  - `backend/app/Modules/Tenancy/{Console/CreateSchoolCommand.php,Models/Tenant.php}`
  - `backend/app/Providers/AppServiceProvider.php`, `backend/bootstrap/app.php`
  - `backend/config/{auth,identity,tenancy,scramble}.php`
  - `backend/database/migrations/2026_11_28_000001_create_platform_console_collections.php`
  - `backend/composer.{json,lock}` (`dedoc/scramble`, `openspout/openspout`, dev `brianium/paratest`)
- **Tests:**
  - `backend/tests/{TestCase.php,Concerns/RefreshMongoDatabase.php}`
  - `backend/tests/Feature/Platform/ConsoleTest.php`
  - `backend/tests/Feature/Identity/{BackgroundAndPasswordlessTest.php,RoutePermissionTest.php}`
- **Front end and packages:**
  - `packages/api/**`
  - `frontend/src/{lib/api.ts,stores/{activity,session,school}.ts,components/ui/form.tsx,features/auth/**,features/console/**,features/shell/{AppShell.tsx,SchoolCard.tsx,navItems.ts},app/routes.tsx,locales/{en,hi}/common.json,test/*}`
  - `frontend/{package.json,tsconfig.app.json}`, `package-lock.json`
- **Infra and CI:** `infra/docker-compose.yml`, `.github/workflows/ci.yml`, `.github/scripts/check-report.mjs`

**Report pages updated:**

- `modules/platform.md` (new), `decisions/0010-platform-console.md` (new)
- `modules/identity-and-access.md`, `modules/audit.md`, `modules/licensing.md`, `modules/tenancy.md`
- `frontend.md`, `operations.md`, `configuration.md`, and the generated blocks

**How to test:**

1. `cd infra && docker compose up -d api horizon reverb web` (recreates the api containers with the new `packages/api` mount), then `docker compose exec api php artisan migrate`.
2. `docker compose exec api composer test` (parallel, about a minute), `composer lint`, `composer analyse`, `php artisan report:generate --check`. Then `docker compose exec web npm test -w @campus-tab/frontend`, plus `lint`, `typecheck`, `build` and `npm run check -w @campus-tab/api`.
3. In the browser, follow the Phase 1 "done when" steps at the end of the session summary: create an admin, sign in at http://127.0.0.1:5173/console, create a school, issue its key, activate it at `/activate`, and sign in as its Chief with MFA.

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
