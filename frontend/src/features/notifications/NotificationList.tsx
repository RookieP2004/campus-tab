import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type AppNotification, useNotificationActions } from './api';

/**
 * The person's notifications, newest first. Opening one marks it read and
 * goes to its screen. Used by the bell's panel and the Inbox screen.
 */
export function NotificationList({ items, onOpen }: { items: AppNotification[]; onOpen?: () => void }) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { markRead } = useNotificationActions();

  if (items.length === 0) return <p className="px-1 py-4 text-sm text-muted">{t('notifications.empty')}</p>;

  return (
    <ul className="flex flex-col gap-1">
      {items.map((n) => (
        <li key={n.id}>
          <button
            type="button"
            onClick={() => {
              if (!n.read) markRead.mutate(n.id);
              onOpen?.();
              if (n.link) navigate(n.link);
            }}
            className={cn(
              'flex min-h-11 w-full items-start gap-2.5 rounded-input px-2 py-2 text-start hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring',
              !n.read && 'bg-primary-soft/40',
            )}
          >
            <span aria-hidden="true" className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-primary-solid')} />
            <span className="flex min-w-0 flex-1 flex-col">
              <span className={cn('text-sm text-fg', !n.read && 'font-semibold')}>
                {n.title}
                {!n.read ? <span className="sr-only"> ({t('notifications.unread')})</span> : null}
              </span>
              {n.body ? <span className="text-xs text-muted">{n.body}</span> : null}
              {n.created_at ? (
                <time dateTime={n.created_at} className="text-xs text-muted">
                  {formatDateTime(n.created_at, i18n.language)}
                </time>
              ) : null}
            </span>
          </button>
        </li>
      ))}
    </ul>
  );
}
