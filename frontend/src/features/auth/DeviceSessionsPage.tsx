import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LogOut, Monitor, Smartphone } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Spinner } from '@/components/ui/form';
import { type DeviceSession, sessions } from './api';
import { errorText } from './errors';

/**
 * Where this account is signed in, with remote sign-out. Shared by school
 * users and the platform console (which passes its own requests).
 */
export function DeviceSessionsPanel({
  queryKey,
  list,
  revoke,
  revokeOthers,
}: {
  queryKey: readonly unknown[];
  list: typeof sessions.list;
  revoke: (id: string) => Promise<unknown>;
  revokeOthers?: () => Promise<unknown>;
}) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey, queryFn: list });
  const signOut = useMutation({
    mutationFn: revoke,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  });
  const signOutOthers = useMutation({
    mutationFn: () => (revokeOthers ? revokeOthers() : Promise.resolve()),
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  });

  const when = (iso: string | null) =>
    iso ? new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '—';

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;

  const others = query.data.filter((s) => !s.current);

  return (
    <div className="flex flex-col gap-4">
      {signOut.isError ? <Alert tone="danger">{errorText(t, signOut.error)}</Alert> : null}
      <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface">
        {query.data.map((s: DeviceSession) => (
          <li key={s.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 items-start gap-3">
              {s.kind === 'browser' ? (
                <Monitor aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted" />
              ) : (
                <Smartphone aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted" />
              )}
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">
                  {s.name}
                  {s.current ? <span className="ms-2 rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary">{t('sessions.thisDevice')}</span> : null}
                </p>
                <p className="truncate text-xs text-muted">{s.user_agent ?? '—'}</p>
                <p className="text-xs text-muted">
                  {t('sessions.signedIn', { when: when(s.signed_in_at) })} · {t('sessions.lastUsed', { when: when(s.last_seen_at) })}
                  {s.ip ? ` · ${s.ip}` : ''}
                </p>
              </div>
            </div>
            {s.current ? null : (
              <Button variant="outline" size="sm" onClick={() => signOut.mutate(s.id)} disabled={signOut.isPending}>
                <LogOut aria-hidden="true" />
                {t('sessions.signOutDevice')}
              </Button>
            )}
          </li>
        ))}
      </ul>
      {revokeOthers && others.length > 0 ? (
        <div>
          <Button variant="danger" onClick={() => signOutOthers.mutate()} disabled={signOutOthers.isPending}>
            {t('sessions.signOutOthers')}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

export function DeviceSessionsPage() {
  const { t } = useTranslation();

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <Card>
        <h1 className="text-2xl font-semibold tracking-tight">{t('sessions.title')}</h1>
        <p className="mt-2 text-sm text-muted">{t('sessions.subtitle')}</p>
      </Card>
      <DeviceSessionsPanel queryKey={['me', 'sessions']} list={sessions.list} revoke={sessions.revoke} revokeOthers={sessions.revokeOthers} />
    </div>
  );
}
