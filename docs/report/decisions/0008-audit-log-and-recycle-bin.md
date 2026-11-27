# 0008 — Append-only audit log and the recycle bin

Date: 2026-11-27 · Phase 1 · Status: accepted, with one open question for the legal review

**Context.** The school must be able to see who changed what, and nobody, including us, should be able to rewrite that history. Deletion must be recoverable for 30 days, and permanent only by the Chief.

**Decision.**

1. **Append-only.**
   - **The model:** `AuditLog` throws on saving an existing entry and on delete.
   - **Its query builder** throws on update, delete, increment, upsert and touch.
   - **No soft deletes** and no route that changes or removes an entry. Tests check all of it.
2. **What an entry holds.**
   - **Fields:** actor, action, category, entity, before and after values, IP, device (user agent and device session).
   - **Support work:** under a support grant the entry holds both the agent and the grant id.
   - **Secrets:** passwords, OTPs, TOTP secrets, recovery codes, tokens and link secrets are never written. The redactor drops them and lists only the field names.
3. **Who reads what.**
   - **Chief:** everything (`audit.view:school`).
   - **Principal:** `academic` entries (`audit.view_academic:school`) plus their own actions.
   - **Clerk:** their own actions.
   - **Academic config changes:** a config change is academic when every changed path is one the Principal may edit.
4. **Recycle bin.**
   - **Deleting:** a soft-deleted record (now only user accounts) can be restored by the Principal or Chief.
   - **Purging** needs `records.purge` (Chief only), step-up `permanent_delete`, 30 days in the bin, and the record's exact name typed as confirmation.
   - **The purge entry** keeps only the type, id, who and when. A purged user's devices, tokens and password links go with them.

**Open question for the legal review.**

- **Older entries:** what should a permanent purge do with personal data already inside older audit entries, for example a name or phone in the `after` values of the account's creation? Today those entries stay unchanged, because the log is append-only. Options include keeping them for a set retention period, or replacing personal fields in older entries with a marker on purge (an audited redaction rather than an edit). This needs a decision under the DPDP Act before launch.

**Consequences.**
- The log grows without limit, so retention and archiving are Phase 9 operations work.
- Later modules mark their models `Auditable` and `Recyclable` and register them in `config/audit.php`.
