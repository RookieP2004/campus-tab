import { api } from './api';
import { env } from './env';

/**
 * What the apps (Phase 9) add on top of the web app. Loaded only when the
 * build is for an app shell (VITE_APP_SHELL = mobile | desktop), so the
 * browser bundle never fetches the native plugins.
 *
 * - Deep links: https://<web address>/verify/… and /password/… (and the
 *   campustab:// scheme) open that screen in the app.
 * - Push (Android, iOS): after each sign-in the phone registers its push
 *   token; a sign-out on purpose removes it. Closing the app keeps it (the
 *   owner's choice); the server forgets it after 30 days without a sign-in.
 * - Closing the desktop window ends the sign-in on the server at once. On
 *   phones the operating system just stops the app: the token in memory is
 *   gone (signed out on the device) and the server ends it after the idle
 *   time.
 * - There is no biometric or PIN unlock and nothing about the sign-in is
 *   ever saved on the device.
 */

/** Paths a link may open in the app. Anything else opens the home screen. */
const LINKABLE = [/^\/verify\/[^/]+\/[^/]+$/, /^\/password\/[a-z-]+$/, /^\/notifications$/, /^\/notices\/[^/]+$/];

/** Turns an incoming link into an in-app path, or null if it is not ours. */
export function deepLinkPath(url: string, webUrl: string = env.publicWebUrl): string | null {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  let path: string;
  if (parsed.protocol === 'campustab:') {
    // campustab://verify/SPS-BPL/abc → host "verify", path "/SPS-BPL/abc"
    path = `/${parsed.host}${parsed.pathname}`.replace(/\/+$/, '');
  } else {
    const web = webUrl ? new URL(webUrl) : null;
    if (!web || parsed.origin !== web.origin) return null;
    path = parsed.pathname.replace(/\/+$/, '');
  }
  if (!LINKABLE.some((re) => re.test(path))) return null;

  return path + parsed.search;
}

let pushToken: string | null = null;
let started = false;

/** Called once at start-up in an app build. */
export async function startNative(navigate: (path: string) => void): Promise<void> {
  if (started || env.shell === 'web') return;
  started = true;
  const open = (url: string) => {
    const path = deepLinkPath(url);
    if (path) navigate(path);
  };

  if (env.shell === 'mobile') {
    const { App } = await import('@capacitor/app');
    await App.addListener('appUrlOpen', ({ url }) => open(url));
    const launch = await App.getLaunchUrl();
    if (launch?.url) open(launch.url);
    // Android back button: go back in the app; on the first screen, leave it.
    await App.addListener('backButton', ({ canGoBack }) => {
      if (canGoBack) window.history.back();
      else void App.exitApp();
    });
    return;
  }

  const [{ onOpenUrl, getCurrent }, { getCurrentWindow }] = await Promise.all([import('@tauri-apps/plugin-deep-link'), import('@tauri-apps/api/window')]);
  const first = await getCurrent();
  first?.forEach(open);
  await onOpenUrl((urls) => urls.forEach(open));
  // Closing the window ends the sign-in on the server (push is not involved on desktop).
  await getCurrentWindow().onCloseRequested(async () => {
    await Promise.race([api.POST('/auth/logout', { body: { closing: true } as never }).catch(() => undefined), new Promise((r) => setTimeout(r, 1500))]);
  });
}

/** After every sign-in in the phone app: ask once for permission, then register for push. */
export async function afterSignIn(): Promise<void> {
  if (env.shell !== 'mobile' || !env.pushEnabled) return;
  const [{ PushNotifications }, { Capacitor }] = await Promise.all([import('@capacitor/push-notifications'), import('@capacitor/core')]);
  let permission = await PushNotifications.checkPermissions();
  if (permission.receive === 'prompt' || permission.receive === 'prompt-with-rationale') permission = await PushNotifications.requestPermissions();
  if (permission.receive !== 'granted') return;

  await PushNotifications.removeAllListeners();
  await PushNotifications.addListener('registration', ({ value }) => {
    pushToken = value;
    void api.POST('/me/push-devices', { body: { token: value, platform: Capacitor.getPlatform() === 'ios' ? 'ios' : 'android' } });
  });
  await PushNotifications.addListener('pushNotificationActionPerformed', () => {
    window.location.assign('/notifications');
  });
  await PushNotifications.register();
}

/** A sign-out on purpose in the phone app: stop push to this phone first. */
export async function beforeSignOut(): Promise<void> {
  if (env.shell !== 'mobile' || !pushToken) return;
  // In the body, not the address: the push token never lands in access logs.
  await api.DELETE('/me/push-devices', { body: { token: pushToken } } as never);
  pushToken = null;
}
