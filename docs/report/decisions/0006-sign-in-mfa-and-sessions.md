# 0006 — Sign-in, MFA, passwords and sessions

Date: 2026-11-27 · Phase 1 · Status: accepted

**Context.** School accounts hold children's records. Sign-in is the front door: the PowerSchool breach began with an account without MFA. Many people share one IP at school or on mobile networks (CGNAT), and office staff often share computers.

**Decision.**

1. **The same answer for every failure.** An unknown school, an unknown user, a wrong password and a locked account all get `401 sign_in_failed`, with the message "Those details didn't work, or there were too many tries. Try again in 15 minutes or ask your Principal."
   - While an account is locked its password is **not checked at all**. Otherwise the right password would get a different answer from a wrong one and tell a guesser they had found it.
   - A dummy hash check keeps the timing the same.
2. **Lockout.**
   - **Per account:** 5 failures in a row, wrong MFA codes included, lock it for 15 minutes. A right password does not reset the count while the MFA step is still open. The Principal or Chief can unlock early, and `GET /users` shows the lock.
   - **Per IP:** limits are for bulk abuse only, with roomy defaults in config: 100 failed sign-ins per 15 minutes and 60 OTP sends per 10 minutes.
3. **MFA (TOTP).**
   - **Who:** it is mandatory from level 60 (Clerk, Vice Principal, Principal, Chief) and optional below. MFA users enter their code at every sign-in.
   - **Storage:** the secret uses Laravel's `encrypted` cast. Recovery codes are random, so an HMAC under the app key is enough; a slow hash would only make checking 10 of them slow. Each works once. A TOTP code cannot be replayed.
   - **First session:** a role that needs MFA but has none gets an enrol-only session. Such a session never becomes a full one; confirming enrolment replaces it.
4. **Step-up MFA.**
   - **One middleware:** `step-up:<action>`, with a named list in `config/identity.php` (`export`, `permanent_delete`, `role_edit`, `marks_unlock`, `support_grant`, `mfa_reset`). A code entered at `POST /auth/step-up` counts for 10 minutes on that device only.
   - **Typos fail loudly:** an action name that isn't on the list is an error, so a misspelt name can't silently skip the check.
   - **Coverage:** `role_edit` covers both editing a role and changing a user's roles.
5. **Parent OTP.**
   - **Who:** only accounts whose every role is Parent, and never one with MFA on. Otherwise a teacher could skip MFA with an SMS code.
   - **The code:** only a hash is stored. It lasts 5 minutes (a wrong try never extends it) and allows 5 tries.
   - **Limits and replies:** sends and failures are limited per phone (counted for unknown numbers too) and per IP. The reply is the same whether or not the number is registered.
   - **SMS driver:** `log` and `array` are allowed only in `local` and `testing`. Anywhere else a missing or local-only `SMS_DRIVER` stops the app at start-up.
6. **Passwords. Nobody types or sees another person's password.**
   - **New accounts:** they start with status `invited` and no password. The person gets a one-time set link by email, or SMS through the driver, valid 48 hours. With neither, the Principal or Chief gets a one-time setup code shown once on screen, with the same rules. Resending cancels the old link.
   - **Resets:** "Forgot password" always gives the same reply. Its link lasts 30 minutes, with limits per account (3 an hour, silently) and per IP. The Principal or Chief can send a reset link to anyone below them, but never set the password.
   - **Storage:** links and codes are stored only as SHA-256 hashes.
   - **After any set, change or reset:** every session and token of the user ends (in settings, the current one too), MFA stays as it is, and the user is told by email or SMS.
   - **Rules:** at least 12 characters (raised from 10 in Phase 9, ASVS 2.1.1), not the person's login or name, and not on the bundled common-password list (`resources/security/common-passwords.txt`), plus pattern checks. No outside service is called.
7. **Lost authenticator.**
   - **First choice:** a recovery code.
   - **Otherwise:** the Principal or Chief resets MFA for someone below their level, with step-up `mfa_reset`, and another Chief does it for a Chief. Nobody resets their own. The reset ends every session, and the person enrols again at the next sign-in.
   - **A sole Chief** cannot be helped inside the school. They recover through the platform console in Phase 1 part C, which will verify the key holder outside the app (the licence's registered email and phone) and is fully audited.
8. **Sign in every time: no "remember me", no "trust this device".**
   - **Browser:** a session cookie only (`expire_on_close`), so closing the browser signs you out.
   - **Apps (Phase 9):** they must keep the token **in memory only, never saved on the device**, so closing the app signs you out.
   - **Limits:** every session and token ends after 15 minutes without use at level 60 and up (30 minutes below), and after 8 hours in total, whichever comes first. The values are in `config/identity.php`; `GET /me` returns `session.idle_minutes` and `session.ends_at`.
   - **For part C and later screens:** warn one minute before an idle sign-out, and keep unsaved form input so nothing typed is lost.
9. **Device sessions** record each sign-in, holding only the token's record id or a SHA-256 hash of the session id, never the raw token or cookie. They can be listed and signed out remotely. Revoked records stay as security history, so the model has no soft deletes.

**Consequences.**
- People sign in more often. That is intended for shared office computers and children's data.
- Production needs a real SMS gateway before parents can sign in or SMS links can go out.
- A sole Chief who loses both phone and recovery codes needs the part C console.
