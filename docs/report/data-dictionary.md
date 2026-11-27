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
| `users` | [`User`](../../backend/app/Models/User.php) | `name`, `email`, `password` | — |

<!-- generated:collections:end -->

## Collections written by packages

| Collection | Written by | What it holds |
| --- | --- | --- |
| `migrations` | Laravel | Which migrations (collection and index definitions) have run |
| `failed_jobs` | Laravel queue | Jobs that failed after all retries |
| `job_batches` | Laravel queue | Batched job progress (from Phase 2 imports) |
