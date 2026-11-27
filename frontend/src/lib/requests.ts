import { toApiError } from '@campus-tab/api';
import type { QueryFunctionContext } from '@tanstack/react-query';
import { api, apiErrorFrom, queryHeaders, unwrap } from './api';
import { env } from './env';

type Query = Record<string, string | number | boolean | undefined>;

/**
 * Like apiGet, but returns the whole JSON body, for lists that carry
 * `columns`, `meta` or `can` next to `data`. Refetches are background
 * requests, so polling never extends the idle session.
 */
export function apiGetBody<T>(path: string, query?: Query) {
  return async (context: QueryFunctionContext): Promise<T> => {
    const { response, data, error } = (await api.GET(path as never, {
      signal: context.signal,
      headers: queryHeaders(context),
      params: { query } as never,
    } as never)) as { response: Response; data?: unknown; error?: unknown };
    if (!response.ok) throw apiErrorFrom(response, error);
    return data as T;
  };
}

type Method = 'POST' | 'PUT' | 'PATCH' | 'DELETE';

interface SendOptions {
  path?: Record<string, string>;
  body?: unknown;
  headers?: Record<string, string>;
}

/**
 * A change request through the typed client (session cookie and CSRF
 * header included). Returns what is inside { data }, or throws ApiError.
 * A FormData body is sent as multipart.
 */
export function send<T = unknown>(method: Method, path: string, options: SendOptions = {}): Promise<T> {
  const init = { params: { path: options.path ?? {} }, body: options.body, headers: options.headers } as never;
  switch (method) {
    case 'POST':
      return unwrap<T>(api.POST(path as never, init));
    case 'PUT':
      return unwrap<T>(api.PUT(path as never, init));
    case 'PATCH':
      return unwrap<T>(api.PATCH(path as never, init));
    default:
      return unwrap<T>(api.DELETE(path as never, init));
  }
}

/**
 * Downloads a file the API sends (an .xlsx template or report) with the
 * session cookie, and hands it to the browser to save.
 */
export async function downloadFile(path: string, fileName: string): Promise<void> {
  const base = typeof location !== 'undefined' ? location.origin : 'http://127.0.0.1';
  const response = await fetch(new URL(`${env.apiBaseUrl}${path}`, base).toString(), { credentials: 'include' });
  if (!response.ok) throw await toApiError(response);
  const blob = await response.blob();
  const header = response.headers.get('Content-Disposition') ?? '';
  const match = /filename="?([^";]+)"?/i.exec(header);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = match?.[1] ?? fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
