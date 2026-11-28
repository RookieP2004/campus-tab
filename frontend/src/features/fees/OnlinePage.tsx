import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { fees, type GatewayView, type Reconciliation, rupees } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/**
 * Online payments: the school's own gateway account (the Chief enters the
 * keys; the secrets are stored encrypted and never shown again; the
 * Principal sees the masked key) and the nightly reconciliation results.
 */
export function OnlinePage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const canEdit = holds(me, 'fees.gateway_edit');
  const gateway = useQuery({ queryKey: ['fees', 'gateway'], queryFn: apiGet<GatewayView>('/fees/gateway'), retry: false });
  const recon = useQuery({ queryKey: ['fees', 'reconciliations'], queryFn: apiGet<Reconciliation[]>('/fees/reconciliations'), retry: false, enabled: holds(me, 'fees.dues_view', 'school') });
  const [provider, setProvider] = useState<string | null>(null);
  const [keyId, setKeyId] = useState('');
  const [secret, setSecret] = useState('');
  const [webhook, setWebhook] = useState('');
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  if (gateway.isPending) return <Spinner label={t('common.loading')} />;
  if (gateway.error || !gateway.data) return <Alert tone="danger">{errorText(t, gateway.error)}</Alert>;
  const g = gateway.data;
  const chosen = provider ?? g.provider ?? 'razorpay';

  const save = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await fees.saveGateway({ provider: chosen, key_id: keyId.trim(), key_secret: secret || undefined, webhook_secret: webhook || undefined });
      setSecret('');
      setWebhook('');
      setKeyId('');
      setMessage({ tone: 'success', text: t('fees.online.saved') });
      void queryClient.invalidateQueries({ queryKey: ['fees', 'gateway'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.online.title')}</h1>
      <Card className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t('fees.online.gateway')}</h2>
        {g.configured ? (
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt className="text-muted">{t('fees.online.provider')}</dt>
            <dd>{t(`fees.online.providers.${g.provider}`)}</dd>
            <dt className="text-muted">{t('fees.online.keyId')}</dt>
            <dd className="font-mono">{g.key_id}</dd>
            <dt className="text-muted">{t('fees.online.state')}</dt>
            <dd>
              <StatusChip status={g.status === 'active' ? 'approved' : 'draft'} label={t(`fees.online.states.${g.status ?? 'off'}`)} />
            </dd>
            {g.updated_by ? (
              <>
                <dt className="text-muted">{t('fees.online.updated')}</dt>
                <dd>
                  {g.updated_by} · {formatDateTime(g.updated_at, i18n.language)}
                </dd>
              </>
            ) : null}
          </dl>
        ) : (
          <p className="text-sm text-muted">{t('fees.online.none')}</p>
        )}
        <p className="flex items-start gap-2 text-xs text-muted">
          <ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
          {t('fees.online.secretsNote')}
        </p>
        {canEdit ? (
          <div className="flex flex-col gap-3 border-t border-border pt-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('fees.online.provider')}>
                {(f) => (
                  <Select {...f} value={chosen} onChange={(e) => setProvider(e.target.value)}>
                    {g.drivers.map((d) => (
                      <option key={d} value={d}>
                        {t(`fees.online.providers.${d}`)}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label={t('fees.online.keyId')} hint={chosen === 'razorpay' ? t('fees.online.testOnly') : undefined}>
                {(f) => <Input {...f} value={keyId} maxLength={60} autoComplete="off" onChange={(e) => setKeyId(e.target.value)} />}
              </Field>
              <Field label={t('fees.online.secret')} hint={g.configured ? t('fees.online.keepBlank') : undefined}>
                {(f) => <Input {...f} type="password" value={secret} autoComplete="new-password" onChange={(e) => setSecret(e.target.value)} />}
              </Field>
              <Field label={t('fees.online.webhookSecret')} hint={g.configured ? t('fees.online.keepBlank') : undefined}>
                {(f) => <Input {...f} type="password" value={webhook} autoComplete="new-password" onChange={(e) => setWebhook(e.target.value)} />}
              </Field>
            </div>
            {chosen === 'fake' ? <Alert tone="warning">{t('fees.online.fakeNote')}</Alert> : null}
            <p className="text-xs text-muted">{t('fees.online.webhookUrl', { url: `${location.origin}/api/v1/webhooks/payments/${chosen}` })}</p>
            {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
            <div>
              <Button className="h-11" disabled={busy || keyId.trim() === '' || (!g.configured && (secret.length < 8 || webhook.length < 8))} onClick={() => void save()}>
                {t('fees.online.save')}
              </Button>
            </div>
          </div>
        ) : null}
      </Card>
      {recon.data ? (
        <Card className="flex flex-col gap-2">
          <h2 className="text-lg font-semibold">{t('fees.online.reconciliation')}</h2>
          <p className="text-xs text-muted">{t('fees.online.reconNote')}</p>
          {recon.data.length === 0 ? <p className="text-sm text-muted">{t('fees.online.noRecon')}</p> : null}
          <ul className="flex flex-col divide-y divide-border">
            {recon.data.map((r) => (
              <li key={r.id} className="flex flex-col gap-1 py-2 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="min-w-40 flex-1">
                    {formatDate(asDate(r.date), i18n.language)} · {t('fees.online.matched', { count: r.matched })}
                  </span>
                  <StatusChip status={r.status === 'ok' ? 'approved' : r.status === 'failed' ? 'rejected' : 'pending'} label={t(`fees.online.reconStatus.${r.status}`)} />
                </div>
                {r.mismatches.map((m) => (
                  <p key={m.gateway_payment_id + m.reason} className="text-xs text-muted">
                    {t(`fees.online.mismatch.${m.reason}`)} · <span className="font-mono">{m.gateway_payment_id}</span> · {rupees(m.ours_paise ?? m.theirs_paise, i18n.language)}
                  </p>
                ))}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}
