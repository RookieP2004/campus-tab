# 0009 — Support access

Date: 2026-11-27 · Phase 1 · Status: accepted

**Context.** "The PowerSchool lesson": the 2024 breach came through a support portal and a subcontractor account without MFA. Our team must have no standing access to any school's data.

**Decision.**

1. **The Chief decides.**
   - **Creating a grant:** only the Chief creates one, with step-up `support_grant`, for one named platform admin, one or more areas (`config/identity.php` `support.areas`) and a reason.
   - **Defaults and time limits:** it is read-only unless the Chief says otherwise. Its fixed end time is 15 minutes to 72 hours ahead and can't be extended (make a new grant instead).
   - **Revoking:** the Chief can revoke it early.
2. **The agent acts only inside an active grant.**
   - **Opening a session:** a platform admin with MFA opens one for that school only (`POST /platform/support/sessions`). Its token names the school and the grant, and ends with the grant, or after 8 hours, whichever is first.
   - **On every request:** the `tenant` middleware checks the grant again, so a revoke takes effect at once.
   - **What the agent can do:** they act as a temporary support user holding only the grant's permissions: view actions when read-only, and any write under a read-only grant is refused.
   - **What they can never do:** touch support grants, purge, edit roles or users' roles, export, unlock marks, approve corrections, or do any step-up action.
   - **No level:** they have level 0, so they can't manage any account.
3. **The school sees everything.** Every action is in the audit log with the agent and the grant id. `GET /support-grants` lists every grant, and `GET /support-grants/{id}/activity` shows everything done under one.

**Consequences.** Platform sign-in and the console arrive in part C. Until then the tests create platform tokens directly.
