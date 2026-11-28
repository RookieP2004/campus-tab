# 0005 — Tenant scope, the query guard, and the one sign-in exception

Date: 2026-11-27 · Phase 1 · Status: accepted

**Context.** Every school shares one MongoDB database. A single query without `tenant_id` could show one school another school's data. The blueprint asks for a central scope and a CI test that fails if any query runs without the tenant filter.

**Decision.**

1. **The scope fails closed.** `BelongsToTenant` adds `tenant_id = <current school>` to every query, fills it on create, and adds it to every update and delete filter. With no school set it throws `MissingTenantContext` instead of reading every school. Writing a record of another school throws `CrossTenantWrite`.
2. **The school is set only from the signed-in user** (middleware `tenant`), or by platform code that names the school with `TenantContext::run($tenantId, fn)`.
3. **The query guard** watches every MongoDB command. Any read or write on a collection not on the platform list without `tenant_id` is recorded. In the test suite every recorded query fails the test (`Tests\TestCase::assertPostConditions`), so CI fails. In development it is logged.
4. **Platform collections** (not school data) are listed in `config/tenancy.php`: `tenants`, `licences`, `platform_admins`, `personal_access_tokens`, and Laravel's `migrations`, `jobs`, `failed_jobs`, `job_batches`, `cache`, `cache_locks`, `sessions`. Everything else counts as school data, including collections nobody has listed yet.
5. **The one documented exception: sign-in and token lookup happen before the school is known.**
   - A bearer token is looked up in `personal_access_tokens` (a platform collection). Its owner is loaded inside `TenantContext::bypass('sign-in: load the owner of an API token', …)` (`Identity\Models\PersonalAccessToken`).
   - A session cookie holds only the user id; `Identity\Auth\SchoolUserProvider` loads that user inside a bypass too.
   - Then the `tenant` middleware sets the school from that user's `tenant_id`. Nothing else runs in a bypass.
   - **Password sign-in is not part of the exception.** It finds the school from its code first and looks the user up inside that school (`Identity\Domain\SchoolUserLocator`). `retrieveByCredentials` without a school throws.

**Consequences.** New code that forgets the tenant fails loudly in tests. Adding a bypass anywhere else needs a new decision record. Queued jobs must call `TenantContext::run()` with the school they work for (the context is a scoped binding, empty at the start of each job).

**Later:** a second exception, the payment webhook finding its school from our own order record, is [decision 0015](0015-payment-webhook-tenant-exception.md).
