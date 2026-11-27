# 0001 — MongoDB as a replica set everywhere, and our own test reset

Date: 2026-11-27 · Phase 0 · Status: accepted

## Problem

Leave balances, mark locks and promotions need multi-document transactions, and a standalone MongoDB does not support transactions. Separately, Laravel's `RefreshDatabase` test trait relies on SQL transactions and does not work with `mongodb/laravel-mongodb`.

## Options

1. Standalone MongoDB in development, replica set only in production — transactions would fail locally and bugs would appear only after deployment.
2. A three-node replica set locally — correct but heavy for a laptop.
3. **A single-node replica set (`rs0`) in development and CI**, the same as production behaviour for transactions.

For tests: (a) wrap each test in a MongoDB transaction — fragile, some operations (creating collections and indexes) cannot run inside one; (b) **drop and recreate the test database before each test** — simple and complete.

## Decision

- Option 3. In Docker the `mongo` healthcheck initiates `rs0` on first start; in CI a workflow step runs `docker run … --replSet rs0`, initiates it and waits until it is primary (service containers cannot pass `--replSet`).
- Option (b): [`tests/Concerns/RefreshMongoDatabase.php`](../../../backend/tests/Concerns/RefreshMongoDatabase.php) drops the database and re-runs migrations before each test that uses it. It refuses to run unless the database name ends in `_test`, so it can never wipe development or production data.
- `phpunit.xml` forces the test settings as both `env` and `server` values, because the containers also set those variables and Laravel reads `$_SERVER` first.

## Consequences

Dropping a database per test costs a little time (about 0.3 s each); fine at this size. If the suite grows large, the helper can switch to clearing only the collections a test touched.
