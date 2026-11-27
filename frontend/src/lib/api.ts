import { ApiError, apiErrorFrom, backgroundHeaders, createApiClient, unwrap } from '@campus-tab/api';
import type { QueryFunctionContext } from '@tanstack/react-query';
import { useActivity } from '@/stores/activity';
import { useSessionState } from '@/stores/session';
import { env } from './env';

export { ApiError, apiErrorFrom, unwrap };

/**
 * The one API client for the whole app, generated from the OpenAPI spec
 * (packages/api). It sends the session cookie and Laravel's CSRF header; no
 * token is ever stored in localStorage or sessionStorage.
 *
 * When a request finds the session ended (idle, signed out elsewhere), the
 * sign-in overlay opens over the page, so nothing typed is lost.
 */
export const api = createApiClient({
  baseUrl: env.apiBaseUrl,
  onSessionEnded: (error) => useSessionState.getState().end(error.code),
});

// Real use: every request without the background header. The idle warning
// counts from the last one, as the server does.
api.use({
  onRequest({ request }) {
    if (request.headers.get('X-Background-Request') !== '1') useActivity.getState().touch();
    return request;
  },
});

/**
 * Headers for a TanStack Query fetch. A refetch of data the screen already
 * has (window focus, interval, invalidation) is a background request and
 * must not extend the idle time; the first load of a screen is real use.
 */
export function queryHeaders(context: QueryFunctionContext): Record<string, string> {
  const hasData = context.client.getQueryState(context.queryKey)?.data !== undefined;
  const background = hasData || context.meta?.background === true;
  return background ? { ...backgroundHeaders } : {};
}

/**
 * A query function for a GET request: unwraps { data } and marks refetches
 * as background requests. Use with useQuery({ queryKey, queryFn: getJson(...) }).
 */
export function apiGet<T>(path: string, query?: Record<string, string | number | boolean | undefined>) {
  return async (context: QueryFunctionContext): Promise<T> => {
    // The path is checked against the spec where the call is typed; this
    // helper serves screens that only need { data } back.
    const { response, data, error } = (await api.GET(path as never, {
      signal: context.signal,
      headers: queryHeaders(context),
      params: { query } as never,
    } as never)) as { response: Response; data?: unknown; error?: unknown };
    if (!response.ok) throw apiErrorFrom(response, error);
    const body = data as { data?: T } | undefined;
    return (body && typeof body === 'object' && 'data' in body ? body.data : body) as T;
  };
}

export interface HealthResponse {
  status: 'ok' | 'fail';
  db: 'ok' | 'fail';
  queue: 'ok' | 'fail';
}

/** Health is polled every 30 s, so it is always a background request. */
export async function getHealth(signal?: AbortSignal): Promise<HealthResponse> {
  const { data, response } = await api.GET('/health', { signal, headers: backgroundHeaders });
  if (!response.ok && response.status !== 503) throw new ApiError(response.status, 'http_error', response.statusText);
  return (data ?? (await response.json().catch(() => null))) as HealthResponse;
}
