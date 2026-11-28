import { createApiClient } from '@campus-tab/api';
import { describe, expect, it, vi } from 'vitest';
import { deepLinkPath } from './native';

describe('the apps', () => {
  const web = 'https://app.campustab.example';

  it('opens only our own links in the app', () => {
    expect(deepLinkPath(`${web}/verify/SPS-BPL/${'a'.repeat(43)}`, web)).toBe(`/verify/SPS-BPL/${'a'.repeat(43)}`);
    expect(deepLinkPath(`${web}/password/reset?school=SPS-BPL&token=abc`, web)).toBe('/password/reset?school=SPS-BPL&token=abc');
    expect(deepLinkPath('campustab://verify/SPS-BPL/xyz', web)).toBe('/verify/SPS-BPL/xyz');
    expect(deepLinkPath('campustab://notifications', web)).toBe('/notifications');
    // Someone else's site, or a screen links may not open.
    expect(deepLinkPath('https://evil.example/verify/SPS-BPL/xyz', web)).toBeNull();
    expect(deepLinkPath(`${web}/fees/reports`, web)).toBeNull();
    expect(deepLinkPath('campustab://students', web)).toBeNull();
    expect(deepLinkPath('not a url', web)).toBeNull();
  });

  it('sends the in-memory bearer token and never cookies or the CSRF call', async () => {
    const seen: Request[] = [];
    const fetch = vi.fn(async (input: RequestInfo | URL) => {
      seen.push(input as Request);
      return new Response(JSON.stringify({ data: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
    let token: string | null = 'tok-123';
    const client = createApiClient({ baseUrl: 'https://api.campustab.example/api/v1', getToken: () => token, fetch: fetch as typeof globalThis.fetch });

    await client.POST('/auth/logout' as never, {} as never);
    expect(seen).toHaveLength(1);
    expect(seen[0]!.url).toBe('https://api.campustab.example/api/v1/auth/logout');
    expect(seen[0]!.headers.get('Authorization')).toBe('Bearer tok-123');
    expect(seen[0]!.headers.has('X-XSRF-TOKEN')).toBe(false);
    expect(seen[0]!.credentials).toBe('omit');

    token = null;
    await client.GET('/me' as never, {} as never);
    expect(seen[1]!.headers.has('Authorization')).toBe(false);
  });
});
