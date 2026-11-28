import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeAll, beforeEach, vi } from 'vitest';
import { i18nReady } from '@/i18n';

// English loads lazily in the app; tests wait for it once before any test runs.
beforeAll(async () => {
  await i18nReady;
});

/**
 * jsdom has no matchMedia; tests flip `prefersDark` to simulate the device,
 * and set `width` (CSS pixels) for the phone, tablet and desktop layouts.
 */
export const device = { prefersDark: false, width: 1280 };

/** Whether a media query such as "(min-width: 1024px)" matches the fake device. */
function mediaMatches(query: string): boolean {
  if (query.includes('prefers-color-scheme')) return query.includes('dark') ? device.prefersDark : !device.prefersDark;
  const min = /min-width:\s*(\d+)px/.exec(query);
  const max = /max-width:\s*(\d+)px/.exec(query);
  if (min || max) return (!min || device.width >= Number(min[1])) && (!max || device.width <= Number(max[1]));
  return false;
}

/** A made-up signed-in user for screens behind RequireSession. */
export const demoUser = {
  id: 'u_1',
  tenant_id: 't_01HZZZZZZZZZZZZZZZZZZZZZZZ',
  name: 'Anita Verma',
  email: 'chief@sunrise.test',
  phone: '+919800000001',
  roles: ['chief'],
  level: 90,
  permissions: ['students.view:school', 'users.view:school'],
  language: 'en',
  theme: 'system',
  mfa: { enabled: true, required: true },
  school: { code: 'SUNRISE-BPL', name: 'Sunrise Public School' },
  scope: { children: [] as string[], class_sections: [] as string[], subject_sections: [] as string[], wings: [] as string[] },
  session: { idle_minutes: 15, ends_at: null },
};

type Handler = (request: Request) => Response | Promise<Response | undefined> | undefined;

/**
 * A tiny fake API. Each test can add handlers; `fakeApi.me` is what GET /me
 * answers (null = signed out, 401). Every request is recorded.
 */
export const fakeApi = {
  me: demoUser as typeof demoUser | null,
  handlers: [] as Handler[],
  requests: [] as Request[],
};

export const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

beforeEach(() => {
  device.prefersDark = false;
  device.width = 1280;
  localStorage.clear();
  sessionStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  fakeApi.me = demoUser;
  fakeApi.handlers = [];
  fakeApi.requests = [];

  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query.includes('dark') ? device.prefersDark : mediaMatches(query),
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  );

  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = input instanceof Request ? input : new Request(new URL(String(input), 'http://127.0.0.1:5173'), init);
      fakeApi.requests.push(request);
      for (const handler of fakeApi.handlers) {
        const response = await handler(request);
        if (response) return response;
      }
      const path = new URL(request.url).pathname;
      if (path.endsWith('/health')) return json({ status: 'ok', db: 'ok', queue: 'ok' });
      if (path.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 });
      if (path.endsWith('/me/dashboard')) return json({ data: { role: fakeApi.me?.roles[0] ?? null, roles: [], cards: [] } });
      if (path.endsWith('/me/notifications')) return json({ data: [], unread: 0 });
      if (path.endsWith('/me')) {
        return fakeApi.me ? json({ data: fakeApi.me }) : json({ error: { code: 'unauthenticated', message: 'Sign in to continue.', details: {} } }, 401);
      }
      if (path.includes('/public/tenants/')) {
        return json({
          data: {
            code: 'SUNRISE-BPL',
            name: 'Sunrise Public School',
            short_name: 'SPS',
            logo_url: null,
            accent: '#0F766E',
            languages: { default: 'en', featured: ['en', 'hi'], all: ['en', 'hi'] },
            login_methods: ['password', 'parent_otp'],
          },
        });
      }
      return json({ data: {} });
    }),
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});
