import { Bell } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { useNotificationActions, useNotifications } from '@/features/notifications/api';
import { NotificationList } from '@/features/notifications/NotificationList';
import { useSocketState } from '@/features/notifications/socket';
import { Popover } from './Popover';

/** Bell with the unread badge; its panel lists notifications. */
export function NotificationBell() {
  const { t } = useTranslation();
  const connected = useSocketState((s) => s.connected);
  const { data } = useNotifications(!connected);
  const { markAllRead } = useNotificationActions();
  const unread = data?.unread ?? 0;

  return (
    <Popover
      label={t('notifications.title')}
      trigger={(props) => (
        <Button {...props} variant="ghost" size="icon" className="relative" aria-label={t('notifications.bell', { count: unread })} data-testid="bell">
          <Bell aria-hidden="true" />
          {unread > 0 ? (
            <span
              aria-hidden="true"
              className="absolute end-1.5 top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger-solid px-1 text-[10px] font-bold text-on-solid"
            >
              {unread > 99 ? '99+' : unread}
            </span>
          ) : null}
        </Button>
      )}
    >
      {(close) => (
        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between gap-2">
            <h2 className="text-base font-semibold">{t('notifications.title')}</h2>
            <Button variant="ghost" size="sm" className="h-11" disabled={unread === 0 || markAllRead.isPending} onClick={() => markAllRead.mutate()}>
              {t('notifications.markAllRead')}
            </Button>
          </div>
          <div className="max-h-96 overflow-y-auto">
            <NotificationList items={data?.data ?? []} onOpen={close} />
          </div>
          <div className="flex items-center justify-between gap-2 border-t border-border pt-2 text-sm">
            <Link to="/notifications" onClick={close} className="inline-flex min-h-11 items-center text-primary underline-offset-2 hover:underline">
              {t('notifications.seeAll')}
            </Link>
            <Link to="/account/notifications" onClick={close} className="inline-flex min-h-11 items-center text-primary underline-offset-2 hover:underline">
              {t('nav.notificationSettings')}
            </Link>
          </div>
        </div>
      )}
    </Popover>
  );
}
