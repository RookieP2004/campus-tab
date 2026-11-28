# 0017 — DPDP: parent-only consent, erasure and retention keep the legal records

Date: 2026-11-28 · Phase 9 · Status: accepted (owner's choices of 2026-11-28)

**Context.** The DPDP Act 2023 and Rules 2025 (main duties from 13 May 2027) need versioned notices, consent records, a requests inbox (access, correction, erasure), retention rules, a grievance contact and breach handling. Three things clashed with earlier rules:

- PEN and APAAR consent was ticked by the Clerk at admission (Phase 2).
- The audit log is append-only (decision 0008) and the fee ledger is append-only (CONVENTIONS 3.6), yet erasure and retention delete data.
- A closed year is read-only (Phase 7), yet retention removes old files.

**Decision.**

1. **Consent only from the parent's own account.** A consent counts only when the parent gives it signed in to their own account (`method: parent_account`), against the current notice version. That is the verified parental consent for a child's data. The office cannot record one (`422 consent_parent_only`). PEN and APAAR can be stored only after it (`422 consent_required`), so an admission or an Excel import never brings them in. Office ticks from before Phase 9 stay as history, no longer count, and show as "needs the parent's confirmation". Withdrawing consent for PEN or APAAR removes the number at once.
2. **Erasure keeps the legal records.** Erasing a child (Chief, step-up `permanent_delete`, typed admission number, only for an open erasure request) removes the name, date of birth, gender, ID numbers, the school's own fields, the sign-in, and the contact details of guardians with no other child here. The record stays as "Erased student" with its admission number, so the attendance registers, marks, report cards and fee records the school must keep still add up. **The audit log and the fee ledger are never edited or deleted.** The outcome the parent reads says what was kept and why.
3. **Retention never deletes without the Chief.** Each kind of data has a time (`config/privacy.php`). A nightly job makes a **dry run** only (count, oldest, newest) and tells the Chief. Deleting needs the Chief to confirm that report with step-up (`permanent_delete`) within 7 days; exactly the listed records that are still past the cut-off go. **The audit log and the fee ledger are not retention kinds.** For files in records of a closed year (register photos, leave documents) only the file is deleted, and the record gets a `photo_removed` or `documents_removed` mark written without changing anything else, so a closed year's records stay as they were. A download then answers `410 removed_under_retention`.
4. **Grievance contact** lives in the school config (`school.grievance`), is shown on the public school profile (`GET /public/tenants/{code}/config`) and on everyone's Privacy page.
5. **Incident log and breach runbook.** Every suspected breach is recorded with a timeline that is only added to; the Data Protection Board deadline (72 hours) shows as overdue until marked done. The steps are in [how-to/breach-runbook.md](../how-to/breach-runbook.md).

**Consequences.**

- A child's PEN and APAAR need the parent to have a parent login and answer once. Schools must switch on parent sign-in for the families involved.
- Retention numbers, notice text and consent purposes are starting points; the legal review before launch must confirm them.
- Tests: `tests/Feature/Privacy/PrivacyTest.php`, `tests/Feature/People/StudentsTest.php` ("only after the parent consents"), `tests/Feature/Tenancy/PublicConfigTest.php`.
