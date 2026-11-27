# Approvals

> Status: **built in Phase 5** (leave), extended in Phase 6 (mark corrections). Fees join in Phase 8.

## 1. What it does (plain words, no code)

One inbox for everything waiting on a person's decision: leave requests now, mark corrections from Phase 6, fee adjustments in Phase 8. Each module decides on the server which of its items this person may act on, and shows only what they may see. The inbox lists them oldest first with a count per kind for the filter tabs.

## 2. Who uses it and from which screens

`/approvals` ([frontend.md](../frontend.md#phase-5-leave-screens)), following `docs/design/screens/light/Approvals.dc.html`: the list on the left, the chosen item on the right (on phones, the list and the item are separate views). The Principal and the Clerk also see "Away this week" from the leave calendar.

## 3. Requests it handles

| Method and path | Output | Permission |
| --- | --- | --- |
| `GET /approvals/inbox` | `data` (items: kind, id, title, subtitle, status, at, link, flags), `counts` per kind, `total` | `leave.verify`, `leave.decide`, `leave.decide_principal`, `leave.fast_track`, `student_leave.approve`, `marks.correction_approve` or `staff.approve` |

Flags: `fast_track` (the Principal may decide at the Clerk's stage), `cancel_requested`, `long_student_leave`, `raised_by_you` (a correction the Principal raised: approving it is a separate MFA step).

## 4. Code map

- `backend/app/Modules/Approvals/Domain/ApprovalSource.php`: the interface a module implements (`kind()`, `itemsFor(User)`).
- `backend/app/Modules/Approvals/Http/Controllers/ApprovalInboxController.php`: merges every source and sorts.
- `backend/app/Modules/Approvals/routes.php`
- `backend/config/approvals.php`: the list of sources.
- Sources: `Leave\Domain\LeaveApprovals`, `Assessment\Domain\CorrectionApprovals` (Phase 6).
- Front end: `frontend/src/features/approvals/ApprovalsPage.tsx` (detail panels per kind).

## 5. How the code works, step by step

The controller asks each source in `config/approvals.php` for this person's items, adds up a count per kind and sorts all items by when they started waiting. A source returns nothing when its module is switched off.

## 6. Data it reads and writes

Nothing of its own: each source reads its module's collections.

## 7. Links to other modules

Leave and Assessment provide sources. A correction opens `CorrectionPanel` (step-up and a typed reason). The dashboard's "Leave waiting for you" card uses the same leave source.

## 8. Settings that change its behaviour

`config/approvals.php` `sources`.

## 9. Permissions and the rules behind them

The route admits anyone who may approve something. Each source then checks every item with its own module's rules (for leave: `LeaveRouting::mayAct`), so the inbox never shows something the person may not decide.

## 10. How to change it

Add a source: implement `ApprovalSource` in the module, add it to `config/approvals.php`, add its ability to the route's `permission:` list, and register a detail panel in `ApprovalsPage.tsx`.

## 11. Tests that cover it

`backend/tests/Feature/Leave/LeaveWorkflowTest.php` (the inbox test), `StudentLeaveTest.php` (long student leave flag), `frontend/src/features/leave/leave.test.tsx` (the inbox screen), `backend/tests/Feature/Assessment/MarksTest.php` (corrections in the inbox), `frontend/src/features/assessment/assessment.test.tsx` (the correction panel).

## 12. Decisions and limits

Staff drafts still appear on the dashboard's approvals card, not in this inbox.

## 13. Change history

| Date | Phase | What changed | Why |
| --- | --- | --- | --- |
| 2026-11-27 | 5 | Module created with the leave source | "Shared approvals inbox" in Phase 5 |
| 2026-11-27 | 6 | Mark corrections source and panel; a tab with nothing left falls back to All | Phase 6 corrections |
