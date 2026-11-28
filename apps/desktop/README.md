# apps/desktop — Campus TAB for Windows and macOS

The Tauri 2 project. It wraps the same React build as the web app (`frontend/dist`, built with `VITE_APP_SHELL=desktop`) in the system web view (WebView2 on Windows, WKWebView on macOS), so installers stay small. Sign-in rules: [decision 0016](../../docs/report/decisions/0016-apps-sign-in-and-push.md).

**Nothing is built on your computer.** GitHub Actions builds the Windows installer on a Windows runner and the macOS app on a macOS runner ([`.github/workflows/apps.yml`](../../.github/workflows/apps.yml)).

| File | What it is |
| --- | --- |
| `src-tauri/tauri.conf.json` | Name, id `in.campustab.desktop`, window, content security policy, installers (NSIS for Windows, DMG for macOS), `campustab://` links |
| `src-tauri/src/lib.rs` | One running copy, deep links, optional auto-update |
| `src-tauri/capabilities/default.json` | What the window may do: its own window events and incoming links, nothing else |
| `src-tauri/Cargo.toml`, `Cargo.lock` | Rust crates, pinned exactly |

Printing uses the system print dialog (the page's own print buttons). Closing the window signs out: the token was only in memory, and the window tells the server as it closes.

## Install on your Windows laptop

1. Actions → **Apps** → **Run workflow** with the API address (for the local stack on the same laptop: `http://127.0.0.1:8000`).
2. Download **campus-tab-windows** from the run and unzip it: `Campus TAB_0.1.0_x64-setup.exe`.
3. Run it. Windows SmartScreen says "Windows protected your PC" because the installer is not code-signed yet: **More info → Run anyway**. It installs for your user only (no administrator needed) and installs WebView2 if it is missing.
4. Open **Campus TAB** from the Start menu, enter the school code and sign in.
5. Uninstall from Settings → Apps.

## Signing later

**Windows:** buy an OV or EV code-signing certificate (or use Azure Trusted Signing). Add `WINDOWS_CERTIFICATE` (base64 `.pfx`) and `WINDOWS_CERTIFICATE_PASSWORD` secrets, import the certificate in the Windows job, and set `bundle.windows.certificateThumbprint` (or `signCommand` for Trusted Signing) through `--config`. SmartScreen warnings fade as the signed installer gains reputation.

**macOS (with an Apple Developer account):**

1. Certificates → **+** → *Developer ID Application*; export it with its key as a `.p12`.
2. App Store Connect → Users and Access → Integrations → **App Store Connect API** key (for notarisation): note the issuer id and key id, download the `.p8`.
3. GitHub secrets: `APPLE_CERTIFICATE` (base64 `.p12`), `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY` (`Developer ID Application: <name> (<TEAMID>)`), `APPLE_API_ISSUER`, `APPLE_API_KEY`, `APPLE_API_KEY_PATH` (write the `.p8` to a temp file in the job).
4. Pass them as environment variables to `tauri build` in the macOS job: Tauri imports the certificate, signs, and notarises the app and DMG.

## Auto-update (off until there is somewhere to publish updates)

A private GitHub repository cannot serve updates to the public, so the updater is compiled in only when these secrets exist:

1. Make the update key once: `npx @tauri-apps/cli@2.12.4 signer generate -w campus-tab-updater.key` (inside Docker: `docker run --rm -it -v "$HOME/CampusTAB-keys:/k" node:22 npx @tauri-apps/cli@2.12.4 signer generate -w /k/campus-tab-updater.key`). Back it up with the Android key.
2. Secrets: `TAURI_SIGNING_PRIVATE_KEY` (the private key file's content), `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`, `TAURI_UPDATER_PUBKEY` (the `.pub` content) and `TAURI_UPDATER_ENDPOINT` (an HTTPS address serving `latest.json`, e.g. on the chosen host or CDN).
3. The Windows job then builds with `--features updater`, writes signed update files next to the installer, and the app checks the endpoint at start-up and installs a newer signed version for the next start.
