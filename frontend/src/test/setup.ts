import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

/** jsdom has no matchMedia; tests flip `prefersDark` to simulate the device. */
export const device = { prefersDark: false };

/** A made-up signed-in user for screens behind RequireSession. */
export const demoUser = {
  id: 'u_1',
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

type Handler = (request: Request) => Response | Promise<Response> | undefined;

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
  localStorage.clear();
  sessionStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  fakeApi.me = demoUser;
  fakeApi.handlers = [];
  fakeApi.requests = [];

  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query.includes('dark') ? device.prefersDark : false,
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
