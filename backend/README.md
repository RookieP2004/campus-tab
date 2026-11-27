# Campus TAB — backend

The Campus TAB API: Laravel 13 on PHP 8.4, API-only, MongoDB through `mongodb/laravel-mongodb`, Redis for cache, queues and sessions, Horizon for queue workers, Reverb for WebSockets and Sanctum for sign-in.

Everything runs in Docker; see the repository [`README.md`](../README.md) and [`docs/report/operations.md`](../docs/report/operations.md).

```bash
cd ../infra
docker compose exec api composer test       # Pest
docker compose exec api composer lint       # Pint (check) + PHPStan level 6
docker compose exec api composer format     # Pint (fix)
docker compose exec api php artisan report:generate
```

- Modules: `app/Modules/<Module>/` (routes, controllers, domain code, console commands).
- Shared code: `app/Support/`.
- Tests: `tests/Feature/<Module>/`; database tests use `Tests\Concerns\RefreshMongoDatabase`.
- Rules: [`docs/CONVENTIONS.md`](../docs/CONVENTIONS.md).

Copyright (c) 2026 Sujal Chandel. All rights reserved.
