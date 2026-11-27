import type { TFunction } from 'i18next';
import { ApiError } from '@/lib/api';

/**
 * A translated message for an API error: the screen's own text for known
 * codes (auth.apiErrors.<code>), else the server's plain-English message.
 * Never shows anything the user typed.
 */
export function errorText(t: TFunction, error: unknown): string {
  if (error instanceof ApiError) {
    const key = `auth.apiErrors.${error.code}`;
    const translated = t(key, { defaultValue: '' });
    if (translated) return translated;
    if (error.code === 'validation_failed') {
      const first = Object.values(error.fieldErrors)[0];
      if (Array.isArray(first) && typeof first[0] === 'string') return first[0];
    }
    return error.message || t('auth.apiErrors.http_error');
  }
  return t('auth.apiErrors.network');
}

/** The first server error for one field of a 422 answer. */
export function fieldError(error: unknown, field: string): string | undefined {
  if (!(error instanceof ApiError) || error.code !== 'validation_failed') return undefined;
  const messages = error.fieldErrors[field];
  return Array.isArray(messages) ? messages[0] : undefined;
}
