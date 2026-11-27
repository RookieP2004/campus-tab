# Glossary

## School terms

| Term | Meaning |
| --- | --- |
| Tenant | One school in Campus TAB. Each school's data is kept apart from every other school's |
| Group | A trust or chain that owns several schools (data model from Phase 1, dashboard in v2) |
| Chief Admin | The school's key holder; highest level inside a school |
| Wing | A part of the school such as Primary or Secondary, often run by a Vice Principal |
| Section | One division of a class, for example 7-B |
| Class teacher / subject teacher | Assignments, not separate logins: one teacher can be class teacher of 7-B and maths teacher of 8-A |
| Fee Collector | A duty given to a staff member who takes fees at the counter, not a level |
| PEN / APAAR | Government student identifiers; optional, stored only with consent |
| UDISE+ | The government school-data system |
| DPDP | India's Digital Personal Data Protection Act 2023 and its 2025 Rules |
| HPC | Holistic Progress Card (NEP 2020 style report card) |
| Fee maaf | A fee waiver |
| DLT | India's registration system for SMS templates, required for OTPs |

## Code terms

| Term | Meaning |
| --- | --- |
| API | The back end the screens talk to, at `/api/v1` |
| Module | One business area of the back end, in its own folder under `backend/app/Modules/` |
| Modular monolith | One application cut into modules, instead of many small services |
| Route | A path the API answers, such as `GET /api/v1/health` |
| Controller | The code that receives a request and returns a response |
| Policy | The code that decides whether a person may do an action; every permission check goes through one |
| Middleware | Checks that run before a controller: signed in, school, licence |
| Model | The code representing one kind of record (a collection in MongoDB) |
| Collection | MongoDB's word for a table |
| Replica set | MongoDB running as a group of servers; required for transactions, so even dev runs a one-server set (`rs0`) |
| Transaction | Several database changes that succeed or fail together |
| Migration | Code that creates collections and indexes |
| Queue / job | Work done in the background (imports, PDFs, notifications) |
| Horizon | Runs and shows the queue workers |
| Reverb | Pushes live updates to open screens over WebSockets |
| Sanctum | Laravel's sign-in package (cookies for web, tokens for apps) |
| Seeder | Code that fills the database with demo data |
| Pest | The back-end test framework |
| Pint | Formats PHP code |
| PHPStan | Checks PHP code for type mistakes without running it |
| Vite | The front-end dev server and build tool |
| Vitest | The front-end test framework |
| TanStack Query | Fetches and caches server data in screens |
| Zustand | Holds screen state such as the chosen theme |
| i18next | Translates every on-screen string |
| Design token | A named colour, size or radius (for example `--ct-surface`) used instead of a raw value |
| Docker volume | Storage managed by Docker, outside the D: drive |
| CI | GitHub Actions: checks every change automatically |
| LF / CRLF | Line-ending styles; Linux needs LF |
