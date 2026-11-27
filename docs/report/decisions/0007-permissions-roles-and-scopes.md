# 0007 — Permissions, roles and scopes

Date: 2026-11-27 · Phase 1 · Status: accepted

**Context.** The blueprint's matrix gives every action a scope (own, child, subject, class, wing, school), and the Chief can edit it. Some rules must never become settings: no escalation, the last Chief, locked marks.

**Decision.**

1. **The permission strings.**
   - **Form:** a permission is `module.action:scope`. The code knows only the abilities listed in `config/permissions.php`, each with the scopes it may take.
   - **Unknown strings:** a role can't hold a string the catalogue lacks. That is why there is no setting that lets anyone change a locked mark: no such ability exists, and only a correction approved by the Principal can do it.
2. **The system roles.** Every school is seeded with nine levels (Chief 90 down to Student 10) and two duties.
   - **Duties:** Fee Collector and Attendance Officer have no level, and the Principal gives them. Each may only ever hold the abilities in its allow-list (`permissions.duties`): Fee Collector `fees.*`; Attendance Officer `staff_attendance.enter` and `staff_attendance.view`.
   - **Platform Admin** is not a school role.
   - **Existing schools:** `identity:sync-roles` adds missing roles without touching a Chief's edits.
3. **Checked on the server, for every endpoint.**
   - **Routes:** every school route names `permission:<ability>`.
   - **Policies:** they check the record's scope through the six resolvers (`school` and `own` work now; `child`, `class`, `subject` and `wing` read guardian links and teaching assignments from Phase 2).
   - **A route test** fails if any API route lacks a permission check and isn't on a short list of public and self-service routes.
   - **The front end** only reads `GET /me` to hide things.
4. **The guard rules, fixed in code.**
   - **No escalation:** you manage only people and roles below your level, and grant only permissions you hold at the same or a wider scope.
   - **Chief over Chief:** a Chief may manage other Chiefs, or there could never be a second one. The Chief role's permissions can't be edited.
   - **Last Chief:** the last active Chief can't be deactivated or demoted. This is checked in a transaction that also writes the school record, so two demotions at once conflict.
   - **Chief changes:** adding or removing a Chief is a security event in the audit log. Phase 3 notifications will also alert the other Chiefs.
5. **"Delete staff" means deactivate.** The account goes inactive and into the recycle bin, and every device is signed out. History stays.

**Consequences.**
- Later phases add abilities to the catalogue rather than inventing role checks.
- A Chief who widens a role can't create anything the code doesn't define.
- Phase 4 must also enforce: nobody can enter or change their own attendance through the Attendance Officer duty.
