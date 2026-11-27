import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { apiGetBody, send } from '@/lib/requests';

export interface AppNotification {
  id: string;
  type: string;
  title: string;
  body: string | null;
  /** A screen path inside the app. */
  link: string | null;
  data: Record<string, unknown>;
  read: boolean;
  created_at: string | null;
}

export interface NotificationList {
  data: AppNotification[];
  unread: number;
}

export interface NotificationPreferences {
  push: boolean;
  email: boolean;
  muted: string[];
  /** Types the person can mute. */
  types: string[];
}

export const notificationsKey = ['notifications'] as const;
export const preferencesKey = ['notification-preferences'] as const;

/** Polling interval used only while the socket is not connected. */
export const POLL_MS = 60_000;

/**
 * The bell's list and unread count. The screen sends this request on its
 * own (first load, socket nudges, polling), so every fetch is a background
 * request and never counts as use of the session.
 */
export function useNotifications(poll: boolean) {
  return useQuery({
    queryKey: notificationsKey,
    queryFn: apiGetBody<NotificationList>('/me/notifications'),
    meta: { background: true },
    refetchInterval: poll ? POLL_MS : false,
    refetchIntervalInBackground: false,
    staleTime: 15_000,
    retry: false,
  });
}

export function useNotificationActions() {
  const queryClient = useQueryClient();
  const refresh = () => queryClient.invalidateQueries({ queryKey: notificationsKey });

  const markRead = useMutation({
    mutationFn: (id: string) => send<AppNotification>('POST', '/me/notifications/{id}/read', { path: { id } }),
    onMutate: (id) => {
      queryClient.setQueryData<NotificationList>(notificationsKey, (old) =>
        old
          ? {
              data: old.data.map((n) => (n.id === id ? { ...n, read: true } : n)),
              unread: Math.max(0, old.unread - (old.data.some((n) => n.id === id && !n.read) ? 1 : 0)),
            }
          : old,
      );
    },
    onSettled: refresh,
  });

  const markAllRead = useMutation({
    mutationFn: () => send<{ marked: number }>('POST', '/me/notifications/read-all'),
    onMutate: () => {
      queryClient.setQueryData<NotificationList>(notificationsKey, (old) => (old ? { data: old.data.map((n) => ({ ...n, read: true })), unread: 0 } : old));
    },
    onSettled: refresh,
  });

  return { markRead, markAllRead };
}

export function usePreferencesQuery() {
  return useQuery({ queryKey: preferencesKey, queryFn: apiGet<NotificationPreferences>('/me/notification-preferences') });
}

export const notificationPreferences = {
  save: (body: Pick<NotificationPreferences, 'push' | 'email' | 'muted'>) =>
    send<NotificationPreferences>('PUT', '/me/notification-preferences', { body }),
};
