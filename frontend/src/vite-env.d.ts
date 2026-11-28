/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
  readonly VITE_REVERB_APP_KEY?: string;
  readonly VITE_REVERB_HOST?: string;
  readonly VITE_REVERB_PORT?: string;
  readonly VITE_REVERB_SCHEME?: string;
  readonly VITE_REVERB_ENABLED?: string;
  readonly VITE_APP_SHELL?: 'web' | 'mobile' | 'desktop';
  readonly VITE_PUBLIC_WEB_URL?: string;
  readonly VITE_PUSH_ENABLED?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
