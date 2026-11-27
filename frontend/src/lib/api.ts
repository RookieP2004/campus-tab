import { env } from './env';

/** The one error shape every API error uses. */
export interface ApiErrorBody {
  error: { code: string; message: string; details: Record<string, unknown> };
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
  }
}

/**
 * Minimal JSON fetch for Phase 0. Phase 1 replaces it with the typed client
 * generated into packages/api from the OpenAPI spec.
 */
export async function apiGet<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${env.apiBaseUrl}${path}`, {
    ...init,
    credentials: 'include',
    headers: { Accept: 'application/json', ...init?.headers },
  });
  const body: unknown = await response.json().catch(() => null);

  if (!response.ok) {
    const error = (body as ApiErrorBody | null)?.error;
    throw new ApiError(response.status, error?.code ?? 'http_error', error?.message ?? response.statusText, error?.details);
  }

  return body as T;
}

export interface HealthResponse {
  status: 'ok' | 'fail';
  db: 'ok' | 'fail';
  queue: 'ok' | 'fail';
}

export const getHealth = (signal?: AbortSignal) => apiGet<HealthResponse>('/health', { signal });
