/**
 * @campus-tab/api: the typed client for the Campus TAB API.
 *
 * src/schema.ts is generated from openapi.json (itself exported from the
 * Laravel code); never edit it by hand. Regenerate both with:
 *   docker compose exec api php artisan scramble:export
 *   docker compose exec web npm run generate -w @campus-tab/api
 *
 * The web app signs in with the session cookie only: no token is ever kept
 * in localStorage or sessionStorage. Every request sends the cookie, and
 * every change sends Laravel's CSRF header (read from the XSRF-TOKEN cookie).
 */
import createClient, { type Client, type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export type { components, paths } from './schema';

/** The one error shape every API error uses. */
export interface ApiErrorBody {
  error: { code: string; message: string; details: Record<string, unknown> };
  message?: string;
  errors?: Record<string, string[]>;
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Field errors from a 422 validation answer, keyed by field name. */
  get fieldErrors(): Record<string, string[]> {
    const fields = this.details.fields ?? this.details.errors ?? this.details;
    return fields && typeof fields === 'object' ? (fields as Record<string, string[]>) : {};
  }
}

/**
 * Requests the screens send on their own (refetches, polling, later the
 * notification socket) carry this header. The server checks them like any
 * other request but never counts them as use, so they cannot keep an idle
 * session alive.
 */
export const BACKGROUND_HEADER = 'X-Background-Request';
export const backgroundHeaders = { [BACKGROUND_HEADER]: '1' } as const;

/** 401 codes that mean "this device is signed out". */
export const SESSION_ENDED_CODES = ['session_expired', 'session_revoked', 'unauthenticated'] as const;

export interface ApiClientOptions {
  /** Same-origin path such as /api/v1. */
  baseUrl: string;
  /** Where Laravel sets the XSRF-TOKEN cookie (default /sanctum/csrf-cookie). */
  csrfUrl?: string;
  /** Called when a request finds the session ended (idle, revoked, signed out). */
  onSessionEnded?: (error: ApiError) => void;
  /**
   * The apps (Phase 9): the bearer token, kept in memory only. When given,
   * the client sends `Authorization: Bearer …`, never cookies, and skips
   * the CSRF cookie (a bearer request cannot be forged by another site).
   */
  getToken?: () => string | null;
  /** For tests. */
  fetch?: typeof globalThis.fetch;
}

export type ApiClient = Client<paths>;

const UNSAFE = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

export async function toApiError(response: Response): Promise<ApiError> {
  const body = (await response.clone().json().catch(() => null)) as ApiErrorBody | null;
  const error = body?.error;

  return new ApiError(
    response.status,
    error?.code ?? (response.status === 419 ? 'csrf_mismatch' : 'http_error'),
    error?.message ?? body?.message ?? response.statusText,
    error?.details ?? (body?.errors ? { fields: body.errors } : {}),
  );
}

export function createApiClient(options: ApiClientOptions): ApiClient {
  const doFetch = options.fetch ?? ((...args: Parameters<typeof fetch>) => globalThis.fetch(...args));
  const csrfUrl = options.csrfUrl ?? '/sanctum/csrf-cookie';
  let csrfReady: Promise<void> | null = null;

  const ensureCsrf = (force = false): Promise<void> => {
    if (!force && readCookie('XSRF-TOKEN')) return Promise.resolve();
    csrfReady ??= doFetch(csrfUrl, { credentials: 'include', headers: { Accept: 'application/json' } })
      .then(() => undefined)
      .finally(() => {
        csrfReady = null;
      });
    return csrfReady;
  };

  // Same-origin paths resolved against the page, so every runtime (browser,
  // the apps' web view, tests) builds the same absolute URLs.
  const origin = typeof location !== 'undefined' ? location.origin : undefined;
  const baseUrl = origin ? new URL(options.baseUrl, origin).toString().replace(/\/$/, '') : options.baseUrl;

  const bearer = options.getToken;

  const client = createClient<paths>({
    baseUrl,
    credentials: bearer ? 'omit' : 'include',
    headers: { Accept: 'application/json' },
    fetch: (request: Request) => doFetch(request),
  });

  const middleware: Middleware = {
    async onRequest({ request }) {
      if (bearer) {
        const token = bearer();
        if (token) request.headers.set('Authorization', `Bearer ${token}`);
        return request;
      }
      if (UNSAFE.has(request.method)) {
        await ensureCsrf();
        const token = readCookie('XSRF-TOKEN');
        if (token) request.headers.set('X-XSRF-TOKEN', token);
      }
      return request;
    },
    async onResponse({ request, response }) {
      // An expired CSRF token: fetch a new one and send the request once more.
      if (response.status === 419 && !request.headers.has('X-Campus-Retry')) {
        await ensureCsrf(true);
        const retry = new Request(request, { headers: new Headers(request.headers) });
        retry.headers.set('X-Campus-Retry', '1');
        const token = readCookie('XSRF-TOKEN');
        if (token) retry.headers.set('X-XSRF-TOKEN', token);
        return doFetch(retry);
      }

      if (response.status === 401 && options.onSessionEnded) {
        const error = await toApiError(response);
        if ((SESSION_ENDED_CODES as readonly string[]).includes(error.code)) options.onSessionEnded(error);
      }
      return response;
    },
  };

  client.use(middleware);

  return client;
}

/**
 * The ApiError for a failed answer. openapi-fetch has already read the body
 * into `error` (the response cannot be read a second time), so it is built
 * from that.
 */
export function apiErrorFrom(response: Response, error: unknown): ApiError {
  const body = error && typeof error === 'object' ? (error as Partial<ApiErrorBody>) : null;
  const detail = body?.error;

  return new ApiError(
    response.status,
    detail?.code ?? (response.status === 419 ? 'csrf_mismatch' : 'http_error'),
    detail?.message ?? body?.message ?? response.statusText,
    detail?.details ?? (body?.errors ? { fields: body.errors } : {}),
  );
}

/**
 * Turns an openapi-fetch result into what is inside { data }, or throws
 * ApiError with the server's code, message and details.
 */
export async function unwrap<T = unknown>(
  result: Promise<{ data?: unknown; error?: unknown; response: Response }>,
): Promise<T> {
  const { data, error, response } = await result;

  if (!response.ok) throw apiErrorFrom(response, error);

  const body = data as { data?: unknown } | undefined;
  return (body && typeof body === 'object' && 'data' in body ? body.data : body) as T;
}
