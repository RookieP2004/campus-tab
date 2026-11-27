# Add a back-end module

Example: the `Leave` module.

1. Create `backend/app/Modules/Leave/` with:
   - `routes.php` — routes are loaded automatically by [`backend/routes/api.php`](../../../backend/routes/api.php) under `/api/v1`.
   - `Http/Controllers/`, `Models/`, `Policies/`, `Domain/` as needed.
   - `Console/` for artisan commands — loaded automatically by [`backend/bootstrap/app.php`](../../../backend/bootstrap/app.php).
2. Every school model uses `BelongsToTenant` and `SoftDeletes` (Phase 1 onward), and every permission check goes through a Policy. See [`docs/CONVENTIONS.md`](../../CONVENTIONS.md).
3. Put tests in `backend/tests/Feature/Leave/`. Tests that touch the database start with `uses(Tests\Concerns\RefreshMongoDatabase::class);`.
4. Throw `App\Support\Http\ApiException` for domain errors with a stable code, for example `new ApiException('leave_balance_low', 'Not enough leave balance.', 422)`.
5. Run `docker compose exec api php artisan report:generate` so the module, its routes and its collections appear in the report.
6. Fill in `docs/report/modules/leave.md` (all 13 sections) and add a `CHANGELOG.md` entry. CI fails the pull request otherwise.
