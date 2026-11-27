# 0013 · Leave: who decides, who sees, and a ledger that stays right under concurrent approvals

**Date:** 2026-11-27 · **Phase:** 5 · **Status:** accepted

## Context

Leave touches three things that must never go wrong: who is allowed to decide (nobody may approve their own leave), who may read a request (a sick-leave reason or a medical certificate is private), and the balance (two approvals at the same moment must not both spend the same days). The owner set five rules for this phase and decided one open question: the Principal is the school's leave authority, so the Chief Admin's own leave goes to the Principal.

## Decisions

1. **Routing is worked out, not stored.** `LeaveRouting::chainFor` picks the stages when a request is made:
   - **Chief Admin** → Principal (owner's decision)
   - **Principal** → Chief Admin
   - **Vice Principal or Clerk** → Principal
   - **everyone else** → the school's chain: Clerk, then an optional Vice Principal stage for the applicant's wing, then the Principal

   Who may act at a stage is checked on every move from permissions, levels and active delegations, never from a stored approver list. The applicant (and the parent who applied) is refused at every stage.
2. **Delegation is dated and limited.** A Vice Principal may decide in the Principal's place only between the delegation's dates, and only for people below their own level. It ends on its own.
3. **`clerk_can_reject` is the Chief's switch.** `ConfigSections::CHIEF_ONLY` keeps it out of the Principal's editable settings, as the blueprint says.
4. **Private by default.**
   - Reasons and remarks are sealed with the app key (`SensitiveValue`). Documents are encrypted before they reach the disk and served only through 5-minute signed links.
   - The audit log stores `[private]`.
   - A request outside your chain answers 404. The leave calendar gives names and dates only.
   - Notifications say only that a request changed, in the app as well as by push and email.
5. **The balance is an append-only ledger** (reserve, release, deduct, restore), worked out against the type's quota each time.
6. **One transaction per move, serialised per balance.**
   - Each move re-reads the request inside a MongoDB transaction.
   - Before reading the ledger it bumps a per-balance lock document (`leave_balance_locks.version`). Two transactions on the same balance then write the same document: MongoDB aborts one with a write conflict, and the driver retries it on the new state.
   - Approval writes the request, the ledger and attendance (staff LV with source `leave`; a child's LV) in that one transaction. Cancellation restores the untaken days and their attendance the same way.
7. **Approved leave in attendance is a system entry** (source `leave`, `entered_by` empty), not a manual one, so it does not touch the rule that nobody enters their own attendance.
8. **`Idempotency-Key`** on creates and workflow moves (the shared `idempotent` middleware): a retry gets the first answer back instead of a second action.

## Consequences

- A busy Principal's inbox includes Clerk-stage requests they may fast-track; they are flagged so it is clear the Clerk has not checked them yet.
- Changing a leave type's quota changes everyone's balance for the year, because balances are worked out from the quota.
- The Chief does not see leave reasons unless they are in the chain (only for the Principal's own leave).
