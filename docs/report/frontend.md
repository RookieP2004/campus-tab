# Front end

The web app in [`frontend/`](../../frontend/) is one React 19 + TypeScript codebase. The same build will later run inside the Android, iOS, Windows and Mac apps (Phase 9).

## Structure

| Path | What it holds |
| --- | --- |
| [`frontend/index.html`](../../frontend/index.html) | Page skeleton; a tiny inline script applies the saved theme before the first paint (no flash) |
| [`frontend/src/main.tsx`](../../frontend/src/main.tsx) | Loads fonts and CSS, waits for i18next, mounts the router |
| [`frontend/src/app/routes.tsx`](../../frontend/src/app/routes.tsx) | Route table; every screen is a child of the shell |
| [`frontend/src/app/Providers.tsx`](../../frontend/src/app/Providers.tsx) | TanStack Query, i18next, and the effects that apply theme and language |
| [`frontend/src/app/queryClient.ts`](../../frontend/src/app/queryClient.ts) | Default query settings |
| `frontend/src/features/<feature>/` | One folder per feature: its screens, components and tests. Phase 0: `shell/`, `home/` |
| `frontend/src/components/ui/` | shadcn/ui components, recoloured with our tokens (Phase 0: `button.tsx`) |
| `frontend/src/components/brand/` | [`BrandLockup.tsx`](../../frontend/src/components/brand/BrandLockup.tsx): logo + "Campus **TAB**" wordmark |
| [`frontend/src/i18n/`](../../frontend/src/i18n/) | i18next setup, the language registry, language effect |
| [`frontend/src/locales/<code>/common.json`](../../frontend/src/locales/) | Translations (en, hi) |
| [`frontend/src/stores/preferences.ts`](../../frontend/src/stores/preferences.ts) | Zustand store: language and theme |
| [`frontend/src/theme/useThemeEffect.ts`](../../frontend/src/theme/useThemeEffect.ts) | Writes `data-theme` on `<html>` |
| [`frontend/src/lib/`](../../frontend/src/lib/) | `api.ts` (fetch + error shape), `env.ts` (VITE_* settings), `utils.ts` (`cn`) |
| `frontend/src/test/` | Test setup (`matchMedia`/`fetch` stubs) and `renderApp()` |

## Libraries

| Need | Library |
| --- | --- |
| Routing | React Router (`createBrowserRouter`) |
| Server data | TanStack Query |
| UI state | Zustand (with `persist`) |
| Forms | React Hook Form + Zod (installed; first forms in Phase 1) |
| Styling | Tailwind CSS v4 + shadcn/ui (Radix primitives via `radix-ui`), Lucide icons |
| Languages | i18next + react-i18next, lazy-loaded per language |
| Fonts | Inter and Noto Sans Devanagari, bundled (`@fontsource-variable/*`), no font CDN |
| Tests | Vitest + Testing Library + jsdom |

## Design tokens and themes

- Tokens live in [`packages/ui/src/tokens.css`](../../packages/ui/src/tokens.css) as `--ct-*` CSS variables, one set for light and one for dark, copied from the blueprint's "Theme tokens" table.
- [`packages/ui/src/theme.css`](../../packages/ui/src/theme.css) maps them to Tailwind classes: `bg-bg`, `bg-surface`, `text-fg`, `text-muted`, `border-border`, `bg-primary-solid`, `text-primary`, `text-danger`, `bg-status-pending-bg`, `rounded-card`, `rounded-input`, and so on.
- The theme is `data-theme="light"` or `"dark"` on `<html>`. The person picks **Light**, **Dark** or **Match device** (default) in the top bar; Match device follows the operating system live. The choice is stored in `localStorage` (`campus-tab:preferences`) until Phase 1 saves it on the account.
- **Rule:** never write a colour in a component; use a token class, so every screen works in both themes.
- The logo keeps its blue tile in both themes, as in the dark designs.

## Languages

- English is the default for everyone. Each person picks their language; it changes only their screens.
- [`i18n/languages.ts`](../../frontend/src/i18n/languages.ts) lists every Indian language the app is structured for (code, native name, script, `ltr`/`rtl`), with `shipped: true` for those ready: English and Hindi now.
- English is bundled; every other language is loaded on first use as its own file, so adding languages does not slow the first load.
- Changing language sets `<html lang>` and `<html dir>`. Urdu is registered as `rtl`; write layouts with logical classes (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `border-e`) so they mirror.
- A test fails if a shipped language is missing a key that English has. Recipe: [how-to/add-a-language.md](how-to/add-a-language.md).

## How a screen is built, and how to add one

1. Create `frontend/src/features/<feature>/<Name>Page.tsx`; get text with `const { t } = useTranslation()`; add the keys to **every** shipped `locales/*/common.json`.
2. Fetch data with `useQuery` / `useMutation` (Phase 1 on: through the generated client in `packages/api/`).
3. Style with token classes only; check it in Light and Dark.
4. Add a route as a child of the shell in [`app/routes.tsx`](../../frontend/src/app/routes.tsx).
5. Add `<Name>Page.test.tsx` next to it (render with `renderApp('/path')` from `src/test/renderApp.tsx`).
6. Add a row to the "Where is…?" index in [README.md](README.md) and an entry in [CHANGELOG.md](CHANGELOG.md).

## The Phase 0 shell

[`AppShell.tsx`](../../frontend/src/features/shell/AppShell.tsx) follows `docs/design/screens/{light,dark}/Sidebar.dc.html`:

- **Desktop (1024 px and up):** a sidebar with the brand stack (Campus TAB logo at 40 px and wordmark, then the school card placeholder, then the academic-year switcher placeholder), one navigation item and the server status. The top bar holds the language and theme pickers.
- **Below 1024 px:** the sidebar hides and the top bar shows the compact brand lockup (28 px mark).
- Phase 3 completes it: icon rail at 768-1023 px, bottom tabs under 768 px, search, notifications and the profile menu.

## Talking to the API (Phase 1 part C)

- **The client.** Every request goes through the typed client in [`packages/api`](../../packages/api/src/index.ts). It is generated from the OpenAPI spec (`packages/api/openapi.json`), which is exported from the Laravel code. [`lib/api.ts`](../../frontend/src/lib/api.ts) makes the one client and adds `apiGet()` for TanStack queries.
- **Cookie only.** The web app signs in with the session cookie only. No token, challenge or password is ever written to localStorage or sessionStorage; a test checks this. localStorage holds only display preferences and the public school code.
- **CSRF.** Changes send `X-XSRF-TOKEN` from Laravel's cookie (fetched from `/sanctum/csrf-cookie` when missing; one retry on 419).
- **Background requests.** A refetch of data already on screen, or anything polled, sends `X-Background-Request: 1` and never extends the idle time. [`stores/activity.ts`](../../frontend/src/stores/activity.ts) remembers the last real request, and the idle warning counts from it.
- **Session ended.** A 401 `session_expired` / `session_revoked` sets [`stores/session.ts`](../../frontend/src/stores/session.ts). [`RequireSession`](../../frontend/src/features/auth/RequireSession.tsx) then shows sign-in over the page without unmounting it, so forms keep their input.

## Screens before sign-in and the console

| Path | Screen |
| --- | --- |
| `/school` | School code, with the school's branding from the public config |
| `/sign-in`, `/sign-in/parent` | Password then MFA or recovery code; parent SMS code |
| `/password/forgot`, `/password/set`, `/password/reset` | Forgot password; links from email |
| `/activate` | Licence key → code → Chief Admin |
| `/mfa/enrol` | QR code, key, recovery codes (download, print) |
| `/account/sessions` | Signed-in devices |
| `/console/…` | The platform console ([modules/platform.md](modules/platform.md)) |

Brand stack top-left on every screen: Campus TAB, then the school's logo and name, with the school's accent as a thin strip. Every string is in `locales/en` and `locales/hi`.

## Phase 2 screens (signed-in shell)

| Path | Screen | File |
| --- | --- | --- |
| `/setup` | Setup wizard: profile and logo, year, classes and sections, subjects, staff and teaching, import | [`features/setup/SetupWizardPage.tsx`](../../frontend/src/features/setup/SetupWizardPage.tsx) |
| `/students` | Student list: TanStack Table, the person's custom columns, a column chooser remembered per user, section filter, search | [`features/people/StudentListPage.tsx`](../../frontend/src/features/people/StudentListPage.tsx) |
| `/students/new` | Admit: duplicate check, seat message, PEN/APAAR consent, Aadhaar last 4 only | [`features/people/AdmitStudentPage.tsx`](../../frontend/src/features/people/AdmitStudentPage.tsx) |
| `/students/:id` | Profile with tabs: details, guardians and parent login, custom fields, consents and IDs | [`features/people/StudentProfilePage.tsx`](../../frontend/src/features/people/StudentProfilePage.tsx) |
| `/staff`, `/staff/:id` | Staff list and profile; "Draft for the Principal"; approve or reject drafts; a setup code shown once | [`features/people/StaffPages.tsx`](../../frontend/src/features/people/StaffPages.tsx) |
| `/imports/:entity` | Import: template, upload, progress (polled as background requests), seat check first, row report, download, commit | [`features/people/ImportPage.tsx`](../../frontend/src/features/people/ImportPage.tsx) |
| `/sections/:id/setup-codes` | Student setup codes for the class, printable | [`features/people/SetupCodesPage.tsx`](../../frontend/src/features/people/SetupCodesPage.tsx) |
| `/settings/custom-fields` | Custom-field manager, filtered by the person's level; the scope picker offers only their own scopes | [`features/customisation/CustomFieldsPage.tsx`](../../frontend/src/features/customisation/CustomFieldsPage.tsx) |

- **API calls:** `features/{academic,people,customisation}/api.ts`.
- **Permissions in the UI:** [`features/auth/permissions.ts`](../../frontend/src/features/auth/permissions.ts).
- **Downloads:** templates and reports, through [`lib/requests.ts`](../../frontend/src/lib/requests.ts), with the session cookie.
- **Errors:** `unwrap()` and `apiErrorFrom()` in `packages/api` build the error from the body openapi-fetch already read, so the server's code and message reach the screen.

**Console additions** ([`features/console/ConsoleSchoolExtras.tsx`](../../frontend/src/features/console/ConsoleSchoolExtras.tsx)):

- **School page cards:** usage and seats, the security overview (counts), the client record, payments, licence history, support grants.
- **Renew:** a dialog.
- **Alerts:** a page at `/console/alerts`.
- **School list:** plan, end date, seats used, active users, modules and open alerts.

## Phase 3: shell, dashboards, notices and notifications

**Shell** ([`features/shell/`](../../frontend/src/features/shell/)): exactly one layout renders, chosen by `useViewport()`:

- **1024 px and wider:** a sidebar.
- **768–1023 px:** an icon rail with tooltips.
- **Under 768 px:** bottom tabs: Home, the role's main job, Inbox (unread badge), Notices, More.

The top bar holds:

- **Search:** Ctrl/Cmd K opens a palette of screens plus `GET /me/search`.
- **Quick create:** only the actions the user's permissions allow.
- **The bell:** the notification panel.
- **The profile menu:** role switcher (`?as=`), language, theme, devices, notification settings, sign out.

The school's accent colour drives the header strip and the selected-nav marker.

**Live notifications:** after sign-in, `laravel-echo` + `pusher-js` load in their own chunks and join `school.<tenant_id>.user.<id>`. The 60-second poll runs only while the socket is not connected. Every poll, refetch and the socket's channel-auth call send `X-Background-Request: 1`.

**Shared components** (`packages/ui/src/components/`): StatusChip (text plus shape, never colour alone), KpiCard, TodoList, EmptyState, TypedConfirmDialog, ApprovalTimeline.

| Path | Screen | File |
| --- | --- | --- |
| `/` | My dashboard: the server's cards in its order (the first one large), a renderer per `kind`, the parent's child switcher; refetched on focus as a background request | [`features/dashboard/`](../../frontend/src/features/dashboard/) |
| `/notices`, `/notices/new`, `/notices/:id` | Notices: list with unread marker; composer offering only the audiences the sender's scope allows (the server checks again); attachment limits; a fresh signed link per attachment click; receipts for the sender | [`features/notices/`](../../frontend/src/features/notices/) |
| `/notifications` | Inbox | [`features/notifications/`](../../frontend/src/features/notifications/) |
| `/account/notifications` | Notification preferences (push, email, muted types) | [`features/notifications/`](../../frontend/src/features/notifications/) |
| `/settings/school` | School settings: If-Match versioned saves, conflict reload, licence errors; switching a module off needs its name typed | [`features/settings/`](../../frontend/src/features/settings/) |
| `/more` | The More tab on phones | [`features/shell/`](../../frontend/src/features/shell/) |

**Languages:** Hindi is complete. Other languages fall back to English key by key. `npm run i18n:report -w frontend` writes the table of missing keys per language into [languages.md](languages.md), and `i18n:check` fails CI when that table is out of date.

## Bundle split (Session 2 fix)

Every route is a lazy chunk (the `page(load, name)` helper in [`app/routes.tsx`](../../frontend/src/app/routes.tsx)). The sign-in forms are lazy too, because they pull in zod and react-hook-form.

| When | Main bundle `index-*.js` | gzip |
| --- | --- | --- |
| Before the split | 851.22 kB | 250.64 kB |
| After the split | 511.64 kB | 163.56 kB |
| After the Phase 3 shell | 551.35 kB | 174.34 kB |
| After Phase 4 | 564.83 kB | 178.15 kB |

The rest of the main bundle is React, the router, TanStack Query, i18next and the English strings. The command palette, the socket libraries and every page load separately; the largest attendance chunk, the taker screen, is 18.2 kB.

## Phase 4: attendance screens

All attendance screens are in [`features/attendance/`](../../frontend/src/features/attendance/).

| Path | Screen |
| --- | --- |
| `/attendance` | The sections I may mark or see on a date, with links to the other attendance screens |
| `/attendance/:sectionId?date=&period=` | Taking attendance, phone-first, following `docs/design/screens/light/TeacherAttendance.dc.html`. Everyone starts present; a tap toggles Present and Absent; hold, right-click or "…" gives Late or Half-day. Leave and holiday rows are read-only. Rows are at least 52 px tall; the counts and the save bar stay pinned. One PUT saves the whole register, with no confirmation |
| `/attendance/summary?date=` | Daily summary: sections marked or not, %, staff line, students below the threshold |
| `/attendance/register?section_id=&month=` | Monthly register, with print styles; the shell is hidden when printing |
| `/attendance/me` | My check-in and check-out (app button or office code) and my month |
| `/attendance/staff?date=` | Office view: the day's staff list, manual entry and biometric CSV import |
| `/attendance/staff/qr` | Office QR display (`qrcode.react`); it refreshes 15 s before expiry, always as a background request |
| `/calendar?month=` | Holiday calendar: the Clerk adds, edits and deletes pending entries; the Principal approves or rejects |
| `/students/:id?tab=attendance` | The student's Attendance tab, which is also the parent's view of a child |

**How the screens follow the server's rules:**

- **Driven by `rules`:** the window time ("You can change this until …"); the late-entry banner and the required reason (checked before sending); the optional photo after saving; the refusal message and "Ask the Clerk or the Principal" when the day can't be changed.
- **Online only:** offline, or after a network failure on save, the screen tells the teacher to keep the paper register and enter the day later, within `late_entry_days`. Nothing is queued on the device.
- **Location:** asked for once, and only when the geofence is on. It is sent with the check-in, never stored, and never shown; the screen says only inside or outside the area.
- **No client-side filtering:** every staff row the server sends is listed, and the `own_attendance` and `level_too_low` refusals show in plain words. The calendar buttons come from the server's `can`.
- **Dashboard:** the `action` card is a big "Take attendance" or "Check in" button. The class teacher's main phone tab is Attendance; for the staff role it is My attendance.

**Tests:** [`features/attendance/attendance.test.tsx`](../../frontend/src/features/attendance/attendance.test.tsx) covers:

- **Taking attendance:** starts all present; tap and menu; exactly one PUT; a late entry needs a reason; refusal after the window; offline message with nothing sent; holiday read-only; axe in light and dark.
- **Staff and check-in:** the own-attendance error; every row kept; location sent only with the geofence on.
- **Calendar:** buttons from `can`.
- **Dashboard and reports:** the dashboard action cards and phone tabs; the reports' links and Print.

## Local development notes

- Open the app at **http://127.0.0.1:5173**, never `localhost`: sign-in cookies (Phase 1) are tied to one host name.
- The dev server runs in the `web` container, binds to `0.0.0.0` and watches files by polling, because Docker on Windows does not pass file-change events from the D: drive.
- `node_modules` folders live in Docker volumes; run npm through `docker compose run --rm web …` (see [operations.md](operations.md)).
