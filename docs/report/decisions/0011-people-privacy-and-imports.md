# 0011 · People: scopes from records, Aadhaar, seats, imports and the demo password

**Date:** 2026-11-27 · **Phase:** 2 · **Status:** accepted

## Decisions

1. **Scopes come from records.**
   - `User::accessScope()` asks an `AccessScopeSource`, bound by the People module to `RecordAccessScopes`. It is worked out once per request and flushed when a guardian, staff record, section or assignment changes.
   - The facts:
     - **child:** every guardian record linked to the account
     - **class:** the current year's sections whose class teacher is the person's staff record
     - **subject:** their current-year teaching assignments
     - **wing:** the staff record's wings
   - The old stored `users.access` field is gone, so nothing an admin types can widen a scope.
   - A subject teacher covers a student when they teach any subject in the student's section.
2. **Full Aadhaar numbers are refused everywhere.**
   - **The rule:** a full number is 12 digits, not starting with 0 or 1, that passes the Verhoeff check.
   - **Requests:** the `no-aadhaar` middleware on the `school` group refuses any request body holding one in any field, alone or inside text (4-4-4 groups with spaces or dashes included). The answer is 422 `aadhaar_not_allowed`, naming the fields and never the value.
   - **Imports:** every cell is checked.
   - **Phone numbers:** one written with `+` is not a match.
   - **Custom fields** meant for Aadhaar or bank account numbers are refused by type, name or label, in English and Hindi.
   - **What may be kept:** only `ids.aadhaar_last4` (exactly 4 digits).
3. **PEN and APAAR** are stored only with a `consents` record (given) for that purpose, and the newest record per purpose counts.
4. **Seats.** Creating or importing students beyond the licence's `seats.students` is refused with one message that names the limit, the number enrolled and the number left. An import's dry run puts this check first and blocks the commit. The owner's rule replaces the blueprint's "plus grace" for admissions; the 90% warning stays.
5. **Imports run on the queue** (`RunImport`, as the importer, inside the school).
   - **Dry run:** a row-by-row report (ok, error or duplicate, with plain messages), downloadable as .xlsx. Progress is written every 25 rows, and the screen polls with background requests.
   - **Commit:** checks the rows again, creates only the ok rows, and runs under `AuditRecorder::withMeta(['import_batch_id' => …])`, so every entry it writes carries the batch id. One `import.committed` entry closes it.
   - **Duplicates** are checked on name + date of birth + guardian phone, inside the file and against the school.
6. **The Clerk's draft for the Principal.** The staff record has status `draft` with `requested_roles`. No account exists until the Principal approves; the account is then made through `UserAdmin`, so the approver's level limits the roles.
7. **Student sign-in** is the school setting `sign_in.methods` containing `student_login`, off by default.
   - **Codes:** the class teacher's setup codes are valid 48 hours and shown once.
   - **Login names** come from the admission number.
   - **When the setting is off,** student password sign-in is refused with the same answer as a wrong password.
8. **The demo password.** `DemoSchoolSeeder` gives every demo account one known development password. It is the only exception to "nobody types another person's password", and the seeder throws unless `APP_ENV` is `local` or `testing`.
9. **Logo.** It is uploaded to private storage and served by `GET /public/tenants/{code}/logo`, so no storage link is needed. PNG, JPEG and WebP only, because SVG can carry script.

## Consequences

- A Chief editing roles keeps their changes. `identity:sync-roles --with-new-permissions` adds only permissions that were not part of the role when it was seeded.
- A 12-digit value that is not an Aadhaar number but happens to pass the Verhoeff check (about 1 in 10 of random 12-digit numbers) is also refused. It must then be written with its separators or prefix, such as a phone with `+91`.
