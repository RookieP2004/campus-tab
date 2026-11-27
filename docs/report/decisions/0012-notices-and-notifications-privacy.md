# 0012 · Notices and notifications: generic push and email, signed attachment links, the school's folder

**Date:** 2026-11-27 · **Phase:** 3 · **Status:** accepted

## Context

Notices and notifications carry school news to parents, students and staff. Some of it is private: a child's absence, a named student, a notice meant for one section. It leaves the app by push (shown on phone lock screens) and by email (inboxes are often shared or read on other devices). Attachments are files that must not be reachable by guessing a link or by someone outside the audience.

## Decisions

1. **Push and email carry generic text only.**
   - The text comes from `config/communication.php` `generic_texts` by notification type, for example "New notice from Sunrise Public School. Open Campus TAB to read it."
   - It never holds a name, a mark, a notice title or any content; the only variable is the school's name.
   - The in-app notification may name things (a notice title, "Aarav Patel was absent on 3 December"), because it is read only after sign-in.
   - `Notifier` builds both, and `SendOutsideNotifications` only ever sends the generic one, so a module cannot leak detail by accident.
2. **The live socket carries no readable content.** `NotificationCreated` broadcasts only the notification id, its type and the unread count on the person's own private channel `school.{tenant}.user.{user}`. The screen then fetches the notification over the API. The channel callback allows only the signed-in user of that school.
3. **Audiences are decided on the server**, both for sending (`NoticeAudience::assertMaySend`) and for reading (`NoticeAudience::reaches`). A notice or attachment outside a person's audience answers `404 not_found`, so its existence is not revealed.
4. **Attachments live in the school's own private folder**, `tenants/<tenant_id>/notices/<notice_id>/`, on the private disk, with a random ULID as the file name. Types are limited to PDF, PNG, JPEG, WebP, DOCX and XLSX (no SVG or HTML, which can carry script), at most 5 per notice and 5 MB each.
5. **Attachments are served only through short-lived signed links.**
   - A person asks for a link; the server checks they may read the notice and returns a temporary signed route valid 5 minutes (`notices.link_minutes`).
   - The link is **relative** (`signed:relative`), so it does not depend on the host name the app is served under.
   - The file is sent with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.
   - The same pattern serves the attendance register's paper photo.
6. **Preferences belong to each person:** push on, email off by default, and any type can be muted. There is no school-level switch that forces email on.
7. **Push devices:** the device token is stored encrypted, and found again by an HMAC of it. The log driver writes the device count, never the tokens.
8. **`PUSH_DRIVER`:** `log` and `array` only in local and testing; elsewhere the default is `none` until the apps and a push project exist (Phase 9). The app refuses to start with a development driver outside local and testing, like `SMS_DRIVER`.

## Consequences

- A parent must open the app to learn which child was absent. This is on purpose: the lock screen shows only that there is news from the school.
- A download link copied to someone else works for at most 5 minutes; after that they need their own link, which needs their own permission.
- Read receipts are stored per person and notice, and only the sender and school-wide readers see who read a notice.
