# 0002 — 127.0.0.1 everywhere, and dependencies in Docker volumes

Date: 2026-11-27 · Phase 0 · Status: accepted

## Problem

The repository sits on a Windows D: drive while the containers run Linux.

- Mixing `localhost` and `127.0.0.1` breaks sign-in cookies, which belong to one host name. On this machine `localhost` also resolves to `::1`, where another Windows program listens on port 8000, so `http://localhost:8000` times out.
- `vendor/` and `node_modules/` on a Windows bind mount are slow, collect platform-specific binaries and can pick up CRLF line endings.
- File-change events from the Windows drive do not reach the containers, so Vite's live reload does not fire.

## Decision

- Every local URL uses `127.0.0.1`: `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, CORS (only `FRONTEND_URL` is allowed, with credentials), Vite's HMR host, Reverb's browser host, the README and this report.
- In development the browser calls the API through the Vite proxy (`/api`, `/sanctum`), so the page and the API share one origin.
- `backend/vendor` and every `node_modules` folder live in named Docker volumes; `composer install` and `npm install` run inside the containers only, triggered automatically when the lock files change.
- Vite binds to `0.0.0.0` and watches by polling (`VITE_USE_POLLING=true`).
- `.gitattributes` keeps LF line endings; every shell script and container entrypoint is LF.

## Consequences

Packages cannot be installed from the Windows terminal; use `docker compose exec api composer …` or `docker compose exec web npm …`. Editors that want to read `vendor/` for code completion need a dev-container setup or a one-off copy; this does not affect running the app.
