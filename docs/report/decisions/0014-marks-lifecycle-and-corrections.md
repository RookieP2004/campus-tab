# 0014 · Marks: online-only markbook, conflicts instead of overwrites, Principal-only corrections and no unlock

**Date:** 2026-11-27 · **Phase:** 6 · **Status:** accepted

## Context

Marks decide promotion and report cards. Two teachers (or a teacher and the office) can have the same paper open; a mark that parents have seen must not change quietly; and the blueprint's step-up list named "unlocking marks" while the fixed rule says a locked mark changes only through a correction the Principal approves. The owner set six rules for this phase and decided two open questions in this session.

## Decisions

1. **Online only** (owner's rule). There is no offline marks entry and nothing is queued on the device; offline, the markbook disables its inputs and says so. `docs/BLUEPRINT.md` (the markbook paragraph) and Phase 6 item 5 of `docs/BUILD-PROMPTS.md` now say this.
2. **Conflicts, never overwrites.** Each paper has a `version`, sent as `ETag: "v<n>"`. A save must send `If-Match` (428 without it) and is written by a conditional update on that version. If someone saved first, the answer is 412 `paper_conflict` with the current version, and the screen shows their marks while keeping the cells you typed.
3. **Every mark change is its own audit entry** (`marks.changed`) with the old and new value, because the automatic model entry would only show the whole marks list.
4. **No unlock** (owner's decision). `marks.unlock` and the `marks_unlock` step-up action are removed; `identity:sync-roles --with-new-permissions` takes the ability out of stored roles. A locked mark changes only through a correction.
5. **Corrections are the Principal's, from their own account.**
   - The approve route needs step-up MFA (`marks_correction_approve`, new) and a typed reason of at least 10 characters (owner's rule 2).
   - Support access can never do it.
   - The Clerk, teachers and the Chief can only request; so can the Principal (new: `marks.correction_request:school` for the Principal).
6. **Self-raised corrections** (owner's rule 3). If the Principal raised it, the MFA code must be entered after the request was raised (`step_up_at > created_at`), so raising and approving are two separate steps. The request is flagged `self_approved`, the Chief is notified, and it appears on the Chief's dashboard and in the corrections list.
7. **Transactions** (owner's rule 6). Locking (one paper or all eligible papers of an assessment) and publishing run in MongoDB transactions. Applying a correction writes the paper, the request and the audit entry in one transaction, and only if the paper still holds the old mark.
8. **Published only for families** (owner's rule 4). Students and parents read marks only from published papers. Their notification says "Results are out" and nothing more, in the app, by push and by email.
9. **Auto-published class tests stay editable until locked** (owner's decision). Parents may see a mark change before the lock; every change is audited.
10. **Presets are starting points** (owner's rule 5). Each preset carries "Check against your board's latest circular and change anything that differs", shown when it is chosen and on the scheme; the Principal can change every part.

## Consequences

- A teacher who leaves a markbook open while another edits must review and save again; nothing is lost and nothing is overwritten.
- A Principal correcting their own mistake needs two MFA codes about a minute apart.
- Schools that relied on editing class-test marks after publishing can do so only until the paper is locked.
