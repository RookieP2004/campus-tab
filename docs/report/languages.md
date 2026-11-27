# Languages

English is the default for everyone; each person can pick another language at
sign-up or later, and the choice changes only their own screens. Every
language listed in `frontend/src/i18n/languages.ts` is compared with English
below. A missing key is shown in English (i18next falls back key by key), so
a half-translated language still works.

To translate a language: copy `frontend/src/locales/en/common.json` to
`frontend/src/locales/<code>/common.json`, translate the values (keep every
`{{placeholder}}`), set `shipped: true` in `languages.ts` once it is
complete and reviewed, then run `npm run i18n:report -w @campus-tab/frontend`.
CI runs `npm run i18n:check -w @campus-tab/frontend`, which fails when this
page is out of date.

<!-- generated:i18n:start -->
English has 1550 keys.

| Code | Language | Shipped | File | Missing keys |
| --- | --- | --- | --- | --- |
| `hi` | Hindi (हिन्दी) | yes | yes | none (complete) |
| `bn` | Bengali (বাংলা) | no | no | all 1550 keys missing, falls back to English |
| `mr` | Marathi (मराठी) | no | no | all 1550 keys missing, falls back to English |
| `gu` | Gujarati (ગુજરાતી) | no | no | all 1550 keys missing, falls back to English |
| `pa` | Punjabi (ਪੰਜਾਬੀ) | no | no | all 1550 keys missing, falls back to English |
| `ta` | Tamil (தமிழ்) | no | no | all 1550 keys missing, falls back to English |
| `te` | Telugu (తెలుగు) | no | no | all 1550 keys missing, falls back to English |
| `kn` | Kannada (ಕನ್ನಡ) | no | no | all 1550 keys missing, falls back to English |
| `ml` | Malayalam (മലയാളം) | no | no | all 1550 keys missing, falls back to English |
| `or` | Odia (ଓଡ଼ିଆ) | no | no | all 1550 keys missing, falls back to English |
| `ur` | Urdu (اردو) | no | no | all 1550 keys missing, falls back to English |
<!-- generated:i18n:end -->
