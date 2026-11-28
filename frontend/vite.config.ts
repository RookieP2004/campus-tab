/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const usePolling = process.env.VITE_USE_POLLING === 'true';

// The browser always opens the app at http://127.0.0.1:5173 (never
// "localhost"), so sign-in cookies set in Phase 1 stay on one host.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Logo and favicon are served straight from packages/ui/assets/.
  publicDir: fileURLToPath(new URL('../packages/ui/assets', import.meta.url)),
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Docker on Windows does not forward file-change events from the D:
    // drive into the container, so watch by polling.
    watch: usePolling ? { usePolling: true, interval: 300 } : undefined,
    hmr: { host: '127.0.0.1', clientPort: 5173 },
    proxy: {
      // Same-origin API calls: the browser talks to 127.0.0.1:5173 and Vite
      // forwards /api and /sanctum to the api container.
      '/api': { target: process.env.VITE_API_PROXY_TARGET ?? 'http://127.0.0.1:8000', changeOrigin: false },
      '/sanctum': { target: process.env.VITE_API_PROXY_TARGET ?? 'http://127.0.0.1:8000', changeOrigin: false },
    },
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    // Fixed worker count and a longer timeout so the default run also passes on
    // slower machines and CI runners (the default workers made a few UI tests
    // pass the 5-second limit on a busy laptop).
    maxWorkers: 2,
    testTimeout: 15_000,
  },
});
