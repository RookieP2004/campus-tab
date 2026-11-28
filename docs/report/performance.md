# Performance

The Phase 9 load test: what was measured, on what, and the p95 times.

> **Laptop numbers are only indicative.** Everything (MongoDB, Redis, the API and k6 itself) ran on one laptop inside Docker Desktop, capped at 8 GB, with PHP's built-in server instead of PHP-FPM behind nginx. Real hosting will be different. Run the test again on the real servers before the pilot, and after any big change.

## Results (28 November 2026)

**Data:** 50 schools × 1,000 students = 50,000 students and 50,000 enrolments, 40 sections per school, each with its own class teacher, plus a Principal and a fee collector, an open Maths test per section, and fee dues in 5 schools (the full size the owner asked for; the 8 GB limit was enough, so nothing was scaled down).

**Load:** 20 teachers (ramping up over 30 s, 2 minutes steady, 15 s down) and 3 fee counters at the same time, each in a different school. One teacher round is: sign in, open the dashboard, open and save today's register, open and save the markbook, sign out. One counter round is: sign in, take a cash payment, sign out.

| Action | p95, production-like | median | p95, light load (3 users) | p95, development mode |
| --- | ---: | ---: | ---: | ---: |
| Sign-in | 1,025 ms | 783 ms | 355 ms | 2,753 ms |
| Dashboard | 1,001 ms | 786 ms | 310 ms | 2,770 ms |
| Attendance save (whole register) | 939 ms | 729 ms | 220 ms | 2,647 ms |
| Markbook save | 1,308 ms | 879 ms | 420 ms | 2,959 ms |
| Counter payment (with receipt) | 862 ms | 624 ms | 214 ms | 2,496 ms |

- **Production-like** is the main result: config, routes and events cached, OPcache on, debug off, 8 PHP workers. 4,794 requests in 2 min 50 s (29 a second), **0 failed**, every check passed (826 sign-ins, 579 dashboards, 579 registers, 579 markbooks, 247 payments).
- **Light load** is the same set-up with 2 teachers and 1 counter: roughly how long one request takes when nothing is waiting.
- **Development mode** is the first run, with the development settings (no OPcache, debug on): every request compiled Laravel again. It shows why the production settings matter; 1,724 requests, also 0 failed.

**Reading the numbers:** with 23 users and 8 workers on a laptop, most of the time under load is waiting for a free worker, not work. The light-load column (about 200 to 400 ms) is the work itself. Two of the five targets (dashboard under 800 ms, markbook under 1,000 ms at p95) were missed under full load on the laptop; all five were met under light load. On the real servers, check these first: the number of PHP-FPM workers, OPcache, and a MongoDB that does not share the CPU with PHP.

## How the test is set up

| Part | Where |
| --- | --- |
| Seed command | `php artisan loadtest:seed --schools=50 --students=1000 --fee-schools=5` ([`LoadTestSeedCommand.php`](../../backend/app/Modules/System/Console/LoadTestSeedCommand.php)). Runs only on a database whose name ends in `_load`, never in production. Students, enrolments and guardians are inserted in bulk (no audit entries); everything else goes through the normal code. Every account shares one made-up password. It writes the accounts and ids k6 needs to `infra/loadtest/fixtures.json` (git-ignored) after each school, so a stopped run can be continued. |
| Load-test API | Compose service `api-load` (profile `loadtest`), database `campus_tab_load`, port 8001, production-like settings (see the table above), queue `sync`, push and broadcasting off |
| k6 script | [`infra/loadtest/campus.js`](../../infra/loadtest/campus.js), k6 1.8.3 in Docker (service `k6`). One metric per action (`t_sign_in`, `t_dashboard`, `t_attendance_save`, `t_markbook_save`, `t_counter_payment`), with targets (p95 under 1,500 / 800 / 1,000 / 1,000 / 1,500 ms) and under 1 % failed requests. `SMOKE=1` runs a 20-second check with 3 users |
| Results | `infra/loadtest/results/summary.json` (git-ignored) |

Seeding took 12 minutes 23 seconds on the laptop (about 40 s for a school with fees, 12 s without).

## Run it again

From `infra/`:

```bash
docker compose up -d mongo redis
docker compose --profile loadtest up -d api-load
docker compose exec api-load php artisan migrate --force
docker compose exec api-load php artisan loadtest:seed --schools=50 --students=1000 --fee-schools=5
docker compose --profile loadtest run --rm -e SMOKE=1 k6   # 20-second check first
docker compose --profile loadtest run --rm k6              # the full test, about 3 minutes
docker compose --profile loadtest stop api-load
```

To start again from nothing: `docker compose exec mongo mongosh --eval 'db.getSiblingDB("campus_tab_load").dropDatabase()'` and delete `infra/loadtest/fixtures.json`. The load-test database never touches the development data (`campus_tab`) or the test database (`campus_tab_test`).
