import { useQuery } from '@tanstack/react-query';
import { ApiError, api, apiGet, unwrap } from '@/lib/api';

/** GET /public/tenants/{code}/config: public branding only. */
export interface PublicConfig {
  code: string;
  name: string;
  short_name: string;
  logo_url: string | null;
  accent: string;
  languages: { default: string; featured: string[]; all: string[] };
  login_methods: string[];
}

export interface Me {
  id: string;
  /** For the notification socket: the private channel is school.<tenant_id>.user.<id>. */
  tenant_id: string;
  name: string;
  email: string | null;
  phone: string | null;
  roles: string[];
  level: number;
  permissions: string[];
  language: string;
  theme: 'light' | 'dark' | 'system';
  mfa: { enabled: boolean; required: boolean };
  school: { code: string; name: string };
  /** Scope facts from the school's records, so screens offer only what applies. */
  scope?: AccessScope;
  session: { idle_minutes: number; ends_at: string | null };
}

export interface AccessScope {
  children: string[];
  class_sections: string[];
  /** "<section_id>:<subject_id>" */
  subject_sections: string[];
  wings: string[];
}

export type SignInResult =
  | { mfa_required: true; challenge: string; expires_in: number }
  | {
      mfa_required?: undefined;
      school: { code: string; name: string };
      user: { id: string; name: string; roles: string[] };
      mode: 'browser' | 'token';
      device_session_id: string;
      mfa_enrolment_required: boolean;
    };

export interface DeviceSession {
  id: string;
  kind: 'browser' | 'token';
  name: string;
  user_agent: string | null;
  ip: string | null;
  method: string;
  signed_in_at: string | null;
  last_seen_at: string | null;
  current: boolean;
}

export const meKey = ['me'] as const;

export function usePublicConfig(code: string | null | undefined) {
  return useQuery({
    queryKey: ['public-config', code?.toUpperCase()],
    enabled: Boolean(code),
    retry: false,
    staleTime: 5 * 60_000,
    queryFn: ({ signal }) =>
      unwrap<PublicConfig>(api.GET('/public/tenants/{code}/config', { params: { path: { code: code ?? '' } }, signal })),
  });
}

/**
 * The signed-in school user. A 401 is an error, not empty data: TanStack
 * then keeps the last user on screen while the sign-in overlay asks for the
 * password again.
 */
export function useMe(options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: meKey,
    enabled: options.enabled ?? true,
    retry: false,
    staleTime: 60_000,
    queryFn: apiGet<Me>('/me'),
  });
}

export function isSignedOutError(error: unknown): boolean {
  return error instanceof ApiError && (error.status === 401 || error.code === 'mfa_enrolment_required');
}

export const signIn = {
  password: (body: { school_code: string; login: string; password: string }) =>
    unwrap<SignInResult>(api.POST('/auth/login', { body })),
  mfa: (body: { challenge: string; code: string }) => unwrap<SignInResult>(api.POST('/auth/mfa/verify', { body })),
  requestOtp: (body: { school_code: string; phone: string }) =>
    unwrap<{ message?: string; expires_in?: number }>(api.POST('/auth/otp/request', { body })),
  verifyOtp: (body: { school_code: string; phone: string; code: string }) =>
    unwrap<SignInResult>(api.POST('/auth/otp/verify', { body })),
  forgot: (body: { school_code: string; login: string }) => unwrap<{ message: string }>(api.POST('/auth/password/forgot', { body })),
  /** `language`: the one picked on the page becomes the person's own. */
  setPassword: (body: { school_code: string; token: string; password: string; password_confirmation: string; language?: string }) =>
    unwrap<{ message: string }>(api.POST('/auth/password/set', { body: body as never })),
  logout: () => unwrap(api.POST('/auth/logout')),
  stepUp: (code: string) => unwrap<{ stepped_up_until: string }>(api.POST('/auth/step-up', { body: { code } })),
};

export const mfaEnrolment = {
  start: () => unwrap<{ secret: string; otpauth_url: string }>(api.POST('/me/mfa/enrol')),
  confirm: (code: string) => unwrap<{ recovery_codes: string[] }>(api.POST('/me/mfa/enrol/confirm', { body: { code } })),
};

export const activation = {
  requestOtp: (key: string) =>
    unwrap<{ school: { code: string; name: string }; sent_to: { email: string | null; phone: string | null }; expires_in: number }>(
      api.POST('/licences/activate/otp', { body: { key } }),
    ),
  activate: (body: {
    key: string;
    otp: string;
    chief: { name: string; email: string; phone: string; password: string; password_confirmation: string; language?: string };
  }) => unwrap<{ school: { code: string; name: string }; mfa_enrolment_required: boolean }>(api.POST('/licences/activate', { body: body as never })),
};

export const sessions = {
  list: apiGet<DeviceSession[]>('/me/sessions'),
  revoke: (id: string) => unwrap(api.DELETE('/me/sessions/{id}', { params: { path: { id } } })),
  revokeOthers: () => unwrap<{ sessions_ended: number }>(api.POST('/me/sessions/revoke-others')),
};
