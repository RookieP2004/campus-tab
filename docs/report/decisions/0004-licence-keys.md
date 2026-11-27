# 0004 — Licence keys: hash only, signed payload

Date: 2026-11-27 · Phase 1 · Status: accepted

**Context.** A licence key activates a school once and creates its Chief Admin. The record behind it decides what the school may use and until when, so it must not be guessable, readable from a database copy, or changeable by editing the database.

**Decision.**

- Keys are `CTAB-` plus 16 random characters from `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` (80 bits; no 0, O, 1 or I so they can be read out on the phone). Input ignores case, spaces and dashes.
- Only `HMAC-SHA256(key, LICENCE_KEY_PEPPER)` and the last four characters are stored. An HMAC (not a slow password hash) is enough because the key is long and random, and it lets us find the licence by hash.
- The terms (school, plan, modules, seats, dates) are a canonical JSON payload signed with Ed25519 through PHP's built-in sodium extension. Every check verifies the signature and that the plain fields still match the payload; a mismatch makes the licence inactive.
- Keys come from `.env` (`php artisan licence:keygen --write`); tests generate their own pair per run.
- Activation needs a code sent to the contact we registered for the school, not just the key, so a leaked key alone can't activate a school.
- The first token after activation can only enrol MFA and expires after 30 minutes.

**Consequences.** Losing the pepper or the signing keys invalidates every licence, so they must be backed up with the other production secrets. The secret key lives on the API server for now, because issuing runs in the same app; when the platform console moves to its own service, only the public key needs to stay on school-facing servers.
