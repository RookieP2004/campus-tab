# apps/mobile — Campus TAB for Android and iOS

The Capacitor 8 project. It wraps the same React build as the web app (`frontend/dist`, built with `VITE_APP_SHELL=mobile`). How it signs in, pushes and opens links is in [decision 0016](../../docs/report/decisions/0016-apps-sign-in-and-push.md) and [docs/report/apps.md](../../docs/report/apps.md).

**Nothing is built on your computer.** GitHub Actions builds the APK on Linux and the unsigned iOS build on macOS ([`.github/workflows/apps.yml`](../../.github/workflows/apps.yml)).

| Folder or file | What it is |
| --- | --- |
| `capacitor.config.ts` | App id `in.campustab.app`, name, where the web build is; plain HTTP only when `CAMPUS_DEV_HTTP=1` |
| `android/` | The Android Studio project (from Capacitor's template): deep links, no backups, analytics off, release signing from environment variables |
| `ios/` | The Xcode project (Swift Package Manager): `campustab://` scheme, APNs token hand-over |
| `scripts/icons.mjs` | Renders `packages/ui/assets/logo-mark.svg` into the icon and splash PNGs (CI) |
| `scripts/new-release-key.ps1` | Makes the Android release key once, outside the repository |

## Test the Android app on your phone against this PC (same Wi-Fi)

1. **Find this PC's address on the Wi-Fi.** In PowerShell: `ipconfig`, then look under *Wireless LAN adapter Wi-Fi* for *IPv4 Address*, for example `192.168.31.85`. Routers can hand out a new address later; check again if the app stops connecting.
2. **Let the phone reach ports 8000 (API) and 8080 (live notifications).** In PowerShell **as administrator**:
   ```powershell
   # Your Wi-Fi must be a Private network (Settings → Network & internet → Wi-Fi → your network → Private)
   Get-NetConnectionProfile
   New-NetFirewallRule -DisplayName "Campus TAB dev (API 8000, Reverb 8080)" -Direction Inbound -Protocol TCP -LocalPort 8000,8080 -Action Allow -Profile Private
   ```
   Remove it when you are done: `Remove-NetFirewallRule -DisplayName "Campus TAB dev (API 8000, Reverb 8080)"`.
3. **Start the stack** (`cd infra; docker compose up -d`) and check from the phone's browser that `http://192.168.31.85:8000/api/v1/health` shows `"status":"ok"`.
4. **Build the APK in GitHub:** Actions → **Apps** → **Run workflow**, API address `http://192.168.31.85:8000` (your address), leave the web address empty. The run builds an app that may use plain HTTP **only because the address starts with `http://`**.
5. When the **Android APK** job is green, open the run, download **campus-tab-android** (a zip with the `.apk`), and copy the `.apk` to the phone (USB, or upload it to your own Drive and open it on the phone).
6. On the phone, open the `.apk`. Android asks to allow installs from that app (Files or Drive): allow it once. Without a release key in the secrets the APK is debug-signed, which is fine for this test.
7. Open **Campus TAB**, enter `SUNRISE-BPL`, sign in with a demo account (`docs/report/how-to/demo-school.md`). Close the app from the recent-apps screen and open it again: it asks you to sign in again (the token was in memory only).

## The Android release key (once)

1. Run `powershell -ExecutionPolicy Bypass -File apps\mobile\scripts\new-release-key.ps1 -OutDir "$HOME\CampusTAB-keys"` from the repository folder. keytool runs inside Docker and asks for a password (use a long one from a password manager). The script refuses a folder inside the repository.
2. In GitHub: Settings → Secrets and variables → Actions → **New repository secret**:
   - `ANDROID_KEYSTORE_BASE64` — the whole content of `campus-tab-release.jks.base64.txt`
   - `ANDROID_KEYSTORE_PASSWORD` and `ANDROID_KEY_PASSWORD` — the password you chose (the same for a PKCS12 key)
   - `ANDROID_KEY_ALIAS` — `campustab`
3. Delete the `.base64.txt` file. From now on the **Apps** workflow builds a release-signed APK.
4. **Back it up — losing this key means you can never update the app on Google Play.**
   - Copy `campus-tab-release.jks` to two places that are not this PC: an encrypted USB drive kept at home, and your password manager as an attachment (or an encrypted cloud folder).
   - Store the password and alias in the password manager next to it.
   - Never email it, never commit it (`.gitignore` blocks `*.jks`), never put it in a chat.
   - On Google Play, turn on **Play App Signing** when you publish: Google then keeps the app-signing key and this one becomes the upload key, which Google can reset if it is ever lost.

## Push (Android)

1. Create a Firebase project (Analytics **off**), add an Android app with package `in.campustab.app`, download `google-services.json`.
2. Put its content into the GitHub secret `GOOGLE_SERVICES_JSON`. Builds then switch phone push on (`VITE_PUSH_ENABLED=true`).
3. Firebase → Project settings → Service accounts → **Generate new private key**. On the server, set `PUSH_DRIVER=native` and `FCM_CREDENTIALS` to that JSON (or its base64). Never commit either file.

## iOS: signing steps once you have an Apple Developer account

CI builds an **unsigned** `.ipa` (it proves the project compiles; it cannot be installed). With an account:

1. **Identifiers:** developer.apple.com → Certificates, Identifiers & Profiles → Identifiers → **+** → App IDs → App, bundle id `in.campustab.app`. Tick **Push Notifications** (and **Associated Domains** if you will use https links).
2. **APNs key (push):** Keys → **+** → tick *Apple Push Notifications service* → download the `.p8` once. On the server: `PUSH_DRIVER=native`, `APNS_KEY` (the `.p8` content or its base64), `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_ID=in.campustab.app`, and `APNS_SANDBOX=true` for development builds.
3. **Distribution certificate:** Certificates → **+** → *Apple Distribution*. Make the certificate signing request in Keychain Access on a Mac (or with openssl), download the certificate, export it with its key as a `.p12` with a password.
4. **Provisioning profile:** Profiles → **+** → *App Store Connect* (or *Ad Hoc* to install on listed iPhones) → choose the App ID and certificate → download the `.mobileprovision`.
5. **In the Xcode project** (once, on a Mac, then commit): open `ios/App/App.xcodeproj`, target *App* → *Signing & Capabilities*: choose your team, add **Push Notifications** (Xcode writes `App.entitlements` with `aps-environment`), and for https links add **Associated Domains** `applinks:<your web host>`.
6. **GitHub secrets for a signed build:** `IOS_CERTIFICATE_P12_BASE64`, `IOS_CERTIFICATE_PASSWORD`, `IOS_PROVISIONING_PROFILE_BASE64`, `APPLE_TEAM_ID`. Then change the iOS job to import the certificate into a temporary keychain (`security create-keychain`, `security import`), copy the profile to `~/Library/MobileDevice/Provisioning Profiles/`, run `xcodebuild archive` **without** `CODE_SIGNING_ALLOWED=NO`, and `xcodebuild -exportArchive` with an `ExportOptions.plist` (`method` = `app-store-connect` or `release-testing`).
7. **Upload:** App Store Connect → create the app; upload with Transporter or `xcrun altool --upload-app`; test through TestFlight.
8. For https links, serve `https://<web host>/.well-known/apple-app-site-association` listing `<TEAMID>.in.campustab.app` with paths `/verify/*` and `/password/*`.

## Android App Links (https links) once the web domain exists

Set the repository variable `APP_WEB_URL` (e.g. `https://app.example.in`) and serve `https://<web host>/.well-known/assetlinks.json` with package `in.campustab.app` and the SHA-256 fingerprint of the signing key (`keytool -list -v -keystore campus-tab-release.jks`, or the Play App Signing fingerprint). Until then `campustab://` links work.
