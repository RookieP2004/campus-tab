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
