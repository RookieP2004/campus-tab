# Data dictionary

Every collection in MongoDB, what each field means, which module owns it and who writes it. The target model (about 45 collections) is in "Data model" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md); this page lists what exists in code.

## Rules for every school collection (from Phase 1)

- Every document carries `tenant_id` (set by the `BelongsToTenant` trait from the signed-in user), `created_by`, `updated_by`, `created_at`, `updated_at` and `deleted_at` (soft delete).
- Every index starts with `tenant_id`.
- Small bounded lists are embedded (marks inside a paper, attendance entries inside a day); anything that grows gets its own collection.
- Full Aadhaar numbers are never stored.

## Databases

| Database | Used by |
| --- | --- |
| `campus_tab` | Local development (`MONGODB_DATABASE`) |
| `campus_tab_test` | Tests; dropped and recreated before every database test |

## Collections in code (generated)

`users` is a placeholder so Laravel's sign-in config has a MongoDB model; Phase 1 replaces it with the Identity module's tenant-scoped users.

<!-- generated:collections:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Collection | Model | Fillable fields | Flags |
| --- | --- | --- | --- |
| `licences` | [`Licence`](../../backend/app/Modules/Licensing/Models/Licence.php) | `tenant_id`, `key_hash`, `key_last4`, `plan`, `modules`, `seats`, `valid_from`, `valid_to`, `status`, `payload`, `signature`, `activated_at`, `revoked_at` | — |
| `personal_access_tokens` | [`PersonalAccessToken`](../../backend/app/Modules/Identity/Models/PersonalAccessToken.php) | `name`, `token`, `abilities`, `expires_at` | — |
| `platform_admins` | [`PlatformAdmin`](../../backend/app/Modules/Identity/Models/PlatformAdmin.php) | `name`, `email`, `password`, `mfa`, `status` | — |
| `school_configs` | [`SchoolConfig`](../../backend/app/Modules/Tenancy/Models/SchoolConfig.php) | `tenant_id`, `version`, `body`, `diff`, `changed_by`, `reason`, `rolled_back_from` | tenant-scoped |
| `support_grants` | [`SupportGrant`](../../backend/app/Modules/Identity/Models/SupportGrant.php) | `tenant_id`, `granted_by`, `agent`, `scope`, `read_only`, `reason`, `expires_at`, `revoked_at` | tenant-scoped |
| `tenants` | [`Tenant`](../../backend/app/Modules/Tenancy/Models/Tenant.php) | `code`, `name`, `group_id`, `status`, `config_version`, `contact` | — |
| `users` | [`User`](../../backend/app/Modules/Identity/Models/User.php) | `tenant_id`, `name`, `email`, `phone`, `password`, `roles`, `mfa`, `status`, `language`, `theme` | tenant-scoped, soft deletes |

<!-- generated:collections:end -->

## Collections written by packages

| Collection | Written by | What it holds |
| --- | --- | --- |
| `migrations` | Laravel | Which migrations (collection and index definitions) have run |
| `failed_jobs` | Laravel queue | Jobs that failed after all retries |
| `job_batches` | Laravel queue | Batched job progress (from Phase 2 imports) |
