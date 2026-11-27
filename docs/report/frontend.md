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

## Local development notes

- Open the app at **http://127.0.0.1:5173**, never `localhost`: sign-in cookies (Phase 1) are tied to one host name.
- The dev server runs in the `web` container, binds to `0.0.0.0` and watches files by polling, because Docker on Windows does not pass file-change events from the D: drive.
- `node_modules` folders live in Docker volumes; run npm through `docker compose run --rm web …` (see [operations.md](operations.md)).
