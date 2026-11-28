import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Campus TAB for Android and iOS. The web view loads the same React build
 * as the browser (frontend/dist, built with VITE_APP_SHELL=mobile and the
 * API address set at build time).
 *
 * CAMPUS_DEV_HTTP=1 is for testing on a phone against a computer on the
 * same Wi-Fi (http://192.168.x.y:8000): it allows plain HTTP. Release
 * builds never set it, so they talk to the API over HTTPS only.
 */
const devHttp = process.env.CAMPUS_DEV_HTTP === '1';

const config: CapacitorConfig = {
  appId: 'in.campustab.app',
  appName: 'Campus TAB',
  webDir: '../../frontend/dist',
  server: {
    androidScheme: 'https',
    cleartext: devHttp,
  },
  android: {
    allowMixedContent: devHttp,
    // No web view debugging in release builds.
    webContentsDebuggingEnabled: devHttp,
  },
  ios: {
    contentInset: 'automatic',
    limitsNavigationsToAppBoundDomains: false,
  },
  plugins: {
    PushNotifications: {
      presentationOptions: ['alert', 'sound'],
    },
  },
};

export default config;
