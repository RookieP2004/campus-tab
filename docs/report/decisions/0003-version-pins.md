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

Removed from the Laravel skeleton: the `laravel/pao` test-output formatter and the skeleton's tool instruction files, which the project does not use (see the ground rules in [`docs/CONVENTIONS.md`](../../CONVENTIONS.md)).

Revisit when `typescript-eslint` supports TypeScript 7.
