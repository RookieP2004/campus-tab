# 0003 — Version pins chosen in Phase 0

Date: 2026-11-27 · Phase 0 · Status: accepted

| Pin | Why |
| --- | --- |
| PHP **8.4** (container and CI) | Meets Laravel 13's PHP 8.3+ with longer support; CI uses the same version so results match |
| MongoDB PHP extension **2.5.9** | `mongodb/laravel-mongodb` 5.12 needs `mongodb/mongodb` 2.5, which needs extension 2.4+ |
| `mongodb/laravel-mongodb` **^5.7** (5.12 installed) | First line that supports Laravel 13 |
| TypeScript **~6.0** | TypeScript 7 is out, but `typescript-eslint` supports only versions below 6.1 |
| React **19**, Tailwind CSS **4**, Vite **8** | As specified in the blueprint; Vite 8 is what `@vitejs/plugin-react` and Vitest now require |
| MongoDB server **8.0**, Redis **7**, Node **22** | As specified in the blueprint |
| Capacitor **8.6.2** (`@capacitor/app` 8.1.2, `push-notifications` 8.1.3), Tauri CLI/API **2.12.4**, crates `tauri` =2.12.5, plugins deep-link 2.6.3, single-instance 2.5.5, updater 2.14.1 (Phase 9) | Exact pins, each at least two weeks old when chosen (supply-chain caution); `Cargo.lock` committed |
| `grafana/k6` **1.8.3**, `clamav/clamav` **1.5**, `nginx` **1.31-alpine**, gitleaks **v8.30.1**, `sharp` **0.35.5** (Phase 9) | Load test, upload scanning, the production-like web server, the secret scan; `sharp` 0.35.5 because 0.34.5 has high advisories |

Removed from the Laravel skeleton: the `laravel/pao` test-output formatter and the skeleton's tool instruction files, which the project does not use (see the ground rules in [`docs/CONVENTIONS.md`](../../CONVENTIONS.md)).

Revisit when `typescript-eslint` supports TypeScript 7.
