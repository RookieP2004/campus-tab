# Campus TAB — Build prompts for Claude Code

These are the prompts that build Campus TAB, in order: ten phases, each a working piece of the app with its own "done when" test. Paste them into Claude Code one phase at a time. The full plan behind them is in `docs/BLUEPRINT.md`; the screen designs are in `docs/design/`.

---

## 1. One-time setup

1. **Install on your computer:** Git, Docker Desktop, Node.js 22 or newer, and Claude Code. PHP and Composer run inside Docker, so you don't need them installed.
   Later, for Phase 9 only: Android Studio (Android app), Xcode on a Mac (iPhone and Mac app), and Rust (Windows and Mac desktop app).
2. **Create a private GitHub repository** under RookieP2004, for example `campus-tab`, and clone it to your computer.
3. **Unzip this starter pack into the repository folder.** It brings:
   - `docs/BLUEPRINT.md` — the full plan
   - `docs/BUILD-PROMPTS.md` — this file
   - `docs/design/` — the screen designs (light and dark) and how to read them
   - `packages/ui/assets/` — the Campus TAB logo files
   - `.gitignore` — already keeps `.claude/`, `CLAUDE.md` and secrets out of your pushes
4. **Commit and push this first version yourself:**
   ```bash
   git add .
   git commit -m "Blueprint, designs and starter files"
   git push
   ```
5. **Open a terminal in the repository folder and start Claude Code.**

## 2. How every session works

- **One phase per session.** Start a fresh Claude Code session for each phase.
- **From Phase 1 on, paste the session starter first** (below), then the phase prompt.
- **Read the plan before saying yes.** Claude Code shows a plan first; reply "go ahead", or tell it what to change.
- **Big phase? Split it.** Add a line such as *"Do items 1 to 4 now, then stop and show me."* Then paste *"Continue with items 5 to 9"* in the same session, or use the Continue prompt in a new one.
- **Check "done when" yourself.** Claude Code ends by telling you how to check each item (which commands to run, which screen to open). Do those checks.
- **Read the change log.** Every session adds an entry to `docs/report/CHANGELOG.md` saying what changed, why and how to test it.
- **You commit and push.** One branch per phase:
  ```bash
  git checkout -b phase-1-tenancy     # before the session
  git add . && git commit -m "Phase 1: tenancy, licensing, identity"   # after checking
  git push -u origin phase-1-tenancy  # then merge on GitHub
  ```
- **Never paste real student data, passwords or API keys into a session.** Keys go in your `.env` file, which is never pushed.

### Session starter — paste first, every session from Phase 1

```text
Read docs/CONVENTIONS.md and follow it for this whole session. Then read the docs/BLUEPRINT.md sections named in my next message and the docs/report/ pages for the modules you will touch. Show me a plan before you change any file. Do not run git commit or git push. When you finish, update docs/report/ (the module pages you touched and a CHANGELOG.md entry) and tell me exactly how to check each "done when" item.
```

---

## 3. The phase prompts

### Phase 0 — Foundations

```text
Read docs/BLUEPRINT.md fully before writing code. Set up the Campus TAB monorepo from "System architecture > Repository layout". Show me a plan first.

Ground rules for this and every later session:
- Never run git commit or git push, and never open pull requests. Only write files; the owner commits and pushes.
- No file or folder name may contain "claude". .claude/ and CLAUDE.md are already in .gitignore; keep them there.
- No AI or tool credit anywhere: not in code comments, commit messages, README, package metadata or licence files. Author and copyright: Sujal Chandel.
- Write these ground rules, plus the coding rules below, into docs/CONVENTIONS.md.
- Start docs/report/ with the structure in "Application Report" and update it at the end of every session: the module pages touched plus a CHANGELOG.md entry. Add a `php artisan report:generate` command and a CI check that fails a code change without its report update.

Build:
- backend/: Laravel 13 on PHP 8.3+, API-only. Install mongodb/laravel-mongodb ^5.7, Sanctum, Horizon, Reverb. Redis for cache and queues.
- frontend/: React 19 + TypeScript + Vite, Tailwind CSS v4, shadcn/ui, React Router, TanStack Query, Zustand, React Hook Form + Zod, i18next with English as the default and a per-user language choice; ship en and hi first, structured for every regional language including right-to-left Urdu.
- packages/ui/: the "Theme tokens" table as CSS variables for light and dark themes, using the logo files already in packages/ui/assets/.
- infra/docker-compose.yml: MongoDB 8 as a single-node replica set (rs0), Redis, Mailpit, the API and the Vite dev server. One `docker compose up` starts everything; PHP and Composer run inside containers.
- A test helper that drops and recreates the test database between tests (Laravel's RefreshDatabase does not work with MongoDB).
- GitHub Actions: Pint, PHPStan level 6, Pest, ESLint, tsc, Vitest, build.
- README.md: what Campus TAB is, how to run it locally, where the docs are.

Coding rules for docs/CONVENTIONS.md: every school model uses a BelongsToTenant trait; tenant_id comes only from the signed-in user; every permission check goes through a Policy; soft deletes on every school model; every write is audit-logged; fee records are append-only (reverse, never edit); a locked mark changes only through a correction the Principal approves; never store full Aadhaar numbers; every user-facing string goes through i18next; every screen works in light and dark themes.

Done when: docker compose up serves GET /api/v1/health returning {"status":"ok","db":"ok","queue":"ok"}; the React app shows an empty shell with the Campus TAB logo and name in the brand stack, in light and dark; CI is green; and docs/report/ holds its README, the overview and the Phase 0 change-log entry.
```

### Phase 1 — Tenancy, licensing, identity and access

```text
Read docs/BLUEPRINT.md sections "Multi-tenancy, licence keys and the JSON config", "Role hierarchy and authority", "Permission matrix" and "Security, privacy and compliance". Build modules Tenancy, Licensing, Identity and Audit.
1. Platform collections: tenants, licences, support_grants, school_configs. Platform admins are separate from school users.
2. Licence keys: human format CTAB-XXXX-XXXX-XXXX-XXXX, store only a hash, Ed25519-signed payload (keys from env). POST /licences/activate with OTP creates the Chief Admin and forces MFA enrolment. Expiry grace, then read-only middleware. Disabled modules return 403 module_disabled.
3. GET /public/tenants/{code}/config returns public branding only.
4. School config: versioned documents, JSON-schema validation, diff and rollback.
5. BelongsToTenant global scope and creating hook, plus a test that fails if any query runs without tenant_id.
6. Auth: Sanctum (SPA cookie and app tokens), TOTP MFA mandatory for level >= 60, step-up MFA for sensitive actions, parent OTP sign-in behind an SMS driver interface (log driver in dev), device sessions with remote logout, lockout after failed attempts, and per-user language and theme settings (defaults: English, Match device).
7. RBAC: permissions as module.action:scope; seed the ten system roles and the default matrix; scope resolvers for own, child, subject, class, wing, school; no-escalation and last-Chief guards.
8. Audit observer (before/after, actor, IP, device); soft delete with recycle bin and restore; permanent purge for the Chief only, with typed confirmation.
9. Support grants: the Chief creates time-boxed, scoped grants; platform agents act only inside an active grant; everything logged.
10. Platform console for our company: artisan commands platform:create-school, platform:issue-licence and platform:revoke-licence, plus a minimal web console (platform admins only, MFA) to list schools, issue and revoke keys, and set plan, seats and modules.
11. React screens: school-code entry that loads branding from the public config; sign-in (password and MFA, OTP for parents); MFA enrolment; licence activation; device sessions.
12. Generate the TypeScript API client into packages/api from the OpenAPI spec and use it in the frontend.
Tests (Pest): cross-tenant read and write blocked; escalation blocked; last Chief protected; expired licence is read-only; support access denied without a grant.
Done when all tests pass, the OpenAPI spec lists every endpoint, and a new school can be created in the console, activated with its key, and its Chief can sign in with MFA.
```

### Phase 2 — School setup, people and customisation

```text
Read "Data model", "Customisation engine" and "Role hierarchy and authority". Build modules Academic Structure, People and Customisation.
1. academic_years, classes, sections (wing, class_teacher_id), subjects, teaching_assignments.
2. students, guardians (siblings share a guardian; a guardian can have a parent login), enrolments, staff. PEN and APAAR optional, each with a consents record; never a full Aadhaar number.
3. Excel import for students and staff: downloadable template, dry-run validation report, then commit; duplicate check on name + date of birth + guardian phone.
4. Custom field engine: definitions with owner_level and scope (school, wing, section, subject-section); values in `custom`; archive instead of delete; promote to school-wide; level checks on every change.
5. React setup wizard: profile and logo, year, classes and sections, subjects, assignments, import.
6. React screens: student list (TanStack Table with custom columns and a column chooser), student profile with tabs, staff list and profile, custom-field manager filtered by the user's level.
7. A demo-school seeder: Sunrise Public School, Bhopal (CBSE), year 2026-27, using the made-up staff, classes and students in docs/design/. Later phases add their own demo data to it.
Done when a Chief sets up a school from zero, imports 500 students from Excel, a class teacher sees a class-only column that a subject teacher cannot, and `php artisan db:seed --class=DemoSchoolSeeder` loads the demo school.
```

### Phase 3 — App shell, dashboards and notices

```text
Read "UI/UX: design system and dashboards" and docs/design/README.md. Build the shell and dashboards. Match the layouts, spacing and colours in docs/design/screens/ (light and dark): they are design references, not code to copy.
1. Responsive shell: sidebar at >= 1024 px, icon rail at 768-1023 px, bottom tabs under 768 px. Brand stack top-left: the Campus TAB logo and name, then the school logo and name from config, then the academic-year switcher. Top bar: search (Ctrl/Cmd K), quick create, notifications, profile menu with role switcher, language picker and theme picker (Light, Dark, Match device; saved per user).
2. packages/ui components: status chip (8 states), KPI card, to-do list, empty state, typed-confirmation dialog, approval timeline.
3. GET /me/dashboard returns the cards in the "My Dashboard, by role" table; every card links to its screen. The Principal dashboard follows docs/design/screens/light/Main.dc.html and the parent home follows ParentHome.dc.html.
4. Notices with audience (school, class, section, role), attachments and read receipts.
5. Notification service: in-app via Reverb, push (FCM interface), email; per-user preferences.
6. Every string goes through i18next. English is the default; a language picker at sign-up and in settings saves each person's choice.
Done when every school role signs in at phone and desktop widths and sees its own dashboard, the theme picker switches light and dark everywhere, and Lighthouse accessibility scores 95+ in both themes.
```

### Phase 4 — Attendance

```text
Read "Core workflows > Attendance". Build the Attendance module. The class teacher's phone screen follows docs/design/screens/light/TeacherAttendance.dc.html.
1. attendance_days per section per date (per period in period mode), entries embedded; mode from school config.
2. Taker screen: everyone present by default, tap to toggle, codes P/A/L/HD; leave and holidays pre-filled; online only. If the internet fails, the teacher keeps the register on paper and enters it once the problem is fixed, on the same screen or by Excel upload (template, dry-run check, then commit), within a set number of days (config, default 7). A late entry needs a reason, can carry a photo of the paper register, only fills a day never marked, shows as a late entry and is audited. Past the limit, a Clerk or the Principal enters it with a reason.
3. Edit window from config; later edits need Clerk or Principal plus a reason; all audited.
4. Parent notification on absence. For a late-entered day it goes out when the entry is saved and names the date ("Aarav was absent on 3 December").
5. Staff check-in: app button (optional geofence), office QR, CSV import from biometric devices, and manual entry by the Attendance Officer (a duty the Principal gives, usually to the Clerk) for staff who couldn't check in online, with time and reason; late rules. A manual entry always comes from someone at a higher level than the person it is for, never from the person themself: the Attendance Officer records teachers and staff; the Vice Principal or Principal records the Clerk; the Principal records the Vice Principal; the Chief records the Principal. Enforce it as the same level check as the user-management guard, with a test for each pair.
6. Geofenced check-in stores only whether the check-in was inside the area, never the raw location.
7. Holiday calendar: Clerk maintains, Principal approves.
8. Reports: daily summary, printable monthly register, attendance % per student, below-threshold alert.
Done when a teacher marks a class of 45 in under 30 seconds on a phone; a teacher enters a missed day from the paper register with a reason and it shows as a late entry; the Attendance Officer records a staff check-in but cannot record their own; and a late edit without permission is refused.
```

### Phase 5 — Leave

```text
Read "Core workflows > Staff leave" and "Student leave". Build the Leave module. The Principal's approvals screen follows docs/design/screens/light/Approvals.dc.html.
1. leave_types and policies set by the Principal; leave_ledger with reserve, deduct and release entries.
2. leave_requests state machine: draft, submitted, returned, verified, approved, rejected, withdrawn, cancelled. Chain from config; own-leave routing (Clerk -> Principal, Principal -> Chief); fast-track; clerk_can_reject flag; dated delegation.
3. Approval, ledger and attendance updates run in one MongoDB transaction.
4. Scheduled reminders at 24 h and escalation at 48 h, both from config.
5. Screens: apply form with live balance, request timeline, Clerk queue, Principal queue with leave calendar, shared approvals inbox.
6. On approval: staff attendance set to On leave; a needs-cover list built from teaching assignments.
7. Student leave: parent applies, class teacher decides, attendance updates.
Done when tests cover every legal transition, illegal ones are refused, balances stay correct under concurrent approvals, and the applicant sees each step live.
```

### Phase 6 — Tests and marks

```text
Read "Core workflows > Tests and marks" and the assessment rows of the permission matrix. Build the Assessment module. The markbook follows docs/design/screens/light/Markbook.dc.html.
1. assessment_schemes (terms, components, weightages, grade scales, rules) with optional starter presets (e.g. CBSE); the Principal builds the school's own board scheme.
2. assessments and papers (section x subject x assessment) with marks embedded; Subject Teachers create class tests within their own scope.
3. Lifecycle draft -> open -> submitted -> verified -> locked -> published, each action permission-checked; optional auto-publish for class tests.
4. Correction requests after lock: Clerk, teachers and the Chief can only request. The change applies only when the Principal approves from their own account, re-confirmed with MFA. Store old mark, new mark, reason, requester, approver and times on the request and in the audit log. Not configurable.
5. Markbook grid: keyboard navigation, paste from Excel, AB/ML codes, max-marks validation, autosave with ETag conflicts, offline; card view on phones.
6. Completion grid for Class Teacher and Principal.
Done when two teachers editing the same paper get a conflict instead of an overwrite, a locked paper refuses edits, a post-lock change without the Principal's approval is refused, and every changed mark shows old and new values in the audit log.
```

### Phase 7 — Report cards and year-end

```text
Read "Core workflows > Yearly report card". Build the Reports module.
1. Grading engine: final results from the scheme (weightage, best-of, grace, rounding, pass rules), unit-tested per starter preset.
2. Template designer with blocks: header (logo and name from config), student details, scholastic table, co-scholastic grades, attendance, remarks, result, signatures. Optional starter presets (CBSE I-VIII, CBSE IX-X, generic state board, HPC-style descriptive block); the Principal can upload the school's letterhead and arrange blocks to match its board's format.
3. Background generation: snapshot the result, render PDF through Gotenberg with Noto fonts, QR code to /verify/{hash}.
4. Class Teacher remarks; Principal review and publish; versioned re-issue.
5. Year-end promotion wizard; roll sections and assignments into the new year as drafts.
Done when 45 report cards generate in the background, every QR verifies, and a re-issued card keeps its old version.
```

### Phase 8 — Fees

```text
Read "Fees" and its "Who can do what" table. Build the Fees module. The fee counter follows docs/design/screens/light/FeeCounter.dc.html and the parent's dues card follows ParentHome.dc.html.
1. fee_heads, fee_categories, fee_plans with instalment schedules, fine rules and discount rules; per-student overrides. Principal and Clerk manage them.
2. A year-start job raises fee_demands per student from their plan; mid-year joiners from their joining month; plan changes affect future instalments only.
3. fee_ledger as append-only entries (demand, payment, adjustment, reversal); balances computed from the ledger; reversals instead of edits.
4. Counter payments by users with the Fee Collector duty: cash, cheque, DD, bank transfer, counter UPI; cheque clearance and bounce flow; sequential receipt numbers per school per year using the config prefix; printable receipt plus a copy in the parent app; day-end cash closing confirmed by Clerk or Principal.
5. Online payments: a gateway account per school with keys encrypted; Razorpay checkout for parents; a signed, idempotent webhook marks dues paid and creates the receipt; nightly reconciliation against the settlement report, mismatches flagged to the Clerk. Use Razorpay's test mode only.
6. Adjustments: rebate, waiver, fine waiver, refund, scholarship credit, carry-forward. Clerk proposes, Principal approves; alert the Chief above a configured waiver amount.
7. Reports: daily collection by collector and mode, dues and defaulters by class, head-wise collection, adjustments given, cheque status; Excel export.
8. Screens: fee setup, fee counter, student fee ledger, approvals-inbox entries, parent dues page with Pay now and receipts.
Done when an online test payment marks the due paid with no manual step, a replayed webhook changes nothing, a counter payment and its reversal both show in the ledger with who and why, and a waiver cannot apply without the Principal's approval.
```

### Phase 9 — Apps, hardening and pilot

```text
Read "Delivery: apps, hosting and DevOps" and "Security, privacy and compliance". Package and harden.
1. Capacitor 8 project (Android, iOS): school-code onboarding, push, biometric unlock, deep links, offline queue, app icons from packages/ui/assets/logo-mark.svg.
2. Tauri 2 project (Windows, macOS): installers, auto-update, printing, app icons from the same logo.
3. DPDP features: versioned notices, consent records, data-requests inbox, retention jobs, breach runbook.
4. Security pass against OWASP ASVS level 2; export watermarking and alerts; dependency audit; load test with seed data for 50 schools x 1,000 students.
5. Pilot checklist and an admin training guide.
Done when the apps install on a real Android phone, an iPhone, a Windows laptop and a Mac, and penetration-test findings are fixed.
```

---

## 4. Helper prompts

Paste the session starter first, then one of these.

**Continue a phase in a new session**

```text
We are in Phase <N>. Read docs/report/CHANGELOG.md (latest entries) and the report pages for this phase's modules. Compare what exists with the Phase <N> prompt below, tell me what is done and what is left, then continue with what is left.
<paste the Phase N prompt>
```

**Fix a bug**

```text
Bug: I did <steps>. I expected <what should happen>. Instead <what happened, with any error text>.
Find the cause and explain it in plain words. Fix it, add a test that fails without the fix, and update the report pages and change log.
```

**Change or add a feature**

```text
Change: <what you want and why>.
First list everything this touches (screens, requests, files, data, permissions, report pages) and any risks, and wait for my OK. Then make the change with tests, update the report and change log, and update docs/BLUEPRINT.md if the plan itself changes.
```

**Explain part of the app**

```text
Using docs/report/ and the code, explain how <feature> works in plain words: the screens, the requests, the files involved and the data it changes, with links to each file.
```

**Check the report is still true**

```text
Audit docs/report/ against the current code. List every page or section that is missing or out of date, then fix the report (not the code) and add a change-log entry.
```
