# Security

How Campus TAB is protected, checked against **OWASP ASVS 4.0.3, level 2** (every level 1 and level 2 requirement), with the results of the ZAP baseline scan, the dependency and secret scans, the security headers and the upload virus scan. Written in Phase 9. The **external penetration test** happens before launch, outside this report ([pilot/before-going-live.md](pilot/before-going-live.md)).

## Summary

| Check | Result (2026-11-28) | Where |
| --- | --- | --- |
| ZAP baseline, web app (production build behind `edge`) | **0 high, 0 medium, 0 low**; 2 informational | [security/zap-baseline-web.html](security/zap-baseline-web.html) |
| ZAP baseline, API (`/api/v1/public/tenants/{code}/config`) | **0 high, 0 medium, 0 low**; 2 informational | [security/zap-baseline-api.html](security/zap-baseline-api.html) |
| `composer audit` (fails CI on high or critical) | 0 advisories | CI job **Security** |
| `npm audit`, web app, phone app, desktop app (fails CI on high or critical) | 0 advisories (after updating `sharp` 0.34.5 → 0.35.5 in the phone app's icon tooling, which had high advisories) | CI job **Security** |
| gitleaks, whole history and working tree | No leaks (3 made-up test values allow-listed by exact string, `.gitleaks.toml`) | CI job **Security** |
| No tracking or third-party analytics | None | CI step `no-trackers.mjs` |
| Upload virus scan (ClamAV) | EICAR refused by the real ClamAV through the live API (`422 file_infected`); fail-closed when the scanner is down | `VirusScanTest` |

The first ZAP run found one **low** issue (no `Cross-Origin-Embedder-Policy` on the web app); it was fixed (`require-corp`) and the scan run again. The two informational notes are ZAP's "modern web application" (it is a single-page app) and "storable content" (the hashed build files are cached on purpose; API answers are `no-store`).

**How to run the scan again** (from `infra/`, with the stack up):

```bash
docker compose exec web npm run build -w @campus-tab/frontend
docker compose --profile edge up -d edge
docker run --rm --network campus-tab_default -v "$PWD/../docs/report/security:/zap/wrk:rw" -t ghcr.io/zaproxy/zaproxy:stable zap-baseline.py -t http://edge:80/ -r zap-baseline-web.html -J zap-baseline-web.json -m 3 -I
docker run --rm --network campus-tab_default -v "$PWD/../docs/report/security:/zap/wrk:rw" -t ghcr.io/zaproxy/zaproxy:stable zap-baseline.py -t http://edge:80/api/v1/public/tenants/SUNRISE-BPL/config -r zap-baseline-api.html -J zap-baseline-api.json -m 3 -I
```

## Security headers

| Header | Web app (nginx `edge`, [`security-headers.inc.template`](../../infra/docker/edge/security-headers.inc.template)) | API ([`SecurityHeaders.php`](../../backend/app/Support/Http/SecurityHeaders.php)) |
| --- | --- | --- |
| Content-Security-Policy | `default-src 'self'; script-src 'self'; style-src 'self'` (no inline scripts or styles), `connect-src 'self'` + the Reverb socket, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` | `default-src 'none'` (it serves only JSON and files) |
| frame-ancestors | `'none'` (and `X-Frame-Options: DENY`) | `'none'` (and `X-Frame-Options: DENY`) |
| Referrer-Policy | `same-origin`: nothing goes to other sites; the app's own API calls keep their Referer, which Sanctum needs to recognise the signed-in browser on GET requests (with `no-referrer` the web app could not stay signed in). The password pages take the one-time token out of the address as soon as they open. | `no-referrer` |
| Strict-Transport-Security | `max-age=31536000; includeSubDomains`, **production only** (`HSTS_HEADER`) | same, only when `APP_ENV=production` |
| Others | `X-Content-Type-Options: nosniff`, `Permissions-Policy` (camera and location for the school's own pages only), `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `Cross-Origin-Embedder-Policy` | `nosniff`, `Permissions-Policy` (none), `COOP`, `Cache-Control: no-store` on API answers, no `X-Powered-By` |
| Desktop app | Tauri's own CSP in `tauri.conf.json` | — |

The strict CSP needed one code change: printing setup codes added an inline `<style>`; it now uses a constructed stylesheet.

## Uploads

Every file in a school request passes `ScanUploadedFiles` (the `school` middleware group) before a controller sees it: notice attachments, leave documents, letterheads, register photos, the school logo and imports. A structural test fails if any route reading an uploaded file is not behind it. ClamAV (`clamav` container) gets the file over its `INSTREAM` protocol. Infected: `422 file_infected`, nothing stored, an audit entry `upload.infected` (field, size, SHA-256, what was found; never the content) and a log warning. Scanner down: `503 virus_scan_unavailable` (fail closed). The EICAR test string exists in the code only as two halves joined at runtime, so desktop antivirus never quarantines a file in the repository.

## OWASP ASVS 4.0.3, level 2

Status: **Met**, **Partly** (what is missing is said), **N/A** (does not apply), **Launch** (depends on the hosting, decided before launch; listed in [before-going-live](pilot/before-going-live.md)). Requirement text is paraphrased.

### V1 Architecture, design and threat modelling

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 1.1.1 | Secure development life cycle | Met | CONVENTIONS, PR review by the owner, CI gates, report per change |
| 1.1.2 | Threat modelling for design changes | Partly | Decisions record the threats per feature (0005, 0009, 0013, 0015, 0016, 0017); no formal STRIDE model yet |
| 1.1.3 | Security constraints in user stories | Met | Owner's rules in each BUILD-PROMPTS phase, "done when" checks |
| 1.1.4 | Trust boundaries documented | Met | `02-request-journey.md`, decisions 0005, 0015, 0016 |
| 1.1.5 | High-level architecture documented | Met | BLUEPRINT "System architecture", `01-overview.md` |
| 1.1.6 | Central, reusable security controls | Met | Middleware groups, `BelongsToTenant`, Policies, `AccessControl`, `ScanUploadedFiles`, `SecurityHeaders` |
| 1.1.7 | Secure coding checklist available | Met | CONVENTIONS sections 3 and 4 |
| 1.2.1 | Low-privilege accounts per component | Launch | Containers run unprivileged services; production accounts per service at hosting |
| 1.2.2 | Component communication authenticated | Partly | MongoDB/Redis on a private network locally; auth on both is a launch item |
| 1.2.3 | One vetted authentication mechanism | Met | Sanctum (session or token) + TOTP, `SignInService` |
| 1.2.4 | All sign-in paths equally strong | Met | Password, parent OTP, student code all pass the same lockout and session rules (decision 0006) |
| 1.4.1 | Access control enforced at trusted points | Met | Server-side Policies and `permission:` middleware on every route (route test) |
| 1.4.4 | One access-control mechanism | Met | `AccessControl` + Policies |
| 1.4.5 | Feature/attribute based access control | Met | `module.action:scope` abilities (decision 0007) |
| 1.5.1 | Input/output requirements defined | Met | Request validation per controller, OpenAPI spec |
| 1.5.2 | No serialization of untrusted data | Met | JSON only; no `unserialize` of input |
| 1.5.3 | Validation on the server | Met | Laravel validation in every controller |
| 1.5.4 | Output encoding near the interpreter | Met | React escapes; Blade/`htmlspecialchars` in PDF HTML |
| 1.6.1 | Key management policy | Partly | Keys only in env (`APP_KEY`, licence Ed25519, push keys); rotation procedure is a launch item |
| 1.6.2 | Key material protected | Met | Env only, never in code (gitleaks), encrypted fields with `APP_KEY` |
| 1.6.3 | Keys replaceable | Partly | Push, gateway and licence keys replaceable by config; `APP_KEY` rotation needs re-encryption (launch runbook) |
| 1.6.4 | No secrets in the client | Met | Front end and apps carry only public addresses |
| 1.7.1 | Common logging format | Met | Laravel log + `audit_logs` (decision 0008) |
| 1.7.2 | Logs sent to a remote system | Launch | Log shipping chosen with hosting |
| 1.8.1 | Sensitive data identified | Met | `data-dictionary.md`, `SensitiveValue`, DPDP purposes |
| 1.8.2 | Protection levels defined | Met | Decisions 0011, 0013, 0017 |
| 1.9.1 | Encrypted traffic between components | Launch | TLS to Atlas/Redis at hosting; local stack is one private network |
| 1.9.2 | Both sides of a connection verified | Launch | Same |
| 1.10.1 | Source control with review | Met | GitHub, owner reviews every PR |
| 1.11.1 | Business flows documented | Met | Module pages, sections 1 and 5 |
| 1.11.2 | High-value flows safe under concurrency | Met | MongoDB transactions (leave, marks, fees, promotion), atomic receipt counter, ETag on markbook |
| 1.12.2 | Uploads served as downloads or from another domain | Met | Signed links, `Content-Disposition`, `nosniff`, API CSP `default-src 'none'` |
| 1.14.1 | Components of different trust separated | Met | API, workers, Gotenberg (no network out, JS off), ClamAV in separate containers |
| 1.14.2 | Signed binaries / trusted connections | Partly | Android release signed with our key; Windows/macOS/iOS signing after certificates (app READMEs) |
| 1.14.3 | Pipeline warns of vulnerable components | Met | CI Security job |
| 1.14.4 | Repeatable deployments | Partly | CI builds apps; server deploy pipeline with hosting |
| 1.14.5 | Deployments sandboxed | Launch | Containers; hosting choice |
| 1.14.6 | No unsupported client technology | Met | React only |

### V2 Authentication

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 2.1.1 | Passwords at least 12 characters | **Partly** | Minimum is **10** (`identity.passwords.min_length`, Phase 1). Raising it to 12 is a one-line change: owner's decision |
| 2.1.2 | 64+ characters allowed | Met | Up to 200 |
| 2.1.3 | No truncation | Met | bcrypt pre-check: 200 max; no trimming |
| 2.1.4 | Any Unicode allowed | Met | No character rules |
| 2.1.5 | Users can change their password | Met | `PUT /me/password` |
| 2.1.6 | Change needs the current password | Met | `MeController::changePassword` |
| 2.1.7 | Breached/common passwords refused | Met | Bundled common list + padded-word rule (`PasswordPolicy`) |
| 2.1.8 | Strength meter | Partly | Clear rules and errors; no meter |
| 2.1.9 | No composition rules | Met | None |
| 2.1.10 | No forced rotation | Met | None |
| 2.1.11 | Paste and password managers allowed | Met | Standard inputs, `autocomplete` set |
| 2.1.12 | Show password option | Partly | No show/hide toggle yet (small front-end change) |
| 2.2.1 | Anti-automation | Met | Account lockout (5), per-IP failures, OTP send limits (`identity.sign_in`, `parent_otp`) |
| 2.2.2 | Weak authenticators (SMS) not alone for high roles | Met | OTP only for parents; MFA mandatory from Clerk up |
| 2.2.3 | Notify on credential changes | Met | `PasswordChangedMail`, MFA reset notices |
| 2.3.1 | Initial secrets random and short-lived | Met | Password links (`PasswordLinks`), licence activation OTP |
| 2.3.2 | Enrolment of authenticators | Met | TOTP enrolment with recovery codes |
| 2.3.3 | Renewal instructions | Met | Recovery codes regeneration, MFA reset by a higher level |
| 2.4.1 | Approved password hashing | Met | bcrypt |
| 2.4.2 | Salt | Met | bcrypt built-in |
| 2.4.3 | PBKDF2 iterations | N/A | bcrypt used |
| 2.4.4 | bcrypt work factor ≥ 10 | Met | 12 (`BCRYPT_ROUNDS`) |
| 2.4.5 | Extra secret-keyed iteration (pepper) | Partly | Not used; tokens and push tokens use HMAC with `APP_KEY` |
| 2.5.1 | Recovery secret not sent in clear | Met | One-time link, hash stored |
| 2.5.2 | No hints or secret questions | Met | None |
| 2.5.3 | Recovery never reveals the password | Met | Set-new only |
| 2.5.4 | No shared or default accounts | Met | Demo password local/testing only (`DemoSchoolSeeder` refuses elsewhere) |
| 2.5.5 | Notify when a factor changes | Met | Mails on password and MFA changes |
| 2.5.6 | Secure forgotten-password flow | Met | Emailed one-time link; MFA still required after |
| 2.5.7 | Lost MFA: identity proofed again | Met | Reset only by a higher level with step-up (`users.reset_mfa`), platform for a Chief (`ChiefRecovery`) |
| 2.6.1–2.6.3 | Recovery codes single-use, random, hashed | Met | `Mfa` recovery codes |
| 2.7.1 | SMS not default for high-value | Met | Parents only |
| 2.7.2 | Out-of-band codes expire ≤ 10 min | Met | OTP 5 minutes |
| 2.7.3 | Out-of-band codes single-use | Met | `OtpService` |
| 2.7.4 | Secure channel | Launch | SMS provider with DLT |
| 2.7.5 | Stored hashed | Met | `OtpService` keeps a hash |
| 2.7.6 | Code from CSPRNG, ≥ 20 bits | Met | 6 digits from `random_int` |
| 2.8.1 | TOTP lifetime defined | Met | 30 s step |
| 2.8.2 | TOTP secrets protected | Met | Encrypted at rest |
| 2.8.3 | Approved algorithms | Met | RFC 6238 (pragmarx/google2fa) |
| 2.8.4 | TOTP code used once | Met | Last used step stored (`Mfa::checkTotp`) |
| 2.8.5 | Reuse logged | Partly | A reused code is refused (`verifyKeyNewer` with the last step) and counts as a failed attempt; not a separate audit entry |
| 2.8.6 | Device revocable | Met | MFA reset |
| 2.8.7 | Biometrics secondary only | N/A | No biometrics (owner's rule, decision 0016) |
| 2.9.1–2.9.3 | Cryptographic authenticators | N/A | Not used |
| 2.10.1 | Service credentials not static user passwords | Met | Service keys from env |
| 2.10.2 | No default service credentials | Launch | MongoDB/Redis auth at hosting |
| 2.10.3 | Service passwords protected | Launch | Hosting secret store |
| 2.10.4 | No secrets in code | Met | gitleaks in CI |

### V3 Session management

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 3.1.1 | No session token in URLs | Met | Cookie or `Authorization` header only |
| 3.2.1 | New token at sign-in | Met | `session()->regenerate()`, new Sanctum token |
| 3.2.2 | ≥ 64 bits | Met | Laravel session ids, Sanctum 40-char tokens |
| 3.2.3 | Tokens stored safely in the client | Met | HttpOnly cookie; apps: memory only (decision 0016) |
| 3.2.4 | Approved algorithms | Met | Laravel/Sanctum |
| 3.3.1 | Sign-out invalidates | Met | `SessionIssuer::endCurrent`, token deleted |
| 3.3.2 | Idle 30 min / absolute 12 h | Met | Idle 15 (MFA roles) or 30 minutes, 8 hours total |
| 3.3.3 | Other sessions end after password change | Met | `revokeAllFor(..., 'password_changed')` |
| 3.3.4 | See and end active sessions | Met | `/account/sessions` |
| 3.4.1 | Cookie `Secure` | Launch | `SESSION_SECURE_COOKIE=true` in production |
| 3.4.2 | `HttpOnly` | Met | Default true |
| 3.4.3 | `SameSite` | Met | `lax` |
| 3.4.4 | `__Host-` prefix | Partly | Cookie named `campus-tab-session`; rename with HTTPS at launch |
| 3.4.5 | Path set when sharing a domain | Met | Own host |
| 3.5.1 | Users can revoke tokens | Met | Device sessions screen ends a token |
| 3.5.2 | Tokens are not static API keys | Met | Expiring Sanctum tokens |
| 3.5.3 | Stateless tokens signed | N/A | Tokens are stateful |
| 3.7.1 | Re-auth for sensitive actions | Met | Step-up MFA (`step-up:<action>` on routes, route test) |

### V4 Access control

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 4.1.1 | Enforced on the server | Met | Policies, `permission:` on every school route (`RoutePermissionTest`) |
| 4.1.2 | Users cannot change access attributes | Met | `tenant_id` only from the user (CONVENTIONS 3.2); roles via guarded endpoints |
| 4.1.3 | Least privilege | Met | Scoped abilities; no escalation guard |
| 4.1.5 | Fails securely | Met | Unknown tenant throws; `ApiException` 403 |
| 4.2.1 | Protection against IDOR | Met | Tenant scope on every query (`TenantQueryGuard`), record scopes (class, child, own) |
| 4.2.2 | CSRF protection | Met | Sanctum XSRF for cookies; apps use bearer tokens and are refused cookies (`AppOriginCors`) |
| 4.3.1 | Admin interfaces need MFA | Met | Platform console and level ≥ 60 |
| 4.3.2 | No directory listing or metadata files | Met | nginx serves only the build; API has no file browsing |
| 4.3.3 | Extra checks for high-value actions | Met | Step-up, four-eyes on fee adjustments, Principal-only mark corrections |

### V5 Validation, sanitization and encoding

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 5.1.1 | HTTP parameter pollution | Met | Laravel validation of arrays and types |
| 5.1.2 | Mass assignment | Met | `$fillable` on every model; validated input only |
| 5.1.3 | Positive validation | Met | Rule lists per field |
| 5.1.4 | Structured, typed data | Met | Dates, enums, integers (paise), JSON schema for config |
| 5.1.5 | Redirect allow-list | Met | No open redirects; deep links allow-listed (`deepLinkPath`) |
| 5.2.1 | Rich text sanitized | Met | No HTML input; notices are plain text |
| 5.2.2 | Unstructured data sanitized | Met | Plain text rendered escaped |
| 5.2.3 | Mail header injection | Met | Laravel mailer, fixed headers |
| 5.2.4 | No `eval` | Met | None (bundle checked) |
| 5.2.5 | Template injection | Met | No user-provided templates; report templates are block lists |
| 5.2.6 | SSRF | Met | Outbound only to configured hosts (Gotenberg, gateways, FCM/APNs) |
| 5.2.7 | SVG sanitized | Met | SVG logos refused (`SchoolLogoTest`) |
| 5.2.8 | Markdown/expression sanitized | N/A | Not used |
| 5.3.1 | Context output encoding | Met | React, `htmlspecialchars` |
| 5.3.2 | Unicode preserved | Met | UTF-8 everywhere |
| 5.3.3 | XSS escaping | Met | React; strict CSP as a second layer |
| 5.3.4 | Parameterised queries | Met | Eloquent/MongoDB builder only |
| 5.3.5 | Escaping where needed | Met | Regex inputs quoted in search |
| 5.3.6 | JSON injection | Met | `json_encode` only |
| 5.3.7 | LDAP injection | N/A | No LDAP |
| 5.3.8 | OS command injection | Met | No shell calls with input |
| 5.3.9 | File inclusion | Met | No dynamic includes |
| 5.3.10 | XPath/XML injection | N/A | No XML input (xlsx read by OpenSpout) |
| 5.4.1–5.4.3 | Memory safety | N/A | Managed languages |
| 5.5.1 | Serialized data integrity | Met | Signed URLs, encrypted casts |
| 5.5.2 | XML parsers safe (XXE) | Met | OpenSpout reads xlsx without external entities |
| 5.5.3 | No untrusted deserialization | Met | None |
| 5.5.4 | JSON parsed safely | Met | `JSON.parse`, `json_decode` |

### V6 Stored cryptography

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 6.1.1 | Regulated personal data encrypted at rest | Partly | Sensitive fields (medical notes, leave reasons, documents, push tokens, gateway keys) app-encrypted; whole-database encryption at rest at hosting (Atlas) — Launch |
| 6.1.2 | Health data encrypted | Met | `SensitiveValue` for medical custom fields and leave reasons |
| 6.1.3 | Financial data encrypted | Met | Gateway secrets encrypted; no card data stored |
| 6.2.1 | Crypto fails safely | Met | Laravel `Crypt` (AES-256-CBC + MAC) |
| 6.2.2 | Proven crypto only | Met | Laravel, OpenSSL, sodium (licence Ed25519) |
| 6.2.3 | Modes and IVs correct | Met | Library defaults |
| 6.2.4 | Algorithms replaceable | Met | Through the libraries |
| 6.2.5 | No insecure modes | Met | None |
| 6.2.6 | Nonces not reused | Met | Library random IVs |
| 6.3.1 | CSPRNG for randoms | Met | `random_bytes`, `random_int`, `Str::random` |
| 6.3.2 | Random GUIDs | Met | ULIDs/ObjectIds are not used as secrets; secrets are 32+ random bytes |
| 6.4.1 | Secrets management | Launch | Hosting secret store; GitHub secrets for builds |
| 6.4.2 | Keys isolated from the app | Launch | KMS at hosting |

### V7 Error handling and logging

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 7.1.1 | No credentials or tokens in logs | Met | `Redactor::SECRET_KEYS`, decision 0006; push tokens never logged |
| 7.1.2 | No other sensitive data in logs | Met | Generic notification texts; infected uploads logged by hash |
| 7.1.3 | Security events logged | Met | Sign-in, step-up, role changes, exports, retention, erasure, infected uploads |
| 7.1.4 | Enough to investigate | Met | Actor, IP, device, before/after (decision 0008) |
| 7.2.1 | Authentication decisions logged | Met | Sign-ins, failures (`sign_in_failed`), lockouts, MFA changes in the audit log |
| 7.2.2 | Access failures logged | Partly | 403s are audited for sensitive routes; not every denied read |
| 7.3.1 | Log injection prevented | Met | Structured JSON context |
| 7.3.3 | Logs protected | Met | Audit log append-only, no update/delete path (decision 0008) |
| 7.3.4 | Time synchronised | Launch | NTP on hosts |
| 7.4.1 | Generic errors with an id | Met | `{"error":{code,message}}`; no stack traces when `APP_DEBUG=false` |
| 7.4.2 | Exceptions handled everywhere | Met | `ApiExceptionRenderer` |
| 7.4.3 | Last-resort handler | Met | Same |

### V8 Data protection

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 8.1.1 | Sensitive data not in shared caches | Met | `Cache-Control: no-store, private` on API answers |
| 8.1.2 | Server caches protected | Met | Redis private network; per-tenant cache keys |
| 8.1.3 | Minimum data in requests | Met | Ids, not personal data, in URLs |
| 8.1.4 | Detect abnormal request volumes | Partly | Rate limits on sign-in, OTP, verification; large exports alert the Chief; general scraping detection at hosting (WAF) |
| 8.2.1 | Anti-caching headers | Met | API and `index.html` (`no-cache`) |
| 8.2.2 | No sensitive data in browser storage | Met | Only school code, theme, language, column choice; apps keep the token in memory |
| 8.2.3 | Client data cleared after sign-out | Met | Query cache cleared on sign-out; app token dropped |
| 8.3.1 | Sensitive data in bodies, not URLs | Met | Push token sent in the body on delete |
| 8.3.2 | Users can get and delete their data | Met | DPDP requests inbox, access copy, erasure (Privacy module) |
| 8.3.3 | Clear notice and consent | Met | Versioned privacy notice, per-purpose parent consent |
| 8.3.4 | Sensitive data identified with a policy | Met | Decisions 0011, 0013, 0017; `config/privacy.php` |
| 8.3.5 | Access to sensitive data audited | Met | Audit log; exports `export.made` |
| 8.3.6 | Sensitive data cleared from memory | N/A | Managed runtime |
| 8.3.7 | Sensitive data encrypted | Met | See 6.1 |
| 8.3.8 | Retention classified and enforced | Met | Retention rules with dry run and Chief confirmation |

### V9 Communications

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 9.1.1 | TLS for all client traffic | Launch | HTTPS at hosting; release apps refuse cleartext (decision 0016); HSTS ready |
| 9.1.2 | Strong cipher suites | Launch | Load balancer config |
| 9.1.3 | TLS 1.2+ only | Launch | Load balancer config |
| 9.2.1 | Trusted certificates for outbound | Met | Guzzle verifies TLS (FCM, APNs, gateways) |
| 9.2.2 | Encrypted internal traffic | Launch | Atlas/Redis TLS |
| 9.2.3 | External connections authenticated | Met | Gateway signatures, FCM OAuth, APNs JWT |
| 9.2.4 | Revocation checking | Launch | Platform default |
| 9.2.5 | Backend TLS failures logged | Met | Push/gateway failures logged |

### V10 Malicious code

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 10.2.1 | No phoning home or data collection without consent | Met | No analytics or trackers (`no-trackers.mjs`); Firebase used only for push delivery, analytics off |
| 10.2.2 | No unnecessary permissions (apps) | Met | Android: internet and notifications only; no backups (`allowBackup=false`) |
| 10.3.1 | Updates over secure channels, signed | Met | Tauri updater only with a signing key; app stores for phones |
| 10.3.2 | Integrity protection (SRI) | Met | No third-party scripts at all; everything self-hosted |
| 10.3.3 | Subdomain takeover protection | Launch | DNS hygiene at hosting |

### V11 Business logic

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 11.1.1 | Steps in order | Met | State machines (leave, papers, cards, requests) refuse out-of-order moves |
| 11.1.2 | Realistic timing | Partly | OTP and sign-in limits; no timing checks on ordinary forms |
| 11.1.3 | Per-user limits | Met | Seat limits, rate limits |
| 11.1.4 | Anti-automation on business functions | Partly | Idempotency keys, rate limits on public pages |
| 11.1.5 | Limits documented | Met | Module pages, section 8 |
| 11.1.6 | No race conditions | Met | Transactions, atomic counters, ETags, unique indexes |
| 11.1.7 | Unusual activity monitored | Met | Platform security alerts, Chief alerts (waivers, large exports) |
| 11.1.8 | Configurable alerts | Met | `policies.fees.chief_alert_paise`, `policies.exports.chief_alert_rows` |

### V12 Files and resources

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 12.1.1 | No huge files | Met | `max:` limits per upload (512 KB–10 MB) |
| 12.1.2 | Compressed file checks | Met | xlsx row limit (5,000); ClamAV scans archives |
| 12.1.3 | Per-user quota | Partly | Per-upload limits and counts per record; no total quota |
| 12.2.1 | Content type checked | Met | `mimes:` rules; logo dimensions; SVG refused |
| 12.3.1 | User file names not used in paths | Met | Stored under ids; names sanitised for display |
| 12.3.2 | Path traversal prevented | Met | Same |
| 12.3.3 | No remote file inclusion | Met | None |
| 12.3.4 | Reflected file download prevented | Met | Fixed names, `Content-Disposition` |
| 12.3.5 | No OS commands with file metadata | Met | None |
| 12.4.1 | Stored outside the web root | Met | `storage/app/private` via signed links |
| 12.4.2 | Antivirus scan | Met | ClamAV on every upload, fail-closed |
| 12.5.1 | Web tier serves only expected types | Met | nginx serves the build only |
| 12.5.2 | Uploads never run as HTML/JS | Met | Signed downloads with `nosniff`, API CSP `default-src 'none'` |
| 12.6.1 | Outbound allow-list | Partly | Only configured hosts are called; network egress rules at hosting |

### V13 API and web services

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 13.1.1 | Same parsers everywhere | Met | One JSON API |
| 13.1.3 | No secrets in API URLs | Met | Signed short-lived links only for files |
| 13.1.4 | Authorization at URL and resource | Met | Route permission + Policy + tenant scope |
| 13.1.5 | Unexpected content types refused | Met | JSON validation; multipart only on upload routes |
| 13.2.1 | Methods valid per action | Met | REST routes, method-specific |
| 13.2.2 | Schema validation | Met | Validation rules; config JSON schema |
| 13.2.3 | CSRF for cookie REST | Met | Sanctum XSRF |
| 13.2.5 | Content-Type checked | Met | Laravel |
| 13.2.6 | Headers and payload trusted only after checks | Met | Webhook raw-body signature (decision 0015) |
| 13.3.x | SOAP | N/A | — |
| 13.4.x | GraphQL | N/A | — |

### V14 Configuration

| ID | Requirement | Status | Where |
| --- | --- | --- | --- |
| 14.1.1 | Automated, repeatable builds | Met | CI (`ci.yml`, `apps.yml`) |
| 14.1.2 | Compiler hardening | N/A | — |
| 14.1.3 | Server configuration hardened | Partly | nginx `server_tokens off`; production server baseline at hosting |
| 14.1.4 | Automated deployment | Launch | With hosting |
| 14.2.1 | Components up to date, checked | Met | Audits in CI, exact pins (decision 0003) |
| 14.2.2 | Unneeded features removed | Met | Horizon dashboard local only; no debug routes in production |
| 14.2.3 | SRI for outside assets | Met | No outside assets |
| 14.2.4 | Trusted package sources | Met | npm, Packagist, crates.io, official images |
| 14.2.5 | Inventory of components | Partly | Lock files (`composer.lock`, `package-lock.json`, `Cargo.lock`); an SBOM export is a launch item |
| 14.2.6 | Third-party code sandboxed | Partly | PDF rendering and virus scanning in separate containers |
| 14.3.2 | Debug off in production | Launch | `APP_DEBUG=false` |
| 14.3.3 | No version headers | Met | No `X-Powered-By`, `server_tokens off` |
| 14.4.1 | Content-Type with charset | Met | Laravel JSON, nginx defaults |
| 14.4.2 | API answers safe to render | Met | JSON + `nosniff` + CSP `default-src 'none'` |
| 14.4.3 | CSP | Met | See headers |
| 14.4.4 | nosniff | Met | Both |
| 14.4.5 | HSTS | Met (production only) | See headers |
| 14.4.6 | Referrer-Policy | Met | web app `same-origin` (see Headers), API `no-referrer` |
| 14.4.7 | frame-ancestors | Met | `'none'` |
| 14.5.1 | Only needed methods | Met | Routes define methods |
| 14.5.2 | Origin not used for authentication | Met | Origin only decides cookie vs token, never who you are |
| 14.5.3 | Strict CORS allow-list | Met | Web origin (credentials) + app origins (no credentials) |
| 14.5.4 | Proxy headers trusted only from the proxy | Launch | `TrustProxies` set to the load balancer |

## Gaps to decide or finish before launch

1. **Password minimum 12** (2.1.1): today 10. One setting; your decision.
2. Everything marked **Launch**: TLS, secure cookies, database/Redis auth and TLS, secret store, log shipping, deploy pipeline, WAF/egress rules — in [before-going-live](pilot/before-going-live.md).
3. Threat model (1.1.2) and an SBOM (14.2.5) for the penetration testers.
