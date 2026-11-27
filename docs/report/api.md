# API

REST over HTTP(S) at `/api/v1`, JSON in and out. Conventions (from the blueprint's "API design"):

- Plural nouns (`/students`), actions as sub-resources (`/leave-requests/{id}/verify`).
- One error shape everywhere: `{ "error": { "code": "…", "message": "…", "details": {} } }`. Codes are listed in [modules/system.md](modules/system.md), section 3.
- From Phase 1: the school always comes from the signed-in user's token, never the request; `Idempotency-Key` on creates and workflow actions; `ETag`/`If-Match` on markbook and config saves; cursor pagination; rate limits.
- From Phase 1 an OpenAPI spec is generated from the code and a TypeScript client is generated from it into `packages/api/`; a link to the spec will be added here.

## Requests in plain words

| Request | What it does | Who | Module page |
| --- | --- | --- | --- |
| `GET /api/v1/health` | Says whether the API can reach its database and queue | Anyone | [System](modules/system.md) |

Each phase adds its requests to this table.

## All routes (generated)

Only routes under `/api` are listed. Horizon's dashboard (`/horizon`, local only) and Laravel's `/up` sit outside it.

<!-- generated:routes:start -->
<!-- Written by `php artisan report:generate`. Do not edit by hand. -->

| Method | Path | Name | Handler | Middleware |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/config` | config.show | `SchoolConfigController` | api, auth:sanctum, tenant, mfa.enrolled, licence |
| PUT | `/api/v1/config` | config.update | `SchoolConfigController` | api, auth:sanctum, tenant, mfa.enrolled, licence |
| GET | `/api/v1/config/versions` | config.versions.index | `SchoolConfigController` | api, auth:sanctum, tenant, mfa.enrolled, licence |
| GET | `/api/v1/config/versions/{version}` | config.versions.show | `SchoolConfigController` | api, auth:sanctum, tenant, mfa.enrolled, licence |
| POST | `/api/v1/config/versions/{version}/rollback` | config.versions.rollback | `SchoolConfigController` | api, auth:sanctum, tenant, mfa.enrolled, licence |
| GET | `/api/v1/health` | system.health | `HealthController` | api |
| POST | `/api/v1/licences/activate` | licences.activate | `ActivationController` | api, throttle:activation |
| POST | `/api/v1/licences/activate/otp` | licences.activate.otp | `ActivationController` | api, throttle:activation |
| GET | `/api/v1/public/tenants/{code}/config` | public.tenants.config | `PublicConfigController` | api, throttle:public |

<!-- generated:routes:end -->
