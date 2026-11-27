# Configuration

Two kinds of settings:

1. **Environment variables** — how the server is wired (database, Redis, mail). Set per environment in `.env` files that are never committed. Names only here, never values of secrets.
2. **The school config** — every school-level choice (branding, modules, attendance mode, leave chain, fee rules), stored as a versioned JSON document per school. Built in **Phase 1**; each key will be listed below with its default and effect. The intended shape is "The school config (JSON)" in [`docs/BLUEPRINT.md`](../BLUEPRINT.md).

## Environment variables (back end)

Defined in [`backend/.env.example`](../../backend/.env.example). In Docker, [`infra/docker-compose.yml`](../../infra/docker-compose.yml) sets the connection variables directly, which override `backend/.env`.

| Variable | Default (dev) | What it changes |
| --- | --- | --- |
| `APP_NAME` | `Campus TAB` | Name in emails and logs |
| `APP_ENV` | `local` | `local`, `testing`, `staging`, `production` |
| `APP_KEY` | generated on first start | Encryption key; secret |
| `APP_DEBUG` | `true` | Shows exception details in 500 errors; must be `false` outside development |
| `APP_URL` | `http://127.0.0.1:8000` | The API's own address |
| `FRONTEND_URL` | `http://127.0.0.1:5173` | The web app's address; the only origin CORS allows (comma-separate several) |
| `SANCTUM_STATEFUL_DOMAINS` | `127.0.0.1:5173` | Hosts whose requests get sign-in cookies (Phase 1) |
| `DB_CONNECTION` | `mongodb` | Always `mongodb` |
| `MONGODB_URI` | `mongodb://mongo:27017/?replicaSet=rs0` | MongoDB address; must name the replica set |
| `MONGODB_DATABASE` | `campus_tab` | Database name (tests force `campus_tab_test`) |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `redis`, `6379`, none | Redis for cache, sessions and queues |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `redis` | Where cache, sessions and jobs live |
| `BROADCAST_CONNECTION` | `reverb` | Live updates through Reverb |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | dev placeholders | Reverb credentials; secret outside development |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `reverb`, `8080`, `http` | How the API reaches Reverb |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | `smtp`, `mailpit`, `1025` | Outgoing email (Mailpit catches it in dev) |
| `MAIL_FROM_ADDRESS` | `no-reply@campustab.local` | Sender address |
| `AWS_*` | empty, `ap-south-1` | Object storage for files (from Phase 2) |
| `REPORT_PATH` | `/docs/report` in Docker, else `../docs/report` | Where `report:generate` writes |

## Environment variables (front end)

Defined in [`frontend/.env.example`](../../frontend/.env.example). Only `VITE_*` values reach the browser, so they are never secret.

| Variable | Default | What it changes |
| --- | --- | --- |
| `VITE_API_BASE_URL` | `/api/v1` | Where the app sends API calls (same origin through the Vite proxy in dev) |
| `VITE_REVERB_APP_KEY` | `campus-tab-local-key` | Public Reverb key |
| `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | `127.0.0.1`, `8080`, `http` | Reverb address as the browser sees it |

Set on the `web` container only: `VITE_USE_POLLING=true` (watch files by polling) and `VITE_API_PROXY_TARGET=http://api:8000` (proxy target).

## Host ports (local stack)

Defined in [`infra/.env.example`](../../infra/.env.example): `API_PORT` 8000, `WEB_PORT` 5173, `REVERB_PORT` 8080, `MONGO_PORT` 27017, `REDIS_PORT` 6379, `MAILPIT_SMTP_PORT` 1025, `MAILPIT_UI_PORT` 8025.

## School config keys

_Phase 1._
