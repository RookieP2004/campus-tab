import { useQuery } from '@tanstack/react-query';
import { ApiError, api, apiGet, unwrap } from '@/lib/api';
import { env } from '@/lib/env';
import { useAppToken } from '@/stores/appToken';

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

/**
 * In the apps the sign-in answer carries a bearer token: it is kept in
 * memory only (stores/appToken) and push is registered for this device.
 * The browser gets its session cookie and ignores this.
 */
async function keep(result: Promise<SignInResult>): Promise<SignInResult> {
  const answer = (await result) as SignInResult & { token?: string };
  if (env.shell !== 'web' && answer.token) {
    useAppToken.getState().set(answer.token);
    if (!('mfa_enrolment_required' in answer && answer.mfa_enrolment_required)) void import('@/lib/native').then((n) => n.afterSignIn());
  }
  return answer;
}

/** The apps name the device in the session list ("Campus TAB phone app"). */
const device = () => (env.shell === 'web' ? {} : { device_name: `Campus TAB ${env.shell === 'mobile' ? 'phone' : 'desktop'} app` });

export const signIn = {
  password: (body: { school_code: string; login: string; password: string }) =>
    keep(unwrap<SignInResult>(api.POST('/auth/login', { body: { ...body, ...device() } as never }))),
  mfa: (body: { challenge: string; code: string }) => keep(unwrap<SignInResult>(api.POST('/auth/mfa/verify', { body: { ...body, ...device() } as never }))),
  requestOtp: (body: { school_code: string; phone: string }) =>
    unwrap<{ message?: string; expires_in?: number }>(api.POST('/auth/otp/request', { body })),
  verifyOtp: (body: { school_code: string; phone: string; code: string }) =>
    keep(unwrap<SignInResult>(api.POST('/auth/otp/verify', { body: { ...body, ...device() } as never }))),
  forgot: (body: { school_code: string; login: string }) => unwrap<{ message: string }>(api.POST('/auth/password/forgot', { body })),
  /** `language`: the one picked on the page becomes the person's own. */
  setPassword: (body: { school_code: string; token: string; password: string; password_confirmation: string; language?: string }) =>
    unwrap<{ message: string }>(api.POST('/auth/password/set', { body: body as never })),
  /** A sign-out on purpose: in the apps it also stops push to this device, then forgets the token. */
  logout: async () => {
    if (env.shell !== 'web') await import('@/lib/native').then((n) => n.beforeSignOut()).catch(() => undefined);
    try {
      return await unwrap(api.POST('/auth/logout'));
    } finally {
      useAppToken.getState().set(null);
    }
  },
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
