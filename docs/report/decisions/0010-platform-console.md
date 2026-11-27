# 0010 · The platform console

**Date:** 2026-11-27 · **Phase:** 1 part C · **Status:** accepted

## Context

Our team needs a console to create schools and manage licences, and the blueprint's PowerSchool lesson warns that a support portal is the most dangerous door into a school platform.

## Decision

1. **No sign-up.** Platform admins are created only on the server by `php artisan platform:create-admin`. The account starts invited with no password; a one-time link (48 h) lets the owner choose it, so nobody else types or sees it.
2. **The same sign-in-every-time rules as school users.** Password and TOTP at every sign-in; enrol-only first session; 5 failures lock 15 minutes; session cookie only (guard `console`, a separate session guard, never a token); 15 minutes idle, 8 hours in total; remote sign-out; step-up for sensitive actions.
3. **One identity per browser.** Signing in to the console signs the browser out of a school account and the other way round.
4. **Two audit logs.** Every console action goes in `platform_audit_logs` (append-only like a school's log). An action on a school also goes in that school's `audit_logs`, with the platform admin as actor, so the school sees it.
5. **Revoke** needs step-up and the school code typed again.
6. **Sole-Chief recovery** only with exactly one active Chief who has lost the authenticator and the recovery codes: step-up, a one-time code to the school's registered contact, then that code and a written reason. It resets only MFA (the Chief enrols again at the next sign-in, all sessions end) and never sets or shows a password. It is a security event in the school's log.
7. **No school data in the console.** It shows counts only; reading school records still needs a Chief's support grant (decision 0009).

## Consequences

- An operator with server access can create admins; server access itself is the control. The admin creation is in the platform audit log as "command line".
- The blueprint's "signed letter" step for lost Chief access is a human check recorded in the written reason; the code to the registered contact is the technical proof.
- Background requests (header `X-Background-Request: 1`) never extend a console or school session.

## Addendum (same day): client records, numbers, alerts, renewals

- Client records and a hand-entered, append-only payment history live on the platform side; every change is in the platform audit log.
- **Per-school numbers:**
  - usage, and security events counted with no names or content
  - worked out by a scheduled job inside each school (`TenantContext::run`); only the numbers are stored (`school_stats`)
  - the console reads only these and platform records, and reading school data still needs a support grant
- **Alerts:** a burst over a minimum and 5 times the usual hourly rate alerts every platform admin by email, with counts only.
- **Renewals and licence changes** take effect at once and are recorded in both audit logs. The console never writes a school's settings, so a newly licensed module is switched on by the Chief.
- **Seat limits:** the licence has two, students and staff (every account except parents and students). Both are enforced wherever a student or a staff account is added.
