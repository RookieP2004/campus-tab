# 0016 — The apps: sign-in in memory, push after closing, bearer-only origins

Date: 2026-11-28 · Phase 9 · Status: accepted (owner's rules and choices of 2026-11-28)

**Context.** Phase 9 wraps the same React build in Capacitor 8 (Android, iOS) and Tauri 2 (Windows, macOS). The blueprint's Delivery table mentioned "fingerprint or face unlock" and an "offline queue". The owner's sign-in rule (CONVENTIONS 3.11) says the apps keep their token in memory only, never saved on the device. The web views load the app from local origins (`https://localhost`, `capacitor://localhost`, `http://tauri.localhost`, `tauri://localhost`), so the session cookie cannot be used.

**Decision.**

1. **Token in memory only.** The apps sign in like any non-browser client and get a Sanctum bearer token (`SessionIssuer::issueToken`). It lives in a JavaScript variable (`frontend/src/stores/appToken.ts`): never `localStorage`, `sessionStorage`, a cookie, the keychain or a file. Closing the app throws it away, which is the sign-out. **No biometric or PIN unlock** (the blueprint's line is replaced) and **no offline queue** (the app is online-only, as decided in Phase 4).
2. **Closing.** The desktop window sends `POST /auth/logout {closing: true}` as it closes, so the server ends the sign-in at once (reason `app_closed`). Phones cannot report being closed: the token is gone from memory, and the server ends the sign-in after the idle time (`TrackDeviceSession`), as for a browser tab left open.
3. **Push after closing (owner's choice).** A phone registers its push token after each sign-in. The registration **stays after the app closes**, so the generic texts ("You have a new notice in Campus TAB") still arrive. It is removed on a sign-out on purpose, a remote sign-out, a password or MFA reset, deactivation (`communication.push.forget_on`), when FCM or APNs say the token is gone, and after **30 days without a sign-in** on that device (`push:forget-stale`, daily). Opening the app always needs a full sign-in. Push texts are the same generic texts as before (decision 0012).
4. **Bearer-only origins.** The apps' origins are allowed by `AppOriginCors` **without credentials**: browsers never send the web session cookie on their behalf, so a page that happens to run on `https://localhost` cannot use someone's web session. The web app's own origin keeps Laravel's credentialed CORS. The origins are a setting (`APP_ORIGINS`).
5. **Build-time API address.** The API, Reverb and public web addresses are baked into each build (`VITE_API_BASE_URL`, `VITE_REVERB_*`, `VITE_PUBLIC_WEB_URL`, `VITE_APP_SHELL`). Plain HTTP is allowed only in builds made for an `http://` address (the Wi-Fi test); release builds for an `https://` address refuse cleartext.
6. **Deep links** open only `/verify/…`, `/password/…`, `/notifications` and `/notices/…` (`deepLinkPath`); anything else is ignored.

**Consequences.**

- Each app launch is a fresh sign-in (with MFA for Clerk and above). This is the intended cost of the rule.
- The Android release key and the Firebase/APNs keys live only in GitHub secrets and the owner's offline backup (`apps/mobile/README.md`).
- Tests: `tests/Feature/Communication/AppPushTest.php` (push kept after closing, removed on purpose, remote and account-wide; 30-day prune; FCM and APNs drivers; bearer-only CORS), `frontend/src/lib/native.test.ts` (deep links; bearer token, no cookies, no CSRF call).
