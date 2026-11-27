# Permissions

> Built in **Phase 1** (Identity and Access). Phase 0 has no signed-in users and no permission checks; the only route, `GET /api/v1/health`, is public.

When built, this page lists the permission matrix **as actually coded**: every permission string (`module.action:scope`, for example `marks.edit:subject`), the roles that hold it by default and the Policy file behind each rule. The intended matrix is "Permission matrix" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md).

Rules that are fixed in code (not settings), from [`docs/CONVENTIONS.md`](../CONVENTIONS.md):

- Every permission check goes through a Policy, on the server. Hiding a button is never the protection.
- Nobody can create a role or user above their level, or grant a permission they lack.
- The last Chief cannot be removed or demoted.
- A locked mark changes only through a correction the Principal approves from their own account.
- Fee records are append-only; reversals instead of edits.
