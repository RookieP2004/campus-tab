# Before going live

Everything that must be in place before the first real school uses Campus TAB with real data. Tick each line; most need an account in the company's name or a decision only the owner can make.

## Accounts and providers

| Done | Item | What to do | Notes |
| --- | --- | --- | --- |
| ☐ | **Hosting** | Choose the provider and an Indian region (AWS Mumbai or similar); MongoDB Atlas (M10+, encryption at rest, continuous backup with point-in-time restore); Redis; object storage | Code is cloud-neutral (Docker). Set up staging and production |
| ☐ | **Domain** | Buy the domain; DNS for `app.` (web), `api.` (API), `ws.` (Reverb); TLS certificates (automatic renewal) | Set `APP_WEB_URL`/`APP_API_URL` repository variables for app builds |
| ☐ | **SMS provider with DLT registration** | Register the company and the OTP template on a DLT portal (Jio, Airtel, Vodafone Idea or BSNL); pick an SMS provider; add its driver | Needed for parent OTP sign-in to Indian numbers. Takes 1–3 weeks |
| ☐ | **Email sender** | A sending domain with SPF, DKIM and DMARC; an SMTP or API provider | Password links, notifications |
| ☐ | **Each school's Razorpay live keys** | The school's own Razorpay account, KYC done; the Chief enters live keys in **Fees → Online payments**; webhook URL and secret set in the Razorpay dashboard | Today only test mode is allowed in code (Phase 8): switching on live mode is a reviewed code change |
| ☐ | **Google Play developer account** | Company account; app listing; data safety form (no tracking, no analytics); Play App Signing on | Upload key = the release key in GitHub secrets |
| ☐ | **Apple Developer account** | Company account (D-U-N-S number needed); then the iOS and macOS signing steps in `apps/mobile/README.md` and `apps/desktop/README.md` | Needed for iPhone and Mac builds that install |
| ☐ | **Firebase project** | Analytics **off**; Android app `in.campustab.app`; `GOOGLE_SERVICES_JSON` secret; server `FCM_CREDENTIALS` | Push for Android |
| ☐ | **APNs key** | From the Apple account; server `APNS_*` settings | Push for iPhone |
| ☐ | **Windows code signing** | OV/EV certificate or Azure Trusted Signing | Removes the SmartScreen warning |

## Security

| Done | Item | Notes |
| --- | --- | --- |
| ☐ | **External penetration test** | By an independent firm (CERT-In empanelled), on staging, web + API + Android app; fix every finding, retest. Give them `docs/report/security.md` |
| ☐ | Production settings | `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `HSTS_HEADER` on the web server, `TrustProxies` set, Horizon dashboard not exposed |
| ☐ | Secrets | All keys in the host's secret store; `APP_KEY`, licence signing keys and push keys backed up offline; rotation steps written |
| ☐ | Database and Redis | Authentication and TLS on; network access only from the app |
| ☐ | Backups | Atlas point-in-time restore on; a restore drill done once before launch and each term |
| ☐ | Monitoring | Error tracking without personal data, uptime checks, Atlas alerts, log retention |
| ☐ | ClamAV | Running next to the API with automatic definition updates; alert when it is down (uploads are refused then) |
| ☐ | Password minimum | Decide 10 or 12 characters (ASVS asks for 12) |

## Legal and privacy

| Done | Item | Notes |
| --- | --- | --- |
| ☐ | **Legal review** | A lawyer reviews: the privacy notice text, consent purposes and wording, retention times (`config/privacy.php`), the data processing agreement with each school, terms of use, the breach runbook |
| ☐ | Data processing agreement | Signed with each pilot school before data is loaded |
| ☐ | Each school's privacy notice | Published (Privacy office → Privacy notice) and grievance contact set (School settings) |
| ☐ | Parent communication | Letter to parents: what the app does, the privacy notice, how to give consent in the app |

## Each pilot school

See the [pilot checklist](pilot-checklist.md).
