import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { Switch as Toggle } from '@/components/ui/switch';
import { type NotificationPreferences, notificationPreferences, preferencesKey, useNotificationActions, useNotifications, usePreferencesQuery } from './api';
import { NotificationList } from './NotificationList';
import { useSocketState } from './socket';

/** Inbox: every notification, for phones (the bottom tab) and "See all". */
export function InboxPage() {
  const { t } = useTranslation();
  const connected = useSocketState((s) => s.connected);
  const { data, isPending, error } = useNotifications(!connected);
  const { markAllRead } = useNotificationActions();

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('notifications.title')}</h1>
        <Button variant="outline" disabled={!data || data.unread === 0 || markAllRead.isPending} onClick={() => markAllRead.mutate()}>
          {t('notifications.markAllRead')}
        </Button>
      </div>
      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}
      {data ? (
        <Card className="p-2 sm:p-3">
          <NotificationList items={data.data} />
        </Card>
      ) : null}
    </div>
  );
}

/** Push on or off, email on or off, and mute per type. */
export function NotificationSettingsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const { data, isPending, error } = usePreferencesQuery();
  const [draft, setDraft] = useState<NotificationPreferences | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  // What is on screen: the person's unsaved changes, else what the server has.
  const current = draft ?? data ?? null;

  const save = async () => {
    if (!current) return;
    setSaving(true);
    setMessage(null);
    try {
      const saved = await notificationPreferences.save({ push: current.push, email: current.email, muted: current.muted });
      queryClient.setQueryData(preferencesKey, saved);
      setDraft(saved);
      setMessage({ tone: 'success', text: t('notifications.settings.saved') });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('notifications.settings.title')}</h1>
        <p className="mt-1 text-sm text-muted">{t('notifications.settings.intro')}</p>
      </div>
      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}
      {current ? (
        <Card className="flex flex-col gap-5">
          {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
          <fieldset className="flex flex-col gap-1">
            <legend className="mb-1 text-sm font-semibold">{t('notifications.settings.channels')}</legend>
            <Toggle label={t('notifications.settings.push')} hint={t('notifications.settings.pushHint')} checked={current.push} onChange={(push) => setDraft({ ...current, push })} />
            <Toggle label={t('notifications.settings.email')} hint={t('notifications.settings.emailHint')} checked={current.email} onChange={(email) => setDraft({ ...current, email })} />
          </fieldset>
          <fieldset className="flex flex-col gap-1">
            <legend className="mb-1 text-sm font-semibold">{t('notifications.settings.types')}</legend>
            <p className="mb-1 text-xs text-muted">{t('notifications.settings.typesHint')}</p>
            {current.types.map((type) => (
              <Toggle
                key={type}
                label={t(`notifications.types.${type}`, { defaultValue: type })}
                checked={!current.muted.includes(type)}
                onChange={(on) => setDraft({ ...current, muted: on ? current.muted.filter((m) => m !== type) : [...current.muted, type] })}
              />
            ))}
          </fieldset>
          <div className="flex justify-end">
            <Button onClick={() => void save()} disabled={saving}>
              {saving ? t('common.working') : t('common.save')}
            </Button>
          </div>
        </Card>
      ) : null}
    </div>
  );
}

