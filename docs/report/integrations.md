# Integrations

Outside services Campus TAB talks to: what we send, what comes back and where the keys are kept. **Names of settings only — never key values.**

| Service | Phase | Used for | What we send | What comes back | Keys (names only) |
| --- | --- | --- | --- | --- | --- |
| Email (SMTP) | 0 (wired), used from 1 | OTP, notices, receipts | Recipient, subject, body | Delivery status | `MAIL_*` |
| Mailpit | 0 | Catches all email in development at http://127.0.0.1:8025 | — | — | none |
| SMS (DLT-registered) | 1 | Parent OTP sign-in | Phone number, template id, OTP | Delivery status | Phase 1 (log driver in dev) |
| Push: FCM (Android), APNs (iOS) | 3 (sender ready), 9 (delivery) | Notifications | Device token, the title "Campus TAB" and the generic text only (no names, content or data payload) | Sent, or "token gone" (the device is then forgotten) | `PUSH_DRIVER=native`, `FCM_CREDENTIALS`, `APNS_KEY`, `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_ID`, `APNS_SANDBOX`. Build side: `GOOGLE_SERVICES_JSON` GitHub secret. Firebase Analytics stays off |
| ClamAV | 9 | Virus scan of every upload | The uploaded file's bytes (inside our own network) | OK or what was found | `CLAMAV_HOST`, `CLAMAV_PORT` (no keys) |
| GitHub Actions | 9 | Building the apps | Source code | APK, installer, unsigned iOS/macOS builds | `ANDROID_KEYSTORE_*`, `GOOGLE_SERVICES_JSON`, `TAURI_*` secrets |
| Object storage (S3-compatible) | 2 | Photos, documents, report PDFs | Files under `tenants/{tenant_id}/…` | Short-lived signed links | `AWS_*` |
| Gotenberg | 7 | Report-card PDFs | HTML | PDF | Phase 7 |
| Razorpay (per school) | 8 | Online fee payment | Order amount, receipt id | Signed webhook | Per school, encrypted in `gateway_accounts` |

In Phase 0 only SMTP to Mailpit is configured, and nothing sends mail yet.
