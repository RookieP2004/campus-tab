# Load the demo school and sign in as each role

The demo school is **Sunrise Public School, Bhopal (CBSE)**, school code **`SUNRISE-BPL`**, year 2026-27. Every name, phone number and email below is made up (taken from the screen designs in `docs/design/`).

## Load it

```bash
cd infra
docker compose exec api php artisan migrate
docker compose exec api php artisan db:seed --class=DemoSchoolSeeder
```

It runs only when `APP_ENV` is `local` or `testing`, and refuses anywhere else. Running it again changes nothing.

## Sign in

Open http://127.0.0.1:5173, enter the school code `SUNRISE-BPL`, then sign in with a login below.

**Every demo account has the same development password: `sunrise-demo-2026`.** This is the one exception to the rule that nobody else knows a person's password, and it exists only in local and test databases.

Clerk and above (and anyone with a teacher role, if they choose) **set up an authenticator app at the first sign-in**, then sign in with a 6-digit code every time.

| Role | Person | Login | MFA |
| --- | --- | --- | --- |
| Chief Admin | Vikram Malviya | `chief@sunrise.test` | set up at first sign-in |
| Principal | Dr. Meera Iyer | `principal@sunrise.test` | set up at first sign-in |
| Vice Principal (Primary wing) | Kavita Joshi | `vp.primary@sunrise.test` | set up at first sign-in |
| Clerk | Ramesh Kumar | `clerk@sunrise.test` | set up at first sign-in |
| Class teacher 7-B (also teaches Maths) | Anjali Verma | `anjali.verma@sunrise.test` | optional |
| Class teacher 8-A (also teaches English) | Pooja Saxena | `pooja.saxena@sunrise.test` | optional |
| Class teacher 3-A | Priya Nair | `priya.nair@sunrise.test` | optional |
| Subject teacher (Science 7-B, 8-A, 8-B) | Rakesh Sharma | `rakesh.sharma@sunrise.test` | optional |
| Subject teacher (Hindi) | Sunita Rao | `sunita.rao@sunrise.test` | optional |
| Subject teacher (PE) | Deepak Yadav | `deepak.yadav@sunrise.test` | optional |
| Staff | Kamla Devi | `kamla.devi@sunrise.test` | optional |
| Parent of Aarav (7-B) and Riya (3-A) | Suresh Patel | `parent@sunrise.test` with the password, **or** "Parent? Sign in with a code" with phone `9800000001` (the SMS code is in `backend/storage/logs/laravel.log`) | none |
| Student | Aarav Patel (7-B) | login name `sps20260002` | none |

## What to look at

- **Anjali Verma** sees only 7-B, including her class-only column *Seat row*.
- **Rakesh Sharma** teaches Science in 7-B but does not see *Seat row*.
- **Dr. Meera Iyer** sees every class and both custom columns (*House* is school-wide).
- **Kavita Joshi** sees only the primary wing (3-A).
- **Suresh Patel** sees exactly his two children.
- **Ramesh Kumar** can admit students, import them, and draft staff for the Principal to approve. He is also the **Attendance Officer**, so he can enter a teacher's or staff member's check-in by hand (never his own), and he keeps the holiday calendar: Christmas (25 December 2026) is approved, Republic Day (26 January 2027) waits for Dr. Meera Iyer's approval.
- **Student sign-in** is switched on in the demo school, so Anjali can print setup codes for 7-B.

## Leave (Phase 5)

The seeder adds the starter leave types (casual, sick, earned, duty, leave without pay) and three requests, made through the real workflow with dates counted from the day you seed:

- **Anjali Verma**'s casual leave, verified by Ramesh Kumar and **waiting for Dr. Meera Iyer** in `/approvals`.
- **Sunita Rao**'s sick leave, **with the Clerk**. Ramesh sees it in his inbox; Dr. Meera Iyer sees it flagged "You can fast-track".
- **Kamla Devi**'s casual leave for **today**, approved: she shows as away on the dashboard and the leave calendar, and her staff attendance shows On leave.

A school made before Phase 5 needs `php artisan identity:sync-roles --with-new-permissions` and `php artisan leave:seed-types --school=SUNRISE-BPL`.

## Tests and marks (Phase 6)

`DemoMarksSeeder` (run by `DemoSchoolSeeder`, or on its own for a school made before Phase 6: `php artisan db:seed --class=DemoMarksSeeder`):

- **Unit Test 1:** 7-B Mathematics (Anjali Verma) and 7-B Science (Rakesh Sharma) are entered, verified, locked and published. Suresh Patel sees Aarav's marks under **Results**.
- **A correction is waiting:** Rakesh Sharma asked to change the first 7-B student's Science mark by 4. Dr. Meera Iyer approves it in `/approvals` with a fresh authenticator code and a typed reason.
- **Unit Test 2:** 8-A Science is open with 9 of 12 marks in (the markbook design), and 8-A English is open and empty. Rakesh Sharma enters the rest at `/tests`.

## Report cards (Phase 7)

`DemoReportsSeeder` (run by `DemoSchoolSeeder`, or `php artisan db:seed --class=DemoReportsSeeder`; start `docker compose up -d gotenberg` first for the PDF):

- An active CBSE-style scheme for Classes 6 to 8 and a **Half-yearly exam** (Term 1) for 7-B Mathematics and Science, locked.
- A template from the CBSE I–VIII preset, and 7-B's cards made. Anjali Verma wrote the class remark on the first two; the first card is **published** with its PDF and QR code.
- Dr. Meera Iyer reviews and publishes the rest at `/report-cards`, and can re-issue the published one with a reason. Suresh Patel sees Aarav's card under **Results → Report cards** if Aarav's is the published one.

## Fees (Phase 8)

`DemoFeesSeeder` (run by `DemoSchoolSeeder`, or `php artisan db:seed --class=DemoFeesSeeder`):

- Heads Tuition, Annual charges, Transport, Exam; one plan for every class with four quarters, a ₹50 late fine after 5 days and a 10% sibling discount on tuition (Aarav and Riya are siblings). Dues raised up to today.
- **Kamla Devi** has the Fee Collector duty and one cash payment today; **Ramesh Kumar** took two counter UPI payments for Aarav. Both can close their day at `/fees/closings`; Ramesh's own closing is confirmed by Dr. Meera Iyer.
- A **waiver** Ramesh proposed waits for Dr. Meera Iyer in `/approvals` (step-up).
- The **local test gateway** is on: Suresh Patel presses **Pay now** at `/fees`, chooses **Pay** in the test checkout, and the dues turn Paid when the signed webhook arrives (the `horizon` container sends it).

## Year-end dues and privacy (Phase 9)

`DemoPrivacySeeder` (run by `DemoSchoolSeeder`, or `php artisan db:seed --class=DemoPrivacySeeder` for a school seeded earlier):

- A **sample privacy notice**, version 1 (marked as sample text that needs a legal review), and the grievance contact *Anita Desai, privacy@sunrise.test* on the school profile.
- **Suresh Patel** has said yes to keeping Aarav's PEN and no to public photos, from his own account (Account menu → **Privacy**).
- An **access request** from Suresh Patel about Riya waits in **Privacy office → Requests** for Vikram Malviya (Chief) or Dr. Meera Iyer (Principal).

What to try:

- As **Dr. Meera Iyer**: **Report cards → Year-end promotion**, preview 2026-27 → 2027-28. Step 3 lists everyone who still owes fees; **Apply** stays off until you approve the list with a code. (Applying really closes the demo year: reload the demo school afterwards with `docker compose down -v`.)
- As **Suresh Patel**: **Privacy**: read the notice, change a consent, send a request.
- As **Ramesh Kumar** (Clerk): a student's **IDs** tab: PEN is open only for Aarav (his parent said yes); Riya's waits for the parent.
- As **Vikram Malviya** (Chief): **Privacy office → Retention**: make a dry run (nothing is deleted), and **Incidents**.
