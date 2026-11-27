# Permissions

> Built in **Phase 1** (Identity and Access). The RBAC matrix arrives in part B; part A has the role-level checks below.

## Coded so far (Phase 1 part A)

| Rule | Who | Where |
| --- | --- | --- |
| View the school config and its history | Chief, Principal | [`SchoolConfigPolicy::view`](../../backend/app/Modules/Tenancy/Policies/SchoolConfigPolicy.php) |
| Change config paths under `policies.attendance`, `policies.leave`, `policies.marks`, `policies.fees`, `academic` | Chief, Principal | [`SchoolConfigPolicy::update`](../../backend/app/Modules/Tenancy/Policies/SchoolConfigPolicy.php), [`ConfigSections`](../../backend/app/Modules/Tenancy/Domain/ConfigSections.php) |
| Change any other config path (`school`, `branding`, `modules`, `locale`, `sign_in`) | Chief | same; refused with `403 config_section_forbidden`, checked for every changed path |
| Do anything at all when the role needs MFA and it isn't enrolled | nobody | middleware `mfa.enrolled` (`403 mfa_enrolment_required`) |
| Act on school data | only users of that school | middleware `tenant`, `BelongsToTenant` |
| Write while the licence is read-only | nobody (exports excepted) | middleware `licence` |
| Use a module not licensed or switched off | nobody | middleware `module:<name>` |

When built, this page lists the permission matrix **as actually coded**: every permission string (`module.action:scope`, for example `marks.edit:subject`), the roles that hold it by default and the Policy file behind each rule. The intended matrix is "Permission matrix" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md).

Rules that are fixed in code (not settings), from [`docs/CONVENTIONS.md`](../CONVENTIONS.md):

- Every permission check goes through a Policy, on the server. Hiding a button is never the protection.
- Nobody can create a role or user above their level, or grant a permission they lack.
- The last Chief cannot be removed or demoted.
- A locked mark changes only through a correction the Principal approves from their own account.
- Fee records are append-only; reversals instead of edits.
