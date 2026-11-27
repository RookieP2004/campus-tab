# Integrations

Outside services Campus TAB talks to: what we send, what comes back and where the keys are kept. **Names of settings only — never key values.**

| Service | Phase | Used for | What we send | What comes back | Keys (names only) |
| --- | --- | --- | --- | --- | --- |
| Email (SMTP) | 0 (wired), used from 1 | OTP, notices, receipts | Recipient, subject, body | Delivery status | `MAIL_*` |
| Mailpit | 0 | Catches all email in development at http://127.0.0.1:8025 | — | — | none |
| SMS (DLT-registered) | 1 | Parent OTP sign-in | Phone number, template id, OTP | Delivery status | Phase 1 (log driver in dev) |
| Push (FCM / APNs) | 3 (sender ready), 9 (delivery) | Notifications | Device token, the title "Campus TAB" and the generic text only (no names or content) | Delivery status | `PUSH_DRIVER` (log or array in dev and tests, none elsewhere until Phase 9) |
| Object storage (S3-compatible) | 2 | Photos, documents, report PDFs | Files under `tenants/{tenant_id}/…` | Short-lived signed links | `AWS_*` |
| Gotenberg | 7 | Report-card PDFs | HTML | PDF | Phase 7 |
| Razorpay (per school) | 8 | Online fee payment | Order amount, receipt id | Signed webhook | Per school, encrypted in `gateway_accounts` |

In Phase 0 only SMTP to Mailpit is configured, and nothing sends mail yet.
