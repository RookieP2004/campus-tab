# Dashboard

> Status: **built in Phase 3** (with the attendance cards added in Phase 4). It is not one of the blueprint's thirteen business modules: it gathers cards from the others.

## 1. What it does (plain words, no code)

The home screen ("My Dashboard, by role") and the quick search (Ctrl/Cmd K).

- **Cards are built on the server** from the person's permissions and scopes, and filled only with what they may see. The screen shows what it is given and never filters.
- **Each role gets its own top card:**
  - Chief: the licence and seats
  - Principal: approvals waiting (staff drafts now; leave and marks in later phases)
  - Class teacher: today's register for their class
  - Parent: their children, for the child switcher
  - Student: their own record
- **Role switcher.** Someone with two roles (a teacher who is also a parent) switches between them; each view shows only that role's cards. Duties (Fee Collector, Attendance Officer) go with whichever role is viewed. Nobody can view as a role they do not hold.
- **"New module available".** When the licence adds a module the school has not switched on, the Chief sees a banner linking to school settings. The platform console never changes school settings, so the Chief decides.
- **Search** finds students and staff, only among those the person may see.

## 2. Who uses it and from which screens

Everyone signed in to a school: the dashboard is the home route (`/`). Front-end files: `frontend/src/features/dashboard/` (`DashboardPage.tsx`, `cards.tsx`, `api.ts`). The search box is in the shell. See [frontend.md](../frontend.md).

## 3. Requests it handles

Both are in the `account` group with no route permission: the cards and results themselves are the check (each provider asks `DashboardContext::can()`; search asks `students.view` and `staff.view`).

| Method and path | Input | Output |
| --- | --- | --- |
| `GET /me/dashboard` | `as?` (one of your own role names) | `role` (the role viewed), `roles` (your level roles with labels, for the switcher), `cards` sorted by priority |
| `GET /me/search` | `q` (2–60 characters) | up to 8 students (name, section · admission number) and 6 active staff (name, designation), each with a screen link |

A card is `{id, kind, title, params?, priority, link, data}`:

- **kind:** licence, kpi, todo, list, action, banner, children, notices
- **title:** an i18n key the screen translates, with `params`
- **priority:** lower comes first; 0 is the role's top card

## 4. Code map

| File | What it does |
| --- | --- |
| [`Dashboard/routes.php`](../../../backend/app/Modules/Dashboard/routes.php) | The two routes |
| [`Http/Controllers/DashboardController.php`](../../../backend/app/Modules/Dashboard/Http/Controllers/DashboardController.php) | Runs every provider, sorts the cards; search |
| [`Domain/CardProvider.php`](../../../backend/app/Modules/Dashboard/Domain/CardProvider.php) | The interface: `cards(User, DashboardContext): list<card>` |
| [`Domain/DashboardContext.php`](../../../backend/app/Modules/Dashboard/Domain/DashboardContext.php) | The tenant, the role viewed (`?as=`), that role's permissions plus duties, `can()` |
| [`Domain/Cards/SchoolAdminCards.php`](../../../backend/app/Modules/Dashboard/Domain/Cards/SchoolAdminCards.php) | Licence and seats, new-module banners, approvals, accounts by role, recent audit, recycle bin |
| [`Domain/Cards/PeopleCards.php`](../../../backend/app/Modules/Dashboard/Domain/Cards/PeopleCards.php) | On roll, admissions and imports, my class (strength, birthdays this week), my classes, children, my record |
| [`Domain/Cards/NoticeCards.php`](../../../backend/app/Modules/Dashboard/Domain/Cards/NoticeCards.php) | The latest 4 notices the person may read, unread count, whether they may send |
| [`Domain/Cards/AttendanceCards.php`](../../../backend/app/Modules/Dashboard/Domain/Cards/AttendanceCards.php) | Today's register, check-in, attendance today, holidays to approve, below threshold |
| [`config/dashboard.php`](../../../backend/config/dashboard.php) | The list of providers |

## 5. How the code works, step by step

1. **The role viewed.** `DashboardContext::for()` sorts the person's roles by level, highest first. If `?as=` names one of their own roles it uses that, otherwise the highest. An unknown or foreign role in `?as=` is ignored, never honoured.
2. **The permissions.** It takes that role's permissions plus every duty the person holds (roles with no level), through `RoleRegistry::permissionsFor()`.
3. **The cards.** The controller calls each provider in `config('dashboard.providers')`. A provider checks `$context->can('<ability>', '<scope>')` (true when the viewed role holds the ability at that scope or wider) and reads only records inside the person's scope.
4. **Sorting.** All cards are sorted by `priority` and returned with the role list for the switcher.

**Cards by provider:**

| Card id | Shown when | Priority |
| --- | --- | --- |
| `licence` | `config.edit:school` (Chief) | 0 |
| `new_module.<module>` | Chief, licensed module not switched on | 1 |
| `approvals` | `staff.approve` | 0 for the Principal, 5 for the Chief |
| `users_by_role` | `users.view:school` and `roles.edit` | 20 |
| `recent_audit` | `audit.view:school` | 40 |
| `recycle_bin` | `records.restore` | 45 |
| `on_roll` | `students.view:school` | 10 |
| `admissions` | `students.admit` | 12 |
| `my_class.<section>` | viewing as class teacher | 3 |
| `my_classes` | viewing as subject teacher | 3 |
| `children` | viewing as parent | 0 |
| `my_record` | viewing as student | 0 |
| `notices` | `notices.view` | 30 |
| `take_attendance.<section>` | attendance on; viewing as class teacher with `attendance.mark:class` | 0 |
| `check_in` | attendance on; `staff_attendance.mark:own` and an active staff record | 2 |
| `attendance_today` | attendance on; `attendance.view` at wing or wider | 4 |
| `calendar_approvals` | attendance on; `calendar.approve` and holidays pending | 1 |
| `below_threshold` | attendance on; `attendance.view` at class or wider, not a parent or student view | 14 |

## 6. Data it reads and writes

It writes nothing. It reads licences, school config, staff, students, enrolments, sections, teaching assignments, import batches, users, the audit log and the recycle bin, plus notices and attendance through their own modules' code.

## 7. Links to other modules

- **Calls:** Identity (`RoleRegistry`, `AccessControl`, `PermissionCatalogue::scopeCovers`), Licensing (`Licence`, `Seats`), Tenancy (`SchoolConfigService`), People (`StudentRecords::list()` for search), Audit (`RecycleBin`, `AuditLog`), Communication (`Notices::listFor()`), Attendance (`AttendanceReports`, `AttendanceSettings`).
- **Called by:** nobody; the front end only.

## 8. Settings that change its behaviour

- **`config/dashboard.php` `providers`:** which providers run, in order (`SchoolAdminCards`, `PeopleCards`, `NoticeCards`, `AttendanceCards`).
- **School config `modules.attendance`:** with it off, no attendance card appears.

## 9. Permissions and the rules behind them

No new abilities. Every card names the ability it needs (section 5). **Fixed rules:** cards are built on the server; `?as=` accepts only the person's own roles; search returns only students the person's `students.view` scope covers and staff only with `staff.view`.

## 10. How to change it

- **A new card:** add it to the module's provider, or write a new class implementing `CardProvider` and list it in `config/dashboard.php`. Check `$context->can()` first, read only scoped records, and give it an i18n title key that the front end translates.
- **A new card kind:** add a renderer in `frontend/src/features/dashboard/cards.tsx`.

## 11. Tests that cover it

[`tests/Feature/Dashboard/DashboardTest.php`](../../../backend/tests/Feature/Dashboard/DashboardTest.php):

- the Chief gets the licence first, and a card for a module the licence added that is not switched on
- approvals first for the Principal, with the Clerk's staff drafts
- each role gets only its own cards
- switching between a person's own roles, never to a role they do not hold
- search finds only what the person may see
- the class teacher's register for today at the top, and today's attendance on the Principal's screen

## 12. Decisions and limits

- Approvals show staff drafts only; leave and mark approvals join in Phases 5 and 6.
- Cards are computed on every request; there is no caching yet.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 3 | Page and module created: server-built cards by role, role switcher, new-module banner for the Chief, search | Phase 3 "My Dashboard, by role" |
| 2026-11-27 | 4 | `AttendanceCards`: today's register, check-in, attendance today, holidays to approve, below threshold | Phase 4 attendance |
