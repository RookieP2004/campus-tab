/**
 * Build-time settings from frontend/.env (VITE_* only; never secrets).
 * Hosts are 127.0.0.1, not localhost, so cookies stay on one host.
 */
export const env = {
  /** API base path. Same-origin by default: Vite proxies /api to the api container. */
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
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
