/**
 * Build-time settings from frontend/.env (VITE_* only; never secrets).
 * Hosts are 127.0.0.1, not localhost, so cookies stay on one host.
 */
export const env = {
  /**
   * Which shell runs this build (set at build time, Phase 9): the browser,
   * the Android/iOS app (Capacitor) or the Windows/macOS app (Tauri). The
   * apps sign in with a bearer token kept in memory only.
   */
  shell: (import.meta.env.VITE_APP_SHELL ?? 'web') as 'web' | 'mobile' | 'desktop',
  /** API base path. Same-origin by default: Vite proxies /api to the api container. The apps get a full address at build time. */
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
  /** The web app's public address: deep links (https://…/verify/…, password links) open the same path in the apps. */
  publicWebUrl: import.meta.env.VITE_PUBLIC_WEB_URL ?? '',
  /** Phone push is on only in builds that include the Firebase (Android) or APNs (iOS) setup. */
  pushEnabled: import.meta.env.VITE_PUSH_ENABLED === 'true',
  /**
   * Reverb WebSocket host as the browser sees it: the notification bell
   * listens here. Off in unit tests (they use the polling fallback) or with
   * VITE_REVERB_ENABLED=false.
   */
  reverb: {
    enabled: import.meta.env.MODE !== 'test' && import.meta.env.VITE_REVERB_ENABLED !== 'false',
    key: import.meta.env.VITE_REVERB_APP_KEY ?? 'campus-tab-local-key',
    host: import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1',
    port: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    scheme: import.meta.env.VITE_REVERB_SCHEME ?? 'http',
  },
} as const;
