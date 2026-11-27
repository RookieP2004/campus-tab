# Audit

> Status: **Phase 1 part B: built** (back end). The audit-log and recycle-bin screens come later.

## 1. What it does (plain words, no code)

**A history nobody can rewrite.** Every change to school data is written down: who did it, when, from which device and network, and what it was before and after. Sign-ins, lockouts, MFA changes and support access are recorded too.
- Nothing in the log can be changed or deleted, by anyone, including us.
- Passwords, codes and tokens are never written into it; the log only notes that such a field changed.

**Who sees it.** The Chief sees all of it, the Principal sees the academic part and their own actions, and a Clerk sees their own actions.

**Deleting.** Deleting a record moves it to the recycle bin, and the Principal or Chief can restore it. Only the Chief can delete for ever, and only after 30 days, with a fresh MFA code, typing the record's name to confirm.

## 2. Who uses it and from which screens

_Screens come later (the Chief's audit log and recycle bin). The API is ready._

## 3. Requests it handles

| Method and path | Permission | What it does | Errors |
| --- | --- | --- | --- |
| `GET /audit-logs` | `audit.view` or `audit.view_academic` | Entries newest first, 50 a page (`?cursor=`); filters `entity`, `entity_id`, `action`, `category`, `actor_id`, `support_grant_id`, `from`, `to`. Narrowed to what the reader may see | `403 permission_denied` |
| `GET /recycle-bin?type=` | `records.restore` | Deleted records with `purge_after` | |
| `POST /recycle-bin/{type}/{id}/restore` | `records.restore` | Restores (a user becomes active again) | `404`, `403 level_too_low` |
| `DELETE /recycle-bin/{type}/{id}` `{confirm}` | `records.purge` + step-up `permanent_delete` | Deletes for ever | `409 purge_too_early`, `422 confirmation_mismatch`, `403 step_up_required` |

There is no route that changes or deletes an audit entry.

**Platform audit log (part C).** `platform_audit_logs` holds every console action and `platform:*` command ([`PlatformAuditRecorder.php`](../../../backend/app/Modules/Audit/Domain/PlatformAuditRecorder.php), [`PlatformAuditLog.php`](../../../backend/app/Modules/Audit/Models/PlatformAuditLog.php)).

- It is append-only in the same way as a school's log (same query builder).
- An action on a school is also written to that school's own `audit_logs`:
  - the action name gets a `platform.` prefix
  - the actor type is `platform_admin`
  - `meta.platform_audit_id` links the two entries
- `AuditRecorder::record()` now accepts a platform admin as the actor.

## 4. Code map

`backend/app/Modules/Audit/`:

| File | What it does |
| --- | --- |
| [`Models/AuditLog.php`](../../../backend/app/Modules/Audit/Models/AuditLog.php) | One entry; throws on update and delete; no soft deletes |
| [`Models/AuditLogBuilder.php`](../../../backend/app/Modules/Audit/Models/AuditLogBuilder.php) | Its query builder: update, delete, increment, upsert and touch throw |
| [`Domain/AuditRecorder.php`](../../../backend/app/Modules/Audit/Domain/AuditRecorder.php) | Writes entries: actor (user, support agent with grant id, or system), IP, device; `muted()` for one named entry instead of many |
| [`Domain/Auditable.php`](../../../backend/app/Modules/Audit/Domain/Auditable.php) | Trait: records created, updated, deleted, restored, purged with before and after |
| [`Domain/Redactor.php`](../../../backend/app/Modules/Audit/Domain/Redactor.php) | Removes secrets; lists only the field paths |
| [`Domain/Recyclable.php`](../../../backend/app/Modules/Audit/Domain/Recyclable.php), [`Domain/RecycleBin.php`](../../../backend/app/Modules/Audit/Domain/RecycleBin.php) | The bin: list, restore, purge |
| [`Http/Controllers/`](../../../backend/app/Modules/Audit/Http/Controllers/) | `AuditLogController`, `RecycleBinController` |
| [`AuditServiceProvider.php`](../../../backend/app/Modules/Audit/AuditServiceProvider.php) | Gates `records.restore`, `records.purge`, `audit.view-any` |
| [`routes.php`](../../../backend/app/Modules/Audit/routes.php) | The routes above |

`config/audit.php`: `purge_after_days` (30) and the recyclable types.

## 5. How the code works, step by step

**A change.**
1. A model with `Auditable` is saved.
2. The trait takes the changed fields, leaving out bookkeeping fields.
3. `AuditRecorder` adds the actor (from the signed-in user; a support agent with the grant id), IP, user agent and device session.
4. `Redactor` removes secret fields and lists their paths in `redacted`.
5. One `audit_logs` document is inserted.

**Named events** (sign-in, lockout, MFA, step-up, password, Chief added or removed, support sessions) are written with `AuditRecorder::record()`.

**Purge.**
1. `records.purge` and step-up are checked.
2. The record must be in the bin, and at least 30 days old there.
3. The typed name must match exactly.
4. The user's devices, tokens and password links are removed.
5. `forceDelete`, and an entry `purged` with no before or after.

## 6. Data it reads and writes

| Collection | Fields | Indexes |
| --- | --- | --- |
| `audit_logs` (school) | `tenant_id`, `actor {type, id, name}`, `actor_id`, `support_grant_id`, `action`, `category` (`academic`, `admin`, `security`, `fees`), `entity`, `entity_id`, `before`, `after`, `redacted[]`, `meta`, `ip`, `device {session_id, user_agent}`, `at` | `tenant_id + entity + entity_id + at`, `tenant_id + at`, `tenant_id + category + at`, `tenant_id + actor_id + at`, `tenant_id + support_grant_id + at` |

## 7. Links to other modules

- **Audited models:** User, SchoolRole, SupportGrant and SchoolConfig (the diff only, not the whole body). Later modules add theirs.
- **Recyclable:** User. Later modules register theirs in `config/audit.php`.

## 8. Settings that change its behaviour

`config/audit.php` (`purge_after_days`, `recyclable`); the step-up action `permanent_delete` in `config/identity.php`.

## 9. Permissions and the rules behind them

**Who sees the log:**
- **The Chief:** everything (`audit.view:school`).
- **The Principal:** academic entries (`audit.view_academic:school`) and their own actions (`audit.view:own`).
- **A Clerk:** their own actions.

**The recycle bin:**
- **Restore:** Principal and Chief (`records.restore`).
- **Purge:** Chief only (`records.purge`), plus step-up.
- **The level rule** applies to user records.

## 10. How to change it

- **Audit a new model:** add `use Auditable`. Optionally define `auditCategory()`, `auditIgnored()` and `auditSnapshot()`.
- **Put a model in the bin:** add `SoftDeletes`, implement `Recyclable`, and register it in `config/audit.php`.
- **Never** add an update or delete path for `AuditLog`; the tests fail if one appears.

## 11. Tests that cover it

- [`tests/Feature/Audit/AuditLogTest.php`](../../../backend/tests/Feature/Audit/AuditLogTest.php):
  - append-only: save, delete, bulk update, delete, increment and upsert all refused;
  - no soft deletes, and no write routes;
  - no secrets after a full MFA, sign-in and password-change flow;
  - who sees what; a Principal-editable config change counts as academic;
  - before and after values, actor, IP and device are recorded.
- [`tests/Feature/Audit/RecycleBinTest.php`](../../../backend/tests/Feature/Audit/RecycleBinTest.php): list, restore, the Principal can't purge, step-up, 30 days, typed confirmation, the purge entry has no data.

## 12. Decisions and limits

[0008](../decisions/0008-audit-log-and-recycle-bin.md). **Open question for the legal review:** what a permanent purge does with personal data already inside older audit entries. Retention and archiving of the log are Phase 9 work.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 0 | Page created from the template | Phase 0 starts the Application Report |
| 2026-11-27 | 1B | Append-only audit log with redaction and per-reader visibility; recycle bin with restore and the Chief's purge | Phase 1 item 8, with the owner's rules for the audit log |
| 2026-11-27 | 1C | Platform audit log; school copies of console actions on that school | Phase 1 part C: every console action audited, school actions visible to the school |
| 2026-11-27 | 2 | `AuditRecorder::withMeta()` adds meta to every entry inside it (an import is audited under one batch id); students and staff in the recycle bin | Phase 2 rule 6 |
| 2026-11-27 | 1C | Restoring a user or student from the recycle bin checks the seats | The owner's seat rule |
